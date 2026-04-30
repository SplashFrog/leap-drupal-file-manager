<?php

declare(strict_types=1);

namespace Drupal\leap_file_manager\Form;

use Drupal\Component\Utility\Bytes;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\Event\FileUploadSanitizeNameEvent;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\leap_file_manager\MediaFileManager;
use Drupal\media\MediaInterface;

/**
 * Handles UI alterations for Media forms to enforce secure file replacement.
 *
 * This class is responsible for "Surgical Lockdown" of standard media fields
 * and injecting the custom "Chameleon" replacement UI. It ensures that editors
 * cannot accidentally break SEO links by deleting physical files.
 */
final class MediaFormHandler {

  /**
   * The machine name of our internal tracker field.
   */
  public const string STAGED_FIELD = 'field_leap_staged_file';

  /**
   * The machine name of the SEO preservation toggle.
   */
  public const string KEEP_ORIGINAL_FIELD = 'field_leap_keep_original';

  /**
   * The internal form element key for cancelling a staged file.
   */
  public const string CANCEL_STAGED_FIELD = 'leap_cancel_staged';

  /**
   * The internal form element key for the pseudo-replacement upload field.
   */
  public const string PSEUDO_REPLACEMENT_FIELD = 'leap_pseudo_replacement';

  /**
   * The machine name of the rename field.
   */
  private const string RENAME_FIELD = 'field_leap_rename_file';

  /**
   * Constructs the MediaFormHandler.
   *
   * @param \Drupal\leap_file_manager\MediaFileManager $mediaFileManager
   *   The module orchestrator.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Retrieves module settings.
   */
  public function __construct(
    private readonly MediaFileManager $mediaFileManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Alters media add/edit forms.
   *
   * Injects the replacement UI and applies surgical lockdowns to primary fields.
   */
  public function alterForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\media\MediaInterface $media */
    $media = $form_state->getFormObject()->getEntity();

    // Opt-in Check: Ensure this media type is managed by our module.
    if (!$media instanceof MediaInterface || !$this->mediaFileManager->isManaged($media)) {
      return;
    }

    $source_field = $this->mediaFileManager->discoverSourceField($media);
    if (!$source_field) {
      return;
    }

    // SCENARIO: Initial Creation. Hide workflow fields until the entity is saved.
    if ($media->isNew()) {
      if (isset($form[self::RENAME_FIELD])) {
        $form[self::RENAME_FIELD]['#access'] = FALSE;
      }
      if (isset($form[self::KEEP_ORIGINAL_FIELD])) {
        $form[self::KEEP_ORIGINAL_FIELD]['#access'] = FALSE;
      }
      return;
    }

    // SCENARIO: Editing existing media.
    $source_item = !$media->get($source_field)->isEmpty() ? $media->get($source_field)->first() : NULL;
    if (!$source_item || !$source_item->entity) {
      return;
    }

    // SURGICAL LOCKDOWN: Prevent the user from deleting the original source file.
    // We attach a process callback to hide the remove button AFTER widget generation.
    if (isset($form[$source_field]['widget'][0])) {
      $form[$source_field]['widget'][0]['#process'][] = [self::class, 'applySurgicalLockdown'];
    }

    // --- THE CHAMELEON LOGIC ---
    // Extract settings from the core source field to replicate them in our UI.
    $field_definition = $media->getFieldDefinition($source_field);
    $settings = $field_definition->getSettings();
    $extensions = $settings['file_extensions'] ?? 'txt doc docx pdf';

    // Inject the visual container directly into the source field's render array.
    $form[$source_field]['leap_replacement_container'] = [
      '#type' => 'details',
      '#title' => t('Replace File'),
      '#open' => TRUE,
      '#weight' => 100,
      '#attributes' => ['class' => ['leap-file-manager-ui']],
    ];

    // --- UI RECOVERY FOR STAGED FILES ---
    // If a replacement is already pending, show a status message and cancellation toggle.
    if (!$media->get(self::STAGED_FIELD)->isEmpty()) {
      /** @var \Drupal\file\FileInterface $staged_file */
      $staged_file = $media->get(self::STAGED_FIELD)->entity;
      if ($staged_file) {
        $staged_uri = $staged_file->getFileUri();
        $is_missing = !file_exists($staged_uri);

        // Ghost File Warning: High-priority error if disk is out of sync with DB.
        if ($is_missing) {
          $form[$source_field]['leap_replacement_container']['staged_info'] = [
            '#markup' => '<div class="messages messages--error" style="margin-bottom: 1em;"><strong>' . t('Critical Error:') . '</strong> ' . t('The pending replacement file (@file) is missing from the server. This can happen during database syncs. You MUST cancel this replacement or upload a new file before publishing.', ['@file' => $staged_file->getFilename()]) . '</div>',
          ];
        }
        else {
          $form[$source_field]['leap_replacement_container']['staged_info'] = [
            '#markup' => '<div class="messages messages--warning" style="margin-bottom: 1em;"><strong>' . t('Pending Replacement:') . '</strong> ' . $staged_file->getFilename() . '<br><small>' . t('This file is staged and will overwrite the live file when this revision is Published.') . '</small></div>',
          ];
        }

        $form[$source_field]['leap_replacement_container'][self::CANCEL_STAGED_FIELD] = [
          '#type' => 'checkbox',
          '#title' => t('Cancel and remove this staged replacement file.'),
          '#description' => t('Checking this will delete the pending file upon saving.'),
        ];
      }
    }

    // The raw file upload input.
    $form[$source_field]['leap_replacement_container'][self::PSEUDO_REPLACEMENT_FIELD] = [
      '#type' => 'file',
      '#title' => t('Upload a new file'),
      '#description' => t('It will overwrite the live file upon publishing. Allowed types: %ext.', [
        '%ext' => $extensions,
      ]),
      '#attributes' => [
        'accept' => '.' . str_replace(' ', ',.', $extensions),
      ],
    ];

    // Move the actual DB fields into the visual container and apply States logic.
    if (isset($form[self::KEEP_ORIGINAL_FIELD])) {
      $form[$source_field]['leap_replacement_container'][self::KEEP_ORIGINAL_FIELD] = $form[self::KEEP_ORIGINAL_FIELD];
      unset($form[self::KEEP_ORIGINAL_FIELD]);

      $keep_original_input_name = self::KEEP_ORIGINAL_FIELD . '[value]';

      if (isset($form[self::RENAME_FIELD])) {
        $form[$source_field]['leap_replacement_container'][self::RENAME_FIELD] = $form[self::RENAME_FIELD];
        unset($form[self::RENAME_FIELD]);

        // Only show Rename field if "Keep Original" is unchecked.
        $form[$source_field]['leap_replacement_container'][self::RENAME_FIELD]['#states'] = [
          'visible' => [
            ':input[name="' . $keep_original_input_name . '"]' => ['checked' => FALSE],
          ],
        ];
      }
    }

    // Register validation and submission handlers.
    $form['#validate'][] = [self::class, 'validateReplacementFile'];
    $form['#validate'][] = [self::class, 'validateGhostFiles'];
    array_unshift($form['actions']['submit']['#submit'], [self::class, 'processReplacementFile']);
  }

