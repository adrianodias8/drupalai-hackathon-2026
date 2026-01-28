(function (Drupal, once, drupalSettings) {
  Drupal.behaviors.ragMinimalUi = {
    attach: function (context) {
      once('rag-minimal-ui', '.rag-minimal-actions', context).forEach(function (container) {
        const approve = container.querySelector('.rag-minimal-approve');
        const reject = container.querySelector('.rag-minimal-reject');
        const form = container.closest('.rag-minimal-form');
        const preview = form ? form.querySelector('.rag-minimal-link-preview') : null;

        if (approve) {
          approve.addEventListener('click', function (e) {
            e.preventDefault();
            approve.classList.add('rag-minimal-approved');
            approve.classList.remove('rag-minimal-rejected');
            reject && reject.classList.remove('rag-minimal-rejected');

            const candidate = drupalSettings?.rag_minimal?.linkCandidate;
            if (!preview || !candidate || !candidate.url || !candidate.excerpt) {
              return;
            }

            const phrase = candidate.link_phrase || '';
            preview.innerHTML = '<strong>Excerpt preview:</strong><br>';

            if (phrase && candidate.excerpt.includes(phrase)) {
              const parts = candidate.excerpt.split(phrase);
              preview.appendChild(document.createTextNode(parts[0] || ''));
              const link = document.createElement('a');
              link.href = candidate.url;
              link.target = '_blank';
              link.rel = 'noopener noreferrer';
              link.textContent = phrase;
              preview.appendChild(link);
              preview.appendChild(document.createTextNode(parts.slice(1).join(phrase)));
            } else {
              const link = document.createElement('a');
              link.href = candidate.url;
              link.target = '_blank';
              link.rel = 'noopener noreferrer';
              link.textContent = candidate.excerpt;
              preview.appendChild(link);
            }

          });
        }

        if (reject) {
          reject.addEventListener('click', function (e) {
            reject.classList.add('rag-minimal-rejected');
            reject.classList.remove('rag-minimal-approved');
            approve && approve.classList.remove('rag-minimal-approved');
          });
        }
      });
    },
  };
})(Drupal, once, drupalSettings);
