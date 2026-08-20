(() => {
  "use strict";

  const scriptElement = document.currentScript;
  const siteRoot =
    scriptElement && scriptElement.src
      ? new URL("../", scriptElement.src)
      : new URL("./", window.location.href);

  const fromRoot = (path) =>
    new URL(String(path || "").replace(/^\/+/, ""), siteRoot).href;
  let authStatePromise = null;

  window.hwcSiteAsset = fromRoot;
  window.hwcSiteReady = fetch(fromRoot("public_site_api.php"), {
    cache: "no-store",
    credentials: "same-origin",
  })
    .then((response) =>
      response.ok
        ? response.json()
        : Promise.reject(new Error(`HTTP ${response.status}`)),
    )
    .then((data) =>
      data && data.ok
        ? data
        : Promise.reject(new Error("Content API unavailable")),
    )
    .catch((error) => {
      console.warn("Using built-in public-site fallback content:", error);
      return { ok: false, content: {}, stories: [], services: [] };
    });

  function appendStylesheet(href, marker) {
    if (document.querySelector(`link[${marker}]`)) return;
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = fromRoot(href);
    link.setAttribute(marker, "1");
    document.head.appendChild(link);
  }

  function loadPremiumLightTheme() {
    appendStylesheet(
      "pages/premium-light.css?v=20260819-2",
      "data-hwc-premium-light",
    );
  }

  loadPremiumLightTheme();

  async function getAuthState() {
    if (!authStatePromise) {
      authStatePromise = fetch(fromRoot("session_status.php"), {
        cache: "no-store",
        credentials: "same-origin",
      })
        .then((response) =>
          response.ok ? response.json() : { authenticated: false },
        )
        .catch(() => ({ authenticated: false }));
    }
    return authStatePromise;
  }

  async function syncAuthLinks(container = document) {
    const links = container.querySelectorAll("[data-auth-link]");
    if (!links.length) return;
    const state = await getAuthState();
    links.forEach((link) => {
      if (state.authenticated) {
        link.textContent = "My Portal";
        link.setAttribute("href", fromRoot(state.portal));
        link.setAttribute(
          "aria-label",
          `Open ${state.role} portal for ${state.name}`,
        );
      } else {
        link.textContent = "Login";
        link.setAttribute("href", fromRoot("login.php"));
      }
    });
  }

  async function loadFragment(elementId, relativePath) {
    const target = document.getElementById(elementId);
    if (!target) return;

    try {
      const response = await fetch(fromRoot(relativePath), {
        cache: "no-cache",
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      target.innerHTML = await response.text();
      configureRootLinks(target);
      highlightCurrentNavigation(target);
      updateCurrentYear(target);
      syncAuthLinks(target);
      const data = await window.hwcSiteReady;
      applySiteContent(data, target);
    } catch (error) {
      console.error(`Unable to load ${relativePath}:`, error);
    }
  }

  function configureRootLinks(container) {
    container.querySelectorAll("[data-route]").forEach((element) => {
      element.setAttribute("href", fromRoot(element.dataset.route));
    });
    container.querySelectorAll("[data-asset]").forEach((element) => {
      element.setAttribute("src", fromRoot(element.dataset.asset));
    });
  }

  function highlightCurrentNavigation(container) {
    const currentPath = window.location.pathname.replace(/\/+$/, "") || "/";
    container.querySelectorAll(".nav-link[data-route]").forEach((link) => {
      const linkPath = new URL(link.href).pathname.replace(/\/+$/, "") || "/";
      if (linkPath === currentPath) {
        link.classList.add("active");
        link.setAttribute("aria-current", "page");
      }
    });
  }

  function updateCurrentYear(container = document) {
    const year = new Date().getFullYear();
    container.querySelectorAll("[data-current-year]").forEach((element) => {
      element.textContent = String(year);
    });
  }

  function contactHref(kind, value) {
    if (kind === "whatsapp")
      return `https://wa.me/${String(value || "").replace(/\D/g, "")}`;
    if (kind === "phone")
      return `tel:${String(value || "").replace(/[^+\d]/g, "")}`;
    if (kind === "email") return `mailto:${String(value || "").trim()}`;
    return "#";
  }

  function applyHomeContent(content) {
    if (!document.body.classList.contains("home-page")) return;
    const eyebrow = document.querySelector(".home-eyebrow");
    if (eyebrow && content.home_eyebrow) {
      eyebrow.replaceChildren();
      const dot = document.createElement("span");
      dot.className = "home-eyebrow-dot";
      eyebrow.append(dot, document.createTextNode(" " + content.home_eyebrow));
    }
    const title = document.querySelector(".home-hero h1");
    if (title && content.home_title) {
      const text = String(content.home_title).trim();
      const brand = String(
        content.global_brand_name || "Healthcare Wellness Club",
      ).trim();
      title.replaceChildren();
      const pos = text.toLowerCase().lastIndexOf(brand.toLowerCase());
      if (brand && pos >= 0) {
        title.append(document.createTextNode(text.slice(0, pos)));
        const accent = document.createElement("span");
        accent.textContent = text.slice(pos);
        title.appendChild(accent);
      } else title.textContent = text;
    }
    const lead = document.querySelector(".home-hero-lead");
    if (lead && content.home_lead) lead.textContent = content.home_lead;
    const primary = document.querySelector(
      ".home-hero-actions .home-primary-btn",
    );
    if (primary && content.home_primary_cta) {
      primary.replaceChildren(
        document.createTextNode(content.home_primary_cta + " "),
      );
      const arrow = document.createElement("span");
      arrow.setAttribute("aria-hidden", "true");
      arrow.textContent = "→";
      primary.appendChild(arrow);
    }
    const secondary = document.querySelector(
      ".home-hero-actions .home-secondary-btn",
    );
    if (secondary && content.home_secondary_cta)
      secondary.textContent = content.home_secondary_cta;
  }

  function applySiteContent(data, container = document) {
    const content = data && data.content ? data.content : {};
    container.querySelectorAll("[data-cms-key]").forEach((element) => {
      const value = content[element.dataset.cmsKey];
      if (typeof value === "string" && value !== "")
        element.textContent = value;
    });
    container.querySelectorAll("[data-cms-brand]").forEach((element) => {
      if (content.global_brand_name)
        element.textContent = content.global_brand_name;
    });
    container.querySelectorAll("[data-site-contact]").forEach((element) => {
      const kind = element.dataset.siteContact;
      const key =
        kind === "whatsapp"
          ? "global_whatsapp"
          : kind === "phone"
            ? "global_phone"
            : kind === "email"
              ? "global_email"
              : "global_location";
      const value = content[key];
      if (!value) return;
      if (kind === "location") element.textContent = value;
      else {
        element.setAttribute("href", contactHref(kind, value));
        if (element.hasAttribute("data-contact-text"))
          element.textContent = value;
      }
    });

    applyHomeContent(content);
    if (content.global_whatsapp) {
      document
        .querySelectorAll(
          '.floating-btns a[href*="wa.me"], .home-quick-contact a[href*="wa.me"]',
        )
        .forEach(
          (a) => (a.href = contactHref("whatsapp", content.global_whatsapp)),
        );
    }
    if (content.global_phone) {
      document
        .querySelectorAll('.floating-btns a[href^="tel:"]')
        .forEach((a) => (a.href = contactHref("phone", content.global_phone)));
    }
    document.querySelectorAll("[data-social]").forEach((link) => {
      const value = String(
        content["social_" + link.dataset.social] || "",
      ).trim();
      const safe = /^https?:\/\//i.test(value) ? value : "";
      link.hidden = !safe;
      if (safe) {
        link.href = safe;
        link.target = "_blank";
        link.rel = "noopener noreferrer";
      }
    });
    const dock = document.querySelector(".premium-action-dock");
    if (dock) {
      const whatsapp = dock.querySelector('[data-dock="whatsapp"]');
      const phone = dock.querySelector('[data-dock="phone"]');
      if (whatsapp && content.global_whatsapp)
        whatsapp.href = contactHref("whatsapp", content.global_whatsapp);
      if (phone && content.global_phone)
        phone.href = contactHref("phone", content.global_phone);
    }
    document.dispatchEvent(
      new CustomEvent("hwc-site-content", { detail: data }),
    );
  }

  function createChatbot() {
    if (document.getElementById("chat-toggle-btn")) return;
    const button = document.createElement("button");
    button.id = "chat-toggle-btn";
    button.type = "button";
    button.className = "chat-toggle-btn premium-ai-button";
    button.setAttribute("aria-label", "Open wellness chat");
    button.setAttribute("aria-expanded", "false");
    button.innerHTML = `<span class="ai-orbit" aria-hidden="true"></span><span class="ai-glow" aria-hidden="true"></span><img src="${fromRoot("chat.png")}" alt="" width="60" height="60"><span class="ai-status" aria-hidden="true"></span><span class="ai-label">AI Wellness</span>`;

    const frame = document.createElement("iframe");
    frame.id = "chatbot-frame";
    frame.className = "chatbot-frame";
    frame.src = "https://www.chatbase.co/chatbot-iframe/rWYfFJUdA4l7XbwTThmpz";
    frame.title = "Healthcare Wellness Club chat";
    frame.loading = "lazy";

    button.addEventListener("click", () => {
      const isOpen = frame.classList.toggle("is-open");
      button.setAttribute("aria-expanded", String(isOpen));
    });

    const dock = document.createElement("aside");
    dock.className = "premium-action-dock";
    dock.setAttribute("aria-label", "Quick contact and AI assistant");
    const whatsapp = document.createElement("a");
    whatsapp.dataset.dock = "whatsapp";
    whatsapp.href = "https://wa.me/918858302744";
    whatsapp.target = "_blank";
    whatsapp.rel = "noopener";
    whatsapp.className = "dock-action dock-whatsapp";
    whatsapp.innerHTML = '<span aria-hidden="true">◉</span><b>WhatsApp</b>';
    const phone = document.createElement("a");
    phone.dataset.dock = "phone";
    phone.href = "tel:+915483561586";
    phone.className = "dock-action dock-phone";
    phone.innerHTML = '<span aria-hidden="true">☎</span><b>Call Now</b>';
    dock.append(button, whatsapp, phone);
    document
      .querySelectorAll(".floating-btns,.home-quick-contact")
      .forEach((element) => (element.hidden = true));
    document.body.appendChild(dock);
    document.body.appendChild(frame);
  }

  document.addEventListener("DOMContentLoaded", async () => {
    if (document.body.classList.contains("stories-page"))
      appendStylesheet(
        "pages/stories.css?v=20260819-1",
        "data-hwc-stories-theme",
      );
    configureRootLinks(document);
    updateCurrentYear(document);
    syncAuthLinks(document);
    loadFragment("navbar-placeholder", "pages/navbar.html");
    loadFragment("footer-placeholder", "pages/footer.html");
    createChatbot();
    const data = await window.hwcSiteReady;
    applySiteContent(data, document);
  });
})();
