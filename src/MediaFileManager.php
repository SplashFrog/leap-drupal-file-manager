<?php

declare(strict_types=1);

namespace Drupal\leap_file_manager;

use Drupal\leap_file_manager\Form\MediaFormHandler;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Event\FileUploadSanitizeNameEvent;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\leap_file_manager\State\EntityState;
use Drupal\leap_file_manager\State\StateAnalyzer;
use Drupal\leap_file_manager\Strategy\MediaTypeStrategyInterface;
use Drupal\leap_file_manager\Strategy\StrategyFactory;
use Drupal\leap_file_manager\Transfer\DrupalNativeTransfer;
use Drupal\leap_file_manager\Transfer\FileTransferInterface;
use Drupal\leap_file_manager\Transfer\ManualTransfer;
use Drupal\media\MediaInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * Orchestrates the secure replacement and visibility of media files.
 *
 * This service is the "Brain" of the module. It manages the lifecycle of
 * media file replacements, ensuring that:
 * 1. SEO filenames are preserved during physical overwrites.
 * 2. New files are staged securely in non-public folders until published.
 * 3. Physical file moves are handled by the most appropriate engine
 *    (Native or Manual).
 * 4. Stale metadata and cache derivatives are flushed upon completion.
 */
class MediaFileManager {

  /**
   * The machine name of the rename field.
   */
  private const string RENAME_FIELD = 'field_leap_rename_file';

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Temporary runtime state storage for cross-hook communication.
   *
   * @var array
   */
  private array $runtimeState = [];

  /**
   * The active physical file movement engine.
   */
  private readonly FileTransferInterface $transferEngine;

  /**
   * Constructs the MediaFileManager orchestrator.
   *
   * @param \Drupal\leap_file_manager\State\StateAnalyzer $stateAnalyzer
   *   Determines current entity moderation state.
   * @param \Drupal\leap_file_manager\Strategy\StrategyFactory $strategyFactory
   *   Selects the correct strategy (Image vs File) for processing.
   * @param \Drupal\file\FileRepositoryInterface $fileRepository
   *   Standard Drupal file repository for native operations.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   Low-level file system operations.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads and manages entities.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   Dispatches sanitation events.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Creates the module logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Retrieves module settings.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   Generates absolute URLs for CDN purging.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   Verifies file scheme validity.
   * @param \Drupal\Core\Database\Connection $database
   *   Direct database access for Manual transfer engine.
   * @param object|null $purgeQueuers
   *   Optional: The Purge module's queuer service.
   * @param object|null $purgeQueue
   *   Optional: The Purge module's queue service.
   * @param object|null $purgeInvalidations
   *   Optional: The Purge module's invalidations factory.
   */
  public function __construct(
    private readonly StateAnalyzer $stateAnalyzer,
    private readonly StrategyFactory $strategyFactory,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventDispatcherInterface $eventDispatcher,
    LoggerChannelFactoryInterface $logger_factory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly Connection $database,
    private readonly ?object $purgeQueuers = NULL,
    private readonly ?object $purgeQueue = NULL,
    private readonly ?object $purgeInvalidations = NULL,
  ) {
    $this->logger = $logger_factory->get('leap_file_manager');

    // Hot-swap engines based on whether a true private stream wrapper is available.
    $settings = $this->configFactory->get('leap_file_manager.settings');
    $is_native = $settings->get('use_private_filesystem') && $this->streamWrapperManager->isValidScheme('private');

    $this->transferEngine = $is_native
      ? new DrupalNativeTransfer($this->fileSystem, $this->fileRepository, $this->entityTypeManager)
      : new ManualTransfer($this->fileSystem, $this->entityTypeManager, $this->streamWrapperManager, $this->database, $this->logger);
  }

  /**
   * Dynamically retrieves the configured secure scheme.
   *
   * @return string
   *   Returns 'private://' if enabled, otherwise a public fallback path.
   */
  public function getSecureScheme(): string {
    $settings = $this->configFactory->get('leap_file_manager.settings');
    if ($settings->get('use_private_filesystem')) {
      if ($this->streamWrapperManager->isValidScheme('private')) {
        return 'private://';
      }
    }
    return 'public://' . trim($this->getFallbackPath(), '/') . '/';
  }

