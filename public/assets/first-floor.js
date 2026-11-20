(() => {
  'use strict';
  document.querySelectorAll('.ff-preparation').forEach(root => {
    root.querySelectorAll('[data-ff-filter]').forEach(button => button.addEventListener('click', () => {
      const selected = button.dataset.ffFilter;
      root.querySelectorAll('[data-ff-filter]').forEach(tab => tab.setAttribute('aria-pressed', String(tab === button)));
      let visible = 0;
      root.querySelectorAll('[data-ff-status]').forEach(card => {
        card.hidden = !(selected === 'all' || card.dataset.ffStatus === selected || (selected === 'overdue' && card.dataset.ffOverdue === '1'));
        if (!card.hidden) visible++;
      });
      root.querySelector('.ff-filter-empty').hidden = visible !== 0;
    }));
    root.querySelectorAll('.station-task-action').forEach(form => form.addEventListener('submit', event => {
      if (form.dataset.submitting === '1') { event.preventDefault(); return; }
      form.dataset.submitting = '1';
      form.setAttribute('aria-busy', 'true');
      form.querySelectorAll('button[type=submit]').forEach(button => { button.disabled = true; button.textContent = 'Saving…'; });
    }));
  });
})();
