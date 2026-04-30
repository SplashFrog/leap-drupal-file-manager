<?php

declare(strict_types=1);

namespace Drupal\leap_file_manager\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Leap File Manager settings.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * Constructs a SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['leap_file_manager.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'leap_file_manager_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('leap_file_manager.settings');

    $form['use_private_filesystem'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use Private File System'),
      '#default_value' => $config->get('use_private_filesystem'),
      '#description' => $this->t('If enabled, secure files (staged drafts and archived media) will be stored in the <code>private://</code> directory. Ensure this is configured in <code>settings.php</code>.'),
    ];

    $form['public_fallback_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public Fallback Path'),
      '#default_value' => $config->get('public_fallback_path'),
      '#required' => TRUE,
      '#description' => $this->t('The directory name within <code>public://</code> to use if the private file system is disabled or unavailable. Do not use leading or trailing slashes.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('leap_file_manager.settings');
    $old_path = $config->get('public_fallback_path');
    $new_path = trim((string) $form_state->getValue('public_fallback_path'), '/ ');

    // Handle directory management (renaming or creating fallback folders).
    $this->manageSecureDirectory($old_path, $new_path);

    $config
      ->set('use_private_filesystem', $form_state->getValue('use_private_filesystem'))
      ->set('public_fallback_path', $new_path)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Manages the creation, renaming, and security of the fallback directory.
   *
   * @param string $old_path
   *   The previous fallback path.
   * @param string $new_path
   *   The new fallback path.
   */
  private function manageSecureDirectory(string $old_path, string $new_path): void {
    // Resolve the absolute physical path of the public directory.
    $public_base_real = $this->fileSystem->realpath('public://');
    if (!$public_base_real) {
      $this->messenger()->addError($this->t('Could not resolve the physical path for public://'));
      return;
    }

    $old_real = $public_base_real . '/' . $old_path;
    $new_real = $public_base_real . '/' . $new_path;

    // 1. If the path changed, attempt to rename the existing directory natively.
    if ($old_path !== $new_path && is_dir($old_real)) {
      if (@rename($old_real, $new_real)) {
        $this->messenger()->addStatus($this->t('Secure directory renamed from %old to %new.', [
          '%old' => $old_path,
          '%new' => $new_path,
        ]));
      }
      else {
        $this->messenger()->addError($this->t('Failed to rename secure directory natively. Check host permissions.'));
      }
    }

    // 2. Ensure the new directory exists using native PHP (avoiding stream wrapper overhead).
    if (!is_dir($new_real)) {
      if (!@mkdir($new_real, 0777, TRUE)) {
        $this->messenger()->addError($this->t('Failed to create the secure directory: %path. Check host permissions.', ['%path' => $new_real]));
        return;
      }
    }

    // 3. Manage the .htaccess file for security and SEO protection.
    // This prevents directory listing and search engine indexing of staged files.
    $htaccess_path = $new_real . '/.htaccess';
    $htaccess_content = "# Protect staged and archived media from SEO indexing.\n";
    $htaccess_content .= "<IfModule mod_headers.c>\n";
    $htaccess_content .= "  Header set X-Robots-Tag \"noindex, nofollow\"\n";
    $htaccess_content .= "</IfModule>\n\n";
    $htaccess_content .= "# Turn off all options we don't need.\n";
    $htaccess_content .= "Options -Indexes -ExecCGI -Includes -MultiViews\n\n";
    $htaccess_content .= "# Set the catch-all handler to prevent scripts from being executed.\n";
    $htaccess_content .= "SetHandler Drupal_Security_Do_Not_Remove_See_SA_2006_006\n";
    $htaccess_content .= "<Files *>\n";
    $htaccess_content .= "  # Override the handler again if we're run later in the evaluation list.\n";
    $htaccess_content .= "  SetHandler Drupal_Security_Do_Not_Remove_See_SA_2013_003\n";
    $htaccess_content .= "</Files>\n\n";
    $htaccess_content .= "# If we know how to do it safely, disable the PHP engine entirely.\n";
    $htaccess_content .= "<IfModule mod_php.c>\n";
    $htaccess_content .= "  php_flag engine off\n";
    $htaccess_content .= "</IfModule>\n";

    if (!file_exists($htaccess_path) || file_get_contents($htaccess_path) !== $htaccess_content) {
      if (@file_put_contents($htaccess_path, $htaccess_content) !== FALSE) {
        $this->messenger()->addStatus($this->t('Security .htaccess file updated.'));
      }
      else {
        $this->messenger()->addError($this->t('Failed to write .htaccess file to %path', ['%path' => $htaccess_path]));
      }
    }
  }

}
