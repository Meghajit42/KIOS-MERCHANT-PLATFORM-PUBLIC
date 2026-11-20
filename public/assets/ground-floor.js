(() => {
  'use strict';
  document.querySelectorAll('.gf-preparation').forEach(root => {
    root.querySelectorAll('[data-gf-filter]').forEach(button => button.addEventListener('click', () => {
      const selected = button.dataset.gfFilter;
      root.querySelectorAll('[data-gf-filter]').forEach(tab => tab.setAttribute('aria-pressed', String(tab === button)));
      let visible = 0;
      root.querySelectorAll('[data-gf-status]').forEach(card => {
        card.hidden = !(selected === 'all' || card.dataset.gfStatus === selected || (selected === 'overdue' && card.dataset.gfOverdue === '1'));
        if (!card.hidden) visible++;
      });
      root.querySelector('.gf-filter-empty').hidden = visible !== 0;
    }));
    root.querySelectorAll('.station-task-action').forEach(form => form.addEventListener('submit', event => {
      if (form.dataset.submitting === '1') { event.preventDefault(); return; }
      form.dataset.submitting = '1';
      form.setAttribute('aria-busy', 'true');
      form.querySelectorAll('button[type=submit]').forEach(button => { button.disabled = true; button.textContent = 'Saving…'; });
    }));
  });
})();
