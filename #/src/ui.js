/* ============================================================
   AMHEEX GAME — UI estilo Roblox
   boot • login/registro • editor de personagem • HUD • joystick
   configurações em abas • voz por proximidade (WebRTC)
   ============================================================ */
(function () {
  'use strict';

  function $(id) { return document.getElementById(id); }
  function on(el, ev, fn) { if (el) el.addEventListener(ev, fn); }
  function lsGet(k, d) { try { var v = localStorage.getItem(k); return v == null ? d : v; } catch (e) { return d; } }
  function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function lsDel(k) { try { localStorage.removeItem(k); } catch (e) {} }

  var CFG = {
    volume: Number(lsGet('amx_volume', 80)),
    voiceRange: Number(lsGet('amx_voice_range', 16)),
    sens: Number(lsGet('amx_sens', 100)),
    names: lsGet('amx_names', 'on') === 'on',
    mic: lsGet('amx_mic', 'off') === 'on',
    quality: lsGet('amx_quality', 'mid')
  };

  var SKIN = { pele: '#c68642', cor: '#4f7cff', calca: '#1f2a44', cabelo: '#1b1b1b', seed: 12345 };
  var USER = null;
  window.AMX_UI = { cfg: CFG, skin: SKIN, user: function () { return USER; } };
  window.AMX_USER = null;

  /* ---------------- 1) BOOT ---------------- */
  var boot = $('boot'), bootFill = $('bootFill'), bootPct = $('bootPct'), bootTip = $('bootTip');
  var pct = 0, bootDone = false;
  var tips = [
    'Preparando o mundo, carregando a AMHEEX AI…',
    'Gerando o chão verde e o céu azul…',
    'Conectando o mundo global…',
    'Acordando o NPC AMHEEX BOT…',
    'Quase lá — abrindo o login…'
  ];
  function paintBoot(p) {
    pct = Math.max(0, Math.min(100, p));
    if (bootFill) bootFill.style.width = pct + '%';
    if (bootPct) bootPct.textContent = Math.round(pct) + '%';
    if (bootTip) bootTip.textContent = tips[Math.min(tips.length - 1, Math.floor(pct / 22))];
  }
  var bootTimer = setInterval(function () {
    if (bootDone) return;
    var step = pct < 55 ? 2.6 : pct < 85 ? 1.4 : 0.6;
    paintBoot(pct + step);
    if (pct >= 100) finishBoot();
  }, 55);
  function finishBoot() {
    if (bootDone) return;
    bootDone = true; clearInterval(bootTimer); paintBoot(100);
    setTimeout(function () { if (boot) boot.classList.add('off'); openLogin(); }, 420);
  }

  /* ---------------- 2) LOGIN ---------------- */
  var loginEl = $('login'), loginMsg = $('loginMsg'), modoLogin = 'entrar';

  function openLogin() {
    if (loginEl) loginEl.classList.add('on');
    var u = lsGet('amx_usuario', '');
    if (u && $('loginUser')) $('loginUser').value = u;
    setTimeout(function () { var el = $('loginUser'); if (el) el.focus(); }, 120);
    var tok = lsGet('amx_token', '');
    if (tok) {
      fetch('?api=perfil&token=' + encodeURIComponent(tok)).then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok && j.user) { USER = j.user; window.AMX_USER = USER; if (loginEl) loginEl.classList.remove('on'); enterAvatar(); } })
        .catch(function () {});
    }
  }
  function setLoginTab(t) {
    modoLogin = t;
    var tabs = document.querySelectorAll('#loginTabs button');
    for (var i = 0; i < tabs.length; i++) tabs[i].classList.toggle('on', tabs[i].getAttribute('data-tab') === t);
    var wrap = $('loginNomeWrap'); if (wrap) wrap.hidden = (t !== 'criar');
    var go = $('loginGo'); if (go) go.textContent = t === 'criar' ? 'CRIAR CONTA E JOGAR' : 'ENTRAR NO MUNDO';
    if (loginMsg) { loginMsg.textContent = ''; loginMsg.className = ''; }
  }
  var tabBtns = document.querySelectorAll('#loginTabs button');
  for (var tb = 0; tb < tabBtns.length; tb++) {
    (function (b) { b.addEventListener('click', function () { setLoginTab(b.getAttribute('data-tab')); }); })(tabBtns[tb]);
  }
  function msg(txt, ok) { if (!loginMsg) return; loginMsg.textContent = txt; loginMsg.className = ok ? 'ok' : ''; }

  function doAuth() {
    var usuario = (($('loginUser') && $('loginUser').value) || '').trim().toLowerCase();
    var senha = (($('loginPass') && $('loginPass').value) || '');
    var nome = (($('loginNome') && $('loginNome').value) || '').trim();
    if (usuario.length < 3) { msg('Usuário precisa de 3 caracteres ou mais.'); return; }
    if (senha.length < 4) { msg('Senha precisa de 4 caracteres ou mais.'); return; }
    var go = $('loginGo'); if (go) { go.disabled = true; go.textContent = 'CONECTANDO…'; }
    var acao = modoLogin === 'criar' ? 'registrar' : 'login';
    fetch('?api=' + acao, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ usuario: usuario, senha: senha, nome: nome || usuario })
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok) {
        msg((j && j.erro) || 'Não foi possível entrar.');
        if (go) { go.disabled = false; go.textContent = modoLogin === 'criar' ? 'CRIAR CONTA E JOGAR' : 'ENTRAR NO MUNDO'; }
        return;
      }
      USER = j.user; window.AMX_USER = USER;
      lsSet('amx_token', USER.token);
      lsSet('amx_usuario', USER.usuario);
      lsSet('amx_nome', USER.nome || USER.usuario);
      msg(j.novo ? 'Conta criada! Montando seu personagem…' : 'Bem-vindo de volta!', true);
      if (j.user.skin && j.user.skin.pele) {
        SKIN = {
          pele: j.user.skin.pele, cor: j.user.skin.cor || '#4f7cff',
          calca: j.user.skin.calca || '#1f2a44', cabelo: j.user.skin.cabelo || '#1b1b1b',
          seed: Number(j.user.skin.seed || 12345)
        };
        window.AMX_UI.skin = SKIN;
      }
      setTimeout(function () { if (loginEl) loginEl.classList.remove('on'); enterAvatar(); }, 420);
    }).catch(function () {
      msg('Servidor indisponível. Tente novamente.');
      if (go) { go.disabled = false; go.textContent = 'ENTRAR NO MUNDO'; }
    });
  }
  on($('loginGo'), 'click', doAuth);
  on($('loginPass'), 'keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doAuth(); } });
  on($('loginUser'), 'keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); var p = $('loginPass'); if (p) p.focus(); } });

  /* ---------------- 3) EDITOR DE PERSONAGEM ---------------- */
  var avatarEl = $('avatar');
  var PALETAS = {
    pele: ['#f6d5b8', '#e8b98f', '#c68642', '#a5673f', '#8d5524', '#5c3a21', '#f1c9a5', '#3f2a1c'],
    cor: ['#4f7cff', '#ff3d81', '#22c55e', '#f59e0b', '#a855f7', '#ef4444', '#0ea5e9', '#e2e8f0', '#111827'],
    calca: ['#1f2a44', '#111827', '#3b4a6b', '#6b7280', '#7c2d12', '#14532d', '#4c1d95', '#e5e7eb'],
    cabelo: ['#1b1b1b', '#3b2314', '#6b4423', '#d4a24c', '#8b2f2f', '#e5e7eb', '#7c3aed', '#0ea5e9']
  };
  function swId(k) { return 'av' + k.charAt(0).toUpperCase() + k.slice(1); }
  function buildSwatches() {
    ['pele', 'cor', 'calca', 'cabelo'].forEach(function (k) {
      var box = $(swId(k)); if (!box) return;
      box.innerHTML = '';
      PALETAS[k].forEach(function (hex) {
        var i = document.createElement('i');
        i.style.background = hex; i.setAttribute('data-hex', hex);
        i.addEventListener('click', function () {
          SKIN[k] = hex; paintSwatches();
          if (window.AMX_PREVIEW) window.AMX_PREVIEW(SKIN);
        });
        box.appendChild(i);
      });
    });
    paintSwatches();
  }
  function paintSwatches() {
    ['pele', 'cor', 'calca', 'cabelo'].forEach(function (k) {
      var box = $(swId(k)); if (!box) return;
      var kids = box.querySelectorAll('i');
      for (var i = 0; i < kids.length; i++) kids[i].classList.toggle('on', kids[i].getAttribute('data-hex') === SKIN[k]);
    });
  }
  function enterAvatar() {
    if (avatarEl) avatarEl.classList.add('on');
    if (avatarEl && !avatarEl._built) { buildSwatches(); avatarEl._built = true; }
    paintSwatches();
    var nome = (USER && (USER.nome || USER.usuario)) || 'Jogador';
    if (window.AMX_SET) window.AMX_SET.nome = nome;
    setTimeout(function () { if (window.AMX_PREVIEW) window.AMX_PREVIEW(SKIN); }, 220);
  }
  on($('avRandom'), 'click', function () {
    function pick(a) { return a[Math.floor(Math.random() * a.length)]; }
    SKIN.pele = pick(PALETAS.pele); SKIN.cor = pick(PALETAS.cor);
    SKIN.calca = pick(PALETAS.calca); SKIN.cabelo = pick(PALETAS.cabelo);
    SKIN.seed = Math.floor(1 + Math.random() * 999999);
    paintSwatches();
    if (window.AMX_PREVIEW) window.AMX_PREVIEW(SKIN);
  });
  on($('avPlay'), 'click', function () {
    var nome = (USER && (USER.nome || USER.usuario)) || 'Jogador';
    if (avatarEl) avatarEl.classList.remove('on');
    if (USER && USER.token) {
      fetch('?api=salvar_perfil', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: USER.token, skin: SKIN, nome: nome })
      }).then(function (r) { return r.json(); }).then(function (j) { if (j && j.ok && j.user) { USER = j.user; window.AMX_USER = USER; } }).catch(function () {});
    }
    if (window.AMX_SKIN) window.AMX_SKIN(SKIN);
    if (window.AMX_START) window.AMX_START(nome);
    setTimeout(function () { if (CFG.mic) startVoice(); }, 900);
  });

  /* ---------------- 4) HUD ---------------- */
  var rbChat = $('rbChat');
  function toggleChat(force) {
    if (!rbChat) return;
    var open = force == null ? !rbChat.classList.contains('on') : !!force;
    rbChat.classList.toggle('on', open);
    if (open) { var ci = $('rbChatInput'); if (ci) setTimeout(function () { ci.focus(); }, 60); }
  }
  on($('rbChatBtn'), 'click', function () { toggleChat(); });
  on($('rbMenu'), 'click', function () { openSettings(true, 'config'); });

  var chatTab = 'global';
  var chatTabs = document.querySelectorAll('#rbChatTabs button');
  for (var ct = 0; ct < chatTabs.length; ct++) {
    (function (b) {
      b.addEventListener('click', function () {
        chatTab = b.getAttribute('data-tab');
        for (var i = 0; i < chatTabs.length; i++) chatTabs[i].classList.toggle('on', chatTabs[i] === b);
        if (window.MP && window.MP.renderChat) window.MP.renderChat();
      });
    })(chatTabs[ct]);
  }
  on($('rbChatForm'), 'submit', function (e) {
    e.preventDefault();
    var inp = $('rbChatInput');
    var t = inp && inp.value ? inp.value.trim() : '';
    if (!t) return;
    if (window.MP && window.MP.sendChat) window.MP.sendChat(t);
    if (inp) inp.value = '';
  });

  var sides = document.querySelectorAll('#rbLeft .rbSide');
  for (var sd = 0; sd < sides.length; sd++) {
    (function (b) {
      b.addEventListener('click', function () {
        var o = b.getAttribute('data-open');
        if (o === 'sair') { sair(); return; }
        if (o === 'skin') { if (avatarEl) avatarEl.classList.add('on'); return; }
        openSettings(true, (o === 'loja' || o === 'inv') ? 'galeria' : 'config');
      });
    })(sides[sd]);
  }

  /* ---------------- 5) JOYSTICK ---------------- */
  var joy = $('rbJoy'), knob = $('rbJoyKnob');
  var joyActive = false, joyId = null;
  function setMove(f, b, l, r) {
    var mi = window.WORLD && window.WORLD.player && window.WORLD.player.moveInput;
    if (!mi) return;
    mi.f = f; mi.b = b; mi.l = l; mi.r = r;
  }
  function joyStart(e) {
    joyActive = true; joyId = e.pointerId;
    if (joy && joy.setPointerCapture) { try { joy.setPointerCapture(e.pointerId); } catch (err) {} }
    joyMove(e);
  }
  function joyMove(e) {
    if (!joyActive || (joyId != null && e.pointerId !== joyId)) return;
    var rect = joy.getBoundingClientRect();
    var cx = rect.left + rect.width / 2, cy = rect.top + rect.height / 2;
    var dx = e.clientX - cx, dy = e.clientY - cy;
    var max = rect.width / 2 - 8;
    var d = Math.sqrt(dx * dx + dy * dy);
    if (d > max) { dx = dx / d * max; dy = dy / d * max; }
    if (knob) knob.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
    var nx = dx / max, ny = dy / max;
    setMove(ny < -0.25, ny > 0.25, nx < -0.25, nx > 0.25);
    e.preventDefault();
  }
  function joyEnd(e) {
    if (joyId != null && e.pointerId !== joyId) return;
    joyActive = false; joyId = null;
    if (knob) knob.style.transform = 'translate(0,0)';
    setMove(false, false, false, false);
  }
  if (joy) {
    joy.addEventListener('pointerdown', joyStart);
    joy.addEventListener('pointermove', joyMove);
    joy.addEventListener('pointerup', joyEnd);
    joy.addEventListener('pointercancel', joyEnd);
  }
  on($('rbJump'), 'pointerdown', function (e) { e.preventDefault(); if (window.WORLD && window.WORLD.player) window.WORLD.player.jump = true; });
  var correndo = false;
  on($('rbRun'), 'click', function () {
    correndo = !correndo;
    this.classList.toggle('on', correndo);
    if (window.WORLD && window.WORLD.player) window.WORLD.player.run = correndo;
  });
  on($('rbCam'), 'click', function () {
    if (!window.WORLD || !window.WORLD.control) return;
    var m = (window.WORLD.player && window.WORLD.player.mode) || 'third';
    window.WORLD.control(m === 'third' ? 'first' : m === 'first' ? 'shooter' : 'third');
  });
  on($('rbAction'), 'click', function () {
    if (window.WORLD && window.WORLD.player && window.WORLD.player.mode === 'shooter') {
      if (window.WORLD.player.fire) window.WORLD.player.fire();
    } else if (window.AMX_UI && window.AMX_UI.openAI) window.AMX_UI.openAI();
  });

  /* ---------------- 6) CONFIGURAÇÕES ---------------- */
  var setEl = $('rbSettings');
  function openSettings(force, tab) {
    if (!setEl) return;
    var open = force == null ? !setEl.classList.contains('on') : !!force;
    setEl.classList.toggle('on', open);
    if (open) {
      if (tab) selectSetTab(tab);
      syncSettingsUI();
      if (window.MP && window.MP.renderPlayers) window.MP.renderPlayers();
      renderGallery();
    }
  }
  function selectSetTab(t) {
    var bs = document.querySelectorAll('#rbSetTabs button');
    for (var i = 0; i < bs.length; i++) bs[i].classList.toggle('on', bs[i].getAttribute('data-t') === t);
    var bodies = document.querySelectorAll('.rbSetBody');
    for (var k = 0; k < bodies.length; k++) bodies[k].classList.toggle('on', bodies[k].getAttribute('data-body') === t);
  }
  var setTabs = document.querySelectorAll('#rbSetTabs button');
  for (var st = 0; st < setTabs.length; st++) {
    (function (b) { b.addEventListener('click', function () { selectSetTab(b.getAttribute('data-t')); }); })(setTabs[st]);
  }
  on($('rbCont'), 'click', function () { openSettings(false); });
  on(setEl, 'click', function (e) { if (e.target === setEl) openSettings(false); });

  var segs = $('rbVolSegs');
  if (segs) {
    segs.innerHTML = '';
    for (var si = 0; si < 10; si++) {
      var seg = document.createElement('i');
      seg.setAttribute('data-v', String((si + 1) * 10));
      seg.addEventListener('click', function () {
        CFG.volume = Number(this.getAttribute('data-v'));
        lsSet('amx_volume', CFG.volume);
        if (window.AMX_SET) window.AMX_SET.volume = CFG.volume / 100;
        paintSegs();
      });
      segs.appendChild(seg);
    }
  }
  function paintSegs() {
    if (!segs) return;
    var kids = segs.querySelectorAll('i');
    for (var i = 0; i < kids.length; i++) kids[i].classList.toggle('on', (i + 1) * 10 <= CFG.volume);
  }
  function syncSettingsUI() {
    var q = $('rbQuality'); if (q) q.value = CFG.quality;
    var vr = $('rbVoiceRange'); if (vr) vr.value = CFG.voiceRange;
    var vrv = $('rbVoiceRangeV'); if (vrv) vrv.textContent = CFG.voiceRange + 'm';
    var s = $('rbSens'); if (s) s.value = CFG.sens;
    var sv = $('rbSensV'); if (sv) sv.textContent = CFG.sens;
    var nm = $('rbNames'); if (nm) nm.value = CFG.names ? 'on' : 'off';
    var mic = $('rbMic'); if (mic) mic.textContent = voice.on ? (voice.muted ? 'MUDO (V)' : 'LIGADO (V)') : 'LIGAR (V)';
    paintSegs();
  }
  on($('rbQuality'), 'change', function () {
    CFG.quality = this.value; lsSet('amx_quality', CFG.quality);
    if (window.WORLD && window.WORLD.setQuality) window.WORLD.setQuality(CFG.quality);
  });
  on($('rbVoiceRange'), 'input', function () {
    CFG.voiceRange = Number(this.value); lsSet('amx_voice_range', CFG.voiceRange);
    var v = $('rbVoiceRangeV'); if (v) v.textContent = CFG.voiceRange + 'm';
    if (window.AMX_SET) window.AMX_SET.voiceRange = CFG.voiceRange;
  });
  on($('rbSens'), 'input', function () {
    CFG.sens = Number(this.value); lsSet('amx_sens', CFG.sens);
    var v = $('rbSensV'); if (v) v.textContent = CFG.sens;
    if (window.AMX_SET) window.AMX_SET.sens = CFG.sens / 100;
  });
  on($('rbNames'), 'change', function () {
    CFG.names = this.value === 'on'; lsSet('amx_names', CFG.names ? 'on' : 'off');
    if (window.AMX_NAMES) window.AMX_NAMES(CFG.names);
  });
  on($('rbMic'), 'click', function () { if (voice.on) toggleMute(); else startVoice(); syncSettingsUI(); });

  function renderGallery() {
    var box = $('rbGallery'); if (!box) return;
    var objs = (window.WORLD && window.WORLD.state && window.WORLD.state.objects) || [];
    var html = '';
    for (var i = 0; i < objs.length; i++) {
      var o = objs[i];
      if (o.isAvatar) continue;
      html += '<div class="rbCard"><b>' + (o.nome || o.ent) + '</b>' + (o.forma || o.ent) + '</div>';
    }
    box.innerHTML = html || '<div class="rbCard"><b>Mapa vazio</b>peça algo para a IA</div>';
  }
  on($('rbReportGo'), 'click', function () {
    var who = $('rbReportWho'), why = $('rbReportWhy');
    if (!who || !who.value) { if (window.MP) window.MP.toast('Nenhum jogador para denunciar.'); return; }
    fetch('?api=mp_chat', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: 'denuncia', name: 'DENÚNCIA', msg: 'Denúncia contra ' + who.options[who.selectedIndex].text + ': ' + (why ? why.value : '') })
    }).catch(function () {});
    if (window.MP) window.MP.toast('Denúncia registrada.');
  });
  on($('rbRegen'), 'click', function () {
    openSettings(false);
    if (avatarEl) avatarEl.classList.add('on');
    buildSwatches();
    if (window.AMX_PREVIEW) window.AMX_PREVIEW(SKIN);
  });

  function sair() {
    if (USER && USER.token) {
      fetch('?api=sair', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token: USER.token }) }).catch(function () {});
    }
    lsDel('amx_token');
    USER = null; window.AMX_USER = null;
    openSettings(false);
    if (avatarEl) avatarEl.classList.remove('on');
    var hud = $('rblx'); if (hud) hud.classList.remove('on');
    if (window.MP && window.MP.enable) window.MP.enable(false);
    if (loginEl) loginEl.classList.add('on');
  }
  on($('rbSair'), 'click', sair);

  /* ---------------- 7) VOZ POR PROXIMIDADE ---------------- */
  var voice = { on: false, muted: false, stream: null, peers: {}, pollTimer: null, starting: false };
  window.AMX_VOICE = voice;
  var ICE = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }, { urls: 'stun:stun1.l.google.com:19302' }] };

  function startVoice() {
    if (voice.on || voice.starting) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      if (window.MP) window.MP.toast('Este navegador não suporta microfone.'); return;
    }
    voice.starting = true;
    navigator.mediaDevices.getUserMedia({ audio: true, video: false }).then(function (st) {
      voice.stream = st; voice.on = true; voice.starting = false; voice.muted = false;
      startPolling(); syncSettingsUI();
      if (window.MP) window.MP.toast('Voz ligada — só quem está perto escuta.');
    }).catch(function () { voice.starting = false; if (window.MP) window.MP.toast('Microfone bloqueado pelo navegador.'); });
  }
  function stopVoice() {
    voice.on = false; stopPolling();
    for (var id in voice.peers) closePeer(id);
    voice.peers = {};
    if (voice.stream) { try { voice.stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {} voice.stream = null; }
    syncSettingsUI();
  }
  function toggleMute() {
    voice.muted = !voice.muted;
    if (voice.stream) { try { voice.stream.getAudioTracks().forEach(function (t) { t.enabled = !voice.muted; }); } catch (e) {} }
    syncSettingsUI();
    if (window.MP) window.MP.toast(voice.muted ? 'Microfone mutado.' : 'Microfone ativo.');
  }
  function rtcSend(to, kind, data) {
    if (!window.MP || !window.MP.id || !to) return;
    fetch('?api=rtc_send', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ from: window.MP.id, to: to, kind: kind, data: data })
    }).catch(function () {});
  }
  function startPolling() { if (voice.pollTimer) return; voice.pollTimer = setInterval(pollSignals, 1200); pollSignals(); }
  function stopPolling() { if (voice.pollTimer) { clearInterval(voice.pollTimer); voice.pollTimer = null; } }
  function pollSignals() {
    if (!voice.on || !window.MP || !window.MP.id) return;
    fetch('?api=rtc_poll&id=' + encodeURIComponent(window.MP.id)).then(function (r) { return r.json(); })
      .then(function (j) { if (!j || !j.ok || !j.msgs) return; for (var i = 0; i < j.msgs.length; i++) handleSignal(j.msgs[i]); })
      .catch(function () {});
  }
  function handleSignal(m) {
    if (!m || !m.from) return;
    var id = m.from;
    if (m.kind === 'offer') {
      var pc = ensurePeer(id);
      pc.setRemoteDescription(new RTCSessionDescription(m.data)).then(function () { return pc.createAnswer(); })
        .then(function (ans) { return pc.setLocalDescription(ans); })
        .then(function () { rtcSend(id, 'answer', pc.localDescription); }).catch(function () {});
    } else if (m.kind === 'answer') {
      var p2 = voice.peers[id];
      if (p2 && !p2._answered) { p2._answered = true; p2.setRemoteDescription(new RTCSessionDescription(m.data)).catch(function () {}); }
    } else if (m.kind === 'ice') {
      var p3 = voice.peers[id];
      if (p3 && m.data) { try { p3.addIceCandidate(new RTCIceCandidate(m.data)).catch(function () {}); } catch (e) {} }
    } else if (m.kind === 'bye') { closePeer(id); }
  }
  function ensurePeer(id) {
    if (voice.peers[id]) return voice.peers[id];
    var pc = new RTCPeerConnection(ICE);
    pc._offered = false; pc._answered = false; pc._audio = null;
    voice.peers[id] = pc;
    if (voice.stream) { try { voice.stream.getTracks().forEach(function (t) { pc.addTrack(t, voice.stream); }); } catch (e) {} }
    pc.onicecandidate = function (e) { if (e.candidate) rtcSend(id, 'ice', e.candidate); };
    pc.ontrack = function (e) {
      var audio = document.createElement('audio');
      audio.autoplay = true; audio.srcObject = e.streams[0]; audio.style.display = 'none';
      audio.volume = Math.max(0, Math.min(1, CFG.volume / 100));
      document.body.appendChild(audio); pc._audio = audio;
      try { audio.play().catch(function () {}); } catch (err) {}
    };
    pc.onconnectionstatechange = function () { if (pc.connectionState === 'failed' || pc.connectionState === 'closed') closePeer(id); };
    return pc;
  }
  function offerTo(id) {
    var pc = ensurePeer(id);
    if (pc._offered) return;
    pc._offered = true;
    pc.createOffer({ offerToReceiveAudio: true }).then(function (off) { return pc.setLocalDescription(off); })
      .then(function () { rtcSend(id, 'offer', pc.localDescription); })
      .catch(function () { pc._offered = false; });
  }
  function closePeer(id) {
    var pc = voice.peers[id];
    if (!pc) return;
    try { rtcSend(id, 'bye', null); } catch (e) {}
    try { if (pc._audio) { pc._audio.srcObject = null; pc._audio.parentNode.removeChild(pc._audio); } } catch (e) {}
    try { pc.close(); } catch (e) {}
    delete voice.peers[id];
  }
  function proximityTick() {
    if (!voice.on || !window.MP || !window.MP.remotes) return;
    var me = window.WORLD && window.WORLD.player && window.WORLD.player.node;
    if (!me) return;
    var range = CFG.voiceRange, vistos = {};
    for (var id in window.MP.remotes) {
      var r = window.MP.remotes[id];
      if (!r || !r.node) continue;
      vistos[id] = true;
      var dx = r.node.position.x - me.position.x, dz = r.node.position.z - me.position.z;
      var dist = Math.sqrt(dx * dx + dz * dz);
      var perto = dist <= range;
      var pc = voice.peers[id];
      if (perto) {
        if (!pc) pc = ensurePeer(id);
        if (String(window.MP.id) < String(id) && !pc._offered) offerTo(id);
      }
      if (pc && pc._audio) {
        pc._audio.muted = !perto || voice.muted;
        pc._audio.volume = Math.max(0.05, Math.min(1, (CFG.volume / 100) * (1 - dist / (range * 1.6))));
      }
    }
    for (var pid in voice.peers) { if (!vistos[pid]) closePeer(pid); }
  }
  setInterval(proximityTick, 900);

  document.addEventListener('keydown', function (e) {
    var tag = (e.target && e.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (e.key === 'v' || e.key === 'V') { e.preventDefault(); if (voice.on) toggleMute(); else startVoice(); }
    if (e.key === 't' || e.key === 'T') { e.preventDefault(); toggleChat(true); }
    if (e.key === 'Escape') { toggleChat(false); openSettings(false); }
  });

  /* ---------------- 8) BOOTSTRAP ---------------- */
  function applySaved() {
    if (window.WORLD && window.WORLD.setQuality) window.WORLD.setQuality(CFG.quality);
    if (window.AMX_SET) {
      window.AMX_SET.volume = CFG.volume / 100;
      window.AMX_SET.voiceRange = CFG.voiceRange;
      window.AMX_SET.sens = CFG.sens / 100;
    }
    if (window.AMX_NAMES) window.AMX_NAMES(CFG.names);
    syncSettingsUI();
  }
  var tries = 0;
  var readyTimer = setInterval(function () {
    tries++;
    if (window.WORLD && window.AMX_START) { clearInterval(readyTimer); applySaved(); if (pct > 92) finishBoot(); }
    else if (tries > 120) { clearInterval(readyTimer); }
  }, 100);
  window.addEventListener('load', function () { setTimeout(function () { if (!bootDone) finishBoot(); }, 2800); });

  window.AMX_UI.openSettings = openSettings;
  window.AMX_UI.toggleChat = toggleChat;
  window.AMX_UI.startVoice = startVoice;
  window.AMX_UI.stopVoice = stopVoice;
  window.AMX_UI.toggleMute = toggleMute;
  window.AMX_UI.sair = sair;
})();
