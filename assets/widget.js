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
  var ATTACH_ICON = '<svg viewBox="0 0 24 24" fill="none"><path d="M12 6v12M6 12h12" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var COPY_ICON = '<svg viewBox="0 0 24 24" fill="none"><rect x="9" y="9" width="10" height="10" rx="2" stroke="currentColor" stroke-width="2"></rect><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>';
  var COPY_OK_ICON = '<svg viewBox="0 0 24 24" fill="none"><path d="M6 12.5l3.4 3.5L18 8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"></path></svg>';

  function normalizePanelSize(value) {
    var normalized = String(value || '').trim().toLowerCase();
    if (normalized !== 'compact' && normalized !== 'large') {
      normalized = 'normal';
    }
    return normalized;
  }

  function parseBool(value, fallback) {
    if (typeof value === 'boolean') { return value; }
    if (typeof value === 'number') { return value !== 0; }
    if (typeof value === 'string') {
      var normalized = value.trim().toLowerCase();
      if (normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on') { return true; }
      if (normalized === '0' || normalized === 'false' || normalized === 'no' || normalized === 'off') { return false; }
    }
    return !!fallback;
  }

  var SHOW_STREAM_STEPS = parseBool(cfg.show_stream_steps, true);
  var str = cfg.strings || {};
  var themeMediaQuery = null;
  var themeMediaChangeHandler = null;
  var modeCustomStyleEl = null;

  var state = {
    sessionId: getOrCreateSessionId(),
    messages: loadMessages(),
    formDraft: loadFormDraft(),
    pendingImage: null,
    retryPayloads: {},
    connection: (typeof navigator !== 'undefined' && navigator.onLine === false) ? 'offline' : 'online',
    panelOpen: false,
    formOpen: false,
    waiting: false
  };

  var ui = buildWidgetUI();
  setThemeMode(cfg.theme_mode || 'auto');
  renderAll();
  window.setInterval(refreshMessageTimestamps, 45000);

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

  function sourceTitleFromUrl(url) {
    var normalized = normalizeHttpUrl(url);
    if (!normalized) { return ''; }

    try {
      var parsed = new URL(normalized);
      var path = String(parsed.pathname || '/');
      if (path === '/' || !path) {
        return sanitizeText(parsed.hostname.replace(/^www\./i, ''), 120);
      }

      var parts = path.split('/').filter(Boolean);
      var slug = parts.length ? parts[parts.length - 1] : parsed.hostname;
      slug = decodeURIComponent(slug).replace(/[-_]+/g, ' ').trim();
      if (!slug) {
        slug = parsed.hostname.replace(/^www\./i, '');
      }
      return sanitizeText(slug, 120);
    } catch (e) {
      return '';
    }
  }

  function extractSourcesFromText(text, maxItems) {
    var raw = String(text || '');
    if (!raw) { return []; }

    var limit = Math.max(1, Number(maxItems || 6));
    var out = [];
    var seen = Object.create(null);

    function pushSource(urlValue, titleValue) {
      var url = normalizeHttpUrl(urlValue);
      if (!url || seen[url]) { return; }
      seen[url] = 1;

      var title = sanitizeText(titleValue || '', 120);
      if (!title) {
        title = sourceTitleFromUrl(url);
      }
      if (!title) {
        title = sanitizeText(url, 120);
      }

      out.push({ title: title, url: url });
    }

    raw.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, function (_m, label, url) {
      pushSource(url, label);
      return _m;
    });

    var bare = raw.match(/https?:\/\/[^\s<>()]+/gi) || [];
    for (var i = 0; i < bare.length; i++) {
      var candidate = String(bare[i] || '').replace(/[),.;!?]+$/, '');
      pushSource(candidate, '');
      if (out.length >= limit) { break; }
    }

    return out.slice(0, limit);
  }

  function mergeSources(primary, secondary, maxItems) {
    var limit = Math.max(1, Number(maxItems || 6));
    var merged = [];
    var seen = Object.create(null);

    function append(list) {
      if (!Array.isArray(list)) { return; }
      for (var i = 0; i < list.length; i++) {
        var item = list[i];
        if (!item || typeof item !== 'object') { continue; }
        var url = normalizeHttpUrl(item.url || '');
        if (!url || seen[url]) { continue; }
        seen[url] = 1;

        var title = sanitizeText(item.title || '', 120);
        if (!title) {
          title = sourceTitleFromUrl(url);
        }
        merged.push({ title: title || sanitizeText(url, 120), url: url });
        if (merged.length >= limit) { return; }
      }
    }

    append(primary);
    if (merged.length < limit) {
      append(secondary);
    }

    return merged.slice(0, limit);
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
        var ts = Number(m.ts || 0);
        var content = role === 'assistant'
          ? sanitizeAssistantText(m.content || '', 2500)
          : sanitizeText(m.content || '', 1500);

        if (!content) { return null; }
        if (!isFinite(ts) || ts <= 0) {
          ts = Date.now();
        }

        var out = { role: role, content: content, ts: ts };
        if (role === 'assistant') {
          out.sources = mergeSources(
            normalizeSources(m.sources || []),
            extractSourcesFromText(content, 6),
            6
          );
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
    var serializable = state.messages.map(function (m) {
      var ts = Number(m.ts || 0);
      if (!isFinite(ts) || ts <= 0) {
        ts = Date.now();
      }
      var out = {
        role: m.role === 'assistant' ? 'assistant' : 'user',
        content: m.role === 'assistant'
          ? sanitizeAssistantText(m.content || '', 2500)
          : sanitizeText(m.content || '', 1500),
        ts: ts
      };
      if (out.role === 'assistant') {
        out.sources = mergeSources(
          normalizeSources(m.sources || []),
          extractSourcesFromText(out.content, 6),
          6
        );
      }
      return out;
    });
    safeSetItem(STORAGE_MESSAGES, JSON.stringify(serializable));
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

    escaped = escaped.replace(/!\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/g, function (_m, alt, url) {
      var nUrl = normalizeHttpUrl(String(url || '').replace(/&amp;/g, '&'));
      if (!nUrl) { return alt || ''; }
      var altText = String(alt || '');
      return '<img src="' + escapeHtml(nUrl) + '" alt="' + altText + '" loading="lazy" decoding="async">';
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

  function parseTableCells(line) {
    var text = String(line || '').trim();
    if (!text) { return []; }
    if (text.charAt(0) === '|') { text = text.slice(1); }
    if (text.charAt(text.length - 1) === '|') { text = text.slice(0, -1); }
    return text.split('|').map(function (cell) { return cell.trim(); });
  }

  function isMarkdownTableSeparator(line) {
    var cells = parseTableCells(line);
    if (!cells.length) { return false; }
    return cells.every(function (cell) {
      var compact = cell.replace(/\s+/g, '');
      return /^:?-{3,}:?$/.test(compact);
    });
  }

  function parseListItemLine(line) {
    var raw = String(line || '');
    var match = /^([ \t]*)([-*]|\d+\.)\s+(.+)$/.exec(raw);
    if (!match) { return null; }

    var indentChars = match[1].replace(/\t/g, '  ');
    var indent = Math.floor(indentChars.length / 2);
    var marker = match[2];
    var type = /^\d+\.$/.test(marker) ? 'ol' : 'ul';

    return {
      indent: indent,
      type: type,
      content: match[3]
    };
  }

  function renderMarkdownTable(lines) {
    if (!Array.isArray(lines) || lines.length < 2) { return ''; }

    var headerCells = parseTableCells(lines[0]);
    var separatorCells = parseTableCells(lines[1]);
    if (!headerCells.length || !isMarkdownTableSeparator(lines[1])) { return ''; }

    var aligns = separatorCells.map(function (cell) {
      var compact = cell.replace(/\s+/g, '');
      var starts = compact.charAt(0) === ':';
      var ends = compact.charAt(compact.length - 1) === ':';
      if (starts && ends) { return 'center'; }
      if (ends) { return 'right'; }
      return 'left';
    });

    var html = '<div class="askwp-table-wrap"><table><thead><tr>';
    headerCells.forEach(function (cell, idx) {
      var align = aligns[idx] || 'left';
      html += '<th style="text-align:' + align + ';">' + renderInlineMarkdown(cell) + '</th>';
    });
    html += '</tr></thead>';

    if (lines.length > 2) {
      html += '<tbody>';
      lines.slice(2).forEach(function (rowLine) {
        var rowCells = parseTableCells(rowLine);
        if (!rowCells.length) { return; }
        html += '<tr>';
        for (var i = 0; i < headerCells.length; i++) {
          var align = aligns[i] || 'left';
          var rawCell = rowCells[i] || '';
          html += '<td style="text-align:' + align + ';">' + renderInlineMarkdown(rawCell) + '</td>';
        }
        html += '</tr>';
      });
      html += '</tbody>';
    }

    html += '</table></div>';
    return html;
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
    var listStack = [];

    function flushParagraph() {
      if (!paragraphLines.length) { return; }
      htmlParts.push('<p>' + renderInlineMarkdown(paragraphLines.join('\n')).replace(/\n/g, '<br>') + '</p>');
      paragraphLines = [];
    }

    function closeListLevel() {
      if (!listStack.length) { return; }
      var top = listStack[listStack.length - 1];
      if (top.liOpen) {
        htmlParts.push('</li>');
        top.liOpen = false;
      }
      htmlParts.push('</' + top.type + '>');
      listStack.pop();
    }

    function closeAllLists() {
      while (listStack.length) {
        closeListLevel();
      }
    }

    function openList(type, indent) {
      htmlParts.push('<' + type + '>');
      listStack.push({ type: type, indent: indent, liOpen: false });
    }

    function handleListItem(item) {
      if (!listStack.length) {
        openList(item.type, item.indent);
      } else {
        var current = listStack[listStack.length - 1];
        if (item.indent > current.indent + 1) {
          item.indent = current.indent + 1;
        }

        while (listStack.length && item.indent < listStack[listStack.length - 1].indent) {
          closeListLevel();
        }

        current = listStack[listStack.length - 1];
        if (current && item.indent === current.indent && item.type !== current.type) {
          closeListLevel();
          current = listStack[listStack.length - 1];
        }

        if (!current || item.indent > current.indent) {
          if (current && !current.liOpen) {
            htmlParts.push('<li>');
            current.liOpen = true;
          }
          openList(item.type, item.indent);
        }
      }

      var top = listStack[listStack.length - 1];
      if (top.liOpen) {
        htmlParts.push('</li>');
        top.liOpen = false;
      }
      htmlParts.push('<li>' + renderInlineMarkdown(item.content));
      top.liOpen = true;
    }

    var i = 0;
    while (i < lines.length) {
      var line = String(lines[i] || '');
      var trimmed = String(line || '').trim();
      var headingMatch = /^(#{1,6})\s+(.+)$/.exec(trimmed);
      var tokenOnly = /^%%ASKWP_BC_(\d+)%%$/.exec(trimmed);
      var listItem = parseListItemLine(line);
      var isHr = /^([-*_])(?:\s*\1){2,}$/.test(trimmed);
      var isQuote = /^\s*>/.test(line);

      if (!trimmed) {
        flushParagraph();
        closeAllLists();
        i++;
        continue;
      }

      if (tokenOnly) {
        flushParagraph();
        closeAllLists();
        htmlParts.push(trimmed);
        i++;
        continue;
      }

      if (
        line.indexOf('|') !== -1 &&
        i + 1 < lines.length &&
        isMarkdownTableSeparator(lines[i + 1])
      ) {
        var tableStart = i;
        flushParagraph();
        closeAllLists();

        var tableLines = [line, lines[i + 1]];
        i += 2;
        while (i < lines.length) {
          var nextLine = String(lines[i] || '');
          var nextTrimmed = nextLine.trim();
          if (!nextTrimmed || nextLine.indexOf('|') === -1) {
            break;
          }
          tableLines.push(nextLine);
          i++;
        }

        var tableHtml = renderMarkdownTable(tableLines);
        if (tableHtml) {
          htmlParts.push(tableHtml);
          continue;
        }

        i = tableStart;
        line = String(lines[i] || '');
        trimmed = line.trim();
        headingMatch = /^(#{1,6})\s+(.+)$/.exec(trimmed);
        tokenOnly = /^%%ASKWP_BC_(\d+)%%$/.exec(trimmed);
        listItem = parseListItemLine(line);
        isHr = /^([-*_])(?:\s*\1){2,}$/.test(trimmed);
        isQuote = /^\s*>/.test(line);
      }

      if (headingMatch) {
        flushParagraph();
        closeAllLists();
        var level = headingMatch[1].length;
        htmlParts.push('<h' + level + '>' + renderInlineMarkdown(headingMatch[2]) + '</h' + level + '>');
        i++;
        continue;
      }

      if (isHr) {
        flushParagraph();
        closeAllLists();
        htmlParts.push('<hr>');
        i++;
        continue;
      }

      if (isQuote) {
        flushParagraph();
        closeAllLists();

        var quoteLines = [];
        while (i < lines.length && /^\s*>/.test(String(lines[i] || ''))) {
          quoteLines.push(String(lines[i] || '').replace(/^\s*(>\s*)+/, ''));
          i++;
        }
        htmlParts.push('<blockquote><p>' + renderInlineMarkdown(quoteLines.join('\n')).replace(/\n/g, '<br>') + '</p></blockquote>');
        continue;
      }

      if (listItem) {
        flushParagraph();
        handleListItem(listItem);
        i++;
        continue;
      }

      closeAllLists();
      paragraphLines.push(line);
      i++;
    }

    flushParagraph();
    closeAllLists();

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

  function normalizeThemeMode(mode) {
    var normalized = String(mode || '').toLowerCase();
    if (normalized !== 'light' && normalized !== 'dark') {
      normalized = 'auto';
    }
    return normalized;
  }

  function onThemePreferenceChange() {
    applyThemeModeClass();
  }

  function ensureModeCustomStyleEl() {
    if (modeCustomStyleEl) {
      return modeCustomStyleEl;
    }
    modeCustomStyleEl = document.createElement('style');
    modeCustomStyleEl.setAttribute('data-askwp-mode-custom-css', '1');
    document.head.appendChild(modeCustomStyleEl);
    return modeCustomStyleEl;
  }

  function applyModeCustomCss() {
    var lightCss = String(cfg.custom_css_light || '').trim();
    var darkCss = String(cfg.custom_css_dark || '').trim();
    var hasAny = !!(lightCss || darkCss);

    if (!hasAny) {
      if (modeCustomStyleEl) {
        modeCustomStyleEl.textContent = '';
      }
      return;
    }

    var isDark = !!(ui && ui.root && ui.root.classList.contains('askwp-theme-dark'));
    var css = isDark ? darkCss : lightCss;
    var styleEl = ensureModeCustomStyleEl();
    if (styleEl.textContent !== css) {
      styleEl.textContent = css;
    }
  }

  function setThemeMode(mode) {
    cfg.theme_mode = normalizeThemeMode(mode);

    if (themeMediaQuery) {
      if (themeMediaChangeHandler && typeof themeMediaQuery.removeEventListener === 'function') {
        themeMediaQuery.removeEventListener('change', themeMediaChangeHandler);
      } else if (themeMediaChangeHandler && typeof themeMediaQuery.removeListener === 'function') {
        themeMediaQuery.removeListener(themeMediaChangeHandler);
      }
    }
    themeMediaQuery = null;
    themeMediaChangeHandler = null;

    if (cfg.theme_mode === 'auto' && window.matchMedia) {
      themeMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
      themeMediaChangeHandler = onThemePreferenceChange;
      if (typeof themeMediaQuery.addEventListener === 'function') {
        themeMediaQuery.addEventListener('change', themeMediaChangeHandler);
      } else if (typeof themeMediaQuery.addListener === 'function') {
        themeMediaQuery.addListener(themeMediaChangeHandler);
      }
    }

    applyThemeModeClass();
  }

  function applyThemeModeClass() {
    var mode = normalizeThemeMode(cfg.theme_mode);
    var isDark = false;

    if (mode === 'dark') {
      isDark = true;
    } else if (mode === 'auto' && window.matchMedia) {
      isDark = !!window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    ui.root.classList.toggle('askwp-theme-dark', isDark);
    ui.root.classList.toggle('askwp-theme-light', !isDark);
    applyModeCustomCss();
  }

  function formatClockTime(dateObj) {
    try {
      return dateObj.toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit'
      });
    } catch (e) {
      return dateObj.toTimeString().slice(0, 5);
    }
  }

  function formatMessageTimestamp(ts) {
    var value = Number(ts || 0);
    if (!isFinite(value) || value <= 0) {
      return '';
    }

    var now = Date.now();
    var diff = Math.max(0, now - value);
    var minute = 60 * 1000;
    var hour = 60 * minute;
    var day = 24 * hour;

    if (diff < minute) {
      return str.time_just_now || 'just now';
    }

    if (diff < hour) {
      return Math.floor(diff / minute) + 'm ago';
    }

    var dateObj = new Date(value);
    var nowObj = new Date(now);

    if (dateObj.toDateString() === nowObj.toDateString()) {
      return formatClockTime(dateObj);
    }

    if (diff < day * 7) {
      try {
        return dateObj.toLocaleString([], {
          weekday: 'short',
          hour: 'numeric',
          minute: '2-digit'
        });
      } catch (e) {
        return formatClockTime(dateObj);
      }
    }

    try {
      return dateObj.toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
      });
    } catch (e) {
      return formatClockTime(dateObj);
    }
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
    renderImageAttachment();
    renderInputState();
    renderConnectionState();
    renderModeVisibility();
  }

  function renderChatList() {
    ui.chatList.innerHTML = '';

    if (!state.messages.length) {
      var welcome = document.createElement('div');
      welcome.className = 'askwp-msg askwp-msg-assistant';
      welcome.tabIndex = 0;
      welcome.setAttribute('role', 'article');
      welcome.setAttribute('aria-label', str.assistant_message || 'Assistant message');
      if (cfg.bot_avatar_url) {
        welcome.appendChild(createAvatar());
      }
      var welcomeBody = document.createElement('div');
      welcomeBody.className = 'askwp-msg-body';
      welcomeBody.innerHTML = '<p>' + escapeHtml(str.title || cfg.bot_name || 'Chat Assistant') + '</p>';
      welcome.appendChild(welcomeBody);
      ui.chatList.appendChild(welcome);

      if (Array.isArray(cfg.suggested_questions) && cfg.suggested_questions.length) {
        var sqWrap = document.createElement('div');
        sqWrap.className = 'askwp-suggested-questions';
        cfg.suggested_questions.forEach(function (q) {
          var text = sanitizeText(q, 150);
          if (!text) { return; }
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'askwp-sq-btn';
          btn.textContent = text;
          btn.addEventListener('click', function () {
            ui.input.value = text;
            handleSendMessage();
          });
          sqWrap.appendChild(btn);
        });
        ui.chatList.appendChild(sqWrap);
      }
    }

    state.messages.forEach(function (msg) {
      var el = document.createElement('div');
      el.className = 'askwp-msg askwp-msg-' + msg.role;
      if (msg.role === 'assistant' && msg.isError) {
        el.classList.add('askwp-msg-error');
      }
      el.tabIndex = 0;
      el.setAttribute('role', 'article');
      el.setAttribute('aria-label', msg.role === 'assistant'
        ? (str.assistant_message || 'Assistant message')
        : (str.user_message || 'Your message'));

      if (msg.role === 'assistant' && cfg.bot_avatar_url) {
        el.appendChild(createAvatar());
      }

      var body = document.createElement('div');
      body.className = 'askwp-msg-body';

      if (msg.role === 'assistant') {
        body.innerHTML = renderAssistantMarkdown(msg.content);

        var displaySources = mergeSources(
          normalizeSources(msg.sources || []),
          extractSourcesFromText(msg.content || '', 6),
          6
        );

        if (displaySources.length) {
          var srcWrap = document.createElement('div');
          srcWrap.className = 'askwp-msg-sources';
          displaySources.forEach(function (s) {
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
        if (msg.content) {
          var userText = document.createElement('p');
          userText.textContent = msg.content;
          body.appendChild(userText);
        }

        if (msg.attachment && /^data:image\//i.test(String(msg.attachment.dataUrl || ''))) {
          var userAttachment = document.createElement('div');
          userAttachment.className = 'askwp-user-attachment';

          var userAttachmentImg = document.createElement('img');
          userAttachmentImg.src = msg.attachment.dataUrl;
          userAttachmentImg.alt = msg.attachment.name || '';
          userAttachmentImg.loading = 'lazy';
          userAttachmentImg.decoding = 'async';
          userAttachment.appendChild(userAttachmentImg);
          body.appendChild(userAttachment);
        }
      }

      var msgTime = formatMessageTimestamp(msg.ts);
      if (msgTime) {
        var timeEl = document.createElement('div');
        timeEl.className = 'askwp-msg-time';
        timeEl.setAttribute('data-ts', String(Number(msg.ts || 0)));
        timeEl.textContent = msgTime;
        body.appendChild(timeEl);
      }

      if (msg.role === 'assistant' && msg.retryToken) {
        appendRetryButton(body, msg.retryToken);
      }

      if (msg.role === 'assistant') {
        appendCopyButton(body, msg.content);
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
    refreshMessageTimestamps();
  }

  function refreshMessageTimestamps() {
    if (!ui || !ui.chatList) { return; }
    var nodes = ui.chatList.querySelectorAll('.askwp-msg-time[data-ts]');
    for (var i = 0; i < nodes.length; i++) {
      var node = nodes[i];
      var ts = Number(node.getAttribute('data-ts') || 0);
      var label = formatMessageTimestamp(ts);
      if (!label) { continue; }
      if (node.textContent !== label) {
        node.textContent = label;
      }
    }
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

  async function copyTextToClipboard(text) {
    var value = String(text || '');
    if (!value) { return false; }

    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
        return true;
      }
    } catch (_err) {
      // fallback below
    }

    try {
      var ta = document.createElement('textarea');
      ta.value = value;
      ta.setAttribute('readonly', 'readonly');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      ta.style.top = '-9999px';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      var copied = document.execCommand('copy');
      document.body.removeChild(ta);
      return !!copied;
    } catch (_e) {
      return false;
    }
  }

  function appendCopyButton(targetBody, rawText) {
    if (!targetBody) { return; }
    var copyText = sanitizeAssistantText(rawText, 2500);
    if (!copyText) { return; }

    targetBody.classList.add('askwp-copyable');

    var copyLabel = str.copy_message || 'Copy message';
    var copiedLabel = str.copied || 'Copied';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'askwp-msg-copy';
    btn.innerHTML = COPY_ICON;
    btn.setAttribute('aria-label', copyLabel);
    btn.setAttribute('title', copyLabel);
    btn.setAttribute('data-label', copyLabel);

    var resetTimer = null;
    function resetCopyButtonState() {
      if (resetTimer) {
        clearTimeout(resetTimer);
        resetTimer = null;
      }
      btn.classList.remove('askwp-msg-copy-copied');
      btn.classList.remove('askwp-msg-copy-failed');
      btn.innerHTML = COPY_ICON;
      btn.setAttribute('aria-label', copyLabel);
      btn.setAttribute('title', copyLabel);
      btn.setAttribute('data-label', copyLabel);
    }

    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      if (btn.disabled) { return; }

      btn.disabled = true;
      copyTextToClipboard(copyText).then(function (ok) {
        resetCopyButtonState();
        if (ok) {
          btn.classList.add('askwp-msg-copy-copied');
          btn.innerHTML = COPY_OK_ICON;
          btn.setAttribute('aria-label', copiedLabel);
          btn.setAttribute('title', copiedLabel);
          btn.setAttribute('data-label', copiedLabel);
          resetTimer = setTimeout(function () {
            resetCopyButtonState();
          }, 1400);
        } else {
          btn.classList.add('askwp-msg-copy-failed');
          resetTimer = setTimeout(function () {
            btn.classList.remove('askwp-msg-copy-failed');
          }, 900);
        }
      }).finally(function () {
        btn.disabled = false;
      });
    });

    targetBody.appendChild(btn);
  }

  function clonePayload(payload) {
    if (!payload || typeof payload !== 'object') { return null; }
    try {
      return JSON.parse(JSON.stringify(payload));
    } catch (_e) {
      return null;
    }
  }

  function rememberRetryPayload(payload) {
    var cloned = clonePayload(payload);
    if (!cloned) { return ''; }
    var token = 'retry-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    state.retryPayloads[token] = cloned;

    var keys = Object.keys(state.retryPayloads);
    if (keys.length > 12) {
      keys.sort();
      var toDrop = keys.length - 12;
      for (var i = 0; i < toDrop; i++) {
        delete state.retryPayloads[keys[i]];
      }
    }

    return token;
  }

  function getRetryPayload(token) {
    var key = String(token || '').trim();
    if (!key || !state.retryPayloads[key]) {
      return null;
    }
    return clonePayload(state.retryPayloads[key]);
  }

  function classifyChatError(err) {
    var text = String(err && err.message ? err.message : '');
    var offline = (typeof navigator !== 'undefined' && navigator.onLine === false);

    if (offline) {
      return {
        message: str.error_offline || 'You appear to be offline. Please reconnect and retry.',
        connection: 'offline',
        code: 'offline'
      };
    }

    if (/http_5\d\d/.test(text)) {
      return {
        message: str.error_server || 'The assistant is temporarily unavailable. Please retry.',
        connection: 'issue',
        code: 'server'
      };
    }

    if (/http_4\d\d/.test(text)) {
      return {
        message: str.error_server || 'The assistant is temporarily unavailable. Please retry.',
        connection: 'issue',
        code: 'http'
      };
    }

    if (/failed to fetch|network|load failed|typeerror|aborterror/i.test(text)) {
      return {
        message: str.error_network || 'We could not reach the server. Please try again.',
        connection: 'issue',
        code: 'network'
      };
    }

    return {
      message: str.error || 'The chat is currently unavailable. Please try again later.',
      connection: 'issue',
      code: 'generic'
    };
  }

  function renderConnectionState() {
    if (!ui || !ui.connection || !ui.connectionLabel) { return; }
    var mode = String(state.connection || 'online');
    if (mode !== 'offline' && mode !== 'connecting' && mode !== 'issue') {
      mode = 'online';
    }

    var label = str.connection_online || 'Online';
    if (mode === 'offline') {
      label = str.connection_offline || 'Offline';
    } else if (mode === 'connecting') {
      label = str.connection_connecting || 'Connecting';
    } else if (mode === 'issue') {
      label = str.connection_issue || 'Issue detected';
    }

    ui.connection.className = 'askwp-connection askwp-conn-' + mode;
    ui.connectionLabel.textContent = label;
  }

  function setConnectionState(mode) {
    var next = String(mode || 'online');
    if (next !== 'offline' && next !== 'connecting' && next !== 'issue') {
      next = 'online';
    }
    if (state.connection === next) { return; }
    state.connection = next;
    renderConnectionState();
  }

  function retryFailedMessage(token) {
    if (state.waiting) { return; }
    var payload = getRetryPayload(token);
    if (!payload) {
      addMessage('assistant', str.retry_unavailable || 'Retry is no longer available for that message.');
      return;
    }

    var tokenValue = String(token || '');
    if (tokenValue) {
      state.messages = state.messages.filter(function (m) {
        return !(m && m.role === 'assistant' && m.retryToken === tokenValue);
      });
      persistMessages();
      renderAll();
    }

    handleSendMessage({ retryPayload: payload });
  }

  function appendRetryButton(targetBody, retryToken) {
    var token = String(retryToken || '').trim();
    if (!targetBody || !token) { return; }

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'askwp-msg-retry';
    btn.textContent = str.retry || 'Retry';
    btn.setAttribute('aria-label', str.retry || 'Retry');
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      retryFailedMessage(token);
    });
    targetBody.appendChild(btn);
  }

  function scrollToBottom() {
    window.setTimeout(function () {
      ui.chatList.scrollTop = ui.chatList.scrollHeight;
    }, 30);
  }

  function renderInputState() {
    var waiting = !!state.waiting;
    var empty = !ui.input.value.trim() && !state.pendingImage;
    ui.input.disabled = waiting;
    ui.sendBtn.disabled = waiting || empty;
    if (ui.attachBtn) {
      ui.attachBtn.disabled = waiting;
    }
    if (ui.fileInput) {
      ui.fileInput.disabled = waiting;
    }
    if (ui.imageRemoveBtn) {
      ui.imageRemoveBtn.disabled = waiting;
    }
  }

  function renderImageAttachment() {
    if (!ui.attachmentRow || !ui.attachmentName || !ui.attachmentPreview) {
      return;
    }

    if (!state.pendingImage) {
      ui.attachmentRow.hidden = true;
      ui.attachmentName.textContent = '';
      ui.attachmentPreview.src = '';
      ui.attachmentPreview.alt = '';
      if (ui.attachBtn) {
        ui.attachBtn.classList.remove('askwp-attach-active');
      }
      return;
    }

    var kb = Math.max(1, Math.round((state.pendingImage.size || 0) / 1024));
    ui.attachmentName.textContent = state.pendingImage.name + ' (' + kb + ' KB)';
    ui.attachmentPreview.src = state.pendingImage.dataUrl;
    ui.attachmentPreview.alt = state.pendingImage.name || '';
    ui.attachmentRow.hidden = false;
    if (ui.attachBtn) {
      ui.attachBtn.classList.add('askwp-attach-active');
    }
  }

  function clearPendingImage() {
    state.pendingImage = null;
    if (ui.fileInput) {
      ui.fileInput.value = '';
    }
    renderImageAttachment();
    renderInputState();
  }

  function renderModeVisibility() {
    var formVisible = !!state.formOpen;
    ui.chatList.parentNode.querySelector('.askwp-panel-footer').hidden = formVisible;
    ui.chatList.hidden = formVisible;
    ui.formOverlay.hidden = !formVisible;
  }

  // ── Messages ──

  function addMessage(role, text, sources, meta) {
    var content = role === 'assistant'
      ? sanitizeAssistantText(text, 2500)
      : sanitizeText(text, 1500);

    var normalizedRole = role === 'assistant' ? 'assistant' : 'user';
    var hasAttachment = normalizedRole === 'user'
      && meta
      && typeof meta === 'object'
      && /^data:image\//i.test(String(meta.imageDataUrl || ''));

    if (!content && !hasAttachment) { return; }

    var msg = { role: normalizedRole, content: content, ts: Date.now() };
    if (msg.role === 'assistant') {
      msg.sources = mergeSources(
        normalizeSources(sources || []),
        extractSourcesFromText(content, 6),
        6
      );
      if (meta && typeof meta === 'object') {
        if (meta.isError) {
          msg.isError = true;
        }
        if (meta.retryToken) {
          msg.retryToken = String(meta.retryToken || '').trim();
        }
        if (meta.errorCode) {
          msg.errorCode = sanitizeText(meta.errorCode, 40);
        }
      }
    } else if (hasAttachment) {
      msg.attachment = {
        name: sanitizeText(meta.imageName || 'image', 80) || 'image',
        dataUrl: String(meta.imageDataUrl || '')
      };
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

  async function postChatStream(payload, onDelta, onDone, onStatus, onError) {
    var res;
    try {
      res = await fetch(cfg.stream_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: 'same-origin'
      });
    } catch (e) {
      onError(e);
      return;
    }

    if (!res.ok || !res.body) {
      onError(new Error('stream_http_' + (res.status || 0)));
      return;
    }

    var reader = res.body.getReader();
    var decoder = new TextDecoder();
    var buffer = '';
    var parsedEvents = 0;

    function tickUi() {
      return new Promise(function (resolve) {
        setTimeout(resolve, 0);
      });
    }

    try {
      while (true) {
        var result = await reader.read();
        if (result.done) { break; }

        buffer += decoder.decode(result.value, { stream: true });

        var lines = buffer.split('\n');
        buffer = lines.pop() || '';

        for (var i = 0; i < lines.length; i++) {
          var line = lines[i].trim();
          if (!line || line.indexOf('data: ') !== 0) { continue; }

          var jsonStr = line.slice(6);
          if (jsonStr === '[DONE]') { continue; }

          var event;
          try { event = JSON.parse(jsonStr); }
          catch (e) { continue; }

          if (event.error) {
            onError(new Error(event.error));
            return;
          }

          if (event.status && typeof onStatus === 'function') {
            onStatus(event.status);
            await tickUi();
          }

          if (event.delta) {
            onDelta(event.delta);
          }

          if (event.done) {
            onDone(event.sources || [], event.usage || null);
          }

          parsedEvents += 1;
          if (parsedEvents % 24 === 0) {
            await tickUi();
          }
        }
      }
    } catch (e) {
      onError(e);
    }
  }

  async function handleSendMessage(options) {
    if (state.waiting) { return; }
    options = (options && typeof options === 'object') ? options : {};

    var retryPayload = (options.retryPayload && typeof options.retryPayload === 'object')
      ? clonePayload(options.retryPayload)
      : null;

    var payload = null;

    if (retryPayload) {
      payload = retryPayload;
      if (!Array.isArray(payload.messages) || !payload.messages.length) {
        addMessage('assistant', str.retry_unavailable || 'Retry is no longer available for that message.');
        return;
      }
      payload.session_id = String(payload.session_id || state.sessionId);
      payload.page_url = String(payload.page_url || window.location.href);
      payload.page_title = String(payload.page_title || document.title);
      payload.messages = payload.messages.slice(-MAX_MESSAGES);
    } else {
      var userText = sanitizeText(ui.input.value, 1500);
      var pendingImage = state.pendingImage;
      if (!userText && !pendingImage) { return; }

      var displayText = userText;
      if (!displayText && pendingImage) {
        displayText = str.image_attached || 'Image attached';
      }

      ui.input.value = '';
      ui.input.style.height = '';

      addMessage(
        'user',
        displayText,
        null,
        pendingImage
          ? { imageDataUrl: pendingImage.dataUrl, imageName: pendingImage.name }
          : null
      );

      payload = {
        session_id: state.sessionId,
        page_url: window.location.href,
        page_title: document.title,
        messages: getChatPayloadMessages()
      };
      if (pendingImage) {
        payload.attachment = {
          data_url: pendingImage.dataUrl,
          mime_type: pendingImage.mimeType,
          name: pendingImage.name
        };
        clearPendingImage();
      }
    }

    openPanel();

    var retryPayloadBase = clonePayload(payload);
    setConnectionState('connecting');

    if (cfg.stream_url) {
      payload.stream_id = state.sessionId + '-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
      var streamStepsEnabled = SHOW_STREAM_STEPS;

      // Lock input without showing typing dots.
      state.waiting = true;
      renderInputState();

      // Create streaming bubble directly in the DOM (not in state).
      var streamBubble = document.createElement('div');
      streamBubble.className = 'askwp-msg askwp-msg-assistant';
      streamBubble.tabIndex = 0;
      streamBubble.setAttribute('role', 'article');
      streamBubble.setAttribute('aria-label', str.assistant_message || 'Assistant message');
      if (cfg.bot_avatar_url) {
        streamBubble.appendChild(createAvatar());
      }
      var streamBody = document.createElement('div');
      streamBody.className = 'askwp-msg-body';
      var streamStatusWrap = null;
      if (streamStepsEnabled) {
        streamStatusWrap = document.createElement('div');
        streamStatusWrap.className = 'askwp-stream-steps';
        streamStatusWrap.hidden = true;
        streamBody.appendChild(streamStatusWrap);
      }

      var streamAnswerWrap = document.createElement('div');
      streamAnswerWrap.className = 'askwp-stream-answer';
      streamAnswerWrap.innerHTML = '<p class="askwp-stream-cursor">\u200b</p>';
      streamBody.appendChild(streamAnswerWrap);
      streamBubble.appendChild(streamBody);
      ui.chatList.appendChild(streamBubble);
      scrollToBottom();

      var streamContent = '';
      var revealedLen = 0;
      var revealQueue = [];
      var revealTimer = null;
      var streamDoneData = null;
      var streamFinished = false;
      var streamStatusLines = [];
      var streamStatusQueue = [];
      var streamStatusPumpTimer = null;
      var streamProgressPollTimer = null;
      var streamProgressInFlight = false;
      var streamProgressDone = false;
      var streamProgressSeen = Object.create(null);
      var streamStatusFirstShownAt = 0;
      var streamStatusLastShownAt = 0;
      var streamHasVisibleOutput = !streamStepsEnabled;
      var streamOutputStarted = false;
      var streamCanRevealOutput = !streamStepsEnabled;
      var streamPendingDeltas = [];
      var streamHideStatusTimer = null;
      var streamMinStepMs = 140;
      var streamMinVisibleMs = 500;
      var streamFailedError = null;
      var streamRetryToken = '';
      var streamErrorCode = '';

      function stopStreamProgressPolling() {
        if (streamProgressPollTimer) {
          clearTimeout(streamProgressPollTimer);
          streamProgressPollTimer = null;
        }
        streamProgressInFlight = false;
        streamProgressDone = true;
      }

      function buildStreamProgressUrl() {
        if (!streamStepsEnabled) { return ''; }
        var base = typeof cfg.stream_progress_url === 'string' ? cfg.stream_progress_url : '';
        var streamId = payload.stream_id ? String(payload.stream_id) : '';
        if (!base || !streamId) { return ''; }

        var sep = base.indexOf('?') === -1 ? '?' : '&';
        return base + sep + 'stream_id=' + encodeURIComponent(streamId) + '&_t=' + Date.now();
      }

      function renderStreamStatuses() {
        if (!streamStepsEnabled || !streamStatusWrap) { return; }
        if (streamHasVisibleOutput || !streamStatusLines.length) {
          streamStatusWrap.hidden = true;
          streamStatusWrap.innerHTML = '';
          return;
        }

        streamStatusWrap.hidden = false;
        streamStatusWrap.innerHTML = '';

        for (var i = 0; i < streamStatusLines.length; i++) {
          var stepEl = document.createElement('div');
          stepEl.className = 'askwp-stream-step';
          if (i === streamStatusLines.length - 1) {
            stepEl.classList.add('askwp-stream-step-active');
          }
          stepEl.textContent = streamStatusLines[i];
          streamStatusWrap.appendChild(stepEl);
        }
      }

      function appendStreamStatusLine(clean) {
        if (streamHasVisibleOutput) { return; }
        if (!clean) { return; }
        if (streamStatusLines.length && streamStatusLines[streamStatusLines.length - 1] === clean) {
          return;
        }

        streamStatusLines.push(clean);
        streamStatusLines = streamStatusLines.slice(-6);

        var now = Date.now();
        streamStatusLastShownAt = now;
        if (!streamStatusFirstShownAt) {
          streamStatusFirstShownAt = now;
        }

        renderStreamStatuses();
        scrollToBottom();
      }

      function scheduleStreamStatusPump(delayMs) {
        if (streamStatusPumpTimer) { return; }
        streamStatusPumpTimer = setTimeout(function () {
          streamStatusPumpTimer = null;
          pumpStreamStatuses();
        }, Math.max(0, Number(delayMs || 0)));
      }

      function pumpStreamStatuses() {
        if (streamHasVisibleOutput) {
          streamStatusQueue = [];
          return;
        }
        if (!streamStatusQueue.length) { return; }

        var now = Date.now();
        if (streamStatusLastShownAt > 0) {
          var gap = now - streamStatusLastShownAt;
          if (gap < streamMinStepMs) {
            scheduleStreamStatusPump(streamMinStepMs - gap);
            return;
          }
        }

        appendStreamStatusLine(streamStatusQueue.shift());

        if (streamStatusQueue.length) {
          scheduleStreamStatusPump(streamMinStepMs);
        }
      }

      function pushStreamStatus(statusText) {
        if (!streamStepsEnabled) { return; }
        if (streamHasVisibleOutput) { return; }
        var clean = sanitizeText(statusText, 170);
        if (!clean) { return; }
        if (streamStatusQueue.length && streamStatusQueue[streamStatusQueue.length - 1] === clean) {
          return;
        }
        if (!streamStatusLines.length && !streamStatusQueue.length) {
          appendStreamStatusLine(clean);
          return;
        }
        if (streamStatusLines.length && streamStatusLines[streamStatusLines.length - 1] === clean) {
          return;
        }
        streamStatusQueue.push(clean);
        pumpStreamStatuses();
      }

      function pushProgressSteps(steps) {
        if (!streamStepsEnabled) { return; }
        if (!Array.isArray(steps) || !steps.length) { return; }
        for (var i = 0; i < steps.length; i++) {
          var clean = sanitizeText(steps[i], 170);
          if (!clean) { continue; }
          if (streamProgressSeen[clean]) { continue; }
          streamProgressSeen[clean] = 1;
          pushStreamStatus(clean);
        }
      }

      async function pollStreamProgressOnce() {
        if (!streamStepsEnabled) { return; }
        if (streamProgressDone) { return; }
        if (streamFinished || streamHasVisibleOutput || streamOutputStarted) { return; }
        if (streamProgressInFlight) { return; }

        var url = buildStreamProgressUrl();
        if (!url) { return; }

        streamProgressInFlight = true;
        try {
          var res = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
          });
          if (!res.ok) { return; }

          var data = await res.json();
          if (!data || !data.success || !data.data || typeof data.data !== 'object') { return; }

          pushProgressSteps(Array.isArray(data.data.steps) ? data.data.steps : []);
          if (data.data.done) {
            stopStreamProgressPolling();
            return;
          }
        } catch (_e) {
          // Ignore transient polling errors.
        } finally {
          streamProgressInFlight = false;
          if (!streamProgressDone && !streamFinished && !streamHasVisibleOutput && !streamOutputStarted && !streamProgressPollTimer) {
            streamProgressPollTimer = setTimeout(function () {
              streamProgressPollTimer = null;
              pollStreamProgressOnce();
            }, 220);
          }
        }
      }

      function releasePendingDeltas() {
        if (!streamCanRevealOutput) { return; }
        if (!streamPendingDeltas.length) { return; }
        for (var i = 0; i < streamPendingDeltas.length; i++) {
          revealQueue.push(streamPendingDeltas[i]);
        }
        streamPendingDeltas = [];
        if (!revealTimer) { revealTick(); }
      }

      function hideStreamStatuses(delayMs, forceImmediate) {
        if (streamHasVisibleOutput) { return; }

        var delay = Number(delayMs || 0);
        if (!forceImmediate && streamStatusFirstShownAt > 0) {
          var minRemaining = streamMinVisibleMs - (Date.now() - streamStatusFirstShownAt);
          if (minRemaining > delay) {
            delay = minRemaining;
          }
        }
        if (!forceImmediate && streamStatusQueue.length > 0) {
          var queueReplayDelay = (streamStatusQueue.length * streamMinStepMs) + 120;
          if (queueReplayDelay > delay) {
            delay = queueReplayDelay;
          }
        }
        if (delay > 0) {
          if (streamHideStatusTimer) { return; }
          streamHideStatusTimer = setTimeout(function () {
            streamHideStatusTimer = null;
            if (streamHasVisibleOutput) { return; }
            if (streamStatusPumpTimer) {
              clearTimeout(streamStatusPumpTimer);
              streamStatusPumpTimer = null;
            }
            streamStatusQueue = [];
            streamHasVisibleOutput = true;
            streamCanRevealOutput = true;
            stopStreamProgressPolling();
            renderStreamStatuses();
            releasePendingDeltas();
          }, delay);
          return;
        }

        if (streamHideStatusTimer) {
          clearTimeout(streamHideStatusTimer);
          streamHideStatusTimer = null;
        }
        if (streamStatusPumpTimer) {
          clearTimeout(streamStatusPumpTimer);
          streamStatusPumpTimer = null;
        }
        streamStatusQueue = [];

        streamHasVisibleOutput = true;
        streamCanRevealOutput = true;
        stopStreamProgressPolling();
        renderStreamStatuses();
        releasePendingDeltas();
      }

      function revealTick() {
        if (revealQueue.length === 0) {
          revealTimer = null;
          if (streamDoneData) { finishStream(); }
          return;
        }
        revealedLen += revealQueue.shift().length;
        var visible = streamContent.slice(0, revealedLen);
        streamAnswerWrap.innerHTML = renderAssistantMarkdown(visible) || '<p>\u200b</p>';
        scrollToBottom();
        revealTimer = setTimeout(revealTick, 20);
      }

      function finishStream() {
        if (streamFinished) { return; }
        streamFinished = true;
        if (revealTimer) { clearTimeout(revealTimer); revealTimer = null; }
        if (streamHideStatusTimer) { clearTimeout(streamHideStatusTimer); streamHideStatusTimer = null; }
        if (streamStatusPumpTimer) { clearTimeout(streamStatusPumpTimer); streamStatusPumpTimer = null; }
        stopStreamProgressPolling();
        streamStatusQueue = [];
        hideStreamStatuses(0, true);
        var content = sanitizeAssistantText(streamContent, 2500);
        var errorInfo = streamFailedError ? classifyChatError(streamFailedError) : null;
        if (errorInfo) {
          streamErrorCode = streamErrorCode || errorInfo.code || 'generic';
          if (!content) {
            content = errorInfo.message;
          }
        } else if (!content) {
          content = str.error || 'The chat is currently unavailable.';
        }
        var normalizedSources = mergeSources(
          streamDoneData ? normalizeSources(streamDoneData.sources) : [],
          extractSourcesFromText(content, 6),
          6
        );

        var streamTs = Date.now();
        var streamMsg = {
          role: 'assistant',
          content: content,
          sources: normalizedSources,
          ts: streamTs
        };
        if (streamFailedError) {
          streamMsg.isError = true;
          if (streamRetryToken) {
            streamMsg.retryToken = streamRetryToken;
          }
          if (streamErrorCode) {
            streamMsg.errorCode = streamErrorCode;
          }
          streamBubble.classList.add('askwp-msg-error');
        }
        state.messages.push(streamMsg);
        persistMessages();

        streamAnswerWrap.innerHTML = renderAssistantMarkdown(content);
        if (normalizedSources.length) {
          var srcWrap = document.createElement('div');
          srcWrap.className = 'askwp-msg-sources';
          normalizedSources.forEach(function (s) {
            if (!s.title || !s.url) { return; }
            var link = document.createElement('a');
            link.href = s.url;
            link.textContent = s.title;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            srcWrap.appendChild(link);
          });
          if (srcWrap.childNodes.length) {
            streamAnswerWrap.appendChild(srcWrap);
          }
        }

        var streamTime = formatMessageTimestamp(streamTs);
        if (streamTime) {
          var streamTimeEl = document.createElement('div');
          streamTimeEl.className = 'askwp-msg-time';
          streamTimeEl.setAttribute('data-ts', String(streamTs));
          streamTimeEl.textContent = streamTime;
          streamAnswerWrap.appendChild(streamTimeEl);
        }

        if (streamRetryToken) {
          appendRetryButton(streamBody, streamRetryToken);
        }
        appendCopyButton(streamBody, content);

        if (errorInfo) {
          setConnectionState(errorInfo.connection || 'issue');
        } else {
          setConnectionState('online');
        }

        state.waiting = false;
        renderInputState();
        if (state.panelOpen && !state.formOpen) {
          ui.input.focus();
        }
      }

      try {
        if (streamStepsEnabled) {
          pollStreamProgressOnce();
        }
        await postChatStream(
          payload,
          function onDelta(text) {
            if (!streamOutputStarted) {
              streamOutputStarted = true;
            }
            streamContent += text;
            if (streamCanRevealOutput) {
              revealQueue.push(text);
              if (!revealTimer) { revealTick(); }
            } else {
              streamPendingDeltas.push(text);
            }
            if (streamStepsEnabled) {
              hideStreamStatuses(streamStatusLines.length ? 150 : 0);
            }
          },
          function onDone(sources, usage) {
            if (!streamDoneData) {
              streamDoneData = { sources: sources || [], usage: usage };
            }
            if (!revealTimer) { finishStream(); }
          },
          function onStatus(statusText) {
            if (streamStepsEnabled) {
              pushProgressSteps([statusText]);
            }
          },
          function onError(err) {
            if (!streamFailedError) {
              streamFailedError = err || new Error('stream_error');
              var classified = classifyChatError(streamFailedError);
              streamErrorCode = classified.code || 'generic';
              if (!streamRetryToken && retryPayloadBase) {
                streamRetryToken = rememberRetryPayload(retryPayloadBase);
              }
              if (!streamContent) {
                streamContent = classified.message;
              }
              setConnectionState(classified.connection || 'issue');
            }
            if (!streamDoneData) {
              streamDoneData = { sources: [], usage: null };
            }
            if (!revealTimer) { finishStream(); }
          }
        );
      } catch (e) {
        if (!streamFailedError) {
          streamFailedError = e || new Error('stream_error');
          var classifiedCatch = classifyChatError(streamFailedError);
          streamErrorCode = classifiedCatch.code || 'generic';
          if (!streamRetryToken && retryPayloadBase) {
            streamRetryToken = rememberRetryPayload(retryPayloadBase);
          }
          if (!streamContent) {
            streamContent = classifiedCatch.message;
          }
          setConnectionState(classifiedCatch.connection || 'issue');
        }
        if (!streamDoneData) {
          streamDoneData = { sources: [], usage: null };
        }
        if (!revealTimer) { finishStream(); }
      }
    } else {
      // Non-streaming fallback.
      setWaiting(true);
      try {
        var data = await postChat(payload);
        var reply = sanitizeAssistantText(data && data.reply ? data.reply : '', 2500);

        if (!reply) {
          reply = str.error || 'The chat is currently unavailable.';
        }

        addMessage('assistant', reply, Array.isArray(data.sources) ? data.sources : []);
        handleServerAction(data && data.action ? data.action : null);
        setConnectionState('online');
      } catch (e) {
        var classifiedFallback = classifyChatError(e);
        var fallbackRetryToken = retryPayloadBase ? rememberRetryPayload(retryPayloadBase) : '';
        addMessage(
          'assistant',
          classifiedFallback.message,
          [],
          {
            isError: true,
            retryToken: fallbackRetryToken,
            errorCode: classifiedFallback.code || 'generic'
          }
        );
        setConnectionState(classifiedFallback.connection || 'issue');
      } finally {
        setWaiting(false);
        if (state.panelOpen && !state.formOpen) {
          ui.input.focus();
        }
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
    state.pendingImage = null;

    ui.input.value = '';
    ui.input.style.height = '';
    if (ui.fileInput) {
      ui.fileInput.value = '';
    }

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
    var panelSize = normalizePanelSize(cfg.panel_size || 'normal');

    var root = document.createElement('div');
    root.className = 'askwp-widget';
    if (isLeft) { root.classList.add('askwp-pos-left'); }
    root.classList.add('askwp-size-' + panelSize);

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

    var headerMain = document.createElement('div');
    headerMain.className = 'askwp-panel-header-main';

    var headerTitle = document.createElement('span');
    headerTitle.className = 'askwp-panel-title';
    headerTitle.textContent = str.title || cfg.bot_name || 'Chat';

    var connection = document.createElement('span');
    connection.className = 'askwp-connection askwp-conn-online';
    connection.setAttribute('role', 'status');
    connection.setAttribute('aria-live', 'polite');

    var connectionDot = document.createElement('span');
    connectionDot.className = 'askwp-connection-dot';
    connectionDot.setAttribute('aria-hidden', 'true');

    var connectionLabel = document.createElement('span');
    connectionLabel.className = 'askwp-connection-label';
    connectionLabel.textContent = str.connection_online || 'Online';

    connection.appendChild(connectionDot);
    connection.appendChild(connectionLabel);
    headerMain.appendChild(headerTitle);
    headerMain.appendChild(connection);

    var headerClose = document.createElement('button');
    headerClose.type = 'button';
    headerClose.className = 'askwp-panel-close';
    headerClose.setAttribute('aria-label', str.close || 'Close');
    headerClose.textContent = '\u00d7';

    panelHeader.appendChild(headerMain);
    panelHeader.appendChild(headerClose);

    // Chat list.
    var chatList = document.createElement('div');
    chatList.className = 'askwp-chat-list';
    chatList.setAttribute('role', 'log');
    chatList.setAttribute('aria-live', 'polite');
    chatList.setAttribute('aria-relevant', 'additions text');
    chatList.setAttribute('aria-atomic', 'false');

    // Panel footer.
    var panelFooter = document.createElement('div');
    panelFooter.className = 'askwp-panel-footer';

    // Input row.
    var inputRow = document.createElement('div');
    inputRow.className = 'askwp-input-row';

    var attachmentRow = null;
    var attachmentPreview = null;
    var attachmentName = null;
    var imageRemoveBtn = null;
    var fileInput = null;
    var fileInputId = '';
    var attachBtn = null;

    if (cfg.image_attachments_enabled) {
      attachmentRow = document.createElement('div');
      attachmentRow.className = 'askwp-attachment-row';
      attachmentRow.hidden = true;

      attachmentPreview = document.createElement('img');
      attachmentPreview.className = 'askwp-attachment-preview';
      attachmentPreview.alt = '';
      attachmentPreview.loading = 'lazy';
      attachmentPreview.decoding = 'async';

      attachmentName = document.createElement('span');
      attachmentName.className = 'askwp-attachment-name';

      imageRemoveBtn = document.createElement('button');
      imageRemoveBtn.type = 'button';
      imageRemoveBtn.className = 'askwp-attachment-remove';
      imageRemoveBtn.setAttribute('aria-label', str.remove_image || 'Remove image');
      imageRemoveBtn.textContent = '\u00d7';

      attachmentRow.appendChild(attachmentPreview);
      attachmentRow.appendChild(attachmentName);
      attachmentRow.appendChild(imageRemoveBtn);

      fileInput = document.createElement('input');
      fileInputId = 'askwp-attach-' + Math.random().toString(36).slice(2, 10);
      fileInput.id = fileInputId;
      fileInput.type = 'file';
      fileInput.accept = 'image/png,image/jpeg,image/webp,image/gif';
      fileInput.className = 'askwp-attach-input';
      fileInput.tabIndex = -1;
      fileInput.setAttribute('aria-hidden', 'true');

      attachBtn = document.createElement('button');
      attachBtn.type = 'button';
      attachBtn.className = 'askwp-attach';
      attachBtn.setAttribute('aria-label', str.attach_image || 'Attach image');
      if (fileInputId) {
        attachBtn.setAttribute('aria-controls', fileInputId);
      }
      attachBtn.innerHTML = ATTACH_ICON;
      inputRow.appendChild(attachBtn);
    }

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

    if (attachmentRow) {
      panelFooter.appendChild(attachmentRow);
    }
    panelFooter.appendChild(inputRow);
    if (fileInput) {
      panelFooter.appendChild(fileInput);
    }
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

    chatList.addEventListener('keydown', function (e) {
      var target = e.target;
      if (!target || typeof target.closest !== 'function') { return; }
      var current = target.closest('.askwp-msg');
      if (!current || current !== target || !chatList.contains(current)) { return; }

      var key = String(e.key || '');
      if (key !== 'ArrowUp' && key !== 'ArrowDown' && key !== 'Home' && key !== 'End') {
        return;
      }

      var messages = Array.prototype.slice.call(chatList.querySelectorAll('.askwp-msg'));
      if (!messages.length) { return; }
      var idx = messages.indexOf(current);
      if (idx === -1) { return; }

      var nextIdx = idx;
      if (key === 'ArrowUp') {
        nextIdx = Math.max(0, idx - 1);
      } else if (key === 'ArrowDown') {
        nextIdx = Math.min(messages.length - 1, idx + 1);
      } else if (key === 'Home') {
        nextIdx = 0;
      } else if (key === 'End') {
        nextIdx = messages.length - 1;
      }

      if (nextIdx !== idx && messages[nextIdx]) {
        e.preventDefault();
        messages[nextIdx].focus();
      }
    });

    window.addEventListener('online', function () {
      setConnectionState('online');
    });
    window.addEventListener('offline', function () {
      setConnectionState('offline');
    });

    if (attachBtn && fileInput) {
      attachBtn.addEventListener('click', function () {
        fileInput.click();
      });

      fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
        if (!file) {
          clearPendingImage();
          return;
        }

        if (!/^image\/(png|jpeg|jpg|webp|gif)$/i.test(file.type || '')) {
          clearPendingImage();
          addMessage('assistant', str.image_invalid || 'Only PNG, JPEG, WEBP, and GIF images are supported.');
          return;
        }

        var maxBytes = Number(cfg.max_image_bytes || (2 * 1024 * 1024));
        if (file.size > maxBytes) {
          clearPendingImage();
          addMessage('assistant', str.image_too_large || 'Image is too large. Max size is 2MB.');
          return;
        }

        var reader = new FileReader();
        reader.onload = function (e) {
          var dataUrl = e && e.target && typeof e.target.result === 'string' ? e.target.result : '';
          if (!/^data:image\//i.test(dataUrl)) {
            clearPendingImage();
            addMessage('assistant', str.image_invalid || 'Only PNG, JPEG, WEBP, and GIF images are supported.');
            return;
          }

          state.pendingImage = {
            name: sanitizeText(file.name || 'image', 80) || 'image',
            mimeType: file.type || 'image/png',
            size: Number(file.size || 0),
            dataUrl: dataUrl
          };
          renderImageAttachment();
          renderInputState();
          if (state.panelOpen && !state.formOpen) {
            input.focus();
          }
        };

        reader.onerror = function () {
          clearPendingImage();
          addMessage('assistant', str.error || 'The chat is currently unavailable. Please try again later.');
        };

        reader.readAsDataURL(file);
      });
    }

    if (imageRemoveBtn) {
      imageRemoveBtn.addEventListener('click', function () {
        clearPendingImage();
        if (state.panelOpen && !state.formOpen) {
          input.focus();
        }
      });
    }

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
      connection: connection,
      connectionLabel: connectionLabel,
      input: input,
      sendBtn: sendBtn,
      formOverlay: formOverlay,
      attachBtn: attachBtn,
      fileInput: fileInput,
      attachmentRow: attachmentRow,
      attachmentPreview: attachmentPreview,
      attachmentName: attachmentName,
      imageRemoveBtn: imageRemoveBtn
    };
  }
})();
