<?php

namespace Drupal\rag_minimal\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Utility\Unicode;
use Drupal\search_api\Entity\Index;

/**
 * RAG helper for Search API + LLM.
 */
final class RagMinimalRag {

  /**
   * Search API index ID.
   */
  private const INDEX_ID = 'create_references_to_articles';

  /**
   * Chat model to use.
   */
  private const MODEL_ID = 'claude-3-5-haiku';

  /**
   * AI provider plugin ID.
   */
  private const PROVIDER_ID = 'amazeeio';

  /**
   * Constructs a RagMinimalRag service.
   *
   * @param \Drupal\ai\AiProviderPluginManager $providerManager
   *   The AI provider plugin manager.
   */
  public function __construct(
    private readonly AiProviderPluginManager $providerManager,
  ) {}

  /**
   * Retrieves contexts from Search API.
   *
   * @param string $question
   *   User question.
   * @param int $limit
   *   Max number of results.
   *
   * @return array
   *   Context items with title/url/snippet.
   */
  public function retrieveContexts(string $question, int $limit = 3, string $exclude_url = ''): array {
    $index = Index::load(self::INDEX_ID);
    if (!$index) {
      return [
        'error' => 'Search API index not found: ' . self::INDEX_ID,
      ];
    }

    $query = $index->query();
    $query->keys($question);
    $query->range(0, $limit);

    $result_set = $query->execute();
    $contexts = [];

    foreach ($result_set->getResultItems() as $item) {
      $fields = $item->getFields();

      $title = $this->firstValue($fields, 'title');
      $url = $this->firstValue($fields, 'url');
      $content = $this->firstValue($fields, 'field_content');

      $snippet = $content !== ''
        ? Unicode::truncate($content, 300, TRUE, TRUE)
        : '';

      $contexts[] = [
        'title' => $title,
        'url' => $url,
        'snippet' => $snippet,
      ];
    }

    if ($exclude_url === '') {
      return $contexts;
    }

    $exclude_norm = $this->normalizeUrl($exclude_url);
    $filtered = [];
    foreach ($contexts as $context) {
      $context_norm = $this->normalizeUrl($context['url'] ?? '');
      if ($exclude_norm !== '' && $context_norm === $exclude_norm) {
        continue;
      }
      $filtered[] = $context;
    }

    return $filtered;
  }

  /**
   * Calls the LLM and returns a reason plus link candidate.
   *
   * @param string $question
   *   Input text.
   * @param array $contexts
   *   Context snippets.
   *
   * @return array
   *   Array with keys: raw, link_candidate.
   */
  public function answerWithLlm(string $question, array $contexts): array {
    if (!$contexts) {
      return [
        'raw' => 'No relevant context found.',
        'link_candidate' => [],
      ];
    }

    if (!empty($contexts['error'])) {
      return [
        'raw' => $contexts['error'],
        'link_candidate' => [],
      ];
    }

    $system_prompt = 'You are a helpful assistant. Use only the provided context. '
      . 'Return only one line in this exact format:' . "\n"
      . 'REASON: <short reason explaining the similarity>' . "\n"
      . 'Be specific: mention the referenced document by its title or URL and '
      . 'cite the key phrase(s) from the input text that match it. Avoid vague '
      . 'phrases like "input text" or "provided context". Use concrete terms, '
      . 'and add a brief extra sentence if needed for clarity. '
      . 'If any context is provided, you must select the single best match. '
      . 'Only say "No matches found." if there is zero usable context.';

    $context_lines = [];
    foreach ($contexts as $context) {
      $title = $context['title'] ?: 'Untitled';
      $url = $context['url'] ?: '';
      $snippet = $context['snippet'] ?: '';

      $line = '- ' . $title;
      if ($url !== '') {
        $line .= ' (' . $url . ')';
      }
      if ($snippet !== '') {
        $line .= ': ' . $snippet;
      }
      $context_lines[] = $line;
    }

    $user_prompt = "Input text:\n<<<\n{$question}\n>>>\n\nContext:\n" . implode("\n", $context_lines);

    try {
      $provider = $this->providerManager->createInstance(self::PROVIDER_ID);
      if (!$provider->isUsable('chat')) {
        return 'LLM provider is not configured for chat.';
      }

      $provider->setConfiguration([
        'temperature' => 0.2,
        'max_tokens' => 200,
      ]);

      $messages = [
        new ChatMessage('user', $user_prompt),
      ];
      $input = new ChatInput($messages);
      $input->setSystemPrompt($system_prompt);

      $output = $provider->chat($input, self::MODEL_ID, ['rag_minimal']);
      $normalized = $output->getNormalized();

      $raw = trim($normalized->getText());
      $candidate = $this->buildFallbackCandidate($question, $contexts);

      return [
        'raw' => $raw,
        'link_candidate' => $candidate,
      ];
    }
    catch (\Exception $e) {
      return [
        'raw' => 'LLM request failed: ' . $e->getMessage(),
        'link_candidate' => [],
      ];
    }
  }

