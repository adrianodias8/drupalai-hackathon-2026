<?php

namespace Drupal\rag_minimal\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rag_minimal\Service\RagMinimalRag;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Simple RAG form.
 */
final class RagMinimalForm extends FormBase implements ContainerInjectionInterface {
  use DependencySerializationTrait;

  /**
   * Constructs a RagMinimalForm.
   *
   * @param \Drupal\rag_minimal\Service\RagMinimalRag $rag
   *   The RAG helper service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private ?RagMinimalRag $rag = NULL,
    private ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('rag_minimal.rag'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'rag_minimal_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = \Drupal::requestStack()->getCurrentRequest();
    if ($request && $request->query->get('reset')) {
      $form_state->set('result', NULL);
    }

    $form['#prefix'] = '<div class="rag-minimal-form" style="max-width: 900px;">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'rag_minimal/rag_minimal_ui';

    $options = $this->getContentOptions();

    $form['content_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Choose the post to find the reference for'),
      '#required' => TRUE,
      '#options' => $options,
      '#empty_option' => $this->t('- Select content -'),
      '#default_value' => $form_state->getValue('content_id') ?? '',
      '#attributes' => [
        'style' => 'width: 100%; margin-bottom: 1rem;',
      ],
    ];

    if (empty($options)) {
      $form['no_content'] = [
        '#markup' => $this->t('No indexed content available to analyze.'),
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => [
        'style' => 'margin-bottom: 1.5rem;',
      ],
      '#access' => empty($form_state->get('result')) && !empty($options),
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Analyse'),
    ];

    $result = $form_state->get('result');
    if (!empty($result)) {
      $form['result'] = [
        '#type' => 'details',
        '#title' => $this->t('RAG Result'),
        '#open' => TRUE,
        '#attributes' => [
          'style' => 'margin-top: 1.5rem;',
        ],
      ];

      $form['result']['llm_answer'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Reason'),
        '#default_value' => $result['llm_answer_raw'] ?? '',
        '#rows' => 6,
        '#disabled' => TRUE,
      ];

      $reference_url = '';
      if (!empty($result['contexts']) && empty($result['contexts']['error'])) {
        $items = [];
        foreach ($result['contexts'] as $context) {
          $url = $context['url'] ?: '';
          if ($url !== '') {
            $items[] = $url;
            if ($reference_url === '') {
              $reference_url = $url;
            }
          }
        }

        $form['result']['titles'] = [
          '#theme' => 'item_list',
          '#title' => $this->t('References'),
          '#items' => $items,
        ];
      }
      elseif (!empty($result['contexts']['error'])) {
        $form['result']['error'] = [
          '#markup' => $this->t('Error: @message', ['@message' => $result['contexts']['error']]),
        ];
      }

      $candidate = $result['link_candidate'] ?? [];
      if (!empty($candidate)) {
        $url = (string) ($candidate['url'] ?? '');
        if ($reference_url !== '') {
          $url = $reference_url;
        }
        if ($url !== '' && !UrlHelper::isValid($url, TRUE) && !str_starts_with($url, '/')) {
          $url = '';
        }
        $excerpt_raw = (string) ($candidate['excerpt'] ?? '');
        $excerpt_raw = preg_replace('/^\\s*<p\\b[^>]*>/i', '', $excerpt_raw);
        $safe_candidate = [
          'url' => $url,
          'excerpt' => Html::escape(strip_tags($excerpt_raw)),
          'link_phrase' => Html::escape($candidate['link_phrase'] ?? ''),
          'reason' => Html::escape($candidate['reason'] ?? ''),
        ];
        $form['#attached']['drupalSettings']['rag_minimal']['linkCandidate'] = $safe_candidate;
        $form['result']['link_preview'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['rag-minimal-link-preview'],
            'style' => 'margin-top: 0.75rem;',
          ],
          'content' => [
            '#markup' => '<strong>' . $this->t('Excerpt preview') . ':</strong><br>' . $safe_candidate['excerpt'],
          ],
        ];
        $form['result']['link_status'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['rag-minimal-link-status'],
            'style' => 'margin-top: 0.5rem;',
          ],
        ];
        $link_status = $form_state->get('link_status');
        if (!empty($link_status['message'])) {
          $form['result']['link_status']['message'] = [
            '#markup' => Html::escape($link_status['message']),
            '#wrapper_attributes' => [
              'class' => [$link_status['class'] ?? ''],
            ],
          ];
        }
      }

      $no_relevant = empty($result['contexts']) || !empty($result['contexts']['error']);
      $llm_raw = $result['llm_answer_raw'] ?? '';
      if (stripos($llm_raw, 'no matches found') !== FALSE) {
        $no_relevant = TRUE;
      }

      if ($no_relevant) {
        $form['result']['next_actions'] = [
          '#type' => 'actions',
          '#attributes' => [
            'style' => 'margin-top: 1rem;',
          ],
        ];
        $form['result']['next_actions']['next'] = [
          '#type' => 'submit',
          '#value' => $this->t('Analyse next text'),
          '#submit' => ['::submitNext'],
          '#limit_validation_errors' => [],
        ];
      }

      if (!$no_relevant) {
        $form['result']['actions'] = [
          '#type' => 'actions',
          '#attributes' => [
            'style' => 'margin-top: 1rem;',
          ],
          '#wrapper_attributes' => [
            'class' => ['rag-minimal-actions'],
          ],
        ];
        $form['result']['actions']['approve'] = [
          '#type' => 'submit',
          '#value' => $this->t('Approve'),
          '#attributes' => [
            'class' => ['rag-minimal-approve'],
          ],
          '#submit' => ['::submitApprove'],
          '#limit_validation_errors' => [],
        ];
        $form['result']['actions']['reject'] = [
          '#type' => 'submit',
          '#value' => $this->t('Reject'),
          '#attributes' => [
            'class' => ['rag-minimal-reject'],
          ],
          '#submit' => ['::submitNext'],
          '#limit_validation_errors' => [],
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $content_id = (string) $form_state->getValue('content_id');
    if ($content_id === '') {
      $this->messenger()->addError($this->t('Please select content to analyze.'));
      return;
    }

    $content = $this->loadContentText((int) $content_id);
    if ($content === '') {
      $this->messenger()->addError($this->t('Selected content has no text to analyze.'));
      return;
    }

    $exclude_url = $this->loadContentUrl((int) $content_id);
    $contexts = $this->rag()->retrieveContexts($content, 3, $exclude_url);
    $llm_answer = $this->rag()->answerWithLlm($content, $contexts);
    $raw = (string) ($llm_answer['raw'] ?? '');
    if (stripos($raw, 'REASON:') === 0) {
      $raw = trim(substr($raw, 7));
    }
    $reference_url = '';
    if (!empty($contexts) && empty($contexts['error'])) {
      foreach ($contexts as $context) {
        if (!empty($context['url'])) {
          $reference_url = $context['url'];
          break;
        }
      }
    }

    $form_state->set('result', [
      'question' => $content,
      'contexts' => $contexts,
      'llm_answer_raw' => $raw,
      'link_candidate' => $llm_answer['link_candidate'] ?? [],
      'reference_url' => $reference_url,
      'content_id' => (int) $content_id,
    ]);

    $form_state->setRebuild();
  }

  /**
   * Clears the current result to allow selecting new content.
   */
  public function submitNext(array &$form, FormStateInterface $form_state): void {
    $form_state->set('result', NULL);
    $form_state->setValue('content_id', '');
    $form_state->setUserInput(array_replace($form_state->getUserInput(), [
      'content_id' => '',
    ]));
    $form_state->set('link_status', NULL);
    $form_state->setRebuild();
  }

