document.querySelectorAll('form[data-confirm-once]').forEach((form) => {
    let submitted = false;

    form.addEventListener('submit', (event) => {
        if (submitted) {
            event.preventDefault();
            return;
        }

        submitted = true;
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"], button:not([type])').forEach((button) => {
            button.disabled = true;
            button.textContent = 'Recording payment…';
        });
    });
});
