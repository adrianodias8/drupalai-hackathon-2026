<?php

namespace Drupal\ai_migration_word;

use Drupal\ai_migration\AiMigrator;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Extended AI Migrator for Word documents.
 *
 * Overrides normalizeResponse to handle AI responses with markdown code blocks
 * and explanatory text before/after the JSON.
 */
class AiMigratorWord extends AiMigrator {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $aiProviderPluginManager,
    $aiMigrationCacheProvider,
    $loggerChannelFactory,
    $httpClientFactory,
    $serializer,
    $schemaFactory,
    EntityFieldManagerInterface $entityFieldManager,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct(
      $aiProviderPluginManager,
      $aiMigrationCacheProvider,
      $loggerChannelFactory,
      $httpClientFactory,
      $serializer,
      $schemaFactory
    );
    $this->entityFieldManager = $entityFieldManager;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeResponse(string $response, string $bundle): array {
    // Normalize the AI response to an entity array, then ensure text formats
    // are set so fields like field_content[0][format] are not empty.

    // Try to extract JSON from markdown code blocks first.
    // This handles responses like "Here is the JSON:\n```json\n{...}\n```"
    if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $response, $matches)) {
      $cleaned = trim($matches[1]);
    }
    else {
      // Fallback: try to find a JSON object directly in the response.
      // Look for content starting with { and ending with }.
      if (preg_match('/\{.*\}/s', $response, $matches)) {
        $cleaned = $matches[0];
      }
      else {
        // Last resort: clean up any remaining backticks.
        $cleaned = preg_replace('/```json|```|`/', '', $response);
        $cleaned = trim($cleaned);
      }
    }

    $decoded = json_decode($cleaned, TRUE);

    if ($decoded === NULL) {
      $this->logger->error('Failed to decode cleaned AI JSON response: @response', ['@response' => $response]);
      return [];
    }
    else {
      $this->logger->debug('Decoded AI JSON response: <pre>@decoded</pre>', ['@decoded' => print_r($decoded, TRUE)]);
    }

    // Denormalize using your custom denormalizer.
    // Then, before returning the array, enforce a default text format so that
    // text fields without a format (e.g. field_content[0][format]) fall back
    // to "content_format".
    try {
      $entity = $this->serializer->denormalize(
        $decoded['data'],
        'Drupal\node\Entity\Node',
        'ai_migration',
        ['resource_type' => 'node--' . $bundle]
      );

      // Convert to an array and make sure text formats are set.
      $data = $entity->toArray();
      $default_format = $this->getDefaultTextFormat();
      $data = $this->applyTextFormatDefaults($data, $bundle, $default_format);

      // Return the entity as an array ready for migration.
      return $data;
    }
    catch (\Exception $e) {
      $this->logger->error('Denormalization failed for bundle @bundle: @message', [
        '@bundle' => $bundle,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Applies default text formats to text fields missing a format.
   *
   * Only applies formats to fields that actually require them (text fields),
   * and specifically ensures field_content uses content_format.
   *
   * @param array $data
   *   The entity data array as returned by ->toArray().
   * @param string $bundle
   *   The bundle name to check field definitions.
   * @param string $default_format
   *   The machine name of the default text format to use.
   *
   * @return array
   *   The processed data with formats ensured.
   */
  protected function applyTextFormatDefaults(array $data, string $bundle, string $default_format): array {
    // Get field definitions for this bundle.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);

    // Text field types that require a format.
    $text_field_types = [
      'text_with_summary',
      'text_long',
      'text',
    ];

    foreach ($field_definitions as $field_name => $field_definition) {
      $field_type = $field_definition->getType();

      // Only process text fields that require a format.
      if (!in_array($field_type, $text_field_types)) {
        continue;
      }

      // Skip if field doesn't exist in data.
      if (!isset($data[$field_name])) {
        continue;
      }

      $field_value = $data[$field_name];

      // Handle multi-value text fields.
      if (is_array($field_value) && isset($field_value[0]) && is_array($field_value[0])) {
        foreach ($field_value as $delta => $value) {
          if (is_array($value) && array_key_exists('value', $value)) {
            
            // For field_content, always use content_format.
            // For other text fields, use default_format if format is missing or empty.
            // For now we force this here as a workaround to ensure the content_format is used.
            if ($field_name === 'field_content') {
              $data[$field_name][$delta]['format'] = $default_format;
            }
            elseif (!array_key_exists('format', $value) || empty($value['format'])) {
              $data[$field_name][$delta]['format'] = $default_format;
            }
          }
        }
      }
      // Handle single-value text fields.
      elseif (is_array($field_value) && array_key_exists('value', $field_value)) {
        // For field_content, always use content_format.
        // For other text fields, use default_format if format is missing or empty.
        if ($field_name === 'field_content') {
          $data[$field_name]['format'] = $default_format;
        }
        elseif (!array_key_exists('format', $field_value) || empty($field_value['format'])) {
          $data[$field_name]['format'] = $default_format;
        }
      }
    }

    return $data;
  }

  /**
   * Gets the default text format from available formats in the installation.
   *
   * @return string
   *   The machine name of the default text format.
   */
  protected function getDefaultTextFormat(): string {
    $format_storage = $this->entityTypeManager->getStorage('filter_format');
    
    // Get all enabled filter formats available in the installation.
    $formats = $format_storage->loadByProperties(['status' => TRUE]);
    
    if (!empty($formats)) {
      // Sort formats by weight (lower weight = higher priority).
      uasort($formats, function ($a, $b) {
        /** @var \Drupal\filter\FilterFormatInterface $a */
        /** @var \Drupal\filter\FilterFormatInterface $b */
        return $a->get('weight') <=> $b->get('weight');
      });
      
      // Return the first format (lowest weight).
      $format = reset($formats);
      return $format->id();
    }

    // Last resort fallback if no formats exist (shouldn't happen in normal Drupal).
    return 'plain_text';
  }

}