  /**
   * Adds the approved link to the selected content.
   */
  public function submitApprove(array &$form, FormStateInterface $form_state): void {
    $result = $form_state->get('result') ?? [];
    $content_id = (int) ($result['content_id'] ?? $form_state->getValue('content_id'));
    $reference_url = (string) ($result['reference_url'] ?? '');
    $candidate = $result['link_candidate'] ?? [];
    $link_phrase = (string) ($candidate['link_phrase'] ?? '');
    $excerpt = (string) ($candidate['excerpt'] ?? '');

    if ($content_id <= 0 || $reference_url === '') {
      $form_state->set('link_status', [
        'message' => (string) $this->t('Link could not be added. Missing reference URL.'),
        'class' => 'rag-minimal-link-fail',
      ]);
      $form_state->setRebuild();
      return;
    }

    $node = $this->entityTypeManager()->getStorage('node')->load($content_id);
    if (!$node || !$node->hasField('field_content') || $node->get('field_content')->isEmpty()) {
      $form_state->set('link_status', [
        'message' => (string) $this->t('Link could not be added. Content not found.'),
        'class' => 'rag-minimal-link-fail',
      ]);
      $form_state->setRebuild();
      return;
    }

    $value = (string) $node->get('field_content')->value;
    $needle = $link_phrase !== '' ? $link_phrase : $excerpt;
    if ($needle === '' || $value === '') {
      $form_state->set('link_status', [
        'message' => (string) $this->t('Link could not be added. No matching text found.'),
        'class' => 'rag-minimal-link-fail',
      ]);
      $form_state->setRebuild();
      return;
    }

    $replacement = '<a href="' . Html::escape($reference_url) . '" target="_blank" rel="noopener noreferrer">' . Html::escape($needle) . '</a>';
    $count = 0;
    $new_value = preg_replace('/' . preg_quote($needle, '/') . '/', $replacement, $value, 1, $count);
    if ($count === 0) {
      $form_state->set('link_status', [
        'message' => (string) $this->t('Link could not be added. Phrase not found in content.'),
        'class' => 'rag-minimal-link-fail',
      ]);
      $form_state->setRebuild();
      return;
    }

    $node->set('field_content', [
      'value' => $new_value,
      'format' => $node->get('field_content')->format ?? NULL,
    ]);
    $node->save();

    $form_state->set('link_status', [
      'message' => (string) $this->t('Link added successfully.'),
      'class' => 'rag-minimal-link-success',
    ]);
    $form_state->setRebuild();
  }

