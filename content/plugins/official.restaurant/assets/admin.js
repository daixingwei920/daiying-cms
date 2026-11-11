(() => {
  'use strict';
  const root = document.querySelector('.restaurant-admin');
  if (!root) return;
  root.querySelectorAll('.ra-main-nav a').forEach(link => {
    if (new URL(link.href).pathname === location.pathname) link.setAttribute('aria-current', 'page');
  });
  root.querySelectorAll('table').forEach(table => {
    const wrap = document.createElement('div');
    wrap.className = 'ra-table-scroll';
    wrap.tabIndex = 0;
    wrap.setAttribute('role', 'region');
    wrap.setAttribute('aria-label', '餐厅记录，可左右滚动');
    table.before(wrap); wrap.append(table);
  });
  const form = root.querySelector('[data-settings-form]');
  if (!form) return;
  const tabs = [...form.querySelectorAll('[data-settings-tab]')];
  const panels = [...form.querySelectorAll('[data-settings-panel]')];
  const show = id => {
    if (!panels.some(panel => panel.dataset.settingsPanel === id)) id = 'basic';
    panels.forEach(panel => { panel.hidden = panel.dataset.settingsPanel !== id; });
    tabs.forEach(tab => tab.setAttribute('aria-current', String(tab.dataset.settingsTab === id)));
    form.querySelector('[data-current-section]').value = id;
  };
  show(location.hash.replace('#ra-', ''));
  tabs.forEach(tab => tab.addEventListener('click', () => show(tab.dataset.settingsTab)));
  form.addEventListener('invalid', event => {
    const panel = event.target.closest('[data-settings-panel]');
    if (panel) show(panel.dataset.settingsPanel);
  }, true);
  window.addEventListener('hashchange', () => show(location.hash.replace('#ra-', '')));
  const state = form.querySelector('[data-save-state]');
  form.querySelectorAll('[data-period-list], [data-gallery-list]').forEach(list => {
    let next = 0;
    list.querySelectorAll('[name]').forEach(input => {
      [...input.name.matchAll(/\[(\d+)\]/g)].forEach(match => { next = Math.max(next, Number(match[1]) + 1); });
    });
    list.dataset.nextIndex = String(next);
  });
  const dirty = () => { state.textContent = '有修改，尚未保存'; };
  form.addEventListener('input', dirty);
  form.addEventListener('change', dirty);
  const insert = (template, list, rowSelector, limit) => {
    if (list.querySelectorAll(rowSelector).length >= limit) return;
    // A monotonic per-list index prevents deletion followed by add from reusing field names.
    let next = Number(list.dataset.nextIndex || 0);
    list.querySelectorAll('[name]').forEach(input => {
      const matches = [...input.name.matchAll(/\[(\d+)\]/g)];
      matches.forEach(match => { next = Math.max(next, Number(match[1]) + 1); });
    });
    const fragment = document.createElement('template');
    fragment.innerHTML = template.innerHTML.replaceAll('__index__', String(next));
    list.dataset.nextIndex = String(next + 1);
    list.append(fragment.content);
    list.querySelector(`${rowSelector}:last-child input`)?.focus();
    dirty();
  };
  form.addEventListener('click', event => {
    const addPeriod = event.target.closest('[data-add-period]');
    if (addPeriod) {
      const day = addPeriod.closest('[data-hours-day]');
      insert(day.querySelector('template'), day.querySelector('[data-period-list]'), '[data-period]', 8);
    }
    if (event.target.closest('[data-add-gallery]')) insert(form.querySelector('[data-gallery-template]'), form.querySelector('[data-gallery-list]'), '[data-gallery-row]', 40);
    const remove = event.target.closest('[data-remove-row]');
    if (remove) {
      const row = remove.closest('[data-period], [data-gallery-row]');
      const container = row.parentElement;
      const focus = container.querySelector('input');
      row.remove(); dirty();
      if (focus?.isConnected) focus.focus();
      else container.parentElement.querySelector('[data-add-period], [data-add-gallery]')?.focus();
    }
  });
  const url = form.querySelector('[data-image-url="hero"]');
  const preview = form.querySelector('[data-image-preview="hero"]');
  const img = preview.querySelector('img');
  const hint = preview.querySelector('p');
  const safeImage = value => /^(https?:\/\/|\/(?!\/))[^\s<>\\]+$/i.test(value);
  const updateImage = () => {
    const value = url.value.trim();
    if (!safeImage(value)) { img.hidden = true; hint.hidden = false; return; }
    hint.textContent = '照片加载中…'; hint.hidden = false; img.hidden = true;
    img.src = value;
  };
  img.addEventListener('load', () => { img.hidden = false; hint.hidden = true; });
  img.addEventListener('error', () => { img.hidden = true; hint.hidden = false; hint.textContent = '照片暂时无法显示，请检查图片地址'; });
  url.addEventListener('change', updateImage);
  const focusPreview = device => {
    img.style.height = device === 'mobile' ? '300px' : '260px';
    img.style.width = device === 'mobile' ? 'min(100%, 320px)' : '100%';
    const x = form.querySelector(`[data-focus="${device}-x"]`).value;
    const y = form.querySelector(`[data-focus="${device}-y"]`).value;
    img.style.objectPosition = `${x}% ${y}%`;
  };
  form.querySelectorAll('[data-focus]').forEach(input => input.addEventListener('input', () => focusPreview(input.dataset.focus.split('-')[0])));
  focusPreview('desktop');
  updateImage();
})();
