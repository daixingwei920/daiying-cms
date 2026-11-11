(() => {
  'use strict';
  const editor = document.querySelector('[data-addon-editor]');
  if (!editor) return;
  const rows = editor.querySelector('[data-addon-rows]');
  const template = editor.querySelector('[data-addon-row-template]');
  const add = editor.querySelector('[data-add-addon-row]');
  let nextIndex = rows.querySelectorAll('[data-addon-row]').length;
  const update = (row) => {
    const custom = row.querySelector('[data-addon-kind]').value === 'custom';
    row.querySelector('[data-addon-custom]').hidden = !custom;
    row.querySelector('[data-addon-name]').required = custom;
    add.disabled = rows.querySelectorAll('[data-addon-row]').length >= 20;
  };
  editor.addEventListener('click', (event) => {
    if (event.target.closest('[data-add-addon-row]') && rows.querySelectorAll('[data-addon-row]').length < 20) {
      const fragment = document.createElement('template');
      fragment.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
      const row = fragment.content.firstElementChild;
      rows.appendChild(row);
      update(row);
      row.querySelector('[data-addon-kind]').focus();
    }
    const remove = event.target.closest('[data-remove-addon-row]');
    if (remove) {
      remove.closest('[data-addon-row]').remove();
      add.disabled = rows.querySelectorAll('[data-addon-row]').length >= 20;
      add.focus();
    }
  });
  editor.addEventListener('change', (event) => {
    if (event.target.matches('[data-addon-kind]')) update(event.target.closest('[data-addon-row]'));
  });
  rows.querySelectorAll('[data-addon-row]').forEach(update);
})();