  /**
   * Builds select options for indexed content.
   */
  private function getContentOptions(): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_content', NULL, 'IS NOT NULL')
      ->range(0, 100)
      ->sort('title', 'ASC');

    $nids = $query->execute();
    if (empty($nids)) {
      return [];
    }

    $nodes = $storage->loadMultiple($nids);
    $options = [];
    foreach ($nodes as $node) {
      $options[(string) $node->id()] = $node->label();
    }

    return $options;
  }

  /**
   * Loads the text to analyze from the selected content item.
   */
  private function loadContentText(int $node_id): string {
    $node = $this->entityTypeManager()->getStorage('node')->load($node_id);
    if (!$node || !$node->hasField('field_content') || $node->get('field_content')->isEmpty()) {
      return '';
    }

    return trim((string) $node->get('field_content')->value);
  }

  /**
   * Loads the URL for the selected content item.
   */
  private function loadContentUrl(int $node_id): string {
    $node = $this->entityTypeManager()->getStorage('node')->load($node_id);
    if (!$node || !($node instanceof NodeInterface)) {
      return '';
    }

    return $node->toUrl()->toString();
  }

  /**
   * Lazy-loads the RAG service for serialized form rebuilds.
   */
  private function rag(): RagMinimalRag {
    if ($this->rag === NULL) {
      $this->rag = \Drupal::service('rag_minimal.rag');
    }
    return $this->rag;
  }

  /**
   * Lazy-loads the entity type manager for serialized form rebuilds.
   */
  private function entityTypeManager(): EntityTypeManagerInterface {
    if ($this->entityTypeManager === NULL) {
      $this->entityTypeManager = \Drupal::service('entity_type.manager');
    }
    return $this->entityTypeManager;
  }

}
