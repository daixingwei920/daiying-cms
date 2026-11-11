(function () {
  // Enhance anchors only after loading: without JS every section remains readable.
  document.querySelectorAll('[data-admin-data-view]').forEach(function (view) {
    var nav = view.querySelector('[data-report-nav]');
    var tabs = Array.from(view.querySelectorAll('[data-report-tab]'));
    var panels = Array.from(view.querySelectorAll('[data-report-panel]'));
    var menu = view.querySelector('[data-report-menu]');
    var narrow = window.matchMedia('(max-width: 760px)');
    if (!nav || !menu || !tabs.length || tabs.length !== panels.length) return;
    nav.setAttribute('role', 'tablist');
    nav.setAttribute('aria-orientation', 'vertical');
    function select(index, focus) {
      tabs.forEach(function (tab, i) {
        tab.setAttribute('aria-selected', i === index ? 'true' : 'false');
        tab.tabIndex = i === index ? 0 : -1;
        panels[i].hidden = i !== index;
      });
      if (focus) tabs[index].focus();
      if (narrow.matches && !focus) menu.open = false;
    }
    tabs.forEach(function (tab, index) {
      tab.setAttribute('role', 'tab');
      tab.setAttribute('aria-controls', panels[index].id);
      panels[index].setAttribute('role', 'tabpanel');
      panels[index].setAttribute('aria-labelledby', tab.id);
      panels[index].tabIndex = 0;
      tab.addEventListener('click', function (event) {
        event.preventDefault();
        select(index, false);
        // A collapsed mobile menu must not retain focus in its hidden contents.
        if (narrow.matches) panels[index].focus({preventScroll: true});
      });
      tab.addEventListener('keydown', function (event) {
        var next = index;
        if (event.key === 'ArrowDown' || event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = tabs.length - 1;
        else return;
        event.preventDefault();
        select(next, true);
      });
    });
    var initial = panels.findIndex(function (panel) { return '#' + panel.id === window.location.hash; });
    select(initial < 0 ? 0 : initial, false);
    function resizeMenu() {
      // Do not strand keyboard focus inside a menu hidden by a breakpoint change.
      if (narrow.matches && menu.contains(document.activeElement)) {
        menu.querySelector('summary').focus({preventScroll: true});
      }
      menu.open = !narrow.matches;
    }
    resizeMenu();
    narrow.addEventListener('change', resizeMenu);
  });
  // One bounded page of existing content; no fetches, polling or extra server queries.
  document.querySelectorAll('[data-content-workspace]').forEach(function (view) {
    var rows = Array.from(view.querySelectorAll('[data-content-row]'));
    var search = view.querySelector('[data-content-search]');
    var type = view.querySelector('[data-content-type-filter]');
    var statuses = Array.from(view.querySelectorAll('[data-content-status]'));
    var result = view.querySelector('[data-content-result]');
    var empty = view.querySelector('[data-content-empty]');
    var drawer = view.querySelector('.content-detail-drawer');
    var status = '';
    var docked = window.matchMedia('(min-width: 1440px)');
    if (!search || !type || !result || !empty) return;
    var index = rows.map(function (row) {
      return {row: row, text: (row.querySelector('[data-content-title]').textContent + ' ' + row.querySelector('[data-content-slug]').textContent).toLocaleLowerCase()};
    });
    function filter() {
      var text = search.value.trim().toLocaleLowerCase();
      var visible = 0;
      index.forEach(function (item) {
        var show = (!status || item.row.dataset.status === status) && (!type.value || item.row.dataset.type === type.value) && (!text || item.text.includes(text));
        item.row.hidden = !show;
        if (show) visible++;
      });
      statuses.forEach(function (button) { button.setAttribute('aria-pressed', button.dataset.contentStatus === status ? 'true' : 'false'); });
      result.textContent = '本页显示 ' + visible + ' / ' + rows.length + ' 条';
      empty.hidden = visible !== 0 || rows.length === 0;
      if (drawer && drawer.open && view.querySelector('.is-detail-selected[hidden]')) drawer.close();
    }
    view.querySelector('[data-content-filters]').hidden = false;
    search.addEventListener('input', filter);
    type.addEventListener('change', filter);
    statuses.forEach(function (button) { button.addEventListener('click', function () { status = button.dataset.contentStatus; filter(); }); });
    view.querySelector('[data-content-reset]').addEventListener('click', function () { search.value = ''; type.value = ''; status = ''; filter(); search.focus(); });
    filter();
    if (!drawer || typeof drawer.showModal !== 'function') return;
    var trigger = null;
    function value(row, selector) { var node = row.querySelector(selector); return node ? node.textContent.trim() : ''; }
    var detailButtons = Array.from(view.querySelectorAll('[data-content-detail]'));
    function showDetail(button, focus) {
        var row = button.closest('[data-content-row]');
        // Initial docking precedes first layout; only a user action needs scroll preservation.
        var scrollRoot = focus ? view.closest('.admin-main') : null;
        var scrollTop = scrollRoot ? scrollRoot.scrollTop : 0;
        trigger = button;
        drawer.dataset.status = row.dataset.status;
        rows.forEach(function (item) { item.classList.toggle('is-detail-selected', item === row); });
        ['title', 'id', 'type', 'state', 'updated', 'slug'].forEach(function (name) { drawer.querySelector('[data-detail-' + name + ']').textContent = value(row, '[data-content-' + name + ']'); });
        var published = row.dataset.published || '';
        drawer.querySelector('[data-detail-published]').textContent = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(published) ? published.slice(0, 16).replace('T', ' ') : (published || '尚未发布');
        drawer.querySelector('[data-detail-published]').title = published;
        [['title', '标题'], ['slug', 'Slug']].forEach(function (field) {
          var filled = !!value(row, '[data-content-' + field[0] + ']');
          var check = drawer.querySelector('[data-detail-check="' + field[0] + '"]');
          check.textContent = (filled ? '✓ ' : '— ') + field[1] + (filled ? '已填写' : '未填写');
          check.classList.toggle('is-missing', !filled);
        });
        ['edit', 'preview'].forEach(function (name) { drawer.querySelector('[data-detail-' + name + ']').href = row.querySelector('[data-content-' + name + ']').href; });
        var previousFocus = document.activeElement;
        if (!drawer.open) {
          drawer.dataset.docked = docked.matches ? 'true' : 'false';
          view.classList.toggle('has-content-detail', docked.matches);
          if (docked.matches) drawer.show(); else drawer.showModal();
        }
        if (focus) drawer.querySelector('[data-content-close]').focus({preventScroll: true});
        else if (previousFocus && previousFocus !== document.body) previousFocus.focus({preventScroll: true});
        else if (document.activeElement && drawer.contains(document.activeElement)) document.activeElement.blur();
        if (scrollRoot) scrollRoot.scrollTop = scrollTop;
    }
    detailButtons.forEach(function (button) {
      var title = button.closest('[data-content-row]').querySelector('[data-content-title]');
      button.textContent = title.textContent;
      button.setAttribute('aria-label', '查看详情：' + title.textContent);
      button.hidden = false;
      title.hidden = true;
      button.addEventListener('click', function () { showDetail(button, true); });
    });
    view.querySelector('[data-content-close]').addEventListener('click', function () { drawer.close(); });
    view.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && drawer.open) { event.preventDefault(); drawer.close(); }
    });
    docked.addEventListener('change', function () { if (drawer.open) drawer.close(); });
    // Initial wide-screen inspector reuses the first row without stealing keyboard focus.
    if (docked.matches && detailButtons.length) showDetail(detailButtons[0], false);
    drawer.addEventListener('close', function () {
      view.classList.remove('has-content-detail');
      rows.forEach(function (item) { item.classList.remove('is-detail-selected'); });
      if (trigger && trigger.isConnected && !trigger.closest('[hidden]')) trigger.focus({preventScroll: true});
      else search.focus({preventScroll: true});
    });
  });
  var key = 'daiying.admin.sidebarCollapsed';
  var body = document.body;
  var button = document.querySelector('[data-admin-sidebar-toggle]');

  function applySidebar(collapsed) {
    body.classList.toggle('admin-sidebar-collapsed', collapsed);
    if (button) {
      button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }
  }

  try {
    applySidebar(localStorage.getItem(key) === '1');
  } catch (error) {
    applySidebar(false);
  }

  if (button) {
    button.addEventListener('click', function () {
      var collapsed = !body.classList.contains('admin-sidebar-collapsed');
      applySidebar(collapsed);
      try {
        localStorage.setItem(key, collapsed ? '1' : '0');
      } catch (error) {
      }
    });
  }

  var toastRegion = document.createElement('div');
  toastRegion.className = 'admin-toast-region';
  toastRegion.setAttribute('aria-live', 'polite');
  document.body.appendChild(toastRegion);

  function toast(message) {
    var node = document.createElement('div');
    node.className = 'admin-toast';
    node.textContent = message;
    toastRegion.appendChild(node);
    window.setTimeout(function () {
      node.remove();
    }, 1800);
  }

  document.addEventListener('click', function (event) {
    var blockAction = event.target.closest('.block-card-actions button');
    if (blockAction) {
      var card = blockAction.closest('.block-card');
      if (card) {
        card.classList.add('is-moving');
      }
      var text = blockAction.textContent.trim();
      if (text) {
        toast('正在' + text + '区块...');
      }
    }
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.matches || !form.matches('form[data-confirm]')) {
      return;
    }
    var message = form.getAttribute('data-confirm') || '确定要继续吗？';
    if (!window.confirm(message)) {
      event.preventDefault();
    }
  });

  document.querySelectorAll('form[data-unsaved-warning]').forEach(function (form) {
    var dirty = false;
    var message = form.getAttribute('data-unsaved-warning') || '有未保存的更改';
    form.addEventListener('input', function () {
      dirty = true;
    });
    form.addEventListener('change', function () {
      dirty = true;
    });
    form.addEventListener('submit', function () {
      dirty = false;
    });
    window.addEventListener('beforeunload', function (event) {
      if (!dirty) {
        return;
      }
      event.preventDefault();
      event.returnValue = message;
    });
  });

  document.querySelectorAll('.admin-notice-success').forEach(function (notice) {
    var text = notice.textContent.trim();
    if (text) {
      toast(text);
    }
  });
})();
