/* =====================================================================
   AMHEEX AI — ícone flutuante + chat + voz (Fish Audio / TTS local / STT navegador)
   Layout e lógica do FAB extraídos do AMHEEX DRIVE e adaptados ao mundo 3D.
   ===================================================================== */
(function () {
  'use strict';
  if (window.__amxaiInit) return;
  window.__amxaiInit = true;

  var root = document.getElementById('amxai-root');
  if (!root) return;
  var fab = document.getElementById('amxai-fab');
  var panel = document.getElementById('amxai-panel');
  var head = document.getElementById('amxai-head');
  var msgs = document.getElementById('amxai-msgs');
  var form = document.getElementById('amxai-form');
  var input = document.getElementById('amxai-input');
  var send = document.getElementById('amxai-send');
  var mic = document.getElementById('amxai-mic');
  var statusEl = document.getElementById('amxai-status');
  var countEl = document.getElementById('amxai-count');
  var pin = document.getElementById('amxai-pin');
  var clearBtn = document.getElementById('amxai-clear');
  var chips = document.getElementById('amxai-chips');
  var voiceBtn = document.getElementById('amxai-voice');
  var liveBtn = document.getElementById('amxai-live');

  var busy = false;
  var history = [];
  var drag = null;
  var lastEnt = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function store(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function load(k, d) { try { var v = JSON.parse(localStorage.getItem(k)); return v == null ? d : v; } catch (e) { return d; } }
  function toast(msg) { if (statusEl) statusEl.textContent = msg; }

  /* ---------------- modo flutuante / fixo ---------------- */
  var mode = load('amx3d_mode', 'float');
  if (mode !== 'fixed') mode = 'float';
  root.dataset.mode = mode;
  pin.classList.toggle('on', mode === 'fixed');

  function setMode(m) {
    mode = m === 'fixed' ? 'fixed' : 'float';
    root.dataset.mode = mode;
    pin.classList.toggle('on', mode === 'fixed');
    store('amx3d_mode', mode);
    if (mode === 'fixed') {
      panel.style.left = panel.style.top = panel.style.right = panel.style.bottom = '';
      panel.style.width = panel.style.height = '';
      fab.style.left = fab.style.top = fab.style.right = fab.style.bottom = '';
    } else {
      applySavedPos(); applyFabPos();
    }
  }

  function openPanel() {
    panel.hidden = false;
    root.dataset.open = '1';
    fab.setAttribute('aria-expanded', 'true');
    if (mode === 'float') applySavedPos();
    setTimeout(function () { try { input.focus(); } catch (e) {} }, 120);
    scrollBottom();
  }
  function closePanel() {
    panel.hidden = true;
    root.dataset.open = '0';
    fab.setAttribute('aria-expanded', 'false');
  }

  /* ---------------- arrastar FAB e painel ---------------- */
  function applyFabPos() {
    var p = load('amx3d_fabpos', null);
    if (!p) return;
    fab.style.left = p.x + 'px'; fab.style.top = p.y + 'px';
    fab.style.right = 'auto'; fab.style.bottom = 'auto';
  }
  function applySavedPos() {
    var p = load('amx3d_pos', null);
    if (!p) return;
    panel.style.left = p.x + 'px'; panel.style.top = p.y + 'px';
    panel.style.right = 'auto'; panel.style.bottom = 'auto';
    if (p.w) panel.style.width = p.w + 'px';
    if (p.h) panel.style.height = p.h + 'px';
  }

  function makeDraggable(el, kind) {
    el.addEventListener('pointerdown', function (e) {
      if (e.target.closest('button') && kind === 'fab') { /* deixa o clique */ }
      if (kind === 'panel' && e.target.closest('button,textarea,input,a')) return;
      if (mode === 'fixed') return;
      var r = el.getBoundingClientRect();
      drag = { kind: kind, id: e.pointerId, ox: e.clientX - r.left, oy: e.clientY - r.top, moved: false, w: r.width, h: r.height };
      try { el.setPointerCapture(e.pointerId); } catch (err) {}
    });
    el.addEventListener('pointermove', function (e) {
      if (!drag || drag.id !== e.pointerId) return;
      var dx = e.clientX - drag.ox, dy = e.clientY - drag.oy;
      if (Math.abs(e.clientX - (drag.ox + el.getBoundingClientRect().left)) > 3) drag.moved = true;
      var x = Math.max(4, Math.min(window.innerWidth - (drag.kind === 'fab' ? 64 : 120), dx));
      var y = Math.max(4, Math.min(window.innerHeight - (drag.kind === 'fab' ? 64 : 90), dy));
      el.style.left = x + 'px'; el.style.top = y + 'px';
      el.style.right = 'auto'; el.style.bottom = 'auto';
      if (drag.kind === 'panel') { el.style.width = drag.w + 'px'; el.style.height = drag.h + 'px'; }
    });
    el.addEventListener('pointerup', function (e) {
      if (!drag || drag.id !== e.pointerId) return;
      var r = el.getBoundingClientRect();
      if (drag.kind === 'fab') store('amx3d_fabpos', { x: r.left, y: r.top });
      else store('amx3d_pos', { x: r.left, y: r.top, w: Math.round(r.width), h: Math.round(r.height) });
      if (drag.kind === 'fab' && drag.moved) window.__amxDragMoved = Date.now();
      drag = null;
    });
  }
  makeDraggable(fab, 'fab');
  makeDraggable(head, 'panel');

  /* ---------------- eventos ---------------- */
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    if (t.closest('#amxai-close')) { e.preventDefault(); closePanel(); return; }
    if (t.closest('#amxai-fab')) {
      e.preventDefault();
      if (Date.now() - (window.__amxDragMoved || 0) < 400) return;
      openPanel(); return;
    }
    if (t.closest('#amxai-pin')) { e.preventDefault(); setMode(mode === 'fixed' ? 'float' : 'fixed'); return; }
    if (t.closest('#amxai-clear')) { e.preventDefault(); limpar(); return; }
    if (t.closest('#amxai-voice')) { e.preventDefault(); toggleVoice(); return; }
    if (t.closest('#amxai-live')) { e.preventDefault(); toggleLive(); return; }
    var chip = t.closest('[data-chip]');
    if (chip) { e.preventDefault(); input.value = chip.getAttribute('data-chip'); sendMsg(); }
  }, true);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) closePanel();
  });

  /* ---------------- render ---------------- */
  function scrollBottom() { msgs.scrollTop = msgs.scrollHeight; }

  function addMsg(role, text, acts) {
    var wrap = document.createElement('div');
    wrap.className = 'amxai-msg ' + role;
    var b = document.createElement('div');
    b.className = 'amxai-bubble';
    b.innerHTML = '<span class="amxai-txt">' + fmt(text) + '</span>';
    if (acts && acts.length) {
      var box = document.createElement('div');
      box.className = 'amxai-acts';
      acts.forEach(function (a) {
        var s = document.createElement('span');
        s.className = 'amxai-act ' + (a.ok ? 'ok' : 'er');
        s.textContent = a.label;
        box.appendChild(s);
      });
      b.appendChild(box);
    }
    wrap.appendChild(b);
    msgs.appendChild(wrap);
    scrollBottom();
    return wrap;
  }

  function fmt(t) {
    var s = esc(t);
    s = s.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
    s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
    s = s.replace(/\n/g, '<br>');
    return s;
  }

  function updateCount() {
    if (countEl) countEl.textContent = history.length + ' msg';
  }

  function actionLabel(a, res) {
    var ok = res && res.ok !== false;
    var icon = ok ? '✓' : '✕';
    switch (a.t) {
      case 'spawn': return icon + ' ' + (a.n || 1) + '× ' + (a.nome || a.ent);
      case 'layout': return icon + ' tela: ' + a.item;
      case 'avatar': return icon + ' avatar ' + (a.nome || '');
      case 'modify': return icon + ' modificar ' + a.ent;
      case 'remove': return icon + ' remover ' + a.ent;
      case 'clear': return icon + ' cena limpa';
      case 'recolor': return icon + ' ' + a.ent + ' → ' + a.cor;
      case 'scale': return icon + ' escala ' + a.ent;
      case 'move': return icon + ' mover ' + a.ent;
      case 'rotate': return icon + ' girar ' + a.ent;
      case 'terrain': return icon + ' chão: ' + a.tipo;
      case 'weather': return icon + ' clima: ' + String(a.tipo).replace('_', ' ');
      case 'camera': return icon + ' câmera: ' + a.cmd;
      case 'physics': return icon + ' gravidade ' + (a.on ? 'on' : 'off');
      case 'skin': return icon + ' skin ' + a.ent;
      case 'world': return icon + ' mundo: ' + a.preset;
      case 'character': return icon + ' personagem ' + (a.nome || 'realista');
      case 'control': return icon + ' controle: ' + (a.mode || 'pad');
      case 'multiplayer': return icon + ' multiplayer ' + (a.on === false ? 'off' : 'on');
      default: return icon + ' ' + a.t;
    }
  }

  /* ---------------- voz: TTS ---------------- */
  var voiceOn = load('amx3d_voz', true);
  if (voiceBtn) voiceBtn.classList.toggle('on', voiceOn);
  var audioEl = null;
  var ttsCache = {};

  function toggleVoice() {
    voiceOn = !voiceOn;
    if (voiceBtn) voiceBtn.classList.toggle('on', voiceOn);
    store('amx3d_voz', voiceOn);
    if (!voiceOn) stopSpeak();
  }

  function stopSpeak() {
    try { if (audioEl) { audioEl.pause(); audioEl.currentTime = 0; } } catch (e) {}
    try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (e) {}
  }

  function speak(text) {
    if (!voiceOn || !text) return;
    stopSpeak();
    var clean = String(text).replace(/[*_`#>]/g, '').slice(0, 420);
    if (ttsCache[clean]) { playAudio(ttsCache[clean]); return; }
    fetch('?api=tts&text=' + encodeURIComponent(clean))
      .then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('audio') === 0) return r.blob();
        throw new Error('sem audio');
      })
      .then(function (blob) {
        var url = URL.createObjectURL(blob);
        ttsCache[clean] = url;
        playAudio(url);
      })
      .catch(function () { browserSpeak(clean); });
  }

  function playAudio(url) {
    try {
      if (!audioEl) audioEl = new Audio();
      audioEl.src = url;
      audioEl.play().catch(function () { browserSpeak(''); });
    } catch (e) { browserSpeak(''); }
  }

  function browserSpeak(text) {
    if (!voiceOn || !text || !window.speechSynthesis) return;
    try {
      var u = new SpeechSynthesisUtterance(text);
      u.lang = 'pt-BR'; u.rate = 1.02; u.pitch = 1;
      var vs = window.speechSynthesis.getVoices();
      for (var i = 0; i < vs.length; i++) {
        if ((vs[i].lang || '').toLowerCase().indexOf('pt') === 0) { u.voice = vs[i]; break; }
      }
      window.speechSynthesis.cancel();
      window.speechSynthesis.speak(u);
    } catch (e) {}
  }

  /* ---------------- voz: STT ---------------- */
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  var rec = null, listening = false, live = false, liveWanted = false;

  function buildRec() {
    if (!SR) return null;
    var r = new SR();
    r.lang = 'pt-BR';
    r.interimResults = true;
    r.continuous = true;
    r.maxAlternatives = 1;
    r.onstart = function () { listening = true; mic.classList.add('listening'); mic.setAttribute('aria-pressed', 'true'); toast('Ouvindo…'); };
    r.onend = function () {
      listening = false; mic.classList.remove('listening'); mic.setAttribute('aria-pressed', 'false');
      if (liveWanted) { setTimeout(function () { try { r.start(); } catch (e) {} }, 350); }
      else toast('Online · motor local');
    };
    r.onerror = function (ev) {
      listening = false; mic.classList.remove('listening');
      if (ev.error === 'not-allowed' || ev.error === 'service-not-allowed') {
        toast('Microfone bloqueado');
        liveWanted = false; live = false;
        if (liveBtn) liveBtn.classList.remove('on');
      } else if (ev.error === 'no-speech') {
        /* silêncio: continua no modo tempo real */
      } else {
        toast('Voz: ' + ev.error);
      }
    };
    r.onresult = function (ev) {
      var interim = '', final = '';
      for (var i = ev.resultIndex; i < ev.results.length; i++) {
        var res = ev.results[i];
        if (res.isFinal) final += res[0].transcript;
        else interim += res[0].transcript;
      }
      if (interim) input.value = interim;
      if (final) {
        input.value = final.trim();
        if (live || final.trim().length > 2) sendMsg();
      }
    };
    return r;
  }

  function startListen() {
    if (!SR) { toast('Este navegador não tem reconhecimento de voz. Use o Chrome/Edge.'); return; }
    if (!rec) rec = buildRec();
    stopSpeak();
    try { rec.start(); } catch (e) { /* já ativo */ }
  }
  function stopListen() {
    liveWanted = false;
    if (rec) { try { rec.stop(); } catch (e) {} }
    listening = false; mic.classList.remove('listening');
  }

  mic.addEventListener('click', function (e) {
    e.preventDefault();
    if (listening) { stopListen(); toast('Microfone pausado'); }
    else { liveWanted = false; startListen(); }
  });

  function toggleLive() {
    live = !live;
    liveWanted = live;
    if (liveBtn) liveBtn.classList.toggle('on', live);
    if (live) { startListen(); toast('Conversa em tempo real'); }
    else { stopListen(); toast('Online · motor local'); }
  }

  /* ---------------- envio ---------------- */
  function sendMsg() {
    var text = (input.value || '').trim();
    if (!text || busy) return;
    input.value = '';
    autoGrow();
    addMsg('user', text);
    history.push({ r: 'user', t: text });
    store('amx3d_hist', history.slice(-60));
    updateCount();

    busy = true; send.disabled = true;
    var pending = addMsg('bot', '');
    pending.querySelector('.amxai-bubble').innerHTML = '<span class="amxai-typing"><i></i><i></i><i></i></span>';
    scrollBottom();

    var ctx = { ultimo: lastEnt, objetos: (window.WORLD ? window.WORLD.state.objects.map(function (o) { return o.nome || o.ent; }) : []) };

    fetch('?api=chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ msg: text, ctx: ctx })
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok) throw new Error((j && j.erro) || 'falha');
      var results = [];
      var acts = j.actions || [];
      acts.forEach(function (a) {
        var res = null;
        try { res = window.WORLD ? window.WORLD.apply(a) : null; } catch (e) { res = { ok: false }; }
        results.push({ label: actionLabel(a, res), ok: !res || res.ok !== false });
        if (a.t === 'spawn') lastEnt = a.nome || a.ent;
        if (a.t === 'avatar') lastEnt = 'avatar';
        if (a.t === 'modify') lastEnt = a.ent;
      });
      var bubble = pending.querySelector('.amxai-bubble');
      bubble.innerHTML = '<span class="amxai-txt">' + fmt(j.reply) + '</span>';
      if (results.length) {
        var box = document.createElement('div');
        box.className = 'amxai-acts';
        results.forEach(function (r) {
          var s = document.createElement('span');
          s.className = 'amxai-act ' + (r.ok ? 'ok' : 'er');
          s.textContent = r.label;
          box.appendChild(s);
        });
        bubble.appendChild(box);
      }
      history.push({ r: 'bot', t: j.reply });
      store('amx3d_hist', history.slice(-60));
      updateCount();
      scrollBottom();
      if (j.sugestoes && j.sugestoes.length) renderChips(j.sugestoes);
      speak(j.fala || j.reply);
      if (window.WORLD) window.WORLD.save();
      toast('Online · ' + (j.ms || 0) + ' ms');
    }).catch(function (err) {
      pending.querySelector('.amxai-bubble').innerHTML = '<span class="amxai-txt">Não consegui falar com o motor local. Verifique se o PHP está rodando (php -S) e recarregue. <code>' + esc(err.message) + '</code></span>';
      toast('Motor offline');
    }).then(function () {
      busy = false; send.disabled = false;
    });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); sendMsg(); });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); }
  });
  function autoGrow() {
    input.style.height = 'auto';
    input.style.height = Math.min(110, input.scrollHeight) + 'px';
  }
  input.addEventListener('input', autoGrow);

  /* ---------------- chips ---------------- */
  var SUG = [
    'Crie um prédio à direita',
    'Crie um guarda-roupa',
    'Crie um avatar chamado Leo',
    'Adicione um título na tela',
    'Adicione um contador de pontos',
    'Modifique a casa para azul e brilhante',
    'Crie 5 dinossauros no centro',
    'Crie game'
  ];
  function renderChips(list) {
    chips.innerHTML = '';
    list.slice(0, 8).forEach(function (t) {
      var b = document.createElement('button');
      b.type = 'button'; b.setAttribute('data-chip', t); b.textContent = t;
      chips.appendChild(b);
    });
  }
  renderChips(SUG);

  /* ---------------- limpar ---------------- */
  function limpar() {
    history = []; store('amx3d_hist', []); msgs.innerHTML = ''; updateCount();
    addMsg('bot', 'Conversa limpa. O mundo 3D continua como está. Peça algo novo — ou diga “limpe a cena”.');
  }

  /* ---------------- boot ---------------- */
  /* uma vez, ao atualizar para o AMHEEX GAME 3.0, limpa o histórico antigo */
  try {
    if (load('amx3d_ver', '') !== '3.0') {
      store('amx3d_hist', []); store('amx3d_ver', '3.0');
    }
  } catch (e) {}
  var saved = load('amx3d_hist', []);
  if (saved && saved.length) {
    history = saved.slice(-40);
    history.forEach(function (m) { addMsg(m.r === 'user' ? 'user' : 'bot', m.t); });
  } else {
    addMsg('bot', 'Oi! Eu sou a **AMHEEX AI**, a inteligência do **AMHEEX GAME**. O mundo começa vazio de propósito: só **chão verde** e a **bola vermelha**. Todo o resto eu crio quando você pedir.\n\nPosso criar **qualquer coisa 3D** (prédio, casa, dinossauro, o que você inventar), **personagens realistas com skin procedural**, **controles** (andar, correr, pular, câmera em 1ª/3ª pessoa, modo tiro), **multiplayer por IP com mapa compartilhado e chat**, **elementos na tela** e **modificar** objetos que já existem. Diga **“crie game”** para montar um jogo inteiro, ou **“ative o multiplayer”**. Fale pelo microfone ou escreva.');
  }
  updateCount();
  applyFabPos();

  fetch('?api=ping').then(function (r) { return r.json(); }).then(function (j) {
    if (j && j.ok) {
      var vm = j.voz === 'fish-clone' ? 'voz AMHEEX (Fish)' : (j.voz === 'espeak-ng' ? 'voz local' : 'voz navegador');
      toast('Online · ' + vm + ' · PHP ' + j.php + (j.multiplayer ? ' · multiplayer' : ''));
    }
  }).catch(function () { toast('Motor offline (PHP não respondeu)'); });

  /* API pública para integrar em qualquer página */
  window.AMHEEX_IA3D = {
    version: '3.0.0',
    chat: function (text) { input.value = text; sendMsg(); },
    speak: speak,
    listen: startListen,
    stopListen: stopListen,
    open: openPanel,
    close: closePanel,
    setVoice: function (on) { voiceOn = !!on; if (voiceBtn) voiceBtn.classList.toggle('on', voiceOn); store('amx3d_voz', voiceOn); },
    history: function () { return history.slice(); }
  };
})();
