<?php

declare(strict_types=1);

namespace Drupal\Tests\leap_file_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\leap_file_manager\Form\MediaFormHandler;
use Drupal\leap_file_manager\MediaFileManager;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Tests that file moves never take over or overwrite another file.
 *
 * Every scenario runs against both transfer engines: the native engine
 * (private:// available) and the manual engine (public fallback directory).
 */
#[Group('leap_file_manager')]
#[RunTestsInSeparateProcesses]
final class MediaFileManagerTest extends KernelTestBase {

  use ContentModerationTestTrait;
  use MediaTypeCreationTrait;

  /**
   * File status for a temporary file (core only defines STATUS_PERMANENT).
   */
  private const int TEMPORARY = 0;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_moderation',
    'field',
    'file',
    'image',
    'leap_file_manager',
    'media',
    'system',
    'user',
    'workflows',
  ];

  /**
   * The source field of the test media type.
   */
  private string $sourceField;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', PrivateStream::class)
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['field', 'system', 'image', 'file', 'media', 'leap_file_manager']);

    $media_type = $this->createMediaType('file', ['id' => 'document']);
    $this->sourceField = $media_type->getSource()->getConfiguration()['source_field'];

    // The fields the module's recipe attaches to managed media types.
    $fields = [
      'field_leap_staged_file' => ['entity_reference', ['target_type' => 'file'], []],
      'field_leap_keep_original' => ['boolean', [], [['value' => 1]]],
      'field_leap_rename_file' => ['string', [], []],
    ];
    foreach ($fields as $name => [$type, $settings, $default]) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'media',
        'type' => $type,
        'settings' => $settings,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'media',
        'bundle' => 'document',
        'default_value' => $default,
      ])->save();
    }

    $workflow = $this->createEditorialWorkflow();
    $workflow->getTypePlugin()->addEntityTypeAndBundle('media', 'document');
    $workflow->save();
  }

  /**
   * Provides the two transfer engines.
   *
   * @return array<string, array{bool}>
   *   Data sets keyed by engine name; TRUE selects the native engine.
   */
  public static function engines(): array {
    return [
      'native engine' => [TRUE],
      'manual engine' => [FALSE],
    ];
  }

  /**
   * A draft moves to secure storage and back when published.
   */
  #[DataProvider('engines')]
  public function testDraftThenPublish(bool $native): void {
    $this->useEngine($native);
    $file = $this->createFile('public://documents/a0.pdf', 'A0');
    $media = $this->createMedia($file, 'draft');
    $this->assertSame($this->secure('documents/a0.pdf'), $this->fileRecord($file->id())->getFileUri());

    $this->saveMedia($media, ['moderation_state' => 'published']);
    $this->assertMediaFile($media, $file->id(), 'public://documents/a0.pdf', 'A0');
  }

  /**
   * A new draft never takes over a record already at its secure path.
   *
   * This is the bulk upload data loss: core's move() with Replace re-used the
   * old record and deleted the brand new file entity.
   */
  #[DataProvider('engines')]
  public function testNewDraftDoesNotTakeOverExistingRecord(bool $native): void {
    $this->useEngine($native);
    $old = $this->createFile($this->secure('documents/a1.pdf'), 'OLD', self::TEMPORARY);
    $file = $this->createFile('public://documents/a1.pdf', 'NEW');
    $media = $this->createMedia($file, 'draft');

    $this->assertMediaFile($media, $file->id(), $this->secure('documents/a1_0.pdf'), 'NEW');
    $this->assertFileRecord($old->id(), $this->secure('documents/a1.pdf'), 'OLD');
    $this->assertFalse($this->fileRecord($old->id())->isPermanent());
  }

  /**
   * A re-upload never takes over the file of an archived earlier attempt.
   */
  #[DataProvider('engines')]
  public function testReuploadWhileEarlierMediaArchived(bool $native): void {
    $this->useEngine($native);
    $earlier_file = $this->createFile('public://documents/a2.pdf', 'EARLIER');
    $earlier = $this->createMedia($earlier_file, 'published');
    $this->saveMedia($earlier, ['moderation_state' => 'archived']);

    $file = $this->createFile('public://documents/a2.pdf', 'NEW');
    $media = $this->createMedia($file, 'draft');
    $this->assertMediaFile($media, $file->id(), $this->secure('documents/a2_0.pdf'), 'NEW');
    $this->assertMediaFile($earlier, $earlier_file->id(), $this->secure('documents/a2.pdf'), 'EARLIER');

    $this->reload($earlier)->delete();
    $this->assertFileRecord($file->id(), $this->secure('documents/a2_0.pdf'), 'NEW');
  }

  /**
   * Publishing never takes over a stale record at the public path.
   */
  #[DataProvider('engines')]
  public function testPublishDoesNotTakeOverExistingRecord(bool $native): void {
    $this->useEngine($native);
    $file = $this->createFile('public://documents/b.pdf', 'NEW');
    $media = $this->createMedia($file, 'draft');
    $stale = $this->createFile('public://documents/b.pdf', NULL, self::TEMPORARY);

    $this->saveMedia($media, ['moderation_state' => 'published']);
    $this->assertMediaFile($media, $file->id(), 'public://documents/b_0.pdf', 'NEW');
    $this->assertSame('public://documents/b.pdf', $this->fileRecord($stale->id())->getFileUri());
  }

  /**
   * A staged replacement overwrites the live file in place, keeping its URL.
   */
  #[DataProvider('engines')]
  public function testInPlaceReplacementKeepsUrl(bool $native): void {
    $this->useEngine($native);
    $original = $this->createFile('public://documents/c.pdf', 'ORIGINAL');
    $media = $this->createMedia($original, 'published');
    $staged = $this->createFile($this->secure('documents/c.pdf'), 'REPLACEMENT', self::TEMPORARY);

    $this->saveMedia($media, [
      'field_leap_staged_file' => $staged->id(),
      'field_leap_keep_original' => TRUE,
      'moderation_state' => 'published',
    ]);
    $this->assertMediaFile($media, $staged->id(), 'public://documents/c.pdf', 'REPLACEMENT');
    $this->assertNull($this->fileRecord($original->id()));
  }

  /**
   * Renaming to a name another document uses never overwrites that document.
   */
  #[DataProvider('engines')]
  public function testRenameDoesNotTakeOverAnotherDocument(bool $native): void {
    $this->useEngine($native);
    $first_file = $this->createFile('public://documents/d1.pdf', 'D1');
    $first = $this->createMedia($first_file, 'published');
    $second_file = $this->createFile('public://documents/d2.pdf', 'D2');
    $second = $this->createMedia($second_file, 'published');

    $this->saveMedia($second, ['field_leap_rename_file' => 'd1', 'moderation_state' => 'published']);
    $this->assertMediaFile($first, $first_file->id(), 'public://documents/d1.pdf', 'D1');
    $this->assertMediaFile($second, $second_file->id(), 'public://documents/d1_0.pdf', 'D2');
  }

  /**
   * Renaming and archiving in one save both renames and secures the file.
   */
  #[DataProvider('engines')]
  public function testRenameAndArchiveSecuresFile(bool $native): void {
    $this->useEngine($native);
    $file = $this->createFile('public://documents/e.pdf', 'E');
    $media = $this->createMedia($file, 'published');

    $this->saveMedia($media, ['field_leap_rename_file' => 'e-renamed', 'moderation_state' => 'archived']);
    $this->assertMediaFile($media, $file->id(), $this->secure('documents/e-renamed.pdf'), 'E');
  }

  /**
   * Replacing and archiving in one save both replaces and secures the file.
   */
  #[DataProvider('engines')]
  public function testReplaceAndArchiveSecuresFile(bool $native): void {
    $this->useEngine($native);
    $original = $this->createFile('public://documents/f.pdf', 'ORIGINAL');
    $media = $this->createMedia($original, 'published');
    $staged = $this->createFile($this->secure('documents/f.pdf'), 'REPLACEMENT', self::TEMPORARY);

    $this->saveMedia($media, [
      'field_leap_staged_file' => $staged->id(),
      'field_leap_keep_original' => TRUE,
      'moderation_state' => 'archived',
    ]);
    $this->assertMediaFile($media, $staged->id(), $this->secure('documents/f.pdf'), 'REPLACEMENT');
  }

  /**
   * Re-saving a secured file in an unpublished state never moves it again.
   */
  #[DataProvider('engines')]
  public function testSecuredFileIsNotMovedAgain(bool $native): void {
    $this->useEngine($native);
    $file = $this->createFile('public://documents/g.pdf', 'G');
    $media = $this->createMedia($file, 'published');
    $this->saveMedia($media, ['moderation_state' => 'archived']);
    $this->assertMediaFile($media, $file->id(), $this->secure('documents/g.pdf'), 'G');

    $this->saveMedia($media, ['name' => 'G relabelled', 'moderation_state' => 'archived']);
    $this->assertMediaFile($media, $file->id(), $this->secure('documents/g.pdf'), 'G');
  }

  /**
   * A public folder that shares the fallback directory's name is still public.
   */
  #[DataProvider('engines')]
  public function testPublicFolderNamedLikeFallbackIsSecured(bool $native): void {
    $this->useEngine($native);
    $file = $this->createFile('public://documents/private-files/h.pdf', 'H');
    $media = $this->createMedia($file, 'published');

    $this->saveMedia($media, ['moderation_state' => 'archived']);
    $this->assertMediaFile($media, $file->id(), $this->secure('documents/private-files/h.pdf'), 'H');

    $this->saveMedia($media, ['moderation_state' => 'published']);
    $this->assertMediaFile($media, $file->id(), 'public://documents/private-files/h.pdf', 'H');
  }

  /**
   * Reverting across a replacement keeps the current file and warns.
   *
   * The pre-replacement revision references the file the replacement deleted.
   */
  #[DataProvider('engines')]
  public function testRevertAcrossReplacementKeepsCurrentFile(bool $native): void {
    $this->useEngine($native);
    $original = $this->createFile('public://documents/r.pdf', 'ORIGINAL');
    $media = $this->createMedia($original, 'published');
    $first_vid = $this->reload($media)->getRevisionId();
    $staged = $this->createFile($this->secure('documents/r.pdf'), 'REPLACEMENT', self::TEMPORARY);
    $this->saveMedia($media, [
      'field_leap_staged_file' => $staged->id(),
      'field_leap_keep_original' => TRUE,
      'moderation_state' => 'published',
    ]);
    $this->assertNull($this->fileRecord($original->id()));

    $this->revert($media, $first_vid);
    $this->assertMediaFile($media, $staged->id(), 'public://documents/r.pdf', 'REPLACEMENT');
    $this->assertStringContainsString('no longer exists', $this->warnings());
  }

  /**
   * Reverting to the draft that staged a replacement never deletes the file.
   *
   * That draft still references the replacement, which is now the live file;
   * publishing it must not "replace" the live file with itself.
   */
  #[DataProvider('engines')]
  public function testRevertToPendingReplacementKeepsCurrentFile(bool $native): void {
    $this->useEngine($native);
    $original = $this->createFile('public://documents/s.pdf', 'ORIGINAL');
    $media = $this->createMedia($original, 'published');
    $staged = $this->createFile($this->secure('documents/s.pdf'), 'REPLACEMENT', self::TEMPORARY);
    $this->saveMedia($media, [
      'field_leap_staged_file' => $staged->id(),
      'field_leap_keep_original' => TRUE,
      'moderation_state' => 'draft',
    ]);
    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    $pending_vid = $storage->getLatestRevisionId($media->id());
    $this->saveMedia($media, ['moderation_state' => 'published']);

    $this->revert($media, $pending_vid);
    $this->saveMedia($media, ['moderation_state' => 'published']);
    $this->assertMediaFile($media, $staged->id(), 'public://documents/s.pdf', 'REPLACEMENT');
  }

  /**
   * An oversized replacement upload fails validation instead of erroring.
   */
  public function testOversizedReplacementIsRejected(): void {
    $field = FieldConfig::loadByName('media', 'document', $this->sourceField);
    $field->setSetting('max_filesize', '1 KB')->save();
    $media = $this->createMedia($this->createFile('public://documents/i.pdf', 'I'), 'published');

    $path = $this->siteDirectory . '/big.pdf';
    file_put_contents($path, str_repeat('x', 2048));
    $upload = new UploadedFile($path, 'big.pdf', 'application/pdf', UPLOAD_ERR_OK, TRUE);
    $files = ['files' => [MediaFormHandler::PSEUDO_REPLACEMENT_FIELD => $upload]];
    $request_stack = $this->container->get('request_stack');
    // Duplicate the current request so the upload request keeps its session.
    $request_stack->push($request_stack->getCurrentRequest()->duplicate(NULL, NULL, NULL, NULL, $files));

    $form_object = $this->container->get('entity_type.manager')->getFormObject('media', 'edit');
    $form_object->setEntity($media);
    $form_state = (new FormState())->setFormObject($form_object);
    $form = [];
    MediaFormHandler::validateReplacementFile($form, $form_state);

    $errors = $form_state->getErrors();
    $this->assertArrayHasKey(MediaFormHandler::PSEUDO_REPLACEMENT_FIELD, $errors);
    $this->assertStringContainsString('1 KB', (string) $errors[MediaFormHandler::PSEUDO_REPLACEMENT_FIELD]);
  }

  /**
   * The free-name rule shared by the move guard and replacement staging.
   */
  public function testGetAvailableUri(): void {
    $manager = $this->manager();
    $this->assertSame('public://free.pdf', $manager->getAvailableUri('public://free.pdf'));

    // Taken on disk only, at the stream root.
    file_put_contents('public://disk.pdf', 'x');
    $this->assertSame('public://disk_0.pdf', $manager->getAvailableUri('public://disk.pdf'));

    // Taken in file_managed only, with the first suffix taken on disk.
    $this->createFile('public://documents/row.pdf', NULL);
    file_put_contents('public://documents/row_0.pdf', 'x');
    $this->assertSame('public://documents/row_1.pdf', $manager->getAvailableUri('public://documents/row.pdf'));

    // Names without an extension.
    $this->createFile('public://documents/README', 'x');
    $this->assertSame('public://documents/README_0', $manager->getAvailableUri('public://documents/README'));
  }

  /**
   * Selects the transfer engine; it is fixed when the manager is built.
   */
  private function useEngine(bool $native): void {
    $this->config('leap_file_manager.settings')->set('use_private_filesystem', $native)->save();
  }

  /**
   * Returns the file manager service.
   */
  private function manager(): MediaFileManager {
    return $this->container->get('leap_file_manager.manager');
  }

  /**
   * Returns a URI in the active engine's secure storage.
   */
  private function secure(string $path): string {
    return $this->manager()->getSecureScheme() . $path;
  }

  /**
   * Creates a file entity, writing $content to disk unless it is NULL.
   */
  private function createFile(string $uri, ?string $content, int $status = FileInterface::STATUS_PERMANENT): FileInterface {
    $file_system = $this->container->get('file_system');
    $directory = $file_system->dirname($uri);
    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    if ($content !== NULL) {
      file_put_contents($uri, $content);
    }
    $file = File::create(['uri' => $uri, 'status' => $status]);
    $file->save();
    return $file;
  }

  /**
   * Creates a document media item in the given moderation state.
   */
  private function createMedia(FileInterface $file, string $state): MediaInterface {
    $media = Media::create([
      'bundle' => 'document',
      'name' => $file->getFilename(),
      $this->sourceField => ['target_id' => $file->id()],
      'moderation_state' => $state,
    ]);
    $media->save();
    return $media;
  }

  /**
   * Sets values on the latest revision of a media item and saves it.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media item.
   * @param array<string, mixed> $values
   *   Field values keyed by field name.
   */
  private function saveMedia(MediaInterface $media, array $values): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    $latest = $storage->loadRevision($storage->getLatestRevisionId($media->id()));
    foreach ($values as $field => $value) {
      $latest->set($field, $value);
    }
    $latest->save();
  }

  /**
   * Reverts a media item to a revision, as core's revision revert form does.
   */
  private function revert(MediaInterface $media, int|string $vid): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    $storage->createRevision($storage->loadRevision($vid))->save();
  }

  /**
   * Returns all warning messages shown to the user, as one string.
   */
  private function warnings(): string {
    return implode(' ', array_map('strval', $this->container->get('messenger')->messagesByType('warning')));
  }

  /**
   * Reloads a media item from storage.
   */
  private function reload(MediaInterface $media): MediaInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    $storage->resetCache([$media->id()]);
    return $storage->load($media->id());
  }

  /**
   * Loads a file entity from storage, bypassing the static cache.
   */
  private function fileRecord(int|string $fid): ?FileInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('file');
    $storage->resetCache([$fid]);
    return $storage->load($fid);
  }

  /**
   * Asserts a file record's URI and the content on disk at that URI.
   */
  private function assertFileRecord(int|string $fid, string $uri, string $content): void {
    $record = $this->fileRecord($fid);
    $this->assertNotNull($record, "File $fid still exists.");
    $this->assertSame($uri, $record->getFileUri());
    $this->assertSame($content, file_get_contents($uri));
  }

  /**
   * Asserts a media item references $fid, stored at $uri with $content.
   */
  private function assertMediaFile(MediaInterface $media, int|string $fid, string $uri, string $content): void {
    $this->assertEquals($fid, $this->reload($media)->get($this->sourceField)->target_id);
    $this->assertFileRecord($fid, $uri, $content);
  }

}
