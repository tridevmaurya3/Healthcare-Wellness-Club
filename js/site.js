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
      "pages/premium-light.css?v=20260820-8",
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
    const aiPanel = document.querySelector(".chatbot-panel");
    const dock = document.querySelector(".premium-action-dock");
    if (dock) {
      const whatsapp = dock.querySelector('[data-dock="whatsapp"]');
      const phone = dock.querySelector('[data-dock="phone"]');
      if (whatsapp && content.global_whatsapp)
        whatsapp.href = contactHref("whatsapp", content.global_whatsapp);
      if (phone && content.global_phone)
        phone.href = contactHref("phone", content.global_phone);
      const handoff = aiPanel.querySelector("[data-ai-whatsapp]");
      if (handoff && content.global_whatsapp)
        handoff.href =
          contactHref("whatsapp", content.global_whatsapp) +
          "?text=" +
          encodeURIComponent(
            "Hello, I need help from Healthcare Wellness Club.",
          );
    }
    if (aiPanel) {
      const safeUrl = /^https:\/\//i.test(
        String(content.global_ai_chat_url || ""),
      )
        ? String(content.global_ai_chat_url).trim()
        : "";
      const name = content.global_ai_name || "HWC AI";
      const title = content.global_ai_title || "Wellness Assistant";
      const welcome =
        content.global_ai_welcome ||
        "Ask about club services, membership, products and general wellness support.";
      aiPanel.querySelector("[data-ai-name]").textContent = name;
      aiPanel.querySelector("[data-ai-title]").textContent = title;
      aiPanel.querySelector("[data-ai-welcome]").textContent = welcome;
      aiPanel.querySelector("[data-ai-handoff]").textContent =
        content.global_ai_handoff_text ||
        "Continue with a person on WhatsApp →";
      const frame = aiPanel.querySelector("#chatbot-frame");
      if (safeUrl && frame.src !== safeUrl) frame.src = safeUrl;
      document.querySelector(".premium-ai-button .ai-label").textContent = name;
    }
    document.dispatchEvent(
      new CustomEvent("hwc-site-content", { detail: data }),
    );
  }

  function money(value) {
    return new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", maximumFractionDigits: 2 }).format(Number(value) || 0);
  }

  function createCompactCombo(data) {
    if (document.querySelector(".public-combo-strip")) return;
    const content = data?.content || {};
    const products = Array.isArray(data?.combo?.products) ? data.combo.products : [];
    if (content.combo_enabled !== "1" || products.length < 2) return;
    const card = document.createElement("section");
    card.className = "public-combo-strip";
    card.setAttribute("aria-label", "Featured product combo offer");
    const images = products.slice(0, 5).map((product) => {
      const raw = String(product.image_url || "").replace(/^\.\.\//, "");
      return raw ? `<img src="${fromRoot(raw)}" alt="${String(product.product_name || "Product").replace(/[&<>\"]/g, "")}">` : `<span>HWC</span>`;
    }).join("");
    card.innerHTML = `<div class="public-combo-images">${images}${products.length > 5 ? `<b>+${products.length - 5}</b>` : ""}</div><div class="public-combo-copy"><small>${content.combo_badge || `${data.combo.discount_percent}% COMBO OFFER`}</small><strong>${content.combo_title || "Featured Wellness Combo"}</strong><span>${products.length} selected products • <s>${money(data.combo.mrp_total)}</s> <b>${money(data.combo.offer_total)}</b></span></div><a href="${fromRoot("shop/index.php#combo-title")}">View Combo →</a>`;
    const footer = document.getElementById("footer-placeholder") || document.querySelector("footer");
    (footer?.parentNode || document.body).insertBefore(card, footer || null);
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

    const backdrop = document.createElement("div");
    backdrop.className = "chatbot-backdrop";
    backdrop.hidden = true;
    const panel = document.createElement("section");
    panel.className = "chatbot-panel";
    panel.hidden = true;
    panel.setAttribute("aria-label", "AI Wellness Assistant");
    panel.setAttribute("aria-hidden", "true");
    panel.innerHTML = `<header class="chatbot-panel-head"><div class="chatbot-panel-brand"><img src="${fromRoot("chat.png")}" alt=""><span><small data-ai-name>HWC AI</small><strong data-ai-title>Wellness Assistant</strong></span></div><div class="chatbot-window-actions"><button type="button" class="chatbot-maximize" aria-label="Maximize AI assistant" title="Maximize or restore">□</button><button type="button" class="chatbot-close" aria-label="Close AI assistant">×</button></div></header><div class="chatbot-panel-intro"><span>ONLINE • SECURE ASSISTANT</span><p data-ai-welcome>Ask about club services, membership, products and general wellness support.</p><nav aria-label="AI quick topics"><a href="${fromRoot("pages/services.html")}">Services</a><a href="${fromRoot("membership.php")}">Membership</a><a href="${fromRoot("shop/index.php")}">Products</a><a href="${fromRoot("pages/contact.html")}?type=wellness">Ask a question</a></nav><div class="chatbot-handoff"><a data-ai-whatsapp data-ai-handoff href="https://wa.me/918858302744" target="_blank" rel="noopener">Continue with a person on WhatsApp →</a></div></div><span class="chatbot-resize-hint">Drag corner to resize</span>`;
    const frame = document.createElement("iframe");
    frame.id = "chatbot-frame";
    frame.className = "chatbot-frame";
    frame.src = "https://www.chatbase.co/chatbot-iframe/rWYfFJUdA4l7XbwTThmpz";
    frame.title = "Healthcare Wellness Club chat";
    frame.loading = "lazy";

    const feedback = document.createElement("form");
    feedback.className = "chatbot-feedback";
    feedback.innerHTML = `<p>Did you get a useful answer?</p><div><button type="button" data-ai-helpful>Yes, helpful</button><button type="button" data-ai-not-helpful>No, report question</button><button type="button" data-ai-risk>Unsafe or inaccurate</button></div><label hidden><span data-ai-report-label>What question was not answered?</span><textarea maxlength="1000" required></textarea><button type="submit">Send for review</button></label><small aria-live="polite"></small>`;
    let aiSession = sessionStorage.getItem("hwc_ai_session");
    if (!aiSession) {
      aiSession = crypto.randomUUID ? crypto.randomUUID() : "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0, v = c === "x" ? r : (r & 3 | 8); return v.toString(16); });
      sessionStorage.setItem("hwc_ai_session", aiSession);
    }
    const track = (event_type, extra = {}) => {
      const body = JSON.stringify({ event_type, session_token: aiSession, page_path: location.pathname, ...extra });
      if (navigator.sendBeacon) navigator.sendBeacon(fromRoot("public_ai_event.php"), new Blob([body], { type: "application/json" }));
      else fetch(fromRoot("public_ai_event.php"), { method: "POST", headers: { "Content-Type": "application/json" }, body, keepalive: true }).catch(() => {});
    };
    panel.querySelectorAll(".chatbot-panel-intro nav a").forEach((link) => link.addEventListener("click", () => track("topic_click", { topic: link.textContent.trim() })));
    panel.querySelector("[data-ai-handoff]").addEventListener("click", () => track("human_handoff"));
    feedback.querySelector("[data-ai-helpful]").addEventListener("click", () => { track("answer_helpful"); feedback.querySelector("small").textContent = "Thank you for your feedback."; });
    let reportMode = "answer_unanswered";
    feedback.querySelector("[data-ai-not-helpful]").addEventListener("click", () => { reportMode = "answer_unanswered"; feedback.querySelector("[data-ai-report-label]").textContent = "What question was not answered?"; feedback.querySelector("label").hidden = false; feedback.querySelector("textarea").focus(); });
    feedback.querySelector("[data-ai-risk]").addEventListener("click", () => { reportMode = "risk_report"; feedback.querySelector("[data-ai-report-label]").textContent = "Briefly describe the unsafe or inaccurate answer"; feedback.querySelector("label").hidden = false; feedback.querySelector("textarea").focus(); });
    feedback.addEventListener("submit", (event) => { event.preventDefault(); const question = feedback.querySelector("textarea").value.trim(); if (question.length < 3) return; track(reportMode, { question }); feedback.reset(); feedback.querySelector("label").hidden = true; feedback.querySelector("small").textContent = reportMode === "risk_report" ? "Safety report saved for priority review." : "Saved for the club team to improve."; });

    const closeChat = () => {
      track("panel_close");
      panel.classList.remove("is-open");
      backdrop.classList.remove("is-open");
      backdrop.hidden = true;
      panel.setAttribute("aria-hidden", "true");
      panel.hidden = true;
      button.setAttribute("aria-expanded", "false");
      document.body.classList.remove("chatbot-open");
      button.focus({ preventScroll: true });
    };
    const openChat = () => {
      track("panel_open");
      panel.hidden = false;
      backdrop.hidden = false;
      window.requestAnimationFrame(() => {
        panel.classList.add("is-open");
        backdrop.classList.add("is-open");
      });
      panel.setAttribute("aria-hidden", "false");
      button.setAttribute("aria-expanded", "true");
      document.body.classList.add("chatbot-open");
      panel.querySelector(".chatbot-close").focus({ preventScroll: true });
    };
    button.addEventListener("click", () =>
      panel.classList.contains("is-open") ? closeChat() : openChat(),
    );
    backdrop.addEventListener("click", closeChat);
    panel.querySelector(".chatbot-close").addEventListener("click", closeChat);
    const maximize = panel.querySelector(".chatbot-maximize");
    maximize.addEventListener("click", () => {
      const expanded = panel.classList.toggle("is-maximized");
      maximize.textContent = expanded ? "❐" : "□";
      maximize.setAttribute(
        "aria-label",
        expanded ? "Restore AI assistant" : "Maximize AI assistant",
      );
      panel.style.left = "";
      panel.style.top = "";
    });
    const dragHandle = panel.querySelector(".chatbot-panel-head");
    dragHandle.addEventListener("pointerdown", (event) => {
      if (
        event.target.closest("button") ||
        panel.classList.contains("is-maximized") ||
        window.innerWidth <= 600
      )
        return;
      const rect = panel.getBoundingClientRect(),
        startX = event.clientX,
        startY = event.clientY;
      panel.style.left = rect.left + "px";
      panel.style.top = rect.top + "px";
      panel.style.right = "auto";
      panel.style.bottom = "auto";
      dragHandle.setPointerCapture(event.pointerId);
      panel.classList.add("is-dragging");
      const move = (moveEvent) => {
        const maxX = Math.max(0, window.innerWidth - panel.offsetWidth),
          maxY = Math.max(0, window.innerHeight - panel.offsetHeight);
        panel.style.left =
          Math.min(maxX, Math.max(0, rect.left + moveEvent.clientX - startX)) +
          "px";
        panel.style.top =
          Math.min(maxY, Math.max(0, rect.top + moveEvent.clientY - startY)) +
          "px";
      };
      const end = () => {
        panel.classList.remove("is-dragging");
        dragHandle.removeEventListener("pointermove", move);
        dragHandle.removeEventListener("pointerup", end);
        dragHandle.removeEventListener("pointercancel", end);
      };
      dragHandle.addEventListener("pointermove", move);
      dragHandle.addEventListener("pointerup", end);
      dragHandle.addEventListener("pointercancel", end);
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && panel.classList.contains("is-open"))
        closeChat();
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
    whatsapp.innerHTML =
      '<span aria-hidden="true">📱</span><b>Chat on WhatsApp</b>';
    const phone = document.createElement("a");
    phone.dataset.dock = "phone";
    phone.href = "tel:+915483561586";
    phone.className = "dock-action dock-phone";
    phone.innerHTML = '<span aria-hidden="true">📞</span><b>Call Now</b>';
    dock.append(button, whatsapp, phone);
    document
      .querySelectorAll(".floating-btns,.home-quick-contact")
      .forEach((element) => (element.hidden = true));
    panel.append(frame, feedback);
    document.body.append(dock, backdrop, panel);
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
    createCompactCombo(data);
  });
})();
