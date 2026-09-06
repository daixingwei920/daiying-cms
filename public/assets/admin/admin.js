(function () {
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
