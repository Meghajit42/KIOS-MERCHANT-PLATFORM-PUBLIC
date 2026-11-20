document.addEventListener('DOMContentLoaded', () => {
  const button = document.querySelector('[data-print-manual-kot]');
  if (button) button.addEventListener('click', () => window.print());
});
