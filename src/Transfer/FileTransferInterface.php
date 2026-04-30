<?php

declare(strict_types=1);

namespace Drupal\leap_file_manager\Transfer;

use Drupal\Core\File\FileExists;
use Drupal\file\FileInterface;

/**
 * Defines the contract for physical file movement engines.
 *
 * Implementations are responsible for the physical relocation of files
 * and updating the database records to reflect the new URIs.
 */
interface FileTransferInterface {

  /**
   * Ensures the parent directory of the given URI exists and is writable.
   *
   * @param string $uri
   *   The target file URI.
   */
  public function ensureDirectory(string $uri): void;

  /**
   * Moves a file to the destination, updating the database record.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to move.
   * @param string $destination_uri
   *   The target URI.
   * @param \Drupal\Core\File\FileExists $move_mode
   *   The collision handling mode.
   *
   * @return \Drupal\file\FileInterface|null
   *   The updated file entity, or NULL if the move failed due to permissions or collision.
   *
   * @throws \RuntimeException
   *   Thrown if the source file does not exist on the physical disk.
   */
  public function moveFile(FileInterface $file, string $destination_uri, FileExists $move_mode): ?FileInterface;

  /**
   * Executes the anti-hijack detachment on an original file.
   *
   * This is used to "unlink" an old file entity from a URI that is about
   * to be taken over by a new file, preventing Drupal Core from
   * automatically reassigning the old FID.
   *
   * @param \Drupal\file\FileInterface $original_file
   *   The original file entity to detach.
   */
  public function antiHijack(FileInterface $original_file): void;

}
