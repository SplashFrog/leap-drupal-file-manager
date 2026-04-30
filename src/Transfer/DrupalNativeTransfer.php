<?php

declare(strict_types=1);

namespace Drupal\leap_file_manager\Transfer;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;

/**
 * Executes file operations using pure Drupal Core APIs.
 *
 * This engine is highly integrated with Drupal's event system but relies
 * on strict stream wrapper permissions (e.g., the trusted private:// scheme).
 */
final class DrupalNativeTransfer implements FileTransferInterface {

  /**
   * Constructs the DrupalNativeTransfer engine.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\file\FileRepositoryInterface $fileRepository
   *   The file repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function ensureDirectory(string $uri): void {
    $dir = dirname($uri);
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  }

  /**
   * {@inheritdoc}
   */
  public function moveFile(FileInterface $file, string $destination_uri, FileExists $move_mode): ?FileInterface {
    $source_uri = $file->getFileUri();

    // Ghost File Check.
    if (!file_exists($source_uri)) {
      throw new \RuntimeException(sprintf('Source file missing from disk: %s', $source_uri));
    }

    $mode = $move_mode === FileExists::Rename ? FileExists::Rename : FileExists::Replace;
    $moved_file = $this->fileRepository->move($file, $destination_uri, $mode);

    // Ensure metadata filename matches exactly (important for SEO-preserved swaps).
    if ($moved_file) {
      $final_basename = basename($moved_file->getFileUri());
      if ($moved_file->getFilename() !== $final_basename) {
        $moved_file->setFilename($final_basename);
        $moved_file->save();
      }
    }
    return $moved_file;
  }

  /**
   * {@inheritdoc}
   */
  public function antiHijack(FileInterface $original_file): void {
    // Detach the FID from the URI by moving the reference to a temporary name.
    $temp_uri = 'temporary://deleted_file_' . $original_file->id() . '.txt';
    $original_file->setFileUri($temp_uri);
    $original_file->setTemporary();
    $original_file->save();

    // Reset cache so subsequent loads see the change.
    $this->entityTypeManager->getStorage('file')->resetCache([$original_file->id()]);
  }

}