  /**
   * Custom #process callback to surgically lock the source field widget.
   */
  public static function applySurgicalLockdown(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    // 1. Hide the Remove button (physical deletion is managed by the orchestrator).
    if (isset($element['remove_button'])) {
      $element['remove_button']['#access'] = FALSE;
    }

    // 2. Strip required validation from metadata fields (Alt/Title).
    // This allows the user to save the form even if the main source widget is "locked."
    if (isset($element['alt'])) {
      $element['alt']['#required'] = FALSE;
      $element['alt']['#element_validate'] = [];
    }
    if (isset($element['title'])) {
      $element['title']['#required'] = FALSE;
      $element['title']['#element_validate'] = [];
    }

    return $element;
  }

  /**
   * Validates the uploaded file against the source field's restrictions.
   */
  public static function validateReplacementFile(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\media\MediaInterface $media */
    $media = $form_state->getFormObject()->getEntity();
    $source_field = \Drupal::service('leap_file_manager.manager')->discoverSourceField($media);

    $all_files = \Drupal::request()->files->get('files', []);
    if (empty($all_files)) {
      return;
    }

    /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $upload */
    $upload = $all_files[self::PSEUDO_REPLACEMENT_FIELD] ?? NULL;

    if (!$upload || !$upload->isValid()) {
      return;
    }

    $settings = $media->getFieldDefinition($source_field)->getSettings();

    // Validate Extension.
    $allowed_extensions = explode(' ', $settings['file_extensions'] ?? '');
    $extension = strtolower($upload->getClientOriginalExtension());
    if (!in_array($extension, $allowed_extensions, TRUE)) {
      $form_state->setErrorByName(self::PSEUDO_REPLACEMENT_FIELD, t('Only files with the following extensions are allowed: %ext.', ['%ext' => $settings['file_extensions']]));
    }

    // Validate Size.
    $max_size = $settings['max_filesize'] ?? '';
    if (!empty($max_size)) {
      $bytes = Bytes::toNumber($max_size);
      if ($upload->getSize() > $bytes) {
        $form_state->setErrorByName(self::PSEUDO_REPLACEMENT_FIELD, t('The file is too large. Maximum size allowed is %size.', ['%size' => format_size($bytes)]));
      }
    }
  }

