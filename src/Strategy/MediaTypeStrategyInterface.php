<?php

declare(strict_types=1);

namespace Drupal\leap_file_manager\Strategy;

use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Defines a strategy for processing specific media field types.
 *
 * Strategies allow the Orchestrator to delegate type-specific tasks
 * (like dimension clearing or image style flushing) without knowing
 * the internal details of every media type.
 */
interface MediaTypeStrategyInterface {

  /**
   * Evaluates if this strategy applies to the given field type.
   *
   * @param string $field_type
   *   The field type (e.g. 'image', 'file').
   *
   * @return bool
   *   TRUE if this strategy should be used.
   */
  public function applies(string $field_type): bool;

  /**
   * Merges replacement metadata into the original source metadata.
   *
   * This is called before the entity is saved. It ensures that data
   * like alt text is carried over while stale data (like dimensions)
   * can be cleared to force regeneration.
   *
   * @param array $original_data
   *   The existing data array from the source field.
   * @param \Drupal\media\MediaInterface $entity
   *   The media entity being processed.
   *
   * @return array
   *   The updated data array to be saved.
   */
  public function mergeMetadata(array $original_data, MediaInterface $entity): array;

  /**
   * Performs operations required immediately before the entity is saved.
   *
   * Typically used for forcing internal Drupal component updates.
   *
   * @param \Drupal\media\MediaInterface $entity
   *   The media entity.
   */
  public function preSaveProcess(MediaInterface $entity): void;

  /**
   * Performs post-save cleanup or finalization tasks.
   *
   * Typically used for cache-busting (Varnish/CloudFront/Image Styles).
   *
   * @param \Drupal\media\MediaInterface $entity
   *   The media entity.
   * @param \Drupal\file\FileInterface $active_file
   *   The physical file currently referenced by the entity.
   */
  public function postProcess(MediaInterface $entity, FileInterface $active_file): void;

}
