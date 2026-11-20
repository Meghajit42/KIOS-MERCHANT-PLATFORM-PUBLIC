document.addEventListener('click', (event) => {
  const add = event.target.closest('[data-add-variant]');
  if (add) {
    const editor = document.querySelector('[data-variant-editor]');
    const template = document.querySelector('#variant-template');
    if (!editor || !template) return;
    const key = `new${Date.now()}${Math.floor(Math.random() * 1000)}`;
    const fragment = template.content.cloneNode(true);
    fragment.querySelectorAll('[data-name]').forEach((input) => {
      input.name = `variants[${key}][${input.dataset.name}]`;
      input.removeAttribute('data-name');
    });
    const order = editor.querySelectorAll('.variant-edit-row').length;
    const orderInput = fragment.querySelector(`[name="variants[${key}][display_order]"]`);
    if (orderInput) orderInput.value = String(order);
    editor.append(fragment);
  }
  const remove = event.target.closest('[data-remove-variant]');
  if (remove) remove.closest('.variant-edit-row')?.remove();
});

document.addEventListener('change', (event) => {
  const input = event.target.closest('[data-image-upload]');
  if (!input) return;
  const preview = document.querySelector('.admin-upload-preview');
  if (!preview || !input.files || !input.files[0]) return;
  if (preview.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
  const objectUrl = URL.createObjectURL(input.files[0]);
  preview.src = objectUrl;
  preview.dataset.objectUrl = objectUrl;
  preview.hidden = false;
});
