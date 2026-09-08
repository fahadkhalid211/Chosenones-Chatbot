(function () {
  "use strict";

  var STATE = {
    videos: [],
    extractor: null,
    ready: false,
    maxResults: 6,
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

  /* ── Modal ── */
  var modalControlsTimer = null;

  function buildModal() {
    if ($("vsc-modal")) return;

    var overlay = document.createElement("div");
    overlay.id = "vsc-modal";
    overlay.innerHTML =
      '<button id="vsc-modal-close" aria-label="Close">&#x2715;</button>' +
      '<div id="vsc-modal-box">' +
        '<div id="vsc-modal-player">' +
          '<div id="vsc-modal-controls">' +
            '<button id="vsc-ctrl-play" aria-label="Play / Pause">&#9654;</button>' +
            '<div id="vsc-ctrl-progress"><div id="vsc-ctrl-bar"></div></div>' +
            '<button id="vsc-ctrl-fs" aria-label="Fullscreen">&#x26F6;</button>' +
          '</div>' +
        '</div>' +
        '<div id="vsc-modal-title"></div>' +
      '</div>';

    document.body.appendChild(overlay);

    function closeModal() {
      overlay.classList.remove("vsc-modal-open");
      $("vsc-modal-player").innerHTML =
        '<div id="vsc-modal-controls">' +
          '<button id="vsc-ctrl-play" aria-label="Play / Pause">&#9654;</button>' +
          '<div id="vsc-ctrl-progress"><div id="vsc-ctrl-bar"></div></div>' +
          '<button id="vsc-ctrl-fs" aria-label="Fullscreen">&#x26F6;</button>' +
        '</div>';
      if (modalControlsTimer) clearTimeout(modalControlsTimer);
    }

    $("vsc-modal-close").addEventListener("click", closeModal);
    overlay.addEventListener("click", function (e) {
      if (e.target === overlay) closeModal();
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") closeModal();
    });
    overlay.addEventListener("touchstart", function () {
      showControls();
    }, { passive: true });
  }

  function showControls() {
    var ctrl = $("vsc-modal-controls");
    if (!ctrl) return;
    ctrl.classList.add("vsc-controls-visible");
    if (modalControlsTimer) clearTimeout(modalControlsTimer);
    modalControlsTimer = setTimeout(function () {
      ctrl.classList.remove("vsc-controls-visible");
    }, 2000);
  }

  function openModal(fileId, title) {
    buildModal();
    $("vsc-modal-title").textContent = title || "";
    var player = $("vsc-modal-player");
    var existingCtrl = $("vsc-modal-controls");
    var iframe = document.createElement("iframe");
    iframe.src = "https://drive.google.com/file/d/" + encodeURIComponent(fileId) + "/preview";
    iframe.setAttribute("allow", "autoplay; fullscreen");
    iframe.setAttribute("allowfullscreen", "");
    iframe.setAttribute("frameborder", "0");
    player.insertBefore(iframe, existingCtrl);
    $("vsc-modal").classList.add("vsc-modal-open");
    showControls();
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
  }

  /* ── Init ── */
  function init() {
    var app = $("vsc-app");
    if (!app) return;

    STATE.maxResults = parseInt(app.getAttribute("data-max-results"), 10) || 6;
    buildModal();
    setStatus("Loading video library...");

    fetch(VSC_CONFIG.dataUrl)
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
          'Hi! I can search by meaning \u2014 not just keywords. ' +
          'Try typing a topic like <em>\u201cfaith\u201d</em>, <em>\u201cforgiveness\u201d</em>, or <em>\u201cprayer\u201d</em>.',
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
      if (!q) return;
      input.value = "";
      handleSearch(q);
    }
    send.addEventListener("click", trigger);
    input.addEventListener("keydown", function (e) { if (e.key === "Enter") trigger(); });
  }

  /* ── Search ── */
  function handleSearch(query) {
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