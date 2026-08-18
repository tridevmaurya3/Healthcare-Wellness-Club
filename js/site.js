(() => {
  'use strict';

  const scriptElement = document.currentScript;
  const siteRoot = scriptElement && scriptElement.src
    ? new URL('../', scriptElement.src)
    : new URL('./', window.location.href);

  const fromRoot = (path) => new URL(path, siteRoot).href;

  async function loadFragment(elementId, relativePath) {
    const target = document.getElementById(elementId);
    if (!target) return;

    try {
      const response = await fetch(fromRoot(relativePath), { cache: 'no-cache' });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      target.innerHTML = await response.text();
      configureRootLinks(target);
      highlightCurrentNavigation(target);
      updateCurrentYear(target);
    } catch (error) {
      console.error(`Unable to load ${relativePath}:`, error);
    }
  }

  function configureRootLinks(container) {
    container.querySelectorAll('[data-route]').forEach((element) => {
      element.setAttribute('href', fromRoot(element.dataset.route));
    });

    container.querySelectorAll('[data-asset]').forEach((element) => {
      element.setAttribute('src', fromRoot(element.dataset.asset));
    });
  }

  function highlightCurrentNavigation(container) {
    const currentPath = window.location.pathname.replace(/\/+$/, '') || '/';

    container.querySelectorAll('.nav-link[data-route]').forEach((link) => {
      const linkPath = new URL(link.href).pathname.replace(/\/+$/, '') || '/';
      if (linkPath === currentPath) {
        link.classList.add('active');
        link.setAttribute('aria-current', 'page');
      }
    });
  }

  function updateCurrentYear(container = document) {
    const year = new Date().getFullYear();
    container.querySelectorAll('[data-current-year]').forEach((element) => {
      element.textContent = String(year);
    });
  }

  function createChatbot() {
    if (document.getElementById('chat-toggle-btn')) return;

    const button = document.createElement('button');
    button.id = 'chat-toggle-btn';
    button.type = 'button';
    button.className = 'chat-toggle-btn';
    button.setAttribute('aria-label', 'Open wellness chat');
    button.setAttribute('aria-expanded', 'false');
    button.innerHTML = `<img src="${fromRoot('chat.png')}" alt="" width="60" height="60">`;

    const frame = document.createElement('iframe');
    frame.id = 'chatbot-frame';
    frame.className = 'chatbot-frame';
    frame.src = 'https://www.chatbase.co/chatbot-iframe/rWYfFJUdA4l7XbwTThmpz';
    frame.title = 'Healthcare Wellness Club chat';
    frame.loading = 'lazy';

    button.addEventListener('click', () => {
      const isOpen = frame.classList.toggle('is-open');
      button.setAttribute('aria-expanded', String(isOpen));
    });

    document.body.appendChild(button);
    document.body.appendChild(frame);
  }

  document.addEventListener('DOMContentLoaded', () => {
    configureRootLinks(document);
    updateCurrentYear(document);
    loadFragment('navbar-placeholder', 'pages/navbar.html');
    loadFragment('footer-placeholder', 'pages/footer.html');
    createChatbot();
  });
})();
