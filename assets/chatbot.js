(function () {
  "use strict";

  var STATE = {
    videos: [],
    extractor: null,
    ready: false,
    maxResults: 3,
    isLoggedIn: false,
    isMember: false,
    searchesLeft: 0,
    freeLimit: 1,
    membershipUrl: "",
    loginUrl: "",
    popupTitle: "Unlock Unlimited Searches",
    popupMessage: "You've used your 1 free search. Join our membership today for unlimited access to all video teachings and search features.",
    popupButtonText: "Join Membership Now",
    ajaxUrl: "",
    nonce: "",
  };

  var THINKING_PHRASES = [
    "Searching through all videos...",
    "Analysing semantic meaning...",
    "Finding the closest matches...",
    "Almost there...",
  ];

  function $(id) { return document.getElementById(id); }

  function escapeHtml(str) {
    var div = document.createElement("div");
    div.textContent = str == null ? "" : str;
    return div.innerHTML;
  }

  /* ── Video Player Modal ── */
  function buildModal() {
    if ($("vsc-modal")) return;

    var overlay = document.createElement("div");
    overlay.id = "vsc-modal";
    overlay.innerHTML =
      '<button id="vsc-modal-close" aria-label="Close">&#x2715;</button>' +
      '<div id="vsc-modal-box">' +
        '<div id="vsc-modal-player"></div>' +
        '<div id="vsc-modal-title"></div>' +
      '</div>';

    document.body.appendChild(overlay);

    function closeModal() {
      overlay.classList.remove("vsc-modal-open");
      $("vsc-modal-player").innerHTML = "";
    }

    $("vsc-modal-close").addEventListener("click", closeModal);
    overlay.addEventListener("click", function (e) {
      if (e.target === overlay) closeModal();
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && overlay.classList.contains("vsc-modal-open")) {
        closeModal();
      }
    });
  }

  function openModal(fileId, title) {
    buildModal();
    $("vsc-modal-title").textContent = title || "";
    var iframe = document.createElement("iframe");
    iframe.src = "https://drive.google.com/file/d/" + encodeURIComponent(fileId) + "/preview";
    iframe.setAttribute("allow", "autoplay; fullscreen");
    iframe.setAttribute("allowfullscreen", "");
    iframe.setAttribute("frameborder", "0");
    var player = $("vsc-modal-player");
    player.innerHTML = "";
    player.appendChild(iframe);
    $("vsc-modal").classList.add("vsc-modal-open");
  }

  /* ── Membership CTA Popup Modal ── */
  function buildMembershipModal() {
    if ($("vsc-membership-modal")) return;

    var overlay = document.createElement("div");
    overlay.id = "vsc-membership-modal";
    overlay.className = "vsc-membership-overlay";
    overlay.innerHTML =
      '<div class="vsc-membership-card" role="dialog" aria-modal="true">' +
        '<button class="vsc-membership-close" id="vsc-membership-close" aria-label="Close modal">&times;</button>' +
        '<div class="vsc-membership-badge-wrap">' +
          '<div class="vsc-membership-icon">' +
            '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
              '<path d="M6 3h12l4 6-10 12L2 9z"/>' +
              '<path d="M11 3L8 9l4 12 4-12-3-6"/>' +
            '</svg>' +
          '</div>' +
        '</div>' +
        '<h2 class="vsc-membership-title" id="vsc-membership-title">Unlock Unlimited Searches</h2>' +
        '<p class="vsc-membership-sub" id="vsc-membership-sub"></p>' +
        '<div class="vsc-membership-perks">' +
          '<div class="vsc-perk">' +
            '<span class="vsc-perk-icon">&#10003;</span>' +
            '<span><strong>Unlimited searches</strong> across the complete video library</span>' +
          '</div>' +
          '<div class="vsc-perk">' +
            '<span class="vsc-perk-icon">&#10003;</span>' +
            '<span><strong>Instant AI matching</strong> by topic, scripture, and meaning</span>' +
          '</div>' +
          '<div class="vsc-perk">' +
            '<span class="vsc-perk-icon">&#10003;</span>' +
            '<span><strong>Full-length player</strong> with immediate playback</span>' +
          '</div>' +
        '</div>' +
        '<div class="vsc-membership-actions">' +
          '<a id="vsc-membership-cta" href="#" class="vsc-membership-btn">Join Membership Now &rarr;</a>' +
          '<div id="vsc-membership-secondary" class="vsc-membership-secondary"></div>' +
        '</div>' +
      '</div>';

    document.body.appendChild(overlay);

    function closeModal() {
      overlay.classList.remove("vsc-modal-active");
    }

    $("vsc-membership-close").addEventListener("click", closeModal);
    overlay.addEventListener("click", function (e) {
      if (e.target === overlay) closeModal();
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && overlay.classList.contains("vsc-modal-active")) {
        closeModal();
      }
    });
  }

  function openMembershipModal(reason, query) {
    buildMembershipModal();

    var titleEl = $("vsc-membership-title");
    var subEl = $("vsc-membership-sub");
    var ctaEl = $("vsc-membership-cta");
    var secEl = $("vsc-membership-secondary");
    var overlay = $("vsc-membership-modal");

    ctaEl.href = STATE.membershipUrl || "#";
    ctaEl.textContent = (STATE.popupButtonText || "Join Membership Now") + " \u2192";

    if (reason === "guest") {
      titleEl.textContent = "Membership Required";
      if (query) {
        subEl.innerHTML = "Log in to search for <em>&ldquo;" + escapeHtml(query) + "&rdquo;</em> with your free search, or join our membership for unlimited access!";
      } else {
        subEl.textContent = "Please log in to your account to use your 1 free search, or join our membership for unlimited access to the entire video library.";
      }
      secEl.innerHTML = 'Already a member? <a href="' + escapeHtml(STATE.loginUrl || "#") + '">Log in here</a>';
    } else {
      // limit_reached
      titleEl.textContent = STATE.popupTitle || "You've Used Your Free Search";
      if (query) {
        subEl.innerHTML = "To search for <em>&ldquo;" + escapeHtml(query) + "&rdquo;</em> and get unlimited access to all videos, join our membership today!";
      } else {
        subEl.textContent = STATE.popupMessage || "To continue searching Amato The Mentor's complete video library with unlimited access, join our membership today!";
      }
      secEl.innerHTML = '<a href="' + escapeHtml(STATE.membershipUrl || "#") + '">View membership levels &amp; pricing</a>';
    }

    overlay.classList.add("vsc-modal-active");
  }

  /* ── Record Search Usage via WordPress AJAX ── */
  function recordSearchUsage() {
    if (!STATE.ajaxUrl || !STATE.nonce) return;

    var params = new URLSearchParams();
    params.append("action", "vsc_record_search");
    params.append("nonce", STATE.nonce);

    fetch(STATE.ajaxUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: params.toString(),
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data && data.success && data.data) {
          if (!data.data.unlimited) {
            STATE.searchesLeft = data.data.searches_left;
            updateBannerUI();
          }
        }
      })
      .catch(function (err) {
        console.warn("VSC: Search recording notice:", err);
      });
  }

  /* ── Update Banner Bar in DOM ── */
  function updateBannerUI() {
    var banner = document.querySelector(".vsc-banner-bar");
    if (!banner) return;

    if (STATE.isMember) {
      banner.style.display = "none";
      return;
    }

    if (STATE.isLoggedIn) {
      if (STATE.searchesLeft <= 0) {
        banner.className = "vsc-banner-bar vsc-banner-warning";
        banner.innerHTML =
          '<span>Free search limit reached (1/1).</span> ' +
          '<a href="' + escapeHtml(STATE.membershipUrl) + '" class="vsc-banner-link">Join Membership for Unlimited Searches &rarr;</a>';
      } else {
        banner.className = "vsc-banner-bar vsc-banner-info";
        banner.innerHTML =
          '<span>You have <strong>1 free search</strong> available.</span> ' +
          '<a href="' + escapeHtml(STATE.membershipUrl) + '" class="vsc-banner-link">Join Membership for Unlimited &rarr;</a>';
      }
    }
  }

  /* ── Messages ── */
  function addMessage(html, role) {
    var wrap = document.createElement("div");
    wrap.className = "vsc-msg vsc-msg-" + role;
    wrap.innerHTML = html;
    var container = $("vsc-messages");
    container.appendChild(wrap);
    setTimeout(function () { container.scrollTop = container.scrollHeight; }, 30);
    return wrap;
  }

  function setStatus(text) {
    var el = $("vsc-status");
    if (el) el.textContent = text || "";
  }

  /* ── Thinking bubble ── */
  function showThinking() {
    var wrap = document.createElement("div");
    wrap.className = "vsc-msg vsc-msg-bot vsc-thinking-wrap";
    wrap.innerHTML =
      '<span class="vsc-thinking-text">' + THINKING_PHRASES[0] + '</span>' +
      '<span class="vsc-dots"><span></span><span></span><span></span></span>';
    var container = $("vsc-messages");
    container.appendChild(wrap);
    container.scrollTop = container.scrollHeight;

    var idx = 0;
    var textEl = wrap.querySelector(".vsc-thinking-text");
    var interval = setInterval(function () {
      idx = (idx + 1) % THINKING_PHRASES.length;
      textEl.textContent = THINKING_PHRASES[idx];
    }, 900);

    return {
      stop: function () {
        clearInterval(interval);
        if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
      }
    };
  }

  /* ── Cosine similarity ── */
  function cosineSim(a, b) {
    var dot = 0, na = 0, nb = 0;
    var len = Math.min(a.length, b.length);
    for (var i = 0; i < len; i++) {
      dot += a[i] * b[i];
      na  += a[i] * a[i];
      nb  += b[i] * b[i];
    }
    return dot / (Math.sqrt(na) * Math.sqrt(nb) + 1e-8);
  }

  /* ── Render results ── */
  function renderResults(results) {
    if (!results.length) {
      addMessage("<p>No matching videos found. Try a different word or phrase.</p>", "bot");
      return;
    }

    var wrap = document.createElement("div");
    wrap.className = "vsc-msg vsc-msg-bot";

    var intro = document.createElement("p");
    intro.textContent = "Here are the closest matches:";
    wrap.appendChild(intro);

    var grid = document.createElement("div");
    grid.className = "vsc-results";

    results.forEach(function (r, i) {
      var card = document.createElement("div");
      card.className = "vsc-result";
      card.style.animationDelay = (i * 80) + "ms";

      var thumbUrl = "https://drive.google.com/thumbnail?id=" + encodeURIComponent(r.id) + "&sz=w400";

      card.innerHTML =
        '<div class="vsc-thumb-wrap">' +
          '<img class="vsc-thumb" src="' + thumbUrl + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">' +
          '<div class="vsc-play-btn">&#9654;</div>' +
          '<div class="vsc-score-badge">' + Math.round(r.score * 100) + '% match</div>' +
        '</div>' +
        '<div class="vsc-result-info">' +
          '<div class="vsc-result-title">' + escapeHtml(r.title || "Untitled") + '</div>' +
          '<div class="vsc-result-excerpt">' + escapeHtml((r.excerpt || "").slice(0, 100)) + (r.excerpt && r.excerpt.length > 100 ? "\u2026" : "") + '</div>' +
        '</div>';

      card.addEventListener("click", function () { openModal(r.id, r.title); });
      grid.appendChild(card);
    });

    wrap.appendChild(grid);
    var container = $("vsc-messages");
    container.appendChild(wrap);
    setTimeout(function () { container.scrollTop = container.scrollHeight; }, 30);

    // If logged-in non-member who has now used their free search, show in-chat CTA card
    if (!STATE.isMember && STATE.isLoggedIn && STATE.searchesLeft <= 0) {
      setTimeout(function () {
        var ctaCard = document.createElement("div");
        ctaCard.className = "vsc-msg vsc-msg-bot vsc-chat-cta-wrap";
        ctaCard.innerHTML =
          '<div class="vsc-chat-cta">' +
            '<div class="vsc-chat-cta-badge">Free search used (1/1)</div>' +
            '<h4 class="vsc-chat-cta-title">Enjoyed searching the library?</h4>' +
            '<p class="vsc-chat-cta-text">You\'ve used your 1 free search. Join our membership for unlimited access to all videos and semantic search features!</p>' +
            '<a href="' + escapeHtml(STATE.membershipUrl) + '" class="vsc-chat-cta-btn">' + escapeHtml(STATE.popupButtonText || "Join Membership Now") + ' &rarr;</a>' +
          '</div>';
        container.appendChild(ctaCard);
        container.scrollTop = container.scrollHeight;
      }, 500);
    }
  }

  /* ── Init ── */
  function init() {
    var app = $("vsc-app");
    if (!app) return;

    // Load configuration
    var cfg = window.VSC_CONFIG || {};
    STATE.maxResults     = parseInt(app.getAttribute("data-max-results"), 10) || 3;
    STATE.isLoggedIn     = cfg.isLoggedIn != null ? Boolean(cfg.isLoggedIn) : (app.getAttribute("data-logged-in") === "1");
    STATE.isMember       = cfg.isMember != null ? Boolean(cfg.isMember) : (app.getAttribute("data-is-member") === "1");
    STATE.searchesLeft   = cfg.searchesLeft != null ? parseInt(cfg.searchesLeft, 10) : (parseInt(app.getAttribute("data-searches-left"), 10) || 0);
    STATE.freeLimit      = cfg.freeLimit != null ? parseInt(cfg.freeLimit, 10) : (parseInt(app.getAttribute("data-free-limit"), 10) || 1);
    STATE.membershipUrl  = cfg.membershipUrl || app.getAttribute("data-membership-url") || "";
    STATE.loginUrl       = cfg.loginUrl || app.getAttribute("data-login-url") || "";
    STATE.popupTitle     = cfg.popupTitle || app.getAttribute("data-popup-title") || "Unlock Unlimited Searches";
    STATE.popupMessage   = cfg.popupMessage || app.getAttribute("data-popup-message") || "";
    STATE.popupButtonText = cfg.popupButtonText || app.getAttribute("data-popup-button") || "Join Membership Now";
    STATE.ajaxUrl        = cfg.ajaxUrl || "";
    STATE.nonce          = cfg.nonce || "";

    buildModal();
    buildMembershipModal();

    setStatus("Loading video library...");

    var dataUrl = cfg.dataUrl || "assets/data.json";
    fetch(dataUrl)
      .then(function (res) { return res.json(); })
      .then(function (videos) {
        STATE.videos = Array.isArray(videos) ? videos : [];
        if (!STATE.videos.length) {
          setStatus("");
          addMessage("<p>No video data loaded yet. Run the processing pipeline first.</p>", "bot");
          return;
        }
        setStatus("Loading search model (first visit only, then cached)\u2026");
        return import("https://cdn.jsdelivr.net/npm/@xenova/transformers@2.17.2")
          .then(function (mod) {
            return mod.pipeline("feature-extraction", "Xenova/all-MiniLM-L6-v2");
          });
      })
      .then(function (extractor) {
        if (!extractor) return;
        STATE.extractor = extractor;
        STATE.ready = true;
        setStatus("");
        addMessage(
          "Hi! You can search all of \u201cAmato The Mentor\u2019s\u201d videos by meaning \u2014 not just keywords. " +
          "Try typing a topic like <em>\u201cfaith\u201d</em>, <em>\u201cforgiveness\u201d</em>, or <em>\u201cprayer\u201d</em>.",
          "bot"
        );
      })
      .catch(function (err) {
        setStatus("");
        addMessage("<p>Error loading search engine: " + escapeHtml(err.message) + "</p>", "bot");
      });

    var input = $("vsc-input");
    var send  = $("vsc-send");

    function trigger() {
      var q = input.value.trim();
      if (!q) {
        // If empty input but limit reached or guest, show popup on button click
        if (!STATE.isMember) {
          if (!STATE.isLoggedIn) {
            openMembershipModal("guest", "");
            return;
          }
          if (STATE.searchesLeft <= 0) {
            openMembershipModal("limit_reached", "");
            return;
          }
        }
        return;
      }
      input.value = "";
      handleSearch(q);
    }

    send.addEventListener("click", trigger);
    input.addEventListener("keydown", function (e) {
      if (e.key === "Enter") trigger();
    });
  }

  /* ── Search Handler ── */
  function handleSearch(query) {
    // 1. Check Membership / Search Limit Restrictions
    if (!STATE.isMember) {
      // Guest: must log in or join
      if (!STATE.isLoggedIn) {
        openMembershipModal("guest", query);
        addMessage(escapeHtml(query), "user");
        addMessage(
          "<p>🔒 <strong>Membership Required:</strong> Please <a href=\"" + escapeHtml(STATE.loginUrl) + "\">log in</a> to use your 1 free search, or <a href=\"" + escapeHtml(STATE.membershipUrl) + "\">join our membership</a> for unlimited video searches.</p>",
          "bot"
        );
        return;
      }

      // Logged in non-member with 0 searches left
      if (STATE.searchesLeft <= 0) {
        openMembershipModal("limit_reached", query);
        addMessage(escapeHtml(query), "user");
        addMessage(
          "<p>🔒 <strong>Search Limit Reached:</strong> You have used your 1 free search. <a href=\"" + escapeHtml(STATE.membershipUrl) + "\" class=\"vsc-in-msg-btn\">Join Membership for Unlimited Searches &rarr;</a></p>",
          "bot"
        );
        return;
      }

      // First search for logged-in non-member: consume the search
      STATE.searchesLeft = Math.max(0, STATE.searchesLeft - 1);
      recordSearchUsage();
      updateBannerUI();
    }

    // 2. Execute Search
    addMessage(escapeHtml(query), "user");

    if (!STATE.ready) {
      addMessage("Still loading the search model \u2014 please wait a moment and try again.", "bot");
      return;
    }

    var thinking = showThinking();
    var minThink = new Promise(function (res) { setTimeout(res, 1800); });

    STATE.extractor(query, { pooling: "mean", normalize: true })
      .then(function (output) {
        var queryVec = Array.from(output.data);
        var scored = STATE.videos.map(function (v) {
          return { id: v.id, title: v.title, excerpt: v.excerpt, score: cosineSim(queryVec, v.embedding) };
        });
        scored.sort(function (a, b) { return b.score - a.score; });
        return { scored: scored };
      })
      .then(function (data) {
        return minThink.then(function () { return data; });
      })
      .then(function (data) {
        thinking.stop();
        setStatus("");
        renderResults(data.scored.slice(0, STATE.maxResults));
      })
      .catch(function (err) {
        thinking.stop();
        setStatus("");
        addMessage("<p>Search error: " + escapeHtml(err.message) + "</p>", "bot");
      });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();