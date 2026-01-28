<?php

namespace Drupal\ai_migration_word\Plugin\migrate_plus\data_parser;

use Drupal\ai_migration\AiMigrator;
use Drupal\ai_migration\Service\AiMigrationPromptManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate_plus\DataFetcherPluginManager;
use Drupal\migrate_plus\DataParserPluginBase;
use PhpOffice\PhpWord\IOFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Obtain Word document content for AI migration.
 *
 * @DataParser(
 *   id = "ai_word",
 *   title = @Translation("AI Word")
 * )
 */
class AiWord extends DataParserPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The currently saved source url (file path).
   *
   * @var string
   */
  protected $currentUrl;

  /**
   * The active url's source data (extracted text content).
   *
   * @var string
   */
  protected $sourceData;

  /**
   * The fetched items thus far.
   *
   * @var array
   */
  protected $fetchedItems = [];

  /**
   * The results of parsing the Word content with AI.
   *
   * @var array
   */
  protected $aiResults;

  /**
   * The AI migrator service.
   *
   * @var \Drupal\ai_migration\AiMigrator
   */
  protected $aiMigrator;

  /**
   * The prompt manager.
   *
   * @var \Drupal\ai_migration\Service\AiMigrationPromptManager
   */
  protected $promptManager;

  /**
   * Creates a new AiWord instance.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param string $plugin_definition
   *   The plugin definition.
   * @param \Drupal\migrate_plus\DataFetcherPluginManager $fetcherPluginManager
   *   The data fetcher plugin manager.
   * @param \Drupal\ai_migration\AiMigrator $aiMigrator
   *   The AI migrator service.
   * @param \Drupal\ai_migration\Service\AiMigrationPromptManager $promptManager
   *   The prompt manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DataFetcherPluginManager $fetcherPluginManager,
    AiMigrator $aiMigrator,
    AiMigrationPromptManager $promptManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $fetcherPluginManager);
    $this->urls = $configuration['urls'];
    $this->itemSelector = $configuration['item_selector'] ?? '';
    $this->aiMigrator = $aiMigrator;
    $this->promptManager = $promptManager;

    // Load prompt configuration if provided.
    $prompt_config = $configuration[$plugin_id][AiMigrationPromptManager::MIGRATION_ROOT_KEY] ?? NULL;
    if ($prompt_config) {
      $this->promptManager->setConfig($prompt_config);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.migrate_plus.data_fetcher'),
      $container->get('ai_migration.ai_migrator'),
      $container->get('ai_migration.prompt_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function openSourceUrl(string $url): bool {
    // Use cached source data if this is the first request or URL is the same.
    if ($this->currentUrl != $url || !$this->sourceData) {
      // Extract text from Word document.
      $this->sourceData = $this->extractWordContent($url);
      $this->currentUrl = $url;

      if (empty($this->sourceData)) {
        \Drupal::logger('ai_migration_word')->warning(
          'No content extracted from Word document: @file',
          ['@file' => $url]
        );
        return FALSE;
      }

      // Get entity type and bundle from configuration.
      [$entity_type, $bundle] = explode(':', $this->configuration['types'][0]);

      // Convert the Word content using AI.
      $this->aiResults = $this->aiMigrator->convert(
        $this->promptManager,
        $this->currentUrl,
        $this->sourceData,
        $entity_type,
        $bundle,
        $this->configuration['ai_word']['model'] ?? []
      );
    }

    return !is_null($this->sourceData) && !empty($this->aiResults);
  }

  /**
   * {@inheritdoc}
   */
  protected function fetchNextRow(): void {
    if (empty($this->fetchedItems[$this->currentUrl])) {
      $this->currentItem = [
        'url' => $this->currentUrl,
        ...$this->aiResults,
      ];

      if (!empty($this->itemSelector)) {
        $this->currentItem = [
          'url' => $this->currentUrl,
          ...$this->aiResults[$this->itemSelector],
        ];
      }

      $this->fetchedItems[$this->currentUrl] = $this->currentItem;
    }
  }

  /**
   * Extracts text content from a Word document.
   *
   * @param string $filePath
   *   The path to the Word document.
   *
   * @return string
   *   The extracted text content.
   */
  protected function extractWordContent(string $filePath): string {
    try {
      // Resolve the file path if it's a Drupal stream wrapper.
      $realPath = \Drupal::service('file_system')->realpath($filePath);
      if ($realPath === FALSE) {
        // If not a stream wrapper, use the path as-is.
        $realPath = $filePath;
      }

      if (!file_exists($realPath)) {
        \Drupal::logger('ai_migration_word')->error(
          'Word document not found: @file',
          ['@file' => $filePath]
        );
        return '';
      }

      $phpWord = IOFactory::load($realPath);
      $text = '';

      foreach ($phpWord->getSections() as $section) {
        foreach ($section->getElements() as $element) {
          $text .= $this->extractElementText($element) . "\n";
        }
      }

      \Drupal::logger('ai_migration_word')->debug(
        'Extracted @chars characters from Word document: @file',
        ['@chars' => strlen($text), '@file' => $filePath]
      );

      return trim($text);
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_migration_word')->error(
        'Failed to extract content from Word document @file: @message',
        ['@file' => $filePath, '@message' => $e->getMessage()]
      );
      return '';
    }
  }

  /**
   * Recursively extracts text from Word document elements.
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

}

