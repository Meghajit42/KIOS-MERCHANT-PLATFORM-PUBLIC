document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  const submitter = event.submitter;
  const action = submitter?.name === 'action'
    ? submitter.value
    : form.querySelector('input[name="action"]')?.value;
  const prompts = {
    open: 'Open the shop for ordering within its configured hours?',
    close: 'Close the shop to new orders?',
    'end-day': 'End the business day and save the closing summary?',
    reset: 'Generate a new staff password and revoke existing sessions?',
    manage: 'Save this staff access and revoke existing sessions?',
    availability: 'Change this product’s availability?',
  };
  if (prompts[action] && !window.confirm(prompts[action])) event.preventDefault();
});
