<?php

namespace Drupal\ai_migration_word\Form;

use Drupal\ai_migration\AiMigrator;
use Drupal\ai_migration\Service\AiMigrationPromptManager;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use PhpOffice\PhpWord\IOFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for importing Word documents via AI.
 */
class WordMigrationForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The AI migrator service.
   *
   * @var \Drupal\ai_migration\AiMigrator
   */
  protected AiMigrator $aiMigrator;

  /**
   * The prompt manager service.
   *
   * @var \Drupal\ai_migration\Service\AiMigrationPromptManager
   */
  protected AiMigrationPromptManager $promptManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->fileSystem = $container->get('file_system');
    $instance->aiMigrator = $container->get('ai_migration.ai_migrator');
    $instance->promptManager = $container->get('ai_migration.prompt_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_migration_word_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['source_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Source'),
      '#options' => [
        'upload' => $this->t('Upload a new Word document'),
        'media' => $this->t('Select existing document from Media Library'),
      ],
      '#default_value' => 'upload',
      '#required' => TRUE,
    ];

    $form['upload_container'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="source_type"]' => ['value' => 'upload'],
        ],
      ],
    ];

    $form['upload_container']['word_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Word Document'),
      '#description' => $this->t('Upload a Word document (.doc, .docx) to import.'),
      '#upload_location' => 'public://word_imports',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'doc docx'],
        'FileSizeLimit' => ['fileLimit' => 25 * 1024 * 1024],
      ],
    ];

    $form['media_container'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="source_type"]' => ['value' => 'media'],
        ],
      ],
    ];

    $form['media_container']['media_id'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Select Document'),
      '#description' => $this->t('Start typing to search for existing document media.'),
      '#target_type' => 'media',
      '#selection_settings' => [
        'target_bundles' => ['document'],
      ],
    ];

    $form['target_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Import as Content Type'),
      '#description' => $this->t('Select the content type to create from this document.'),
      '#options' => $this->getContentTypeOptions(),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Document'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Get content type options.
   *
   * @return array
   *   Array of content type options.
   */
  protected function getContentTypeOptions(): array {
    $options = [];
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    foreach ($types as $type) {
      $options[$type->id()] = $type->label();
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $source_type = $form_state->getValue('source_type');

    if ($source_type === 'upload') {
      $file = $form_state->getValue('word_file');
      if (empty($file)) {
        $form_state->setErrorByName('word_file', $this->t('Please upload a Word document.'));
      }
    }
    elseif ($source_type === 'media') {
      $media_id = $form_state->getValue('media_id');
      if (empty($media_id)) {
        $form_state->setErrorByName('media_id', $this->t('Please select a document from the media library.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $source_type = $form_state->getValue('source_type');
    $target_bundle = $form_state->getValue('target_bundle');
    $file_path = NULL;

    // Get the file path based on source type.
    if ($source_type === 'upload') {
      $fid = $form_state->getValue('word_file')[0] ?? NULL;
      if ($fid) {
        $file = $this->entityTypeManager->getStorage('file')->load($fid);
        if ($file) {
          $file_path = $this->fileSystem->realpath($file->getFileUri());
        }
      }
    }
    else {
      $media_id = $form_state->getValue('media_id');
      if ($media_id) {
        $media = $this->entityTypeManager->getStorage('media')->load($media_id);
        if ($media && $media->hasField('field_media_document')) {
          $file_entity = $media->get('field_media_document')->entity;
          if ($file_entity) {
            $file_path = $this->fileSystem->realpath($file_entity->getFileUri());
          }
        }
      }
    }

    if (!$file_path || !file_exists($file_path)) {
      $this->messenger()->addError($this->t('Could not locate the Word document file.'));
      return;
    }

    // Extract content from Word document.
    try {
      $content = $this->extractWordContent($file_path);

      if (empty($content)) {
        $this->messenger()->addError($this->t('No content could be extracted from the Word document.'));
        return;
      }

      // Use AI to convert content to entity data.
      $result = $this->aiMigrator->convert(
        $this->promptManager,
        $file_path,
        $content,
        'node',
        $target_bundle,
        []
      );

      if ($result && !empty($result)) {
        // Create the node.
        $node = $this->entityTypeManager->getStorage('node')->create([
          'type' => $target_bundle,
          ...$result,
        ]);
        $node->save();

        $this->messenger()->addStatus($this->t('Successfully imported Word document as %title.', [
          '%title' => $node->label(),
        ]));

        $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      }
      else {
        $this->messenger()->addError($this->t('AI processing failed. Please check the logs for details.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error processing document: @message', [
        '@message' => $e->getMessage(),
      ]));
      \Drupal::logger('ai_migration_word')->error('Error importing Word document: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Extract text content from a Word document.
   *
   * @param string $filePath
   *   The path to the Word document.
   *
   * @return string
   *   The extracted text content.
   */
  protected function extractWordContent(string $filePath): string {
    $phpWord = IOFactory::load($filePath);
    $text = '';

    foreach ($phpWord->getSections() as $section) {
      foreach ($section->getElements() as $element) {
        $text .= $this->extractElementText($element) . "\n";
      }
    }

    return trim($text);
  }

  /**
   * Recursively extract text from Word document elements.
   *
   * @param mixed $element
   *   The PHPWord element.
   *
   * @return string
   *   The extracted text.
   */
  protected function extractElementText($element): string {
    $text = '';

    // Handle elements with getText method.
    if (method_exists($element, 'getText')) {
      $elementText = $element->getText();
      if (is_string($elementText)) {
        $text .= $elementText;
      }
      elseif (is_array($elementText)) {
        foreach ($elementText as $item) {
          if (is_string($item)) {
            $text .= $item;
          }
          elseif (is_object($item) && method_exists($item, 'getText')) {
            $text .= $item->getText();
          }
        }
      }
    }

    // Handle container elements with nested elements.
    if (method_exists($element, 'getElements')) {
      foreach ($element->getElements() as $childElement) {
        $text .= $this->extractElementText($childElement);
      }
    }

    // Handle table cells.
    if (method_exists($element, 'getRows')) {
      foreach ($element->getRows() as $row) {
        if (method_exists($row, 'getCells')) {
          foreach ($row->getCells() as $cell) {
            $text .= $this->extractElementText($cell) . "\t";
          }
        }
        $text .= "\n";
      }
    }

    return $text;
  }

  /**
   * Gets the default text format to use.
   *
   * @return string
   *   The machine name of the default text format.
   */
  protected function getDefaultTextFormat(): string {
    // Try common text formats in order of preference.
    $preferred_formats = [
      'content_format',
    ];

    $format_storage = $this->entityTypeManager->getStorage('filter_format');

    foreach ($preferred_formats as $format_id) {
      $format = $format_storage->load($format_id);
      if ($format && $format->status()) {
        return $format_id;
      }
    }

    // Fallback: get the first available enabled format.
    $formats = $format_storage->loadByProperties(['status' => TRUE]);
    if (!empty($formats)) {
      $format = reset($formats);
      return $format->id();
    }

    // Last resort fallback.
    return 'plain_text';
  }

}

