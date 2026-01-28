<?php

namespace Drupal\rag_minimal\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\rag_minimal\Service\RagMinimalRag;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns a simple RAG-style response from Search API results.
 */
final class RagMinimalController implements ContainerInjectionInterface {

  /**
   * Constructs a RagMinimalController.
   *
   * @param \Drupal\rag_minimal\Service\RagMinimalRag $rag
   *   The RAG helper service.
   */
  public function __construct(
    private readonly RagMinimalRag $rag,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('rag_minimal.rag'),
    );
  }

  /**
   * Answers a question using Search API results as context.
   */
  public function answer(Request $request): JsonResponse {
    $question = trim((string) $request->query->get('q', ''));
    if ($question == '') {
      return new JsonResponse([
        'error' => 'Missing required query parameter: q',
      ], 400);
    }

    $contexts = $this->rag->retrieveContexts($question, 3);
    $answer = $this->buildAnswer($question, $contexts);
    $llm_answer = $this->rag->answerWithLlm($question, $contexts);

    return new JsonResponse([
      'question' => $question,
      'answer' => $answer,
      'llm_answer' => $llm_answer,
      'contexts' => $contexts,
    ]);
  }

  /**
   * Builds a simple answer from contexts.
   *
   * @param string $question
   *   User question.
   * @param array $contexts
   *   Context snippets.
   */
  private function buildAnswer(string $question, array $contexts): string {
    if (!$contexts || !empty($contexts['error'])) {
      return $contexts['error'] ?? 'No relevant context found.';
    }

    $lines = [
      'Question: ' . $question,
      'Context:',
    ];

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

      $lines[] = $line;
    }

    return implode("\n", $lines);
  }

}
