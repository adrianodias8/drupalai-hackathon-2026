<?php

namespace Drupal\ai_migration_word;

use Drupal\ai_migration\AiMigrator;

/**
 * Extended AI Migrator for Word documents.
 *
 * Overrides normalizeResponse to handle AI responses with markdown code blocks
 * and explanatory text before/after the JSON.
 */
class AiMigratorWord extends AiMigrator {

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
      $data = $this->applyTextFormatDefaults($data, 'content_format');

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
   * Applies default text formats to fields missing a format.
   *
   * This is a lightweight version of the logic used in the form submit handler.
   * It does not rely on field definitions – instead it looks for common text
   * field structures (arrays that contain a "value" key) and sets the
   * "format" to the provided default when it is missing or empty.
   *
   * @param array $data
   *   The entity data array as returned by ->toArray().
   * @param string $default_format
   *   The machine name of the default text format to use.
   *
   * @return array
   *   The processed data with formats ensured.
   */
  protected function applyTextFormatDefaults(array $data, string $default_format): array {
    foreach ($data as $field_name => $field_value) {
      // Multi-value text field: [ [ 'value' => '...', 'format' => '' ], ... ].
      if (is_array($field_value) && isset($field_value[0]) && is_array($field_value[0])) {
        foreach ($field_value as $delta => $value) {
          if (is_array($value) && array_key_exists('value', $value)) {
            if (!array_key_exists('format', $value) || empty($value['format'])) {
              $data[$field_name][$delta]['format'] = $default_format;
            }
          }
        }
      }
      // Single-value text field: [ 'value' => '...', 'format' => '' ].
      elseif (is_array($field_value) && array_key_exists('value', $field_value)) {
        if (!array_key_exists('format', $field_value) || empty($field_value['format'])) {
          $data[$field_name]['format'] = $default_format;
        }
      }
    }

    return $data;
  }

}

