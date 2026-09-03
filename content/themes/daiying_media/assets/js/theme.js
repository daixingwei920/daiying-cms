(function () {
  const root = document.documentElement;
  const savedTheme = localStorage.getItem('daiying_media_theme');
  if (savedTheme === 'light' || savedTheme === 'dark') {
    root.dataset.theme = savedTheme;
  }

  const header = document.querySelector('.site-header');
  const onScroll = () => header && header.classList.toggle('is-compact', window.scrollY > 24);
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  document.querySelectorAll('[data-open-search]').forEach((button) => {
    button.addEventListener('click', () => {
      const overlay = document.querySelector('.search-overlay');
      overlay && overlay.classList.add('is-open');
      const input = overlay && overlay.querySelector('input[name="q"]');
      input && input.focus();
    });
  });

  document.querySelectorAll('[data-close-search]').forEach((button) => {
    button.addEventListener('click', () => button.closest('.search-overlay')?.classList.remove('is-open'));
  });

  document.querySelectorAll('[data-menu-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const drawer = document.querySelector('.mobile-drawer');
      const open = drawer && !drawer.classList.contains('is-open');
      drawer && drawer.classList.toggle('is-open', open);
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      localStorage.setItem('daiying_media_theme', next);
    });
  });

  document.querySelectorAll('[data-share-row]').forEach((row) => {
    const title = row.getAttribute('data-share-title') || document.title;
    const text = row.getAttribute('data-share-text') || '';
    const url = row.getAttribute('data-share-url') || window.location.href;
    const status = row.querySelector('[data-share-status]');
    const qrPanel = row.querySelector('[data-share-qr-panel]');
    const setStatus = (message) => {
      if (!status) return;
      status.textContent = message;
      window.clearTimeout(status._daiyingTimer);
      status._daiyingTimer = window.setTimeout(() => {
        status.textContent = '';
      }, 1800);
    };

    row.querySelectorAll('[data-share-native]').forEach((button) => {
      button.addEventListener('click', async () => {
        try {
          if (navigator.share) {
            await navigator.share({ title, text, url });
            setStatus('已打开分享');
            return;
          }
          await navigator.clipboard.writeText(url);
          setStatus('链接已复制');
        } catch (error) {
          setStatus('分享已取消');
        }
      });
    });

    row.querySelectorAll('[data-share-copy]').forEach((button) => {
      button.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(url);
          setStatus('链接已复制');
        } catch (error) {
          setStatus('复制失败，请手动复制地址栏链接');
        }
      });
    });

    row.querySelectorAll('[data-share-qr]').forEach((button) => {
      button.addEventListener('click', () => {
        if (!qrPanel) {
          setStatus('二维码暂不可用');
          return;
        }
        qrPanel.hidden = !qrPanel.hidden;
        setStatus(qrPanel.hidden ? '已收起二维码' : '微信扫码后分享');
      });
    });
  });

  document.querySelectorAll('pre code').forEach((code) => {
    const pre = code.parentElement;
    if (!pre) return;
    const language = Array.from(code.classList).find((name) => name.startsWith('language-'))?.replace('language-', '') || 'code';
    pre.dataset.language = language;
    const copy = document.createElement('button');
    copy.type = 'button';
    copy.className = 'code-copy';
    copy.textContent = 'Copy';
    copy.addEventListener('click', async () => {
      await navigator.clipboard.writeText(code.innerText);
      copy.textContent = 'Copied';
      window.setTimeout(() => { copy.textContent = 'Copy'; }, 1400);
    });
    pre.appendChild(copy);
  });

  const lightbox = document.querySelector('.lightbox');
  const lightboxImage = lightbox && lightbox.querySelector('img');
  document.querySelectorAll('.entry-content img, .entry-cover-wrap img').forEach((image) => {
    image.addEventListener('click', () => {
      if (!lightbox || !lightboxImage) return;
      lightboxImage.src = image.currentSrc || image.src;
      lightboxImage.alt = image.alt || '';
      lightbox.classList.add('is-open');
    });
  });
  lightbox?.addEventListener('click', (event) => {
    if (event.target === lightbox || event.target instanceof HTMLButtonElement) {
      lightbox.classList.remove('is-open');
    }
  });

  const commentForm = document.querySelector('[data-comment-form]');
  const commentBody = commentForm && commentForm.querySelector('[data-comment-body]');
  const commentReplying = commentForm && commentForm.querySelector('[data-comment-replying]');
  const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  document.querySelectorAll('[data-comment-reply]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!(commentBody instanceof HTMLTextAreaElement) || !commentForm) return;
      const author = button.getAttribute('data-comment-author') || '读者';
      const prefix = `@${author} `;
      if (!commentBody.value.trim().startsWith(prefix)) {
        commentBody.value = commentBody.value.trim() === '' ? prefix : `${prefix}${commentBody.value}`;
      }
      const name = commentReplying && commentReplying.querySelector('strong');
      if (name) name.textContent = author;
      commentReplying && (commentReplying.hidden = false);
      commentForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
      window.setTimeout(() => {
        commentBody.focus();
        commentBody.setSelectionRange(commentBody.value.length, commentBody.value.length);
      }, 260);
    });
  });

  document.querySelectorAll('[data-comment-cancel]').forEach((button) => {
    button.addEventListener('click', () => {
      const current = commentReplying?.querySelector('strong')?.textContent || '';
      if (commentBody instanceof HTMLTextAreaElement && current !== '') {
        commentBody.value = commentBody.value.replace(new RegExp(`^@${escapeRegExp(current)}\\s*`), '');
      }
      commentReplying && (commentReplying.hidden = true);
      commentBody instanceof HTMLTextAreaElement && commentBody.focus();
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      document.querySelector('.search-overlay')?.classList.remove('is-open');
      lightbox?.classList.remove('is-open');
    }
  });
})();
