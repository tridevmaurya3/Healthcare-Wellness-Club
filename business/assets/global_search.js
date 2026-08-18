(() => {
  'use strict';

  const input = document.getElementById('globalSearchInput');
  const form = document.getElementById('globalSearchForm');
  if (!input || !form) return;

  document.addEventListener('keydown', event => {
    const tag = (event.target?.tagName || '').toLowerCase();
    const typing = ['input','textarea','select'].includes(tag) || event.target?.isContentEditable;

    if (event.key === '/' && !typing) {
      event.preventDefault();
      input.focus();
      input.select();
      return;
    }

    if (event.key === 'Escape' && document.activeElement === input) {
      if (input.value !== '') {
        input.value = '';
      } else {
        input.blur();
      }
    }
  });
})();