  /**
   * Dynamically retrieves the fallback path.
   *
   * @return string
   *   The directory name (e.g. 'private-files').
   */
  public function getFallbackPath(): string {
    return (string) ($this->configFactory->get('leap_file_manager.settings')->get('public_fallback_path') ?: 'private-files');
  }

  /**
   * Sets a value in the runtime state for the current request.
   */
  private function setRuntimeValue(MediaInterface $entity, string $key, mixed $value): void {
    $this->runtimeState[$entity->uuid()][$key] = $value;
  }

  /**
   * Retrieves a value from the runtime state for the current request.
   */
  private function getRuntimeValue(MediaInterface $entity, string $key, mixed $default = NULL): mixed {
    return $this->runtimeState[$entity->uuid()][$key] ?? $default;
  }

  /**
   * Discovers the primary file field for a given Media entity.
   *
   * @param \Drupal\media\MediaInterface $entity
   *   The media entity.
   *
   * @return string|null
   *   The machine name of the source field.
   */
  public function discoverSourceField(MediaInterface $entity): ?string {
    try {
      $source = $entity->getSource();
      $configuration = $source->getConfiguration();
      if (!empty($configuration['source_field'])) {
        return $configuration['source_field'];
      }
    }
    catch (\Exception) {
    }

    $replacement_field = MediaFormHandler::PSEUDO_REPLACEMENT_FIELD;
    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      $type = $definition->getType();
      if ($field_name !== $replacement_field && in_array($type, ['file', 'image', 'svg_image_field'], TRUE)) {
        return $field_name;
      }
    }
    return NULL;
  }

  /**
   * Determines if a Media entity is opted-in to the File Manager workflow.
   *
   * This is a "Double-Key" validation. It requires both the presence of our
   * custom internal tracker field AND a valid Drupal core source field.
   */
  public function isManaged(MediaInterface $entity): bool {
    $staged_field = MediaFormHandler::STAGED_FIELD;

    // Key 1: Does it have the explicit opt-in tracker field?
    if (!$entity->hasField($staged_field)) {
      return FALSE;
    }

    // Key 2: Does it have a valid physical source field to manage?
    if ($this->discoverSourceField($entity) === NULL) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Processes the media entity during hook_media_presave.
   *
   * Handles Phase 2 (Execution) and Phase 3 (Visibility Enforcement).
   */
  public function processPresave(MediaInterface $entity): void {
    if (!$this->isManaged($entity)) {
      return;
    }

    $source_field_name = $this->discoverSourceField($entity);
    if (!$source_field_name || $entity->get($source_field_name)->isEmpty()) {
      return;
    }

    $staged_field = MediaFormHandler::STAGED_FIELD;
    $has_replacement = $entity->hasField($staged_field) && !$entity->get($staged_field)->isEmpty();
    $has_rename = $entity->hasField(self::RENAME_FIELD) && !$entity->get(self::RENAME_FIELD)->isEmpty();

    $state = $this->stateAnalyzer->analyze($entity);
    $strategy = $this->strategyFactory->getStrategyForEntity($entity, $source_field_name);

    $file_storage = $this->entityTypeManager->getStorage('file');
    $original_fid = $entity->get($source_field_name)->target_id;
    /** @var \Drupal\file\FileInterface|null $original_file */
    $original_file = $file_storage->load($original_fid);

    if (!$original_file) {
      return;
    }

    // Skip processing if this is a non-default (forward) revision.
    // Staging happens purely via field references until publication.
    $is_staged_draft = $state->isForwardRevision && !$entity->isNew();
    $should_execute_replacement = !$state->isForwardRevision || $entity->isNew();

    if ($is_staged_draft) {
      return;
    }

    // Trigger physical file movement.
    if ($should_execute_replacement && ($has_replacement || $has_rename)) {
      $this->executeReplaceRename($entity, $source_field_name, $state, $strategy, $original_file, $has_replacement, $has_rename);
    }

    // Ensure the file is in the correct storage (public vs private) for current state.
    $this->enforceVisibility($entity, $source_field_name, $original_fid, $original_file, $state);
  }

  /**
   * Helper: Strips stream wrapper prefixes from a URI.
   */
  private function getRelativePath(string $uri): string {
    return (string) preg_replace('|^[a-z0-9-]+://|', '', $uri);
  }

  /**
   * Helper: Strips the public fallback directory name from a path.
   */
  private function stripFallbackPrefix(string $relative_path): string {
    $fallback = $this->getFallbackPath() . '/';
    if (str_starts_with($relative_path, $fallback)) {
      return substr($relative_path, strlen($fallback));
    }
    return ltrim($relative_path, '/');
  }

  /**
   * Ensures a directory exists using the active transfer engine.
   */
  public function ensureDirectoryExists(string $uri): void {
    $this->transferEngine->ensureDirectory($uri);
  }

  /**
   * Generates a sanitized URI for the target file.
   */
  private function generateSanitizedTargetUri(string $unsanitized_filename, string $extension, string $destination_dir): string {
    $event = new FileUploadSanitizeNameEvent($unsanitized_filename, $extension);
    /** @var \Drupal\Core\File\Event\FileUploadSanitizeNameEvent $event */
    $event = $this->eventDispatcher->dispatch($event);
    return $destination_dir . '/' . $event->getFilename();
  }

  /**
   * Internal dispatcher for Replacement vs Rename logic.
   */
  protected function executeReplaceRename(MediaInterface $entity, string $source_field_name, EntityState $state, MediaTypeStrategyInterface $strategy, FileInterface $original_file, bool $has_replacement, bool $has_rename): void {
    try {
      if ($has_replacement) {
        $this->handleReplacement($entity, $source_field_name, $strategy, $original_file, $has_rename);
      }
      else {
        $this->handleRenameOnly($entity, $source_field_name, $strategy, $original_file);
      }
    }
    catch (\RuntimeException $e) {
      $this->logger->error('Ghost File Detected: @msg. Aborting replacement to preserve media integrity. Trace: @trace', [
        '@msg' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);

      // State Cleansing: Detach the broken staged file.
      $staged_field = MediaFormHandler::STAGED_FIELD;
      if ($entity->hasField($staged_field)) {
        $entity->set($staged_field, []);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('File replacement/rename failed: @msg. Trace: @trace', [
        '@msg' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
    }
  }

  /**
   * Performs a physical file swap while maintaining the SEO filename.
   */
  private function handleReplacement(MediaInterface $entity, string $source_field_name, MediaTypeStrategyInterface $strategy, FileInterface $original_file, bool $has_rename): void {
    $file_storage = $this->entityTypeManager->getStorage('file');

    $original_uri_current = $original_file->getFileUri();
    $orig_base = pathinfo($original_file->getFilename(), PATHINFO_FILENAME);
    $orig_ext = strtolower(pathinfo($original_file->getFilename(), PATHINFO_EXTENSION));

    $staged_field = MediaFormHandler::STAGED_FIELD;
    $replacement_fid = $entity->get($staged_field)->target_id;
    /** @var \Drupal\file\FileInterface $replacement_file */
    $replacement_file = $file_storage->load($replacement_fid);

    if (!$replacement_file) {
      return;
    }

    $replacement_uri = $replacement_file->getFileUri();
    $repl_base = pathinfo($replacement_file->getFilename(), PATHINFO_FILENAME);
    $repl_ext = strtolower(pathinfo($replacement_file->getFilename(), PATHINFO_EXTENSION));

    $custom_name = $has_rename ? trim((string) $entity->get(self::RENAME_FIELD)->value) : '';
    $keep_original_field = MediaFormHandler::KEEP_ORIGINAL_FIELD;

    // Default to TRUE (preserve SEO name) if the field is missing entirely.
    $force_original_name = TRUE;
    if ($entity->hasField($keep_original_field)) {
      $force_original_name = (bool) $entity->get($keep_original_field)->value;
    }

    // Determine the desired final filename.
    if (!empty($custom_name) && !$force_original_name) {
      $unsanitized_filename = $custom_name . '.' . $repl_ext;
    }
    elseif ($force_original_name) {
      $unsanitized_filename = $orig_base . '.' . $repl_ext;
    }
    else {
      // Automatic SEO cleanup: strip Drupal's _0 suffixes.
      $repl_base_clean = preg_replace('/_[0-9]+$/', '', $repl_base);
      if ($orig_ext === $repl_ext && $orig_base === $repl_base_clean) {
        $unsanitized_filename = $orig_base . '.' . $repl_ext;
      }
      else {
        $unsanitized_filename = $replacement_file->getFilename();
      }
    }

    $target_uri = $this->generateSanitizedTargetUri($unsanitized_filename, ltrim($repl_ext, '.'), dirname($original_uri_current));

    // Collision detection.
    $existing_files = $file_storage->loadByProperties(['uri' => $target_uri]);
    $is_collision = FALSE;
    foreach ($existing_files as $existing_file) {
      if ($existing_file->id() != $replacement_fid && $existing_file->id() != $original_file->id()) {
        $is_collision = TRUE;
        break;
      }
    }

    $move_mode = $is_collision ? FileExists::Rename : FileExists::Replace;

    // Anti-Hijack: Detach the old file from the target URI to prevent core FID hijacking.
    if (!$is_collision && $target_uri === $original_uri_current) {
      $this->transferEngine->antiHijack($original_file);
    }

    if ($replacement_uri !== $target_uri) {
      $this->transferEngine->ensureDirectory($target_uri);
      $replacement_file = $this->transferEngine->moveFile($replacement_file, $target_uri, $move_mode) ?: $replacement_file;

      // Safe Deletion: Only remove the original file AFTER the new one is successfully moved.
      if (!$is_collision && $target_uri !== $original_uri_current && file_exists($original_uri_current)) {
        $this->fileSystem->delete($original_uri_current);
      }

      $this->logger->notice('File replaced successfully for Media ID @id. The original file "@orig" was replaced with "@new" (FID: @fid).', [
        '@id' => (string) $entity->id(),
        '@orig' => (string) $original_file->getFilename(),
        '@new' => (string) $replacement_file->getFilename(),
        '@fid' => (string) $replacement_file->id(),
      ]);
    }

    // Metadata Sync: Overwrite the source field using the Strategy's merger.
    if (!$entity->get($source_field_name)->isEmpty()) {
      $source_item = $entity->get($source_field_name)->first();
      $original_data = $source_item ? $source_item->getValue() : [];
      $new_data = $strategy->mergeMetadata($original_data, $entity);
      $new_data['target_id'] = $replacement_file->id();

      $entity->set($source_field_name, $new_data);
    }

    // Component Cleanup & Pre-save strategy tasks.
    $strategy->preSaveProcess($entity);

    // Track original file for physical deletion during hook_update.
    $this->setRuntimeValue($entity, 'cleanup_fid', $original_file->id());
    $this->setRuntimeValue($entity, 'needs_purge', TRUE);

    // Reset workflow fields.
    $entity->set($staged_field, []);
    if ($entity->hasField(self::RENAME_FIELD)) {
      $entity->set(self::RENAME_FIELD, '');
    }
  }

  /**
   * Handles renaming the physical file without content replacement.
   */
  private function handleRenameOnly(MediaInterface $entity, string $source_field_name, MediaTypeStrategyInterface $strategy, FileInterface $original_file): void {
    $original_uri_current = $original_file->getFileUri();

    $custom_name = '';
    if ($entity->hasField(self::RENAME_FIELD)) {
      $custom_name = trim((string) $entity->get(self::RENAME_FIELD)->value);
    }

    if (empty($custom_name)) {
      return;
    }

    $ext = strtolower(pathinfo($original_file->getFilename(), PATHINFO_EXTENSION));
    $target_uri = $this->generateSanitizedTargetUri($custom_name . '.' . $ext, ltrim($ext, '.'), dirname($original_uri_current));

    if ($original_uri_current !== $target_uri) {
      $this->transferEngine->ensureDirectory($target_uri);
      $this->transferEngine->moveFile($original_file, $target_uri, FileExists::Replace);

      $this->logger->notice('File renamed successfully for Media ID @id. The file "@orig" was renamed to "@new".', [
        '@id' => (string) $entity->id(),
        '@orig' => (string) basename($original_uri_current),
        '@new' => (string) basename($target_uri),
      ]);

      // Metadata Sync.
      $source_item = $entity->get($source_field_name)->first();
      $original_data = $source_item ? $source_item->getValue() : [];
      $new_data = $strategy->mergeMetadata($original_data, $entity);
      $new_data['target_id'] = $original_file->id();

      $entity->set($source_field_name, $new_data);

      $this->setRuntimeValue($entity, 'needs_purge', TRUE);
      $strategy->preSaveProcess($entity);
    }

    if ($entity->hasField(self::RENAME_FIELD)) {
      $entity->set(self::RENAME_FIELD, '');
    }
  }

  /**
   * Enforces file visibility (Public vs Secure) based on moderation state.
   */
  protected function enforceVisibility(MediaInterface $entity, string $source_field_name, $original_fid, FileInterface $original_file, EntityState $state): void {
    $file_storage = $this->entityTypeManager->getStorage('file');
    $active_fid = $entity->get($source_field_name)->target_id;
    $active_file = ($active_fid == $original_fid) ? $original_file : $file_storage->load($active_fid);

    if (!$active_file) {
      return;
    }

    $active_uri = $active_file->getFileUri();

    // Ghost File check: Abort if physical disk is missing.
    if (!file_exists($active_uri)) {
      $this->logger->warning('Enforce Visibility aborted: The file @uri is missing from the physical disk.', ['@uri' => $active_uri]);
      return;
    }

    $is_currently_public = str_starts_with($active_uri, 'public://');
    $is_currently_secure = str_starts_with($active_uri, 'private://') || str_contains($active_uri, '/' . $this->getFallbackPath() . '/');

    $should_be_secure = !$state->isPublishedState;

    // SCENARIO: Moving to secure storage (Unpublished/Archived).
    if ($should_be_secure && $is_currently_public) {
      $relative = $this->getRelativePath($active_uri);
      $new_uri = $this->getSecureScheme() . $relative;
      $this->transferEngine->ensureDirectory($new_uri);
      $this->transferEngine->moveFile($active_file, $new_uri, FileExists::Replace);

      $this->logger->info('File visibility SECURED for media @id: moved to @uri', [
        '@id' => (string) $entity->id(),
        '@uri' => (string) $new_uri,
      ]);
    }
    // SCENARIO: Restoring to public storage (Published).
    elseif ($state->isPublishedState && $is_currently_secure) {
      $relative = $this->getRelativePath($active_uri);
      $clean_relative = $this->stripFallbackPrefix($relative);

      $new_uri = 'public://' . $clean_relative;
      $this->transferEngine->ensureDirectory($new_uri);
      $this->transferEngine->moveFile($active_file, $new_uri, FileExists::Replace);

      $this->logger->info('File visibility PUBLICIZED for media @id: moved to @uri', [
        '@id' => (string) $entity->id(),
        '@uri' => (string) $new_uri,
      ]);

      $this->setRuntimeValue($entity, 'needs_purge', TRUE);
    }
  }

  /**
   * Finalizes processing during hook_media_update.
   *
   * Handles physical cleanup of old records and CDN cache invalidation.
   */
  public function processUpdate(MediaInterface $entity): void {
    if (!$this->isManaged($entity)) {
      return;
    }

    // 1. Cleanup: Physically delete the original file entity that was replaced.
    $cleanup_fid = $this->getRuntimeValue($entity, 'cleanup_fid');
    if ($cleanup_fid) {
      $file = $this->entityTypeManager->getStorage('file')->load($cleanup_fid);
      if ($file) {
        $file->delete();
      }
    }

    // 2. Cache Invalidation: Trigger CDN purges and strategy post-processing.
    if ($this->getRuntimeValue($entity, 'needs_purge')) {
      $source_field_name = $this->discoverSourceField($entity);
      if ($source_field_name && !$entity->get($source_field_name)->isEmpty()) {
        /** @var \Drupal\file\FileInterface|null $file */
        $file = $this->entityTypeManager->getStorage('file')->load($entity->get($source_field_name)->target_id);
        if ($file) {
          $strategy = $this->strategyFactory->getStrategyForEntity($entity, $source_field_name);
          $strategy->postProcess($entity, $file);

          $this->logger->info('File updated and processed for media @id', ['@id' => (string) $entity->id()]);

          // Purge Module Integration.
          if (\Drupal::moduleHandler()->moduleExists('purge') && $this->purgeQueuers && $this->purgeQueue && $this->purgeInvalidations) {
            $file_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
            try {
              $invalidation = $this->purgeInvalidations->get('url', $file_url);
              $this->purgeQueue->add($this->purgeQueuers->get('core'), [$invalidation]);
              $this->logger->info('Queued Purge invalidation for URL: @url', ['@url' => $file_url]);
            }
            catch (\Exception $e) {
              $this->logger->error('Purge queue failed: @msg', ['@msg' => $e->getMessage()]);
            }
          }
        }
      }
    }
  }

}
