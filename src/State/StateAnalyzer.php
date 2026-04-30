<?php

declare(strict_types=1);

namespace Drupal\leap_file_manager\State;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Dynamically determines the lifecycle state of a Media entity.
 *
 * This service abstracts the complexity of Content Moderation workflows,
 * allowing the Orchestrator to make decisions based on standardized
 * state flags rather than workflow-specific machine names.
 */
final class StateAnalyzer {

  /**
   * The module logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a new StateAnalyzer.
   *
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderationInfo
   *   The moderation information service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    private readonly ModerationInformationInterface $moderationInfo,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->logger = $logger_factory->get('leap_file_manager');
  }

  /**
   * Analyzes an entity and returns its precise lifecycle state flags.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to analyze.
   *
   * @return \Drupal\leap_file_manager\State\EntityState
   *   The calculated state DTO.
   */
  public function analyze(EntityInterface $entity): EntityState {
    $wasPublished = $this->calculateWasPublished($entity);

    // 1. Handle Moderated Entities (Standard Content Moderation).
    if ($this->moderationInfo->isModeratedEntity($entity)) {
      $workflow = $this->moderationInfo->getWorkflowForEntity($entity);

      if ($workflow) {
        try {
          $plugin = $workflow->getTypePlugin();

          if ($entity->hasField('moderation_state') && !$entity->get('moderation_state')->isEmpty()) {
            $state_id = $entity->get('moderation_state')->value;
            $state = $plugin->getState($state_id);

            return new EntityState(
              isPublishedState: $state->isPublishedState(),
              isForwardRevision: !$state->isPublishedState() && !$state->isDefaultRevisionState(),
              isTerminalState: !$state->isPublishedState() && $state->isDefaultRevisionState(),
              wasPublished: $wasPublished
            );
          }
        }
        catch (\InvalidArgumentException $e) {
          $this->logger->error('StateAnalyzer failed to locate moderation state "@state_id". Error: @message', [
            '@state_id' => $state_id ?? 'NULL',
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    // 2. Fallback to Core status (Unmoderated entities).
    $isPublished = $entity->isPublished();
    return new EntityState(
      isPublishedState: $isPublished,
      isForwardRevision: FALSE,
      isTerminalState: !$isPublished,
      wasPublished: $wasPublished
    );
  }

  /**
   * Determines if the entity was previously published.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return bool
   *   TRUE if the previous revision was published.
   */
  private function calculateWasPublished(EntityInterface $entity): bool {
    if ($entity->isNew()) {
      return FALSE;
    }

    $original = $entity->original ?? NULL;
    if (!$original) {
      return FALSE;
    }

    if ($this->moderationInfo->isModeratedEntity($original) && $original->hasField('moderation_state')) {
      $workflow = $this->moderationInfo->getWorkflowForEntity($original);
      if ($workflow) {
        try {
          $state_id = $original->get('moderation_state')->value;
          return $workflow->getTypePlugin()->getState($state_id)->isPublishedState();
        }
        catch (\InvalidArgumentException) {
        }
      }
    }

    return $original->isPublished();
  }

}