  /**
   * Blocks form submission if a ghost file is detected and ignored.
   */
  public static function validateGhostFiles(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\media\MediaInterface $media */
    $media = $form_state->getFormObject()->getEntity();

    if (!\Drupal::service('leap_file_manager.manager')->isManaged($media)) {
      return;
    }

    if ($media->get(self::STAGED_FIELD)->isEmpty()) {
      return;
    }

    $staged_file = $media->get(self::STAGED_FIELD)->entity;
    if ($staged_file && !file_exists($staged_file->getFileUri())) {
      // If the user explicitly cancelled it, or uploaded a new file, it's fine.
      $is_cancelled = (bool) $form_state->getValue(self::CANCEL_STAGED_FIELD);
      $all_files = \Drupal::request()->files->get('files', []);
      $has_new_upload = !empty($all_files[self::PSEUDO_REPLACEMENT_FIELD]) && $all_files[self::PSEUDO_REPLACEMENT_FIELD]->isValid();

      if (!$is_cancelled && !$has_new_upload) {
        $form_state->setErrorByName(self::STAGED_FIELD, t('The pending replacement file is missing from the server. You MUST check the "Cancel" box below it or upload a new file.'));
      }
    }
  }

  /**
   * Processes the valid upload and attaches it to the media entity before save.
   */
  public static function processReplacementFile(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\media\MediaInterface $media */
    $media = $form_state->getFormObject()->getEntity();

    // Check if the user opted to cancel an existing staged file explicitly.
    if ($form_state->getValue(self::CANCEL_STAGED_FIELD)) {
      if ($media->hasField(self::STAGED_FIELD) && !$media->get(self::STAGED_FIELD)->isEmpty()) {
        $staged_file = $media->get(self::STAGED_FIELD)->entity;
        if ($staged_file) {
          $staged_file->delete();
        }
        $media->set(self::STAGED_FIELD, []);
      }
    }

    $all_files = \Drupal::request()->files->get('files', []);
    if (empty($all_files)) {
      return;
    }

    $manager = \Drupal::service('leap_file_manager.manager');
    $source_field = $manager->discoverSourceField($media);

    /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $upload */
    $upload = $all_files[self::PSEUDO_REPLACEMENT_FIELD] ?? NULL;

    if (!$upload || !$upload->isValid()) {
      return;
    }

    // IMPLICIT CANCEL: If they uploaded a new file, delete any old pending staged file.
    if ($media->hasField(self::STAGED_FIELD) && !$media->get(self::STAGED_FIELD)->isEmpty()) {
      $staged_file = $media->get(self::STAGED_FIELD)->entity;
      if ($staged_file) {
        $staged_file->delete();
      }
      $media->set(self::STAGED_FIELD, []);
    }

    $manager = \Drupal::service('leap_file_manager.manager');

    // 1. Sanitize the filename exactly as Drupal Core does.
    $original_name = $upload->getClientOriginalName();
    $pathinfo = pathinfo($original_name);
    $extension = strtolower($pathinfo['extension'] ?? '');

    $event = new FileUploadSanitizeNameEvent($original_name, $extension);
    \Drupal::service('event_dispatcher')->dispatch($event);
    $sanitized_name = $event->getFilename();

    // 2. Determine target directory based on source field pattern.
    $source_field = $manager->discoverSourceField($media);
    $settings = $media->getFieldDefinition($source_field)->getSettings();
    $sub_directory = $settings['file_directory'] ?? '';

    if (!empty($sub_directory)) {
      $sub_directory = \Drupal::token()->replace($sub_directory) . '/';
    }

    $secure_base = $manager->getSecureScheme();
    $destination_dir = $secure_base . $sub_directory;
    $destination = $destination_dir . $sanitized_name;

    // Ensure the directory is created using our bulletproof helper.
    $manager->ensureDirectoryExists($destination);

    // Bypass Drupal's brittle stream wrapper copy which fails if parent dirs have strict permissions.
    $scheme = \Drupal::service('stream_wrapper_manager')->getScheme($destination);
    $real_destination = NULL;
    if ($scheme) {
      $base_real = \Drupal::service('file_system')->realpath($scheme . '://');
      if ($base_real) {
        $relative = (string) preg_replace('|^[a-z0-9-]+://|', '', $destination);
        $real_destination = $base_real . '/' . $relative;
      }
    }

    if ($real_destination && move_uploaded_file($upload->getRealPath(), $real_destination)) {
      @chmod($real_destination, 0666);
      $file = File::create([
        'uri' => $destination,
        'uid' => \Drupal::currentUser()->id(),
      // Temporary until promoted to live.
        'status' => 0,
      ]);
      $file->save();

      // Assign to the Staged Field to persist across revisions.
      if ($media->hasField(self::STAGED_FIELD)) {
        // Update the entity in memory for immediate hooks.
        $media->set(self::STAGED_FIELD, ['target_id' => $file->id()]);

        // CRITICAL: Inject into form_state values so it survives EntityForm::buildEntity().
        $form_state->setValue(self::STAGED_FIELD, [['target_id' => $file->id()]]);
      }
    }
    else {
      \Drupal::logger('leap_file_manager')->error('Failed to move uploaded file natively to: @dest', ['@dest' => $real_destination]);
    }
  }

}
