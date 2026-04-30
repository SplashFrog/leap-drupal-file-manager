<?php

declare(strict_types=1);

namespace Drupal\leap_file_manager\Transfer;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;

/**
 * Executes file operations using native PHP and raw SQL.
 *
 * This engine manually bypasses Drupal's brittle stream wrapper validations
 * to guarantee success in restricted environments (like Docker/DDEV) or
 * when dealing with deeply nested public-folder pseudo-private directories.
 */
final class ManualTransfer implements FileTransferInterface {

  /**
   * Constructs a ManualTransfer object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Resolves a URI to its absolute physical path.
   *
   * This bypasses Drupal's realpath() which can fail on custom wrappers
   * or htaccess-restricted directories.
   */
  private function resolvePhysicalPath(string $uri): ?string {
    $scheme = $this->streamWrapperManager->getScheme($uri);
    if (!$scheme) {
      return NULL;
    }

    $base_real = $this->fileSystem->realpath($scheme . '://');
    if (!$base_real) {
      return NULL;
    }

    $relative = (string) preg_replace('|^[a-z0-9-]+://|', '', $uri);
    return $base_real . '/' . $relative;
  }

  /**
   * {@inheritdoc}
   */
  public function ensureDirectory(string $uri): void {
    $dir = dirname($uri);
    $real_dir = $this->resolvePhysicalPath($dir);

    if ($real_dir) {
      clearstatcache(TRUE, $real_dir);
      if (!is_dir($real_dir)) {
        // Create directory with wide permissions natively.
        @mkdir($real_dir, 0777, TRUE);

        // Traverse up to public:// to ensure all parents are reachable by the web server.
        $current = $real_dir;
        $base_public = $this->fileSystem->realpath('public://');
        while ($current && $current !== $base_public && $current !== '/' && $current !== '.') {
          @chmod($current, 0777);
          $current = dirname($current);
        }

        clearstatcache(TRUE);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function moveFile(FileInterface $file, string $destination_uri, FileExists $move_mode): ?FileInterface {
    $source_uri = $file->getFileUri();

    if ($source_uri === $destination_uri) {
      return $file;
    }

    $real_source = $this->resolvePhysicalPath($source_uri);
    $real_dest = $this->resolvePhysicalPath($destination_uri);

    // Ghost File Check.
    if (!$real_source || !file_exists($real_source)) {
      throw new \RuntimeException(sprintf('Source file missing from disk: %s', $real_source ?: $source_uri));
    }

    // Execute physical rename natively.
    if ($real_dest) {
      if (@rename($real_source, $real_dest)) {
        @chmod($real_dest, 0666);

        $file->setFileUri($destination_uri);
        $final_basename = basename($destination_uri);
        $file->setFilename($final_basename);

        // CRITICAL: Update the file_managed table directly to avoid cascade hooks.
        // This is necessary because core Entity API often refuses to change URIs
        // in ways that it considers "invalid" or "duplicates.".
        $this->database->update('file_managed')
          ->fields([
            'uri' => $destination_uri,
            'filename' => $final_basename,
          ])
          ->condition('fid', $file->id())
          ->execute();

        $this->entityTypeManager->getStorage('file')->resetCache([$file->id()]);
        return $file;
      }
      else {
        $this->logger->error('Manual transfer failed from @src to @dest', ['@src' => $real_source, '@dest' => $real_dest]);
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function antiHijack(FileInterface $original_file): void {
    // Forcefully detach the file entity from its URI via direct SQL.
    $temp_uri = 'temporary://deleted_file_' . $original_file->id() . '.txt';
    $original_file->setFileUri($temp_uri);
    $original_file->setTemporary();

    $this->database->update('file_managed')
      ->fields([
        'uri' => $temp_uri,
        'status' => 0,
      ])
      ->condition('fid', $original_file->id())
      ->execute();

    $this->entityTypeManager->getStorage('file')->resetCache([$original_file->id()]);
  }

}
