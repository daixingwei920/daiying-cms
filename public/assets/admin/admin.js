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