  /**
   * Returns the first string value for a Search API field.
   *
   * @param array $fields
   *   Fields keyed by field ID.
   * @param string $field_id
   *   Field ID to read.
   */
  private function firstValue(array $fields, string $field_id): string {
    if (!isset($fields[$field_id])) {
      return '';
    }

    $values = $fields[$field_id]->getValues();
    if (!$values) {
      return '';
    }

    $value = (string) $values[0];
    return trim($value);
  }

  /**
   * Parses a structured LLM response into a link candidate.
   */
  private function parseLinkCandidate(string $text): array {
    if (stripos($text, 'no matches found') !== FALSE) {
      return [];
    }

    $lines = preg_split('/\r?\n/', trim($text));
    $data = [
      'url' => '',
      'excerpt' => '',
      'link_phrase' => '',
      'reason' => '',
    ];

    foreach ($lines as $line) {
      if (stripos($line, 'URL:') === 0) {
        $data['url'] = trim(substr($line, 4));
        continue;
      }
      if (stripos($line, 'EXCERPT:') === 0) {
        $data['excerpt'] = trim(substr($line, 8));
        continue;
      }
      if (stripos($line, 'LINK_PHRASE:') === 0) {
        $data['link_phrase'] = trim(substr($line, 12));
        continue;
      }
      if (stripos($line, 'REASON:') === 0) {
        $data['reason'] = trim(substr($line, 7));
        continue;
      }
    }

    if ($data['url'] === '' || $data['excerpt'] === '' || $data['link_phrase'] === '') {
      return [];
    }

    return $data;
  }

  /**
   * Builds a fallback candidate when the LLM returns no match.
   */
  private function buildFallbackCandidate(string $input, array $contexts): array {
    $first = $contexts[0] ?? [];
    $url = $first['url'] ?? '';
    if ($url === '') {
      return [];
    }

    $excerpt = Unicode::truncate(trim($input), 220, TRUE, TRUE);
    if ($excerpt === '') {
      return [];
    }

    $link_phrase = $this->extractLinkPhrase($excerpt);
    if ($link_phrase === '') {
      $link_phrase = $excerpt;
    }

    return [
      'url' => $url,
      'excerpt' => $excerpt,
      'link_phrase' => $link_phrase,
      'reason' => 'Best available match from search results.',
    ];
  }

  /**
   * Formats a candidate into the expected raw text format.
   */
  /**
   * Extracts a short phrase from the excerpt.
   */
  private function extractLinkPhrase(string $excerpt): string {
    $excerpt = trim($excerpt);
    if ($excerpt === '') {
      return '';
    }

    $sentence_end = preg_match('/^(.+?[.!?])\s/', $excerpt, $matches) ? $matches[1] : '';
    if ($sentence_end !== '') {
      return $sentence_end;
    }

    $words = preg_split('/\s+/', $excerpt);
    $words = array_slice($words, 0, 8);
    return trim(implode(' ', $words));
  }

  /**
   * Normalizes URLs for comparisons.
   */
  private function normalizeUrl(string $url): string {
    $url = trim($url);
    if ($url === '') {
      return '';
    }

    $parts = parse_url($url);
    if ($parts === FALSE) {
      return $url;
    }

    $path = $parts['path'] ?? '';
    $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
    if ($path === '') {
      return $url;
    }

    return $path . $query;
  }

}
