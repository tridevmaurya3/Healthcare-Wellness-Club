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
    var selector = '.os-main .os-card, .os-main .s10-card, .os-main .pp-card, .os-main .bi-card';
    document.querySelectorAll(selector).forEach(function (card, index) {
      if (card.dataset.noCollapse === 'true' || card.closest('[data-no-collapse="true"]')) return;
      if (card.classList.contains('s10-kpi') || card.classList.contains('os-kpi')) return;
      var heading = card.querySelector(':scope > h2, :scope > h3, :scope > .os-title-row');
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

  function init() {
    collapseSidebar();
    collapseCards();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
