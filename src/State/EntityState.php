<?php

declare(strict_types=1);

namespace Drupal\leap_file_manager\State;

/**
 * Represents the calculated lifecycle state of a Media entity.
 *
 * This Data Transfer Object (DTO) encapsulates the complex analysis
 * of Content Moderation workflows and Core status into a set of
 * simple, actionable boolean flags.
 */
readonly class EntityState {

  /**
   * Constructs an EntityState object.
   *
   * @param bool $isPublishedState
   *   TRUE if the entity is currently in a published state (visible to the public).
   * @param bool $isForwardRevision
   *   TRUE if the entity is in a forward revision state (e.g., Draft, Review).
   *   This usually means a different "live" default revision currently exists.
   * @param bool $isTerminalState
   *   TRUE if the entity is in a terminal unpublished state (e.g., Archived).
   * @param bool $wasPublished
   *   TRUE if the entity was published prior to this save event.
   */
  public function __construct(
    public bool $isPublishedState,
    public bool $isForwardRevision,
    public bool $isTerminalState,
    public bool $wasPublished,
  ) {}

}
