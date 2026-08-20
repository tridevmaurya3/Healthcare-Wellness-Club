/* Business OS compact workspace controls. Presentation only; no form/data logic. */
(function () {
  'use strict';

  function button(label, target, expanded) {
    var control = document.createElement('button');
    control.type = 'button';
    control.className = 'bp-collapse-toggle';
    control.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    control.setAttribute('aria-controls', target.id);
    control.innerHTML = '<span>' + (expanded ? 'Close' : 'Open') + '</span><i aria-hidden="true"></i>';
    control.addEventListener('click', function () {
      var isOpen = control.getAttribute('aria-expanded') === 'true';
      control.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
      control.querySelector('span').textContent = isOpen ? 'Open' : 'Close';
      target.hidden = isOpen;
      label.classList.toggle('bp-is-open', !isOpen);
    });
    return control;
  }

  function collapseSidebar() {
    document.querySelectorAll('.os-sidebar').forEach(function (sidebar, sideIndex) {
      var labels = Array.prototype.slice.call(sidebar.querySelectorAll(':scope > .os-nav-label'));
      labels.forEach(function (label, index) {
        var content = [];
        var node = label.nextElementSibling;
        while (node && !node.classList.contains('os-nav-label') && !node.classList.contains('os-sidebar-status')) {
          content.push(node);
          node = node.nextElementSibling;
        }
        if (!content.length) return;
        var shell = document.createElement('div');
        shell.className = 'bp-sidebar-group';
        shell.id = 'bp-side-' + sideIndex + '-' + index;
        content[0].parentNode.insertBefore(shell, content[0]);
        content.forEach(function (item) { shell.appendChild(item); });
        shell.hidden = true;
        label.classList.add('bp-collapse-heading');
        label.appendChild(button(label, shell, false));
      });
    });
  }

  function collapseCards() {
    var selector = '.os-main .os-card, .os-main .s10-card, .os-main .pp-card, .os-main .bi-card, .os-main .csm-section, .os-main .csm-content-card';
    document.querySelectorAll(selector).forEach(function (card, index) {
      if (card.dataset.noCollapse === 'true' || card.closest('[data-no-collapse="true"]')) return;
      if (card.classList.contains('s10-kpi') || card.classList.contains('os-kpi')) return;
      var heading = card.querySelector(':scope > h2, :scope > h3, :scope > .os-title-row, :scope > .csm-card-head');
      if (!heading) return;
      var body = document.createElement('div');
      body.className = 'bp-collapse-body';
      body.id = 'bp-card-' + index;
      var movable = [];
      Array.prototype.slice.call(card.children).forEach(function (child) {
        if (child !== heading) movable.push(child);
      });
      if (!movable.length) return;
      card.insertBefore(body, movable[0]);
      movable.forEach(function (child) { body.appendChild(child); });
      body.hidden = true;
      card.classList.add('bp-collapsible-card');
      var host = heading.classList.contains('os-title-row') ? heading : heading;
      host.classList.add('bp-collapse-heading');
      host.appendChild(button(host, body, false));
    });
  }

  function groupProductMasterByCategory() {
    var cards = document.querySelectorAll('.pmm-card');
    if (!cards.length) return;
    var card = cards[cards.length - 1];
    var rows = Array.prototype.slice.call(card.querySelectorAll(':scope > .pmm-row'));
    if (!rows.length) return;

    var groups = {};
    rows.forEach(function (row) {
      var categoryNode = row.querySelector(':scope > div:first-child small');
      var category = categoryNode && categoryNode.textContent.trim() ? categoryNode.textContent.trim() : 'Uncategorised';
      if (!groups[category]) groups[category] = [];
      groups[category].push(row);
    });

    var toolbar = document.createElement('div');
    toolbar.className = 'pmm-category-toolbar';
    var label = document.createElement('label');
    label.innerHTML = '<span>Product Category</span>';
    var select = document.createElement('select');
    select.setAttribute('aria-label', 'Filter recent products by category');
    select.innerHTML = '<option value="all">All Categories (' + rows.length + ')</option>';
    Object.keys(groups).sort().forEach(function (category) {
      var option = document.createElement('option');
      option.value = category;
      option.textContent = category + ' (' + groups[category].length + ')';
      select.appendChild(option);
    });
    label.appendChild(select);
    toolbar.appendChild(label);
    rows[0].parentNode.insertBefore(toolbar, rows[0]);

    var list = document.createElement('div');
    list.className = 'pmm-category-list';
    toolbar.parentNode.insertBefore(list, toolbar.nextSibling);
    Object.keys(groups).sort().forEach(function (category, index) {
      var section = document.createElement('details');
      section.className = 'pmm-category-section';
      section.dataset.category = category;
      section.open = index === 0;
      var summary = document.createElement('summary');
      summary.innerHTML = '<span>' + category + '</span><b>' + groups[category].length + ' product' + (groups[category].length === 1 ? '' : 's') + '</b>';
      section.appendChild(summary);
      var body = document.createElement('div');
      body.className = 'pmm-category-products';
      groups[category].forEach(function (row) { body.appendChild(row); });
      section.appendChild(body);
      list.appendChild(section);
    });

    select.addEventListener('change', function () {
      var chosen = select.value;
      list.querySelectorAll('.pmm-category-section').forEach(function (section) {
        var visible = chosen === 'all' || section.dataset.category === chosen;
        section.hidden = !visible;
        if (chosen !== 'all' && visible) section.open = true;
      });
    });
  }

  function init() {
    collapseSidebar();
    collapseCards();
    groupProductMasterByCategory();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
