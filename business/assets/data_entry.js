(() => {
  'use strict';

  const norm = value => (value || '').toString().trim().toLowerCase();
  const esc = value => (value || '').toString().replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));

  function weekLabel(dateValue) {
    if (!dateValue) return '';
    const day = Number(dateValue.slice(-2));
    if (!Number.isFinite(day) || day < 1) return '';
    return `Week-${Math.min(4, Math.max(1, Math.ceil(day / 7)))}`;
  }

  document.querySelectorAll('[data-member-picker]').forEach(picker => {
    const search = picker.querySelector('[data-member-search]');
    const select = picker.querySelector('select');
    const count = picker.querySelector('[data-member-count]');
    if (!search || !select) return;

    const options = [...select.options].slice(1);
    const refresh = () => {
      const q = norm(search.value);
      let visible = 0;
      options.forEach(option => {
        const match = !q || norm(option.dataset.search || option.textContent).includes(q);
        option.hidden = !match;
        if (match) visible++;
      });
      if (count) count.textContent = q ? `${visible} match${visible === 1 ? '' : 'es'}` : `${options.length} members`;
      if (select.selectedOptions[0]?.hidden) select.value = '';
    };
    search.addEventListener('input', refresh);
    refresh();
  });

  document.querySelectorAll('[data-auto-week]').forEach(input => {
    const form = input.closest('form');
    const dateName = input.dataset.autoWeek;
    const dateInput = form?.querySelector(`[name="${dateName}"]`);
    if (!dateInput) return;
    const update = () => {
      if (input.dataset.manual === '1' && input.value.trim() !== '') return;
      input.value = weekLabel(dateInput.value);
      input.dataset.manual = '0';
    };
    input.addEventListener('input', () => { input.dataset.manual = input.value.trim() === '' ? '0' : '1'; });
    dateInput.addEventListener('change', update);
    update();
  });

  document.querySelectorAll('[data-order-calculator]').forEach(form => {
    const gross = form.querySelector('[name="gross_amount"]');
    const discount = form.querySelector('[name="discount_amount"]');
    const net = form.querySelector('[name="net_amount"]');
    const preview = form.querySelector('[data-net-preview]');
    if (!gross || !discount || !net) return;

    const update = () => {
      const g = Number(gross.value || 0);
      const d = Number(discount.value || 0);
      if (!Number.isFinite(g) || !Number.isFinite(d)) return;
      const calculated = Math.max(0, g - d);
      if (net.dataset.manual !== '1') net.value = calculated.toFixed(2);
      if (preview) preview.textContent = `Calculated Net: ₹${calculated.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}`;
    };
    net.addEventListener('input', () => { net.dataset.manual = net.value.trim() === '' ? '0' : '1'; });
    gross.addEventListener('input', update);
    discount.addEventListener('input', update);
    update();
  });

  document.querySelectorAll('form[data-smart-entry]').forEach(form => {
    const panel = form.querySelector('[data-duplicate-panel]');
    const message = form.querySelector('[data-duplicate-message]');
    const matches = form.querySelector('[data-duplicate-matches]');
    const confirm = form.querySelector('[name="confirm_duplicate"]');
    const status = form.querySelector('[data-preflight-status]');
    let checking = false;
    let lastSignature = '';

    const signature = () => new URLSearchParams(new FormData(form)).toString().replace(/confirm_duplicate=yes&?/,'');
    const resetGuard = () => {
      if (signature() === lastSignature) return;
      if (panel) panel.hidden = true;
      if (confirm) confirm.checked = false;
      if (status) status.textContent = 'Duplicate guard ready';
    };
    form.addEventListener('input', resetGuard);
    form.addEventListener('change', resetGuard);

    form.addEventListener('submit', async event => {
      if (checking || form.dataset.preflightPass === '1') return;
      event.preventDefault();
      checking = true;
      if (status) status.textContent = 'Checking for duplicates…';

      try {
        const body = new URLSearchParams(new FormData(form));
        const response = await fetch('data_entry_check.php', {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
          body: body.toString(),
          credentials: 'same-origin'
        });
        const data = await response.json();
        if (!data.ok) throw new Error(data.message || 'Duplicate check failed.');
        lastSignature = signature();

        if (data.duplicate && !confirm?.checked) {
          if (panel) panel.hidden = false;
          if (message) message.textContent = data.message || 'Possible duplicate found.';
          if (matches) {
            matches.innerHTML = (data.matches || []).map(item => `<li><b>${esc(item.label)}</b><span>${esc(item.detail)}</span></li>`).join('');
          }
          if (status) status.textContent = `${data.count || 1} possible duplicate${data.count === 1 ? '' : 's'} found`;
          panel?.scrollIntoView({behavior:'smooth', block:'center'});
          checking = false;
          return;
        }

        if (status) status.textContent = data.duplicate ? 'Duplicate override confirmed' : 'No exact duplicate found';
        form.dataset.preflightPass = '1';
        form.submit();
      } catch (error) {
        if (status) status.textContent = 'Preflight unavailable — server validation will still run';
        form.dataset.preflightPass = '1';
        form.submit();
      } finally {
        checking = false;
      }
    });
  });
})();
