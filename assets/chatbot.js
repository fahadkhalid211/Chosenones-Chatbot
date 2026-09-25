(function () {
  "use strict";

  var STATE = {
    videos: [],
    extractor: null,
    ready: false,
    maxResults: 3,
    isLoggedIn: false,
    isMember: false,
    isUnlimited: false,
    searchesLeft: 0,
    allowedLimit: 1,
    usedInCycle: 0,
    freeLimit: 1,
    memberLimit: 10,
    periodHours: 24,
    secondsUntilReset: 0,
    resetTimestamp: 0,
    resetTimeFormatted: "",
    timerInterval: null,
    membershipUrl: "",
    loginUrl: "",
    popupTitle: "Daily Free Search Received",
    popupMessage: "You have received your daily free video search. Monthly Subscribers receive multiple daily searches.",
    popupButtonText: "Subscribe for More Searches",
    ajaxUrl: "",
    nonce: "",
    mediaVideosUrl: "",
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

  /* ── Format Seconds into Countdown String (e.g. 14h 23m 10s) ── */
  function formatCountdown(seconds) {
    if (seconds <= 0) return "00m 00s";
    var h = Math.floor(seconds / 3600);
    var m = Math.floor((seconds % 3600) / 60);
    var s = seconds % 60;
    var mStr = (m < 10 ? "0" + m : m) + "m";
    var sStr = (s < 10 ? "0" + s : s) + "s";
    if (h > 0) {
      return h + "h " + mStr + " " + sStr;
    }
    return mStr + " " + sStr;
  }

  /* ── Live 24-Hour Reset Countdown Timer ── */
  function startResetTimer() {
    if (STATE.timerInterval) {
      clearInterval(STATE.timerInterval);
      STATE.timerInterval = null;
    }

    if (STATE.isUnlimited || !STATE.resetTimestamp) {
      return;
    }

    function tick() {
      var remainingSec = Math.max(0, Math.floor((STATE.resetTimestamp - Date.now()) / 1000));
      var formatted = formatCountdown(remainingSec);

      // Update all countdown elements in DOM
      var displays = document.querySelectorAll(".vsc-timer-display");
      displays.forEach(function (el) {
        el.textContent = formatted;
      });

      var modalTimer = $("vsc-modal-timer");
      if (modalTimer) {
        modalTimer.textContent = formatted;
      }

      var inlineTimers = document.querySelectorAll(".vsc-timer-inline");
      inlineTimers.forEach(function (el) {
        el.textContent = formatted;
      });

      // 24-hour cycle completed while user is on page!
      if (remainingSec <= 0) {
        clearInterval(STATE.timerInterval);
        STATE.timerInterval = null;
        STATE.resetTimestamp = 0;
        STATE.secondsUntilReset = 0;
        STATE.usedInCycle = 0;

        if (STATE.isMember) {
          STATE.searchesLeft = STATE.memberLimit;
          updateBannerUI();
          addMessage(
            "<p>✨ <strong>Good news!</strong> Your daily subscriber searches have reset. You now have " +
            escapeHtml(String(STATE.memberLimit)) + " searches available today!</p>",
            "bot"
          );
        } else {
          STATE.searchesLeft = STATE.freeLimit;
          updateBannerUI();
          addMessage(
            "<p>✨ <strong>Good news!</strong> Your daily free search has reset. You now have 1 free search available!</p>",
            "bot"
          );
        }
      }
    }

    tick();
    STATE.timerInterval = setInterval(tick, 1000);
  }

  /* ── Video Player Modal (Supports Google Drive & WordPress Media Library HTML5) ── */
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
      var player = $("vsc-modal-player");
      var activeVid = player ? player.querySelector("video") : null;
      if (activeVid) activeVid.pause();
      if (player) player.innerHTML = "";
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

  function openModal(video, title) {
    buildModal();

    var vidTitle = (typeof video === "object" ? video.title : title) || "";
    $("vsc-modal-title").textContent = vidTitle;

    var player = $("vsc-modal-player");
    player.innerHTML = "";

    var videoUrl = (typeof video === "object" ? video.videoUrl : "") || "";
    var isMediaLib = (typeof video === "object" && (video.source === "media_library" || Boolean(video.videoUrl)));
    var fileId = typeof video === "object" ? video.id : video;

    if (isMediaLib && videoUrl) {
      // HTML5 video player for WordPress Media Library videos
      var vid = document.createElement("video");
      vid.src = videoUrl;
      vid.controls = true;
      vid.autoplay = true;
      vid.playsInline = true;
      vid.className = "vsc-player-video";
      if (video.thumbUrl) {
        vid.poster = video.thumbUrl;
      }
      player.appendChild(vid);
    } else {
      // Google Drive iframe preview for Drive library videos
      var iframe = document.createElement("iframe");
      iframe.src = "https://drive.google.com/file/d/" + encodeURIComponent(fileId) + "/preview";
      iframe.setAttribute("allow", "autoplay; fullscreen");
      iframe.setAttribute("allowfullscreen", "");
      iframe.setAttribute("frameborder", "0");
      player.appendChild(iframe);
    }

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
        '<h2 class="vsc-membership-title" id="vsc-membership-title">Daily Free Search Received</h2>' +
        '<div id="vsc-modal-timer-container"></div>' +
        '<p class="vsc-membership-sub" id="vsc-membership-sub"></p>' +
        '<div class="vsc-membership-perks" id="vsc-modal-perks">' +
          '<div class="vsc-perk">' +
            '<span class="vsc-perk-icon">&#10003;</span>' +
            '<span><strong>10 video searches every day</strong> for monthly subscribers</span>' +
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
          '<a id="vsc-membership-cta" href="#" class="vsc-membership-btn">Subscribe for More Searches &rarr;</a>' +
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

    var titleEl    = $("vsc-membership-title");
    var timerWrap  = $("vsc-modal-timer-container");
    var subEl      = $("vsc-membership-sub");
    var ctaEl      = $("vsc-membership-cta");
    var secEl      = $("vsc-membership-secondary");
    var perksEl    = $("vsc-modal-perks");
    var overlay    = $("vsc-membership-modal");

    ctaEl.href = STATE.membershipUrl || "#";
    ctaEl.textContent = (STATE.popupButtonText || "Subscribe for More Searches") + " \u2192";

    if (reason === "guest") {
      titleEl.textContent = "Membership Required";
      timerWrap.innerHTML = "";
      if (query) {
        subEl.innerHTML = "Log in to search for <em>&ldquo;" + escapeHtml(query) + "&rdquo;</em> with your free daily search, or subscribe for 10 searches per day!";
      } else {
        subEl.textContent = "Please log in to your account to use your free daily search, or become a Monthly Subscriber to get 10 searches every day.";
      }
      secEl.innerHTML = 'Already a member? <a href="' + escapeHtml(STATE.loginUrl || "#") + '">Log in here</a>';
    } else if (reason === "member_limit_reached") {
      // Monthly member who has reached their 10 daily searches
      titleEl.textContent = "Daily Subscriber Limit Reached";

      var remainingSec = Math.max(0, Math.floor((STATE.resetTimestamp - Date.now()) / 1000));
      var formatted = formatCountdown(remainingSec);
      var exactTimeText = STATE.resetTimeFormatted ? ' <span class="vsc-timer-at">(at ' + escapeHtml(STATE.resetTimeFormatted) + ')</span>' : "";

      timerWrap.innerHTML =
        '<div class="vsc-modal-timer-box">' +
          '<div class="vsc-timer-icon-wrap">' +
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
              '<circle cx="12" cy="12" r="10"/>' +
              '<polyline points="12 6 12 12 16 14"/>' +
            '</svg>' +
          '</div>' +
          '<div class="vsc-timer-info">' +
            '<div class="vsc-timer-label">Your ' + escapeHtml(String(STATE.memberLimit)) + ' daily searches reset in:</div>' +
            '<div class="vsc-timer-clock"><span id="vsc-modal-timer">' + formatted + '</span>' + exactTimeText + '</div>' +
          '</div>' +
        '</div>';

      if (query) {
        subEl.innerHTML = "You have used all " + escapeHtml(String(STATE.memberLimit)) + " of your daily subscriber searches for today. Your searches will reset in <strong>" + formatted + "</strong>.";
      } else {
        subEl.innerHTML = "You have used all " + escapeHtml(String(STATE.memberLimit)) + " of your daily video searches for today. Your 10 searches reset every 24 hours.";
      }

      perksEl.innerHTML =
        '<div class="vsc-perk">' +
          '<span class="vsc-perk-icon">&#10003;</span>' +
          '<span><strong>10 daily searches</strong> renewed every 24 hours</span>' +
        '</div>' +
        '<div class="vsc-perk">' +
          '<span class="vsc-perk-icon">&#10003;</span>' +
          '<span><strong>Full access</strong> to all video teachings and library files</span>' +
        '</div>' +
        '<div class="vsc-perk">' +
          '<span class="vsc-perk-icon">&#10003;</span>' +
          '<span><strong>Unlimited video playback</strong> in full high definition</span>' +
        '</div>';

      ctaEl.textContent = "View My Subscription \u2192";
      secEl.innerHTML = 'Thank you for being an active subscriber!';
    } else {
      // limit_reached: Logged-in non-member has used their 1 free search
      titleEl.textContent = STATE.popupTitle || "Daily Free Search Received";

      var remainingSec = Math.max(0, Math.floor((STATE.resetTimestamp - Date.now()) / 1000));
      var formatted = formatCountdown(remainingSec);
      var exactTimeText = STATE.resetTimeFormatted ? ' <span class="vsc-timer-at">(at ' + escapeHtml(STATE.resetTimeFormatted) + ')</span>' : "";

      timerWrap.innerHTML =
        '<div class="vsc-modal-timer-box">' +
          '<div class="vsc-timer-icon-wrap">' +
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
              '<circle cx="12" cy="12" r="10"/>' +
              '<polyline points="12 6 12 12 16 14"/>' +
            '</svg>' +
          '</div>' +
          '<div class="vsc-timer-info">' +
            '<div class="vsc-timer-label">Next free search available in:</div>' +
            '<div class="vsc-timer-clock"><span id="vsc-modal-timer">' + formatted + '</span>' + exactTimeText + '</div>' +
          '</div>' +
        '</div>';

      if (query) {
        subEl.innerHTML = "You have received your daily free video search. Monthly Subscribers receive multiple daily searches (10 searches/day) across all videos including <em>&ldquo;" + escapeHtml(query) + "&rdquo;</em>.";
      } else {
        subEl.textContent = STATE.popupMessage || "You have received your daily free video search. Monthly Subscribers receive multiple daily searches.";
      }

      perksEl.innerHTML =
        '<div class="vsc-perk">' +
          '<span class="vsc-perk-icon">&#10003;</span>' +
          '<span><strong>10 video searches every day</strong> for monthly subscribers</span>' +
        '</div>' +
        '<div class="vsc-perk">' +
          '<span class="vsc-perk-icon">&#10003;</span>' +
          '<span><strong>Instant AI matching</strong> by topic, scripture, and meaning</span>' +
        '</div>' +
        '<div class="vsc-perk">' +
          '<span class="vsc-perk-icon">&#10003;</span>' +
          '<span><strong>Full-length player</strong> with immediate playback</span>' +
        '</div>';

      ctaEl.textContent = (STATE.popupButtonText || "Subscribe for More Searches") + " \u2192";
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
            if (data.data.used_in_cycle != null) {
              STATE.usedInCycle = data.data.used_in_cycle;
            }
            if (data.data.allowed_limit != null) {
              STATE.allowedLimit = data.data.allowed_limit;
            }
            if (data.data.seconds_until_reset) {
              STATE.secondsUntilReset = data.data.seconds_until_reset;
              STATE.resetTimestamp = Date.now() + (data.data.seconds_until_reset * 1000);
            }
            if (data.data.reset_time_formatted) {
              STATE.resetTimeFormatted = data.data.reset_time_formatted;
            }
            updateBannerUI();
            startResetTimer();
          }
        }
      })
      .catch(function (err) {
        console.warn("VSC: Search recording notice:", err);
      });
  }

  /* ── Build Video Training Text from Title, Topics, Context, Excerpt ── */
  function buildVideoTrainingText(v) {
    var parts = [];
    if (v.title) parts.push(v.title);
    if (v.topics) parts.push("Topics: " + v.topics);
    if (v.context) parts.push("Context: " + v.context);
    if (v.transcript) parts.push("Transcript: " + v.transcript);
    if (v.excerpt && v.excerpt !== v.title && (!v.context || v.excerpt.indexOf(v.context) === -1)) {
      parts.push(v.excerpt);
    }
    return parts.filter(Boolean).join(". ");
  }

  /* ── Auto-Train & Embed New Media Library Videos on Frontend ── */
  function autoTrainMediaVideos(items) {
    if (!items.length || !STATE.extractor) return;

    var batch = items.slice(0, 20);
    var results = [];

    var promises = batch.map(function (v) {
      var text = buildVideoTrainingText(v);
      return STATE.extractor(text, { pooling: "mean", normalize: true })
        .then(function (out) {
          v.embedding = Array.from(out.data);
          results.push({
            wpId: v.wpId,
            id: v.id,
            embedding: v.embedding,
          });
        })
        .catch(function (e) {
          console.warn("VSC: Video embedding error:", e);
        });
    });

    Promise.all(promises).then(function () {
      if (STATE.ajaxUrl && results.length) {
        var params = new URLSearchParams();
        params.append("action", "vsc_save_media_embeddings");
        params.append("nonce", STATE.nonce);
        params.append("items", JSON.stringify(results));

        fetch(STATE.ajaxUrl, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: params.toString(),
        }).catch(function (e) {
          console.warn("VSC: Background auto-save notice:", e);
        });
      }
    });
  }

  /* ── Update Banner Bar in DOM ── */
  function updateBannerUI() {
    var banner = document.querySelector(".vsc-banner-bar");
    if (!banner) return;

    if (STATE.isUnlimited) {
      banner.style.display = "none";
      return;
    }

    var exactText = STATE.resetTimeFormatted ? ' <span class="vsc-reset-exact">(at ' + escapeHtml(STATE.resetTimeFormatted) + ')</span>' : "";
    var remainingSec = Math.max(0, Math.floor((STATE.resetTimestamp - Date.now()) / 1000));
    var formatted = formatCountdown(remainingSec);

    if (STATE.isLoggedIn && STATE.isMember) {
      // Monthly Subscriber
      if (STATE.searchesLeft <= 0) {
        banner.className = "vsc-banner-bar vsc-banner-warning";
        banner.innerHTML =
          '<span>' +
            'You have used all <strong>' + escapeHtml(String(STATE.memberLimit)) + ' daily searches</strong> for today. ' +
            'Resets in <strong class="vsc-timer-display">' + formatted + '</strong>' + exactText + '.' +
          '</span>';
      } else if (STATE.usedInCycle > 0 || STATE.resetTimestamp > 0) {
        banner.className = "vsc-banner-bar vsc-banner-member";
        banner.innerHTML =
          '<span>' +
            '🌟 <strong>Monthly Subscriber:</strong> You have <strong>' + escapeHtml(String(STATE.searchesLeft)) +
            ' of ' + escapeHtml(String(STATE.memberLimit)) + ' daily searches</strong> remaining today. ' +
            'Resets in <strong class="vsc-timer-display">' + formatted + '</strong>' + exactText + '.' +
          '</span>';
      } else {
        banner.className = "vsc-banner-bar vsc-banner-member";
        banner.innerHTML =
          '<span>🌟 <strong>Monthly Subscriber:</strong> You have <strong>' +
          escapeHtml(String(STATE.memberLimit)) + ' daily searches</strong> available today.</span>';
      }
    } else if (STATE.isLoggedIn && !STATE.isMember) {
      // Non-member logged in
      if (STATE.searchesLeft <= 0) {
        banner.className = "vsc-banner-bar vsc-banner-warning";
        banner.innerHTML =
          '<span>' +
            'You have received your daily free video search. ' +
            'Resets in <strong class="vsc-timer-display">' + formatted + '</strong>' + exactText + '. ' +
            'Monthly Subscribers receive multiple daily searches. ' +
          '</span>' +
          '<a href="' + escapeHtml(STATE.membershipUrl) + '" class="vsc-banner-link">Subscribe Now &rarr;</a>';
      } else {
        banner.className = "vsc-banner-bar vsc-banner-info";
        banner.innerHTML =
          '<span>You have <strong>1 free search</strong> available today (resets every ' + escapeHtml(String(STATE.periodHours)) + ' hours).</span> ' +
          '<a href="' + escapeHtml(STATE.membershipUrl) + '" class="vsc-banner-link">Subscribe for 10 Daily Searches &rarr;</a>';
      }
    } else {
      // Guest
      banner.className = "vsc-banner-bar vsc-banner-guest";
      banner.innerHTML =
        '<span>Have an account? <a href="' + escapeHtml(STATE.loginUrl) + '" class="vsc-banner-link">Log in</a> to use your free daily search, or <a href="' + escapeHtml(STATE.membershipUrl) + '" class="vsc-banner-link">Subscribe for 10 Daily Searches</a>.</span>';
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

  /* ── Stopwords for Context Tokenization ── */
  var STOPWORDS = [
    "what", "is", "are", "was", "were", "the", "a", "an", "and", "or", "in", "on", "at",
    "to", "for", "with", "by", "of", "about", "how", "do", "does", "did", "can", "could",
    "should", "would", "he", "she", "it", "they", "we", "you", "i", "me", "my", "your",
    "his", "her", "their", "our", "say", "says", "said", "talk", "talks", "talking",
    "tell", "tells", "telling", "video", "videos", "lesson", "mentor", "amato", "there",
    "here", "when", "where", "why", "who", "which", "give", "gives", "look", "looks"
  ];

  function tokenize(str) {
    if (!str) return [];
    var words = str.toLowerCase().replace(/[^a-z0-9\s]/g, " ").split(/\s+/);
    return words.filter(function (w) {
      return w.length > 2 && STOPWORDS.indexOf(w) === -1;
    });
  }

  /* ── Calculate Contextual Keyword Relevance ── */
  function calculateContextRelevance(query, v) {
    var qWords = tokenize(query);
    if (!qWords.length) return 0;

    var title = (v.title || "").toLowerCase();
    var topics = (v.topics || "").toLowerCase();
    var context = (v.context || "").toLowerCase();
    var transcript = (v.transcript || "").toLowerCase();
    var excerpt = (v.excerpt || "").toLowerCase();

    var fullContext = [title, topics, context, transcript, excerpt].join(" ");
    var cleanQuery = query.toLowerCase().trim();

    var score = 0;

    // 1. Exact phrase match in full context
    if (cleanQuery.length > 4 && fullContext.indexOf(cleanQuery) !== -1) {
      score += 0.45;
    }

    // 2. Keyword match in Title (heavy weight)
    var titleMatches = 0;
    qWords.forEach(function (w) {
      if (title.indexOf(w) !== -1) titleMatches++;
    });
    score += (titleMatches / qWords.length) * 0.35;

    // 3. Keyword match in Topics
    if (topics) {
      var topicMatches = 0;
      qWords.forEach(function (w) {
        if (topics.indexOf(w) !== -1) topicMatches++;
      });
      score += (topicMatches / qWords.length) * 0.35;
    }

    // 4. Keyword match in Context / Excerpt / Transcript
    var bodyMatches = 0;
    qWords.forEach(function (w) {
      if (fullContext.indexOf(w) !== -1) bodyMatches++;
    });
    score += (bodyMatches / qWords.length) * 0.25;

    return Math.min(1.0, score);
  }

  /* ── Extract Best Context Snippet Answering Query ── */
  function extractContextSnippet(query, video) {
    var text = (video.context ? video.context + ". " : "") + (video.excerpt || "") + (video.topics ? " [Topics: " + video.topics + "]" : "");
    if (!text) return escapeHtml(video.title || "Video Lesson");

    text = text.replace(/\s+/g, " ").trim();
    var sentences = text.match(/[^.!?]+[.!?]+/g) || [text];
    var qWords = tokenize(query);

    var bestSentence = "";
    var bestScore = -1;

    sentences.forEach(function (s) {
      var sClean = s.trim();
      if (!sClean || sClean.length < 10) return;
      var sWords = tokenize(sClean);
      var matchCount = 0;
      qWords.forEach(function (qw) {
        if (sWords.indexOf(qw) !== -1) matchCount++;
      });
      if (matchCount > bestScore) {
        bestScore = matchCount;
        bestSentence = sClean;
      }
    });

    var chosen = (bestScore > 0 && bestSentence) ? bestSentence : (video.context || video.excerpt || video.title || "");
    if (chosen.length > 125) {
      chosen = chosen.slice(0, 125) + "\u2026";
    }
    return escapeHtml(chosen);
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

      var thumbUrl = r.thumbUrl;
      if (!thumbUrl && r.id && !r.videoUrl) {
        thumbUrl = "https://drive.google.com/thumbnail?id=" + encodeURIComponent(r.id) + "&sz=w400";
      }

      var topicBadge = r.topics ? '<div class="vsc-result-topics">🏷️ ' + escapeHtml(r.topics) + '</div>' : '';
      var snippetHtml = r.snippet ? r.snippet : escapeHtml((r.excerpt || "").slice(0, 100)) + (r.excerpt && r.excerpt.length > 100 ? "\u2026" : "");

      var thumbContent = "";
      if (thumbUrl) {
        thumbContent = '<img class="vsc-thumb" src="' + escapeHtml(thumbUrl) + '" alt="" loading="lazy"' +
          (r.videoUrl ? ' onerror="this.style.display=\'none\'; if(this.nextElementSibling) this.nextElementSibling.style.display=\'block\';"' : '') + '>';
        if (r.videoUrl) {
          thumbContent += '<video class="vsc-thumb vsc-thumb-video" src="' + escapeHtml(r.videoUrl) + '#t=0.5" preload="metadata" muted playsinline style="display:none;"></video>';
        }
      } else if (r.videoUrl) {
        // Native HTML5 video frame thumbnail at 0.5s
        thumbContent = '<video class="vsc-thumb vsc-thumb-video" src="' + escapeHtml(r.videoUrl) + '#t=0.5" preload="metadata" muted playsinline></video>';
      } else {
        thumbContent = '<div class="vsc-thumb-placeholder">🎬</div>';
      }

      card.innerHTML =
        '<div class="vsc-thumb-wrap">' +
          thumbContent +
          '<div class="vsc-play-btn">&#9654;</div>' +
          '<div class="vsc-score-badge">' + Math.round(r.score * 100) + '% match</div>' +
        '</div>' +
        '<div class="vsc-result-info">' +
          '<div class="vsc-result-title">' + escapeHtml(r.title || "Untitled") + '</div>' +
          topicBadge +
          '<div class="vsc-result-excerpt">' + snippetHtml + '</div>' +
        '</div>';

      var videoThumb = card.querySelector("video.vsc-thumb-video");
      if (videoThumb) {
        videoThumb.addEventListener("loadedmetadata", function () {
          try { this.currentTime = 0.5; } catch (e) {}
        }, { once: true });
      }

      card.addEventListener("click", function () { openModal(r); });
      grid.appendChild(card);
    });

    wrap.appendChild(grid);
    var container = $("vsc-messages");
    container.appendChild(wrap);
    setTimeout(function () { container.scrollTop = container.scrollHeight; }, 30);

    // Show remaining daily searches or CTA card
    var remainingSec = Math.max(0, Math.floor((STATE.resetTimestamp - Date.now()) / 1000));
    var formatted = formatCountdown(remainingSec);
    var exactText = STATE.resetTimeFormatted ? ' (at ' + escapeHtml(STATE.resetTimeFormatted) + ')' : "";

    if (STATE.isMember && !STATE.isUnlimited) {
      setTimeout(function () {
        var memberNote = document.createElement("div");
        memberNote.className = "vsc-msg vsc-msg-bot vsc-chat-cta-wrap";
        if (STATE.searchesLeft <= 0) {
          memberNote.innerHTML =
            '<div class="vsc-chat-cta">' +
              '<div class="vsc-chat-cta-badge">Daily Limit Reached</div>' +
              '<h4 class="vsc-chat-cta-title">All 10 daily searches used</h4>' +
              '<p class="vsc-chat-cta-text">' +
                'You have used all 10 of your daily subscriber searches for today. Resets in <strong class="vsc-timer-display">' + formatted + '</strong>' + exactText + '.' +
              '</p>' +
            '</div>';
        } else {
          memberNote.innerHTML =
            '<div class="vsc-chat-member-status">' +
              '🌟 <strong>' + escapeHtml(String(STATE.searchesLeft)) + ' of ' + escapeHtml(String(STATE.memberLimit)) + ' daily searches remaining today</strong>. Resets in <strong class="vsc-timer-display">' + formatted + '</strong>' + exactText + '.' +
            '</div>';
        }
        container.appendChild(memberNote);
        container.scrollTop = container.scrollHeight;
      }, 500);
    } else if (!STATE.isMember && STATE.isLoggedIn && STATE.searchesLeft <= 0) {
      // Non-member who has used their 1 free search
      setTimeout(function () {
        var ctaCard = document.createElement("div");
        ctaCard.className = "vsc-msg vsc-msg-bot vsc-chat-cta-wrap";
        ctaCard.innerHTML =
          '<div class="vsc-chat-cta">' +
            '<div class="vsc-chat-cta-badge">Daily Free Search Received</div>' +
            '<h4 class="vsc-chat-cta-title">Want multiple daily searches?</h4>' +
            '<p class="vsc-chat-cta-text">' +
              'You have received your daily free video search. Monthly Subscribers receive multiple daily searches. ' +
              'Resets in <strong class="vsc-timer-display">' + formatted + '</strong>' + exactText + '.' +
            '</p>' +
            '<a href="' + escapeHtml(STATE.membershipUrl) + '" class="vsc-chat-cta-btn">' + escapeHtml(STATE.popupButtonText || "Subscribe for More Searches") + ' &rarr;</a>' +
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
    STATE.maxResults         = parseInt(app.getAttribute("data-max-results"), 10) || 3;
    STATE.isLoggedIn         = cfg.isLoggedIn != null ? Boolean(cfg.isLoggedIn) : (app.getAttribute("data-logged-in") === "1");
    STATE.isMember           = cfg.isMember != null ? Boolean(cfg.isMember) : (app.getAttribute("data-is-member") === "1");
    STATE.isUnlimited        = cfg.isUnlimited != null ? Boolean(cfg.isUnlimited) : (app.getAttribute("data-is-unlimited") === "1");
    STATE.searchesLeft       = cfg.searchesLeft != null ? parseInt(cfg.searchesLeft, 10) : (parseInt(app.getAttribute("data-searches-left"), 10) || 0);
    STATE.allowedLimit       = cfg.allowedLimit != null ? parseInt(cfg.allowedLimit, 10) : (parseInt(app.getAttribute("data-allowed-limit"), 10) || 1);
    STATE.usedInCycle        = cfg.usedInCycle != null ? parseInt(cfg.usedInCycle, 10) : (parseInt(app.getAttribute("data-used-in-cycle"), 10) || 0);
    STATE.freeLimit          = cfg.freeLimit != null ? parseInt(cfg.freeLimit, 10) : (parseInt(app.getAttribute("data-free-limit"), 10) || 1);
    STATE.memberLimit        = cfg.memberLimit != null ? parseInt(cfg.memberLimit, 10) : (parseInt(app.getAttribute("data-member-limit"), 10) || 10);
    STATE.periodHours        = cfg.periodHours != null ? parseInt(cfg.periodHours, 10) : (parseInt(app.getAttribute("data-period-hours"), 10) || 24);
    STATE.secondsUntilReset  = cfg.secondsUntilReset != null ? parseInt(cfg.secondsUntilReset, 10) : (parseInt(app.getAttribute("data-seconds-until-reset"), 10) || 0);
    STATE.resetTimeFormatted = cfg.resetTimeFormatted || app.getAttribute("data-reset-formatted") || "";
    STATE.membershipUrl      = cfg.membershipUrl || app.getAttribute("data-membership-url") || "";
    STATE.loginUrl           = cfg.loginUrl || app.getAttribute("data-login-url") || "";
    STATE.popupTitle         = cfg.popupTitle || app.getAttribute("data-popup-title") || "Daily Free Search Received";
    STATE.popupMessage       = cfg.popupMessage || app.getAttribute("data-popup-message") || "";
    STATE.popupButtonText     = cfg.popupButtonText || app.getAttribute("data-popup-button") || "Subscribe for More Searches";
    STATE.ajaxUrl            = cfg.ajaxUrl || "";
    STATE.nonce              = cfg.nonce || "";
    STATE.mediaVideosUrl     = cfg.mediaVideosUrl || "";

    // Synchronize client reset timestamp using local clock delta to prevent skew
    if (STATE.secondsUntilReset > 0) {
      STATE.resetTimestamp = Date.now() + (STATE.secondsUntilReset * 1000);
    } else {
      var attrTs = parseInt(app.getAttribute("data-reset-timestamp"), 10);
      if (attrTs > 0) {
        STATE.resetTimestamp = attrTs * 1000;
      }
    }

    buildModal();
    buildMembershipModal();

    // Start 24-hour reset countdown timer if on active cycle
    if (!STATE.isUnlimited && STATE.resetTimestamp > 0) {
      startResetTimer();
    }

    setStatus("Loading video libraries...");

    // 1. Fetch Google Drive video catalog
    var loadDrive = fetch(cfg.dataUrl || "assets/data.json")
      .then(function (res) { return res.json(); })
      .catch(function () { return []; });

    // 2. Fetch WordPress Media Library video catalog (direct inline payload or AJAX / JSON fallback)
    var loadMedia;
    if (Array.isArray(cfg.mediaVideos) && cfg.mediaVideos.length > 0) {
      loadMedia = Promise.resolve(cfg.mediaVideos);
    } else {
      var fetchUrl = STATE.mediaVideosUrl || (STATE.ajaxUrl ? STATE.ajaxUrl + "?action=vsc_get_media_videos" : "");
      loadMedia = fetchUrl
        ? fetch(fetchUrl)
            .then(function (res) {
              if (!res.ok) throw new Error("HTTP " + res.status);
              return res.json();
            })
            .then(function (data) {
              if (Array.isArray(data)) return data;
              if (data && data.success && data.data && Array.isArray(data.data.videos)) return data.data.videos;
              return [];
            })
            .catch(function () {
              if (STATE.ajaxUrl && fetchUrl !== (STATE.ajaxUrl + "?action=vsc_get_media_videos")) {
                return fetch(STATE.ajaxUrl + "?action=vsc_get_media_videos")
                  .then(function (r) { return r.json(); })
                  .then(function (d) { return (d && d.success && d.data && Array.isArray(d.data.videos)) ? d.data.videos : []; })
                  .catch(function () { return []; });
              }
              return [];
            })
        : Promise.resolve([]);
    }

    // 3. Combine both catalogs into a unified knowledgebase
    Promise.all([loadDrive, loadMedia])
      .then(function (results) {
        var driveVideos = Array.isArray(results[0]) ? results[0] : [];
        var mediaVideos = Array.isArray(results[1]) ? results[1] : [];

        STATE.videos = driveVideos.concat(mediaVideos);

        if (!STATE.videos.length) {
          setStatus("");
          addMessage("<p>No video data loaded yet. Add videos to your WordPress Media Library or Google Drive catalog.</p>", "bot");
          return;
        }

        setStatus("Loading search model (cached on first visit)\u2026");
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

        // Auto-train on any Media Library videos that don't have embeddings yet
        var unindexed = STATE.videos.filter(function (v) {
          return v.source === "media_library" && (!v.embedding || !v.embedding.length) && (v.title || v.excerpt);
        });
        if (unindexed.length > 0) {
          autoTrainMediaVideos(unindexed);
        }

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
        if (!STATE.isUnlimited) {
          if (!STATE.isLoggedIn) {
            openMembershipModal("guest", "");
            return;
          }
          if (STATE.isMember && STATE.searchesLeft <= 0) {
            openMembershipModal("member_limit_reached", "");
            return;
          }
          if (!STATE.isMember && STATE.searchesLeft <= 0) {
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
    // 1. Check Restrictions
    if (!STATE.isUnlimited) {
      // Guest: must log in or join
      if (!STATE.isLoggedIn) {
        openMembershipModal("guest", query);
        addMessage(escapeHtml(query), "user");
        addMessage(
          "<p>🔒 <strong>Membership Required:</strong> Please <a href=\"" + escapeHtml(STATE.loginUrl) + "\">log in</a> to use your free daily search, or <a href=\"" + escapeHtml(STATE.membershipUrl) + "\">subscribe</a> for 10 daily searches.</p>",
          "bot"
        );
        return;
      }

      var remainingSec = Math.max(0, Math.floor((STATE.resetTimestamp - Date.now()) / 1000));
      var formatted = formatCountdown(remainingSec);
      var exactText = STATE.resetTimeFormatted ? ' (at ' + escapeHtml(STATE.resetTimeFormatted) + ')' : "";

      // Monthly member who has reached 10 daily searches
      if (STATE.isMember && STATE.searchesLeft <= 0) {
        openMembershipModal("member_limit_reached", query);
        addMessage(escapeHtml(query), "user");
        addMessage(
          "<p>🔒 <strong>Daily Limit Reached:</strong> You have used all " + escapeHtml(String(STATE.memberLimit)) + " of your daily subscriber searches for today. Resets in <strong class=\"vsc-timer-display\">" + formatted + "</strong>" + exactText + ".</p>",
          "bot"
        );
        return;
      }

      // Logged-in non-member who has reached 1 free search
      if (!STATE.isMember && STATE.searchesLeft <= 0) {
        openMembershipModal("limit_reached", query);
        addMessage(escapeHtml(query), "user");
        addMessage(
          "<p>🔒 <strong>Daily Free Search Used:</strong> You have received your daily free video search. Monthly Subscribers receive multiple daily searches. Resets in <strong class=\"vsc-timer-display\">" + formatted + "</strong>" + exactText + ". <a href=\"" + escapeHtml(STATE.membershipUrl) + "\" class=\"vsc-in-msg-btn\">Subscribe for More Searches &rarr;</a></p>",
          "bot"
        );
        return;
      }

      // Consume one search
      STATE.searchesLeft = Math.max(0, STATE.searchesLeft - 1);
      STATE.usedInCycle = (STATE.usedInCycle || 0) + 1;

      if (!STATE.resetTimestamp || STATE.resetTimestamp <= Date.now()) {
        STATE.secondsUntilReset = STATE.periodHours * 3600;
        STATE.resetTimestamp = Date.now() + (STATE.secondsUntilReset * 1000);
      }

      recordSearchUsage();
      updateBannerUI();
      startResetTimer();
    }

    // 2. Execute Search
    addMessage(escapeHtml(query), "user");

    if (!STATE.ready) {
      addMessage("Still loading the search model \u2014 please wait a moment and try again.", "bot");
      return;
    }

    var thinking = showThinking();
    var minThink = new Promise(function (res) { setTimeout(res, 1800); });

    // Ensure all videos in memory have embeddings before computing scores
    var unindexed = STATE.videos.filter(function (v) {
      return (!v.embedding || !v.embedding.length) && (v.title || v.context || v.excerpt);
    });

    var prepPromise = (unindexed.length > 0)
      ? Promise.all(unindexed.map(function (uv) {
          var txt = buildVideoTrainingText(uv);
          return STATE.extractor(txt, { pooling: "mean", normalize: true }).then(function (res) {
            uv.embedding = Array.from(res.data);
          }).catch(function () {});
        }))
      : Promise.resolve();

    prepPromise
      .then(function () {
        return STATE.extractor(query, { pooling: "mean", normalize: true });
      })
      .then(function (output) {
        var queryVec = Array.from(output.data);
        var scored = [];

        for (var i = 0; i < STATE.videos.length; i++) {
          var v = STATE.videos[i];
          var semanticSim = (v.embedding && v.embedding.length) ? cosineSim(queryVec, v.embedding) : 0;
          var contextScore = calculateContextRelevance(query, v);

          // Combined hybrid score: 65% semantic meaning + 35% contextual keyword alignment
          var score = 0;
          if (semanticSim > 0 && contextScore > 0) {
            score = (semanticSim * 0.65) + (contextScore * 0.35);
          } else if (semanticSim > 0) {
            score = semanticSim;
          } else if (contextScore > 0) {
            score = contextScore * 0.75;
          } else {
            continue;
          }

          var snippet = extractContextSnippet(query, v);

          scored.push({
            id: v.id,
            wpId: v.wpId,
            title: v.title,
            excerpt: v.excerpt,
            context: v.context,
            topics: v.topics,
            snippet: snippet,
            videoUrl: v.videoUrl,
            thumbUrl: v.thumbUrl,
            source: v.source,
            score: score,
          });
        }

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