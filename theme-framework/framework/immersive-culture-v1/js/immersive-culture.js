(function () {
  const root = document.querySelector('.ic-hero');
  if (!root) return;

  root.dataset.ready = 'true';

  document.querySelectorAll('[data-prop-id]').forEach((node) => {
    node.addEventListener('click', () => {
      node.dispatchEvent(new CustomEvent('theme-prop:activate', { bubbles: true }));
    });
  });
})();
