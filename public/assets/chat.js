// Chat reader: formatted / plain / source / code-only views, copy tools, stats.
// The server sends each message as escaped plain text; everything here is an enhancement.
(function () {
  var thread = document.getElementById('thread');
  if (!thread || !window.marked || !window.DOMPurify) return;
  var bar = document.getElementById('reader-bar');
  var msgs = Array.prototype.slice.call(thread.querySelectorAll('.msg'));
  var toast = document.getElementById('reader-toast');
  var meta = thread.dataset;

  msgs.forEach(function (m) {
    m._body = m.querySelector('.msg-body');
    // Older imports kept Claude's stand-in for artifacts and tool steps; hide it.
    m._raw = m._body.textContent.replace(/(^|\n)[ \t]*```[^\n]*\n[ \t]*This block is not supported on your current device yet\.?[ \t]*\n[ \t]*```[ \t]*(?=\n|$)/g, '$1').replace(/\n{3,}/g, '\n\n').trim();
    m._hasCode = /(^|\n)\s*(```|~~~)/.test(m._raw);
  });

  // ---------- preferences (per browser; fine to lose) ----------
  function pref(k, v) {
    try {
      if (v === undefined) return localStorage.getItem('crd.' + k);
      localStorage.setItem('crd.' + k, v);
    } catch (e) { /* storage unavailable */ }
    return null;
  }
  var mode = pref('mode') || 'formatted';
  var who = 'all';
  var size = parseInt(pref('size') || '16', 10);

  // ---------- safe formatting ----------
  DOMPurify.addHook('uponSanitizeAttribute', function (node, data) {
    if (data.attrName === 'class' && !/^language-[\w+#.-]+$/.test(data.attrValue)) data.keepAttr = false;
  });
  DOMPurify.addHook('afterSanitizeAttributes', function (node) {
    if (node.tagName === 'A') { node.setAttribute('target', '_blank'); node.setAttribute('rel', 'noopener noreferrer nofollow'); }
  });
  var CLEAN = {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'b', 'em', 'i', 'del', 's', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
      'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a', 'sup', 'sub', 'kbd', 'mark', 'input'],
    ALLOWED_ATTR: ['href', 'title', 'class', 'align', 'start', 'colspan', 'rowspan', 'type', 'checked', 'disabled']
  };
  function toHtml(raw) {
    var html = DOMPurify.sanitize(marked.parse(raw, { gfm: true, breaks: true }), CLEAN);
    var box = document.createElement('div');
    box.innerHTML = html;
    box.querySelectorAll('input').forEach(function (i) { if (i.type !== 'checkbox') i.remove(); else i.disabled = true; });
    box.querySelectorAll('table').forEach(function (t) {
      var w = document.createElement('div'); w.className = 'md-table'; t.parentNode.insertBefore(w, t); w.appendChild(t);
    });
    box.querySelectorAll('pre').forEach(function (pre) { dressCode(pre); });
    return box;
  }
  function dressCode(pre) {
    var code = pre.querySelector('code') || pre;
    var lang = ((code.className || '').match(/language-([\w+#.-]+)/) || [])[1] || '';
    if (window.hljs && lang && hljs.getLanguage(lang)) { try { hljs.highlightElement(code); } catch (e) { /* leave plain */ } }
    var wrap = document.createElement('div'); wrap.className = 'codeblock';
    var head = document.createElement('div'); head.className = 'codeblock-head';
    var label = document.createElement('span'); label.textContent = lang || 'code';
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'mini'; btn.textContent = 'Copy code';
    btn.addEventListener('click', function () { copy(code.textContent, 'Code copied'); });
    head.appendChild(label); head.appendChild(btn);
    pre.parentNode.insertBefore(wrap, pre); wrap.appendChild(head); wrap.appendChild(pre);
  }

  function paint(m) {
    if (m._painted === mode) return;
    var b = m._body;
    b.className = 'msg-body';
    if (mode === 'source') { b.textContent = m._raw; b.classList.add('as-source'); }
    else if (mode === 'plain') { b.textContent = toHtml(m._raw).textContent.replace(/\n{3,}/g, '\n\n').trim(); }
    else {
      var box = toHtml(m._raw);
      b.textContent = '';
      b.classList.add('md');
      if (mode === 'code') {
        var blocks = box.querySelectorAll('.codeblock');
        blocks.forEach(function (c) { b.appendChild(c); });
      } else {
        while (box.firstChild) b.appendChild(box.firstChild);
      }
    }
    m._painted = mode;
  }

  // Paint messages as they come near the screen, so very long chats open quickly.
  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
    entries.forEach(function (e) { if (e.isIntersecting) paint(e.target); });
  }, { rootMargin: '800px 0px' }) : null;

  function visible(m) {
    if (who !== 'all' && m.dataset.role !== who) return false;
    if (m._raw === '') return false;
    if (mode === 'code' && !m._hasCode) return false;
    return true;
  }
  function refresh() {
    var shown = 0;
    msgs.forEach(function (m) {
      var v = visible(m);
      m.hidden = !v;
      if (v) { shown++; if (io) { io.unobserve(m); io.observe(m); } else paint(m); }
    });
    thread.style.setProperty('--reader-size', size + 'px');
    document.getElementById('reader-empty').hidden = shown > 0;
    bar.querySelectorAll('[data-mode]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.mode === mode ? 'true' : 'false'); });
    bar.querySelectorAll('[data-who]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.who === who ? 'true' : 'false'); });
  }
  function paintAll() { msgs.forEach(function (m) { if (!m.hidden) paint(m); }); }
  window.addEventListener('beforeprint', paintAll);

  // ---------- copy ----------
  function say(text) { toast.textContent = text; toast.hidden = false; clearTimeout(say.t); say.t = setTimeout(function () { toast.hidden = true; }, 2600); }
  function copy(text, done) {
    var ok = function () { say(done); };
    var old = function () {
      var t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
      document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); ok(); } catch (e) { say('Could not copy. Select the text and copy it by hand.'); }
      t.remove();
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(ok, old); else old();
  }
  function speaker(m) { return m.dataset.role === 'user' ? 'You' : meta.assistant; }
  function asText(list) {
    return list.map(function (m) { return speaker(m) + ':\n' + m._raw.trim(); }).join('\n\n');
  }
  function tokens(text) { return Math.round(text.length / 4); }
  function num(n) { return n.toLocaleString(); }

  msgs.forEach(function (m) {
    var b = document.createElement('button'); b.type = 'button'; b.className = 'mini copy-msg'; b.textContent = 'Copy';
    b.setAttribute('aria-label', 'Copy this message');
    b.addEventListener('click', function () { copy(m._raw, 'Message copied'); });
    m.querySelector('header').appendChild(b);
  });

  bar.addEventListener('click', function (e) {
    var t = e.target.closest('button'); if (!t) return;
    if (t.dataset.mode) { mode = t.dataset.mode; pref('mode', mode); refresh(); }
    else if (t.dataset.who) { who = t.dataset.who; refresh(); }
    else if (t.dataset.size) { size = Math.max(13, Math.min(22, size + parseInt(t.dataset.size, 10))); pref('size', String(size)); refresh(); }
    else if (t.id === 'copy-all') { copy(meta.title + '\n\n' + asText(msgs.filter(visible)), 'Conversation copied'); }
    else if (t.id === 'copy-starter') {
      var n = document.getElementById('starter-n').value;
      var list = n === 'all' ? msgs : msgs.slice(-parseInt(n, 10));
      var text = 'I am continuing an earlier conversation with ' + meta.assistant + ' titled "' + meta.title + '".\n'
        + (n === 'all' ? 'Here is the whole conversation' : 'Here are the last ' + list.length + ' messages') + ' for context:\n\n'
        + asText(list) + '\n\nPlease read that and continue from where we left off.';
      copy(text, 'Starter copied: about ' + num(tokens(text)) + ' tokens. Paste it into a new chat.');
    }
    else if (t.id === 'do-print') { paintAll(); window.print(); }
    else if (t.id === 'toggle-stats') { var s = document.getElementById('stats'); s.hidden = !s.hidden; t.setAttribute('aria-expanded', String(!s.hidden)); if (!s.hidden) stats(); }
  });

  // ---------- stats ----------
  var STOP = ' the and for that this with you are was have not but your from they will what can all would there their about which when one had were our has more out just like how some them into than then its also been other could should these those over only very here where because does did get use using need want make made each such both most any may might much many well still even back after before while being through same too via per let lets yes okay sure thanks thank please going want know think see way now new work works working done don didn doesn isn can won ';
  function stats() {
    var box = document.getElementById('stats-body'); if (box.dataset.done) return;
    var words = 0, chars = 0, mine = 0, theirs = 0, code = 0, longest = null, freq = {};
    msgs.forEach(function (m) {
      var w = (m._raw.match(/\S+/g) || []).length;
      words += w; chars += m._raw.length;
      if (m.dataset.role === 'user') mine += w; else theirs += w;
      code += (m._raw.match(/(^|\n)\s*```/g) || []).length / 2;
      if (!longest || w > longest.w) longest = { w: w, m: m };
      (m._raw.toLowerCase().match(/[a-z][a-z'-]{3,}/g) || []).forEach(function (x) { if (STOP.indexOf(' ' + x + ' ') < 0) freq[x] = (freq[x] || 0) + 1; });
    });
    var top = Object.keys(freq).sort(function (a, b) { return freq[b] - freq[a]; }).slice(0, 14);
    var tok = Math.round(chars / 4), mins = Math.max(1, Math.round(words / 230));
    function item(label, value) { var d = document.createElement('div'); var s = document.createElement('strong'); s.textContent = value; var sp = document.createElement('span'); sp.textContent = label; d.appendChild(s); d.appendChild(sp); return d; }
    var grid = document.createElement('div'); grid.className = 'stats';
    grid.appendChild(item('words', num(words)));
    grid.appendChild(item('tokens, roughly', num(tok)));
    grid.appendChild(item('to read it all', mins < 60 ? mins + ' min' : (Math.round(mins / 6) / 10) + ' hr'));
    grid.appendChild(item('written by you', words ? Math.round(mine / words * 100) + '%' : '0%'));
    grid.appendChild(item('code blocks', num(Math.round(code))));
    box.appendChild(grid);
    var p = document.createElement('p'); p.className = 'muted';
    p.textContent = 'In the original chat, each new message re-sent this whole history, roughly ' + num(tok) + ' tokens every time. Starting a fresh chat from a short starter avoids that. Token counts are estimates (about 4 characters each).';
    box.appendChild(p);
    if (top.length) {
      var t = document.createElement('p'); t.className = 'chips'; var lead = document.createElement('span'); lead.className = 'muted'; lead.textContent = 'Most used words:'; t.appendChild(lead);
      top.forEach(function (w) { var c = document.createElement('span'); c.className = 'chip'; c.textContent = w + ' ' + freq[w]; t.appendChild(c); });
      box.appendChild(t);
    }
    if (longest && longest.w > 0) {
      var j = document.createElement('p'); var a = document.createElement('button'); a.type = 'button'; a.className = 'linkish';
      a.textContent = 'Jump to the longest message (' + num(longest.w) + ' words, by ' + speaker(longest.m) + ')';
      a.addEventListener('click', function () { who = 'all'; if (mode === 'code') mode = 'formatted'; refresh(); longest.m.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
      j.appendChild(a); box.appendChild(j);
    }
    box.dataset.done = '1';
  }

  bar.hidden = false;
  refresh();
})();
