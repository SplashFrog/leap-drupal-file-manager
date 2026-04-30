<?php

declare(strict_types=1);

namespace Drupal\leap_file_manager\Strategy;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;

/**
 * Service to select the appropriate processing strategy for a media entity.
 *
 * This factory inspects the media source field and returns a concrete
 * Strategy implementation.
 */
final class StrategyFactory {

  /**
   * The registered strategies.
   *
   * @var \Drupal\leap_file_manager\Strategy\MediaTypeStrategyInterface[]
   */
  private array $strategies = [];

  /**
   * The logger instance.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs the StrategyFactory.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->logger = $logger_factory->get('leap_file_manager');

    // Register known strategies.
    $this->strategies[] = new ImageStrategy();
    $this->strategies[] = new FileStrategy();
  }

  /**
   * Retrieves the processing strategy that applies to the given media entity.
   *
   * @param \Drupal\media\MediaInterface $entity
   *   The media entity.
   * @param string $source_field_name
   *   The machine name of the source field.
   *
   * @return \Drupal\leap_file_manager\Strategy\MediaTypeStrategyInterface
   *   The selected strategy implementation.
   */
  public function getStrategyForEntity(MediaInterface $entity, string $source_field_name): MediaTypeStrategyInterface {
    try {
      $field_definition = $entity->getFieldDefinition($source_field_name);
      if ($field_definition) {
        $field_type = $field_definition->getType();
        foreach ($this->strategies as $strategy) {
          if ($strategy->applies($field_type)) {
            return $strategy;
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to determine strategy for field @field. Error: @msg', [
        '@field' => $source_field_name,
        '@msg' => $e->getMessage(),
      ]);
    }

    // Default fallback (safest option).
    return new FileStrategy();
  }

}
