<?php

declare(strict_types=1);

namespace Drupal\leap_file_manager\Strategy;

use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Handles processing for Image and SVG media types.
 *
 * This strategy ensures that image dimensions are recalculated and
 * image styles are flushed when a physical file is replaced.
 */
final class ImageStrategy implements MediaTypeStrategyInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(string $field_type): bool {
    return in_array($field_type, ['image', 'svg_image_field'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function mergeMetadata(array $original_data, MediaInterface $entity): array {
    /**
     * Business Logic:
     * We keep all existing metadata (like focal_point coordinates, alt, title)
     * but explicitly UNSET 'width' and 'height'.
     *
     * Why? By stripping dimensions, we force Drupal's updateQueuedThumbnail()
     * to realize it has incomplete data, which triggers a fresh physical
     * scan of the new file's dimensions in a single save operation.
     */
    $merged_data = $original_data;
    unset($merged_data['width'], $merged_data['height']);

    return $merged_data;
  }

  /**
   * {@inheritdoc}
   */
  public function preSaveProcess(MediaInterface $entity): void {
    // Force regeneration of the Media entity's internal thumbnail record.
    if (method_exists($entity, 'updateQueuedThumbnail')) {
      $entity->updateQueuedThumbnail();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postProcess(MediaInterface $entity, FileInterface $active_file): void {
    // Flush all generated Image Style derivatives from public://styles.
    $uri = $active_file->getFileUri();
    if ($uri && function_exists('image_path_flush')) {
      image_path_flush($uri);
    }
  }

}
