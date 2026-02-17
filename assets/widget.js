(function () {
  'use strict';

  var cfg = window.ASKWP_CONFIG || {};
  if (!cfg.enabled) {
    return;
  }

  var MAX_MESSAGES = Number(cfg.max_messages || 12);
  var STORAGE_SESSION = 'askwp_session_id_v1';
  var STORAGE_MESSAGES = 'askwp_messages_v1';
  var STORAGE_FORM_DRAFT = 'askwp_form_draft_v1';

  var ICON_PRESETS = {
    'chat-bubble': '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>',
    'headset': '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 1a9 9 0 0 0-9 9v7c0 1.66 1.34 3 3 3h3v-8H5v-2c0-3.87 3.13-7 7-7s7 3.13 7 7v2h-4v8h3c1.66 0 3-1.34 3-3v-7a9 9 0 0 0-9-9z"/></svg>',
    'robot': '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7v1H3v-1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2zM7.5 13a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm9 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zM5 17v1a3 3 0 0 0 3 3h8a3 3 0 0 0 3-3v-1H5z"/></svg>'
  };

  var SEND_ICON = '<svg viewBox="0 0 24 24"><path d="M12 20V4M5 11l7-7 7 7"/></svg>';

  var str = cfg.strings || {};

  var state = {
    sessionId: getOrCreateSessionId(),
    messages: loadMessages(),
    formDraft: loadFormDraft(),
    panelOpen: false,
    formOpen: false,
    waiting: false
  };

  var ui = buildWidgetUI();
  renderAll();

  // ── localStorage helpers ──

  function safeGetItem(key) {
    try { return localStorage.getItem(key); }
    catch (e) { return null; }
  }

  function safeSetItem(key, value) {
    try { localStorage.setItem(key, value); }
    catch (e) { /* quota or private mode */ }
  }

  function safeRemoveItem(key) {
    try { localStorage.removeItem(key); }
    catch (e) { /* quota or private mode */ }
  }

  // ── Session ID ──

  function getOrCreateSessionId() {
    var existing = safeGetItem(STORAGE_SESSION);
    if (existing) {
      return existing;
    }

    var id = '';
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      id = window.crypto.randomUUID();
    } else {
      id = 'askwp-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    safeSetItem(STORAGE_SESSION, id);
    return id;
  }

  // ── Sanitization ──

  function sanitizeText(value, maxLen) {
    var text = String(value || '').replace(/<[^>]*>/g, ' ');
    text = text.replace(/\s+/g, ' ').trim();
    if (maxLen && text.length > maxLen) {
      text = text.slice(0, maxLen);
    }
    return text;
  }

  function sanitizeAssistantText(value, maxLen) {
    var text = String(value || '').replace(/<[^>]*>/g, '');
    text = text.replace(/\r\n?/g, '\n');
    text = text.replace(/[ \t]+\n/g, '\n');
    text = text.replace(/\n{3,}/g, '\n\n').trim();
    if (maxLen && text.length > maxLen) {
      text = text.slice(0, maxLen);
    }
    return text;
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function normalizeHttpUrl(url) {
    var clean = String(url || '').trim();
    if (!clean) { return ''; }

    try {
      var parsed = new URL(clean, window.location.origin);
      if (!/^https?:$/i.test(parsed.protocol)) { return ''; }
      return parsed.toString();
    } catch (e) {
      return '';
    }
  }

  // ── Sources ──

  function normalizeSources(sources) {
    if (!Array.isArray(sources)) { return []; }

    var normalized = [];
    sources.forEach(function (s) {
      if (!s || typeof s !== 'object') { return; }
      var title = sanitizeText(s.title || '', 120);
      var url = normalizeHttpUrl(s.url || '');
      if (!title || !url) { return; }
      normalized.push({ title: title, url: url });
    });

    return normalized.slice(0, 6);
  }

  // ── Messages persistence ──

  function loadMessages() {
    var raw = safeGetItem(STORAGE_MESSAGES);
    if (!raw) { return []; }

    var parsed;
    try { parsed = JSON.parse(raw); }
    catch (e) { return []; }

    if (!Array.isArray(parsed)) { return []; }

    var messages = parsed
      .filter(function (m) { return m && typeof m === 'object'; })
      .map(function (m) {
        var role = m.role === 'assistant' ? 'assistant' : 'user';
        var content = role === 'assistant'
          ? sanitizeAssistantText(m.content || '', 2500)
          : sanitizeText(m.content || '', 1500);

        if (!content) { return null; }

        var out = { role: role, content: content };
        if (role === 'assistant') {
          out.sources = normalizeSources(m.sources || []);
        }
        return out;
      })
      .filter(Boolean)
      .slice(-MAX_MESSAGES);

    safeSetItem(STORAGE_MESSAGES, JSON.stringify(messages));
    return messages;
  }

  function persistMessages() {
    state.messages = state.messages.slice(-MAX_MESSAGES);
    safeSetItem(STORAGE_MESSAGES, JSON.stringify(state.messages));
  }

  // ── Form draft persistence ──

  function loadFormDraft() {
    var raw = safeGetItem(STORAGE_FORM_DRAFT);
    if (!raw) { return null; }
    try {
      var parsed = JSON.parse(raw);
      return (parsed && typeof parsed === 'object') ? parsed : null;
    } catch (e) {
      return null;
    }
  }

  function persistFormDraft(draft) {
    if (!draft || typeof draft !== 'object') { return; }
    safeSetItem(STORAGE_FORM_DRAFT, JSON.stringify(draft));
    state.formDraft = draft;
  }

  function clearFormDraft() {
    safeRemoveItem(STORAGE_FORM_DRAFT);
    state.formDraft = null;
  }

  // ── Markdown rendering ──

  function renderInlineMarkdown(value) {
    var escaped = escapeHtml(value);
    var codeChunks = [];

    escaped = escaped.replace(/`([^`]+)`/g, function (_m, code) {
      var token = '%%ASKWP_IC_' + codeChunks.length + '%%';
      codeChunks.push('<code>' + code + '</code>');
      return token;
    });

    escaped = escaped.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, function (_m, label, url) {
      var nUrl = normalizeHttpUrl(String(url || '').replace(/&amp;/g, '&'));
      if (!nUrl) { return label; }
      return '<a href="' + escapeHtml(nUrl) + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
    });

    escaped = escaped.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    escaped = escaped.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');

    escaped = escaped.replace(/%%ASKWP_IC_(\d+)%%/g, function (_m, idx) {
      return codeChunks[Number(idx)] || '';
    });

    return escaped;
  }

  function renderAssistantMarkdown(value) {
    var text = sanitizeAssistantText(value, 2500);
    if (!text) { return ''; }

    var codeBlocks = [];
    text = text.replace(/```([a-zA-Z0-9_-]+)?\n([\s\S]*?)```/g, function (_m, lang, code) {
      var token = '%%ASKWP_BC_' + codeBlocks.length + '%%';
      var langClass = lang ? ' class="language-' + escapeHtml(lang) + '"' : '';
      codeBlocks.push('<pre class="askwp-code"><code' + langClass + '>' + escapeHtml(code) + '</code></pre>');
      return token;
    });

    var lines = text.split('\n');
    var htmlParts = [];
    var paragraphLines = [];
    var listType = null;

    function flushParagraph() {
      if (!paragraphLines.length) { return; }
      htmlParts.push('<p>' + renderInlineMarkdown(paragraphLines.join('\n')).replace(/\n/g, '<br>') + '</p>');
      paragraphLines = [];
    }

    function closeList() {
      if (listType === 'ul') { htmlParts.push('</ul>'); }
      else if (listType === 'ol') { htmlParts.push('</ol>'); }
      listType = null;
    }

    lines.forEach(function (line) {
      var trimmed = String(line || '').trim();
      var ulMatch = /^[-*]\s+(.+)$/.exec(trimmed);
      var olMatch = /^(\d+)\.\s+(.+)$/.exec(trimmed);

      if (!trimmed) {
        flushParagraph();
        closeList();
        return;
      }

      if (ulMatch) {
        flushParagraph();
        if (listType !== 'ul') { closeList(); listType = 'ul'; htmlParts.push('<ul>'); }
        htmlParts.push('<li>' + renderInlineMarkdown(ulMatch[1]) + '</li>');
        return;
      }

      if (olMatch) {
        flushParagraph();
        if (listType !== 'ol') { closeList(); listType = 'ol'; htmlParts.push('<ol>'); }
        htmlParts.push('<li>' + renderInlineMarkdown(olMatch[2]) + '</li>');
        return;
      }

      if (listType) { closeList(); }
      paragraphLines.push(line);
    });

    flushParagraph();
    closeList();

    var html = htmlParts.join('');
    html = html.replace(/%%ASKWP_BC_(\d+)%%/g, function (_m, idx) {
      return codeBlocks[Number(idx)] || '';
    });

    return html;
  }

  // ── Viewport helpers ──

  function isMobileViewport() {
    return !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
  }

  function prefersReducedMotion() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  // ── Panel open/close ──

  function openPanel() {
    if (state.panelOpen) { return; }
    state.panelOpen = true;

    ui.root.classList.add('askwp-open');
    updateMobileState(true);
    renderChatList();

    var focusDelay = prefersReducedMotion() ? 50 : 400;
    window.setTimeout(function () {
      if (state.panelOpen && !state.formOpen) {
        ui.input.focus();
      }
    }, focusDelay);
  }

  function closePanel() {
    if (!state.panelOpen) { return; }

    state.panelOpen = false;

    if (state.formOpen) {
      closeFormOverlay(true);
    }

    ui.root.classList.remove('askwp-open');
    updateMobileState(false);
  }

  function updateMobileState(open) {
    if (!ui) { return; }
    var mobile = isMobileViewport();
    ui.backdrop.hidden = !(mobile && open);

    if (mobile && open) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
  }

  // ── Rendering ──

  function renderAll() {
    renderChatList();
    renderInputState();
    renderModeVisibility();
  }

  function renderChatList() {
    ui.chatList.innerHTML = '';

    if (!state.messages.length) {
      var welcome = document.createElement('div');
      welcome.className = 'askwp-msg askwp-msg-assistant';
      if (cfg.bot_avatar_url) {
        welcome.appendChild(createAvatar());
      }
      var welcomeBody = document.createElement('div');
      welcomeBody.className = 'askwp-msg-body';
      welcomeBody.innerHTML = '<p>' + escapeHtml(str.title || cfg.bot_name || 'Chat Assistant') + '</p>';
      welcome.appendChild(welcomeBody);
      ui.chatList.appendChild(welcome);
    }

    state.messages.forEach(function (msg) {
      var el = document.createElement('div');
      el.className = 'askwp-msg askwp-msg-' + msg.role;

      if (msg.role === 'assistant' && cfg.bot_avatar_url) {
        el.appendChild(createAvatar());
      }

      var body = document.createElement('div');
      body.className = 'askwp-msg-body';

      if (msg.role === 'assistant') {
        body.innerHTML = renderAssistantMarkdown(msg.content);

        if (Array.isArray(msg.sources) && msg.sources.length) {
          var srcWrap = document.createElement('div');
          srcWrap.className = 'askwp-msg-sources';
          msg.sources.forEach(function (s) {
            if (!s || !s.title || !s.url) { return; }
            var link = document.createElement('a');
            link.href = s.url;
            link.textContent = s.title;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            srcWrap.appendChild(link);
          });
          if (srcWrap.childNodes.length) {
            body.appendChild(srcWrap);
          }
        }
      } else {
        body.textContent = msg.content;
      }

      el.appendChild(body);
      ui.chatList.appendChild(el);
    });

    if (state.waiting) {
      var typing = document.createElement('div');
      typing.className = 'askwp-typing';
      typing.innerHTML = '<span></span><span></span><span></span>';
      ui.chatList.appendChild(typing);
    }

    scrollToBottom();
  }

  function createAvatar() {
    var av = document.createElement('img');
    av.className = 'askwp-msg-avatar';
    av.src = cfg.bot_avatar_url;
    av.alt = '';
    av.loading = 'lazy';
    av.decoding = 'async';
    return av;
  }

  function scrollToBottom() {
    window.setTimeout(function () {
      ui.chatList.scrollTop = ui.chatList.scrollHeight;
    }, 30);
  }

  function renderInputState() {
    var waiting = !!state.waiting;
    var empty = !ui.input.value.trim();
    ui.input.disabled = waiting;
    ui.sendBtn.disabled = waiting || empty;
  }

  function renderModeVisibility() {
    var formVisible = !!state.formOpen;
    ui.chatList.parentNode.querySelector('.askwp-panel-footer').hidden = formVisible;
    ui.chatList.hidden = formVisible;
    ui.formOverlay.hidden = !formVisible;
  }

  // ── Messages ──

  function addMessage(role, text, sources) {
    var content = role === 'assistant'
      ? sanitizeAssistantText(text, 2500)
      : sanitizeText(text, 1500);

    if (!content) { return; }

    var msg = { role: role === 'assistant' ? 'assistant' : 'user', content: content };
    if (msg.role === 'assistant') {
      msg.sources = normalizeSources(sources || []);
    }

    state.messages.push(msg);
    persistMessages();
    renderAll();
  }

  function setWaiting(isWaiting) {
    state.waiting = !!isWaiting;
    renderInputState();
    renderChatList();
  }

  function getChatPayloadMessages() {
    return state.messages
      .map(function (m) { return { role: m.role, content: m.content }; })
      .slice(-MAX_MESSAGES);
  }

  // ── Network ──

  async function postChat(payload) {
    var res = await fetch(cfg.chat_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    });

    if (!res.ok) {
      throw new Error('chat_http_' + res.status);
    }

    return res.json();
  }

  async function handleSendMessage() {
    if (state.waiting) { return; }

    var userText = sanitizeText(ui.input.value, 1500);
    if (!userText) { return; }

    ui.input.value = '';
    ui.input.style.height = '';

    addMessage('user', userText);
    openPanel();
    setWaiting(true);

    try {
      var payload = {
        session_id: state.sessionId,
        page_url: window.location.href,
        page_title: document.title,
        messages: getChatPayloadMessages()
      };

      var data = await postChat(payload);
      var reply = sanitizeAssistantText(data && data.reply ? data.reply : '', 2500);

      if (!reply) {
        reply = str.error || 'The chat is currently unavailable.';
      }

      addMessage('assistant', reply, Array.isArray(data.sources) ? data.sources : []);
      handleServerAction(data && data.action ? data.action : null);
    } catch (e) {
      addMessage('assistant', str.error || 'The chat is currently unavailable.');
    } finally {
      setWaiting(false);
      if (state.panelOpen && !state.formOpen) {
        ui.input.focus();
      }
    }
  }

  function handleServerAction(action) {
    if (!action || typeof action !== 'object') { return; }

    if (action.type === 'show_form') {
      openFormOverlay();
    }
  }

  function sendStatusPing(statusText) {
    var msg = sanitizeText(statusText, 120);
    if (!msg) { return; }

    var pingMessages = getChatPayloadMessages();
    pingMessages.push({ role: 'user', content: msg });

    fetch(cfg.chat_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        session_id: state.sessionId,
        page_url: window.location.href,
        page_title: document.title,
        messages: pingMessages.slice(-MAX_MESSAGES)
      }),
      credentials: 'same-origin'
    }).catch(function () { /* swallow ping errors */ });
  }

  // ── Reset ──

  function resetChatState() {
    state.messages = [];
    safeRemoveItem(STORAGE_MESSAGES);
    safeRemoveItem(STORAGE_SESSION);

    state.sessionId = getOrCreateSessionId();
    state.waiting = false;

    ui.input.value = '';
    ui.input.style.height = '';

    if (state.formOpen) {
      closeFormOverlay(true);
    }

    clearFormDraft();
    renderAll();
  }

  // ── Form overlay ──

  function openFormOverlay() {
    if (!cfg.form_enabled || !cfg.form_schema) { return; }

    if (!state.panelOpen) {
      openPanel();
    }

    state.formOpen = true;
    renderModeVisibility();

    var existing = ui.formOverlay.querySelector('.askwp-form-card');
    if (existing) {
      return;
    }

    buildFormUI();
    sendStatusPing('[FORM_OPENED]');
  }

  function closeFormOverlay(persistDraft) {
    if (!ui || !ui.formOverlay) { return; }

    if (persistDraft) {
      var form = ui.formOverlay.querySelector('form');
      if (form) {
        persistFormDraftFromForm(form);
      }
    }

    state.formOpen = false;
    renderModeVisibility();

    if (state.panelOpen) {
      ui.input.focus();
    }
  }

  function buildFormUI() {
    var schema = cfg.form_schema;
    var fields = schema.fields || [];
    var steps = Number(schema.steps) || 1;
    var hasSteps = steps > 1;

    ui.formOverlay.innerHTML = '';

    var card = document.createElement('div');
    card.className = 'askwp-form-card';

    // Header.
    var head = document.createElement('div');
    head.className = 'askwp-form-head';

    var title = document.createElement('strong');
    title.className = 'askwp-form-title';
    title.textContent = schema.title || 'Form';

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'askwp-form-close';
    closeBtn.setAttribute('aria-label', str.close || 'Close');
    closeBtn.textContent = '\u00d7';
    closeBtn.addEventListener('click', function () {
      closeFormOverlay(true);
    });

    head.appendChild(title);
    head.appendChild(closeBtn);
    card.appendChild(head);

    // Form.
    var form = document.createElement('form');
    form.className = 'askwp-form';

    if (hasSteps) {
      for (var s = 1; s <= steps; s++) {
        var stepDiv = document.createElement('div');
        stepDiv.className = 'askwp-step';
        stepDiv.setAttribute('data-step', String(s));
        if (s !== 1) { stepDiv.hidden = true; }

        var stepFields = fields.filter(function (f) {
          return (Number(f.step) || 1) === s;
        });

        stepFields.forEach(function (f) {
          stepDiv.appendChild(buildFieldElement(f));
        });

        // Navigation.
        var nav = document.createElement('div');
        nav.className = 'askwp-form-nav';

        if (s > 1) {
          var prevBtn = document.createElement('button');
          prevBtn.type = 'button';
          prevBtn.className = 'askwp-form-prev';
          prevBtn.textContent = '\u2190 Back';
          prevBtn.setAttribute('data-prev', String(s - 1));
          nav.appendChild(prevBtn);
        }

        if (s < steps) {
          var nextBtn = document.createElement('button');
          nextBtn.type = 'button';
          nextBtn.className = 'askwp-form-next';
          nextBtn.textContent = 'Next \u2192';
          nextBtn.setAttribute('data-next', String(s + 1));
          nav.appendChild(nextBtn);
        } else {
          var submitBtn = document.createElement('button');
          submitBtn.type = 'submit';
          submitBtn.className = 'askwp-form-submit';
          submitBtn.textContent = str.send || 'Send';
          nav.appendChild(submitBtn);
        }

        stepDiv.appendChild(nav);
        form.appendChild(stepDiv);
      }
    } else {
      fields.forEach(function (f) {
        form.appendChild(buildFieldElement(f));
      });

      var singleNav = document.createElement('div');
      singleNav.className = 'askwp-form-nav';
      var singleSubmit = document.createElement('button');
      singleSubmit.type = 'submit';
      singleSubmit.className = 'askwp-form-submit';
      singleSubmit.textContent = str.send || 'Send';
      singleNav.appendChild(singleSubmit);
      form.appendChild(singleNav);
    }

    var statusNode = document.createElement('div');
    statusNode.className = 'askwp-status';
    statusNode.setAttribute('aria-live', 'polite');
    form.appendChild(statusNode);

    card.appendChild(form);
    ui.formOverlay.appendChild(card);

    // Restore draft.
    if (state.formDraft) {
      applyFormDraft(form, state.formDraft);
    }

    // Event listeners.
    form.addEventListener('click', function (e) {
      var target = e.target;
      if (target.classList.contains('askwp-form-next')) {
        e.preventDefault();
        var nextStep = Number(target.getAttribute('data-next'));
        var currentStep = nextStep - 1;
        if (validateStepFields(form, fields, currentStep, statusNode)) {
          setFormStep(form, nextStep);
          persistFormDraftFromForm(form);
        }
      }
      if (target.classList.contains('askwp-form-prev')) {
        e.preventDefault();
        var prevStep = Number(target.getAttribute('data-prev'));
        clearStatus(statusNode);
        setFormStep(form, prevStep);
        persistFormDraftFromForm(form);
      }
    });

    form.addEventListener('input', function () {
      persistFormDraftFromForm(form);
    });

    form.addEventListener('change', function () {
      persistFormDraftFromForm(form);
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      clearStatus(statusNode);

      if (!validateAllFields(form, fields, statusNode)) { return; }

      persistFormDraftFromForm(form);
      submitForm(form, fields, statusNode);
    });
  }

  function buildFieldElement(field) {
    var wrap = document.createElement('div');
    wrap.className = 'askwp-field';

    var label = document.createElement('label');
    label.textContent = (field.label || field.name || '') + (field.required ? '*' : '');
    wrap.appendChild(label);

    var type = field.type || 'text';
    var name = field.name || '';
    var maxLen = Number(field.maxlength) || 500;
    var required = !!field.required;

    if (type === 'textarea') {
      var ta = document.createElement('textarea');
      ta.name = name;
      ta.maxLength = maxLen;
      if (required) { ta.required = true; }
      wrap.appendChild(ta);
    } else if (type === 'select') {
      var sel = document.createElement('select');
      sel.name = name;
      if (required) { sel.required = true; }

      var defaultOpt = document.createElement('option');
      defaultOpt.value = '';
      defaultOpt.textContent = '-- Select --';
      sel.appendChild(defaultOpt);

      var options = field.options || [];
      if (typeof options === 'string') {
        options = options.split(',').map(function (o) { return o.trim(); });
      }
      options.forEach(function (opt) {
        var o = document.createElement('option');
        o.value = opt;
        o.textContent = opt;
        sel.appendChild(o);
      });
      wrap.appendChild(sel);
    } else if (type === 'checkbox') {
      var cbLabel = document.createElement('label');
      cbLabel.className = 'askwp-checkbox';
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.name = name;
      cb.value = '1';
      cbLabel.appendChild(cb);
      var cbText = document.createElement('span');
      cbText.textContent = field.label || name;
      cbLabel.appendChild(cbText);
      // Replace the generic label with the checkbox label.
      wrap.innerHTML = '';
      wrap.appendChild(cbLabel);
    } else {
      var inp = document.createElement('input');
      inp.type = type === 'phone' ? 'tel' : type;
      inp.name = name;
      inp.maxLength = maxLen;
      if (required) { inp.required = true; }
      wrap.appendChild(inp);
    }

    return wrap;
  }

  function setFormStep(form, stepNumber) {
    var allSteps = form.querySelectorAll('.askwp-step');
    for (var i = 0; i < allSteps.length; i++) {
      allSteps[i].hidden = (Number(allSteps[i].getAttribute('data-step')) !== stepNumber);
    }
  }

  function validateStepFields(form, fields, stepNumber, statusNode) {
    clearStatus(statusNode);
    var stepFields = fields.filter(function (f) {
      return (Number(f.step) || 1) === stepNumber;
    });

    for (var i = 0; i < stepFields.length; i++) {
      var f = stepFields[i];
      if (!f.required) { continue; }
      var el = form.elements[f.name];
      if (!el) { continue; }

      var val = (f.type === 'checkbox') ? el.checked : String(el.value || '').trim();

      if (!val) {
        setStatus(statusNode, 'Please fill in all required fields.', true);
        return false;
      }

      if (f.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
        setStatus(statusNode, 'Please enter a valid email address.', true);
        return false;
      }
    }

    return true;
  }

  function validateAllFields(form, fields, statusNode) {
    var steps = Number(cfg.form_schema.steps) || 1;
    for (var s = 1; s <= steps; s++) {
      if (!validateStepFields(form, fields, s, statusNode)) {
        return false;
      }
    }
    return true;
  }

  function collectFormData(form, fields) {
    var data = { page_url: window.location.href };

    fields.forEach(function (f) {
      var el = form.elements[f.name];
      if (!el) { return; }

      if (f.type === 'checkbox') {
        data[f.name] = !!el.checked;
      } else if (f.type === 'textarea') {
        data[f.name] = sanitizeText(el.value || '', Number(f.maxlength) || 2000);
      } else {
        data[f.name] = sanitizeText(el.value || '', Number(f.maxlength) || 500);
      }
    });

    return data;
  }

  function applyFormDraft(form, draft) {
    if (!draft || typeof draft !== 'object') { return; }

    var fields = cfg.form_schema ? (cfg.form_schema.fields || []) : [];
    fields.forEach(function (f) {
      var el = form.elements[f.name];
      if (!el) { return; }

      if (f.type === 'checkbox') {
        el.checked = !!draft[f.name];
      } else if (typeof draft[f.name] === 'string') {
        el.value = draft[f.name];
      }
    });
  }

  function persistFormDraftFromForm(form) {
    var fields = cfg.form_schema ? (cfg.form_schema.fields || []) : [];
    persistFormDraft(collectFormData(form, fields));
  }

  async function submitForm(form, fields, statusNode) {
    setStatus(statusNode, str.loading || 'Sending...', false);

    var submitBtn = form.querySelector('.askwp-form-submit');
    if (submitBtn) { submitBtn.disabled = true; }

    var payload = collectFormData(form, fields);
    payload.session_id = state.sessionId;

    try {
      var res = await fetch(cfg.form_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: 'same-origin'
      });

      if (!res.ok) {
        throw new Error('form_http_' + res.status);
      }

      var data = await res.json();
      if (!data || data.ok !== true) {
        throw new Error('form_invalid_response');
      }

      var successMsg = (cfg.form_schema && cfg.form_schema.success_message)
        || 'Thank you. Your submission has been sent.';
      setStatus(statusNode, successMsg, false, true);
      addMessage('assistant', successMsg);
      sendStatusPing('[FORM_SUBMITTED]');

      window.setTimeout(function () {
        form.reset();
        setFormStep(form, 1);
        clearStatus(statusNode);
        clearFormDraft();
        closeFormOverlay(false);
      }, 900);
    } catch (e) {
      setStatus(statusNode, str.error || 'Submission failed. Please try again later.', true);
    } finally {
      if (submitBtn) { submitBtn.disabled = false; }
    }
  }

  function setStatus(node, text, isError, isOk) {
    node.textContent = text;
    node.className = 'askwp-status';
    if (isError) { node.classList.add('askwp-error'); }
    if (isOk) { node.classList.add('askwp-ok'); }
  }

  function clearStatus(node) {
    node.textContent = '';
    node.className = 'askwp-status';
  }

  // ── Build widget DOM ──

  function buildWidgetUI() {
    var isLeft = cfg.position === 'bottom-left';

    var root = document.createElement('div');
    root.className = 'askwp-widget';
    if (isLeft) { root.classList.add('askwp-pos-left'); }

    // Launcher.
    var launcher = document.createElement('button');
    launcher.type = 'button';
    launcher.className = 'askwp-launcher';
    launcher.setAttribute('aria-label', str.toggle_label || cfg.bot_name || 'Chat');

    if (cfg.chat_icon === 'custom' && cfg.chat_icon_custom_url) {
      var customImg = document.createElement('img');
      customImg.src = cfg.chat_icon_custom_url;
      customImg.alt = '';
      customImg.className = 'askwp-launcher-icon-img';
      launcher.appendChild(customImg);
    } else {
      var iconHtml = ICON_PRESETS[cfg.chat_icon] || ICON_PRESETS['chat-bubble'];
      var iconWrap = document.createElement('span');
      iconWrap.className = 'askwp-launcher-icon';
      iconWrap.innerHTML = iconHtml;
      launcher.appendChild(iconWrap);
    }

    // Backdrop (mobile overlay).
    var backdrop = document.createElement('div');
    backdrop.className = 'askwp-backdrop';
    backdrop.hidden = true;

    // Panel.
    var panel = document.createElement('section');
    panel.className = 'askwp-panel';

    // Panel header.
    var panelHeader = document.createElement('div');
    panelHeader.className = 'askwp-panel-header';

    var headerTitle = document.createElement('span');
    headerTitle.className = 'askwp-panel-title';
    headerTitle.textContent = str.title || cfg.bot_name || 'Chat';

    var headerClose = document.createElement('button');
    headerClose.type = 'button';
    headerClose.className = 'askwp-panel-close';
    headerClose.setAttribute('aria-label', str.close || 'Close');
    headerClose.textContent = '\u00d7';

    panelHeader.appendChild(headerTitle);
    panelHeader.appendChild(headerClose);

    // Chat list.
    var chatList = document.createElement('div');
    chatList.className = 'askwp-chat-list';

    // Panel footer.
    var panelFooter = document.createElement('div');
    panelFooter.className = 'askwp-panel-footer';

    // Input row.
    var inputRow = document.createElement('div');
    inputRow.className = 'askwp-input-row';

    var input = document.createElement('textarea');
    input.className = 'askwp-input';
    input.placeholder = str.placeholder || 'Type your message...';
    input.maxLength = 1500;
    input.rows = 1;

    var sendBtn = document.createElement('button');
    sendBtn.type = 'button';
    sendBtn.className = 'askwp-send';
    sendBtn.setAttribute('aria-label', str.send || 'Send');
    sendBtn.innerHTML = SEND_ICON;

    inputRow.appendChild(input);
    inputRow.appendChild(sendBtn);

    // Actions row.
    var actionsRow = document.createElement('div');
    actionsRow.className = 'askwp-actions';

    if (cfg.form_enabled && cfg.form_schema) {
      var formBtn = document.createElement('button');
      formBtn.type = 'button';
      formBtn.className = 'askwp-action-btn askwp-action-form';
      formBtn.textContent = str.open_form || cfg.form_schema.trigger_label || 'Open Form';
      actionsRow.appendChild(formBtn);
    }

    var resetBtn = document.createElement('button');
    resetBtn.type = 'button';
    resetBtn.className = 'askwp-action-btn askwp-action-reset';
    resetBtn.textContent = str.reset || 'Reset';
    actionsRow.appendChild(resetBtn);

    panelFooter.appendChild(inputRow);
    panelFooter.appendChild(actionsRow);

    // Form overlay.
    var formOverlay = document.createElement('div');
    formOverlay.className = 'askwp-form-overlay';
    formOverlay.hidden = true;

    // Assemble panel.
    panel.appendChild(panelHeader);
    panel.appendChild(chatList);
    panel.appendChild(panelFooter);
    panel.appendChild(formOverlay);

    // Assemble root.
    root.appendChild(launcher);
    root.appendChild(backdrop);
    root.appendChild(panel);

    document.body.appendChild(root);

    // ── Event listeners ──

    launcher.addEventListener('click', function () {
      if (state.panelOpen) { closePanel(); }
      else { openPanel(); }
    });

    headerClose.addEventListener('click', function () {
      closePanel();
    });

    backdrop.addEventListener('click', function () {
      closePanel();
    });

    sendBtn.addEventListener('click', function () {
      handleSendMessage();
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSendMessage();
      }
    });

    input.addEventListener('input', function () {
      input.style.height = 'auto';
      input.style.height = input.scrollHeight + 'px';
      renderInputState();
    });

    if (cfg.form_enabled && cfg.form_schema) {
      var formActionBtn = actionsRow.querySelector('.askwp-action-form');
      if (formActionBtn) {
        formActionBtn.addEventListener('click', function (e) {
          e.preventDefault();
          openFormOverlay();
        });
      }
    }

    resetBtn.addEventListener('click', function (e) {
      e.preventDefault();
      resetChatState();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') { return; }
      if (state.formOpen) {
        closeFormOverlay(true);
        return;
      }
      if (state.panelOpen) {
        closePanel();
      }
    });

    return {
      root: root,
      launcher: launcher,
      backdrop: backdrop,
      panel: panel,
      chatList: chatList,
      input: input,
      sendBtn: sendBtn,
      formOverlay: formOverlay
    };
  }
})();
