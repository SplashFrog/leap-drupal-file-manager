<?php

declare(strict_types=1);

namespace Drupal\leap_file_manager\Strategy;

use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Handles processing for standard Document, Audio, and general file types.
 *
 * This strategy acts as the default "Pass-through" for generic files.
 */
final class FileStrategy implements MediaTypeStrategyInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(string $field_type): bool {
    return $field_type === 'file';
  }

  /**
   * {@inheritdoc}
   */
  public function mergeMetadata(array $original_data, MediaInterface $entity): array {
    // General files do not have 'alt' or 'title' fields to sync.
    return $original_data;
  }

  /**
   * {@inheritdoc}
   */
  public function preSaveProcess(MediaInterface $entity): void {
    // Force the Media entity to regenerate its internal thumbnail mapping
    // (e.g. mapping the file extension to a generic icon).
    if (method_exists($entity, 'updateQueuedThumbnail')) {
      $entity->updateQueuedThumbnail();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postProcess(MediaInterface $entity, FileInterface $active_file): void {
    // Generic files do not require post-save processing.
  }

}
