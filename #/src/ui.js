/* ============================================================
   AMHEEX GAME — interface (boot, menu, chat, configurações, voz)
   Este módulo NÃO altera o mapa nem o layout fixo do jogo.
   Ele apenas liga os elementos de interface ao motor (WORLD/MP).
   ============================================================ */
(function () {
  'use strict';

  function $(id) { return document.getElementById(id); }
  function on(el, ev, fn) { if (el) el.addEventListener(ev, fn); }
  function lsGet(k, d) { try { var v = localStorage.getItem(k); return v == null ? d : v; } catch (e) { return d; } }
  function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

  var CFG = {
    volume: Number(lsGet('amx_volume', 80)),
    voiceRange: Number(lsGet('amx_voice_range', 16)),
    sens: Number(lsGet('amx_sens', 100)),
    names: lsGet('amx_names', 'on') === 'on',
    mic: lsGet('amx_mic', 'off') === 'on',
    quality: lsGet('amx_quality', 'mid')
  };
  window.AMX_UI = { cfg: CFG };

  /* ---------------------------------------------------------
     1) TELA DE CARREGAMENTO (barra RGB + logo + %)
     --------------------------------------------------------- */
  var boot = $('boot'), bootFill = $('bootFill'), bootPct = $('bootPct'), bootTip = $('bootTip');
  var pct = 0, bootDone = false;
  var tips = [
    'Preparando o mundo, carregando a AMHEEX AI…',
    'Gerando o chão verde e a bola vermelha…',
    'Conectando o multiplayer por IP…',
    'Acordando o NPC AMHEEX BOT…',
    'Quase lá — entrando no mundo…'
  ];

  function paintBoot(p) {
    pct = Math.max(0, Math.min(100, p));
    if (bootFill) bootFill.style.width = pct + '%';
    if (bootPct) bootPct.textContent = Math.round(pct) + '%';
    if (bootTip) bootTip.textContent = tips[Math.min(tips.length - 1, Math.floor(pct / 22))];
  }

  var bootTimer = setInterval(function () {
    if (bootDone) return;
    var step = pct < 55 ? 2.4 : pct < 85 ? 1.3 : 0.55;
    paintBoot(pct + step);
    if (pct >= 100) finishBoot();
  }, 55);

  function finishBoot() {
    if (bootDone) return;
    bootDone = true;
    clearInterval(bootTimer);
    paintBoot(100);
    setTimeout(function () {
      if (boot) boot.classList.add('off');
      showMenu();
    }, 420);
  }

  /* ---------------------------------------------------------
     2) MENU INICIAL
     --------------------------------------------------------- */
  var menu = $('menu');

  function showMenu() {
    if (menu) menu.classList.add('on');
    refreshMenuCount();
  }
  function hideMenu() { if (menu) menu.classList.remove('on'); }

  function refreshMenuCount() {
    var el = $('menuPlayers');
    if (!el) return;
    fetch('?api=mp_state', { method: 'GET' }).then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok && j.players) el.textContent = j.players.length;
      }).catch(function () {});
  }
  setInterval(function () { if (menu && menu.classList.contains('on')) refreshMenuCount(); }, 5000);

  var nameInput = $('menuName');
  if (nameInput) nameInput.value = lsGet('amx_nome', '');

  function play() {
    var nome = nameInput && nameInput.value ? nameInput.value.trim() : '';
    if (!nome) {
      nome = 'Jogador-' + Math.floor(1000 + Math.random() * 9000);
      if (nameInput) nameInput.value = nome;
    }
    lsSet('amx_nome', nome);
    hideMenu();
    if (typeof window.AMX_START === 'function') window.AMX_START(nome);
    setTimeout(function () {
      if (CFG.mic) startVoice();
    }, 900);
  }
  on($('menuPlay'), 'click', play);
  on(nameInput, 'keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); play(); } });

  on($('menuCfg'), 'click', function () { openSettings(true); });
  on($('menuHow'), 'click', showHelp);

  /* ---------------------------------------------------------
     3) BARRA SUPERIOR / CHAT / JOGADORES
     --------------------------------------------------------- */
  var chatPanel = $('chatPanel');
  function toggleChat(force) {
    if (!chatPanel) return;
    var open = force == null ? !chatPanel.classList.contains('on') : !!force;
    chatPanel.classList.toggle('on', open);
    if (open) { var ci = $('chatInput'); if (ci) setTimeout(function () { ci.focus(); }, 60); }
  }
  on($('tbChat'), 'click', function () { toggleChat(); });
  on($('chatClose'), 'click', function () { toggleChat(false); });
  on($('tbPlayers'), 'click', function () {
    toggleChat(true);
    var n = window.MP && window.MP.players ? window.MP.players.length : 0;
    if (window.MP && window.MP.toast) window.MP.toast(n + ' jogador(es) no mundo agora.');
  });
  on($('tbSettings'), 'click', function () { openSettings(true); });
  on($('tbMenu'), 'click', function () {
    toggleChat(false);
    openSettings(false);
    if (menu) menu.classList.add('on');
    if (window.MP && window.MP.active) window.MP.enable(false);
    var tb = $('topbar'); if (tb) tb.classList.remove('on');
    var vb = $('voiceBar'); if (vb) vb.classList.remove('on');
  });

  on($('chatForm'), 'submit', function (e) {
    e.preventDefault();
    var inp = $('chatInput');
    var msg = inp && inp.value ? inp.value.trim() : '';
    if (!msg) return;
    if (window.MP && window.MP.sendChat) window.MP.sendChat(msg);
    if (inp) inp.value = '';
  });

  /* ---------------------------------------------------------
     4) CONFIGURAÇÕES (estilo Roblox, sem mexer no layout fixo)
     --------------------------------------------------------- */
  var settings = $('settings');
  function openSettings(force) {
    if (!settings) return;
    var open = force == null ? !settings.classList.contains('on') : !!force;
    settings.classList.toggle('on', open);
    if (open) syncSettingsUI();
  }
  on($('settingsClose'), 'click', function () { openSettings(false); });
  on(settings, 'click', function (e) { if (e.target === settings) openSettings(false); });

  function syncSettingsUI() {
    var q = $('setQuality'); if (q) q.value = CFG.quality;
    var v = $('setVolume'); if (v) v.value = CFG.volume;
    var vv = $('setVolumeV'); if (vv) vv.textContent = CFG.volume;
    var vr = $('setVoiceRange'); if (vr) vr.value = CFG.voiceRange;
    var vrv = $('setVoiceRangeV'); if (vrv) vrv.textContent = CFG.voiceRange + 'm';
    var s = $('setSens'); if (s) s.value = CFG.sens;
    var sv = $('setSensV'); if (sv) sv.textContent = CFG.sens;
    var mic = $('setMic'); if (mic) mic.value = CFG.mic ? 'on' : 'off';
    var nm = $('setNames'); if (nm) nm.value = CFG.names ? 'on' : 'off';
  }

  on($('setQuality'), 'change', function () {
    CFG.quality = this.value; lsSet('amx_quality', CFG.quality);
    if (window.WORLD && window.WORLD.setQuality) window.WORLD.setQuality(CFG.quality);
  });
  on($('setVolume'), 'input', function () {
    CFG.volume = Number(this.value); lsSet('amx_volume', CFG.volume);
    var vv = $('setVolumeV'); if (vv) vv.textContent = CFG.volume;
    if (window.AMX_SET) window.AMX_SET.volume = CFG.volume / 100;
  });
  on($('setVoiceRange'), 'input', function () {
    CFG.voiceRange = Number(this.value); lsSet('amx_voice_range', CFG.voiceRange);
    var vrv = $('setVoiceRangeV'); if (vrv) vrv.textContent = CFG.voiceRange + 'm';
    if (window.AMX_SET) window.AMX_SET.voiceRange = CFG.voiceRange;
  });
  on($('setSens'), 'input', function () {
    CFG.sens = Number(this.value); lsSet('amx_sens', CFG.sens);
    var sv = $('setSensV'); if (sv) sv.textContent = CFG.sens;
    if (window.AMX_SET) window.AMX_SET.sens = CFG.sens / 100;
  });
  on($('setMic'), 'change', function () {
    CFG.mic = this.value === 'on'; lsSet('amx_mic', CFG.mic ? 'on' : 'off');
    if (CFG.mic) startVoice(); else stopVoice();
  });
  on($('setNames'), 'change', function () {
    CFG.names = this.value === 'on'; lsSet('amx_names', CFG.names ? 'on' : 'off');
    if (window.AMX_NAMES) window.AMX_NAMES(CFG.names);
  });

  function showHelp() {
    var old = $('amxHelp'); if (old) old.parentNode.removeChild(old);
    var box = document.createElement('div');
    box.id = 'amxHelp';
    box.style.cssText = 'position:fixed;inset:0;z-index:99998;display:flex;align-items:center;justify-content:center;background:rgba(4,7,16,.72);backdrop-filter:blur(8px);padding:18px';
    box.innerHTML =
      '<div style="max-width:520px;width:100%;background:linear-gradient(160deg,#141b2e,#0b1120);border:1px solid #2a3552;border-radius:18px;padding:22px;color:#dfe7ff;font-family:Inter,system-ui,sans-serif;box-shadow:0 24px 70px rgba(0,0,0,.6)">' +
      '<h2 style="margin:0 0 10px;font-size:20px;letter-spacing:.5px">COMO JOGAR</h2>' +
      '<p style="margin:6px 0;font-size:14px;line-height:1.55;color:#aab6d6">WASD ou setas para andar, Shift para correr, Espaço para pular. Arraste o mouse para girar a câmera e use o botão de câmera para alternar 1ª/3ª pessoa e modo tiro.</p>' +
      '<p style="margin:6px 0;font-size:14px;line-height:1.55;color:#aab6d6">Toque no ícone da AMHEEX AI para pedir coisas em linguagem natural: “crie uma torre”, “coloque um botão de andar”, “mude para floresta”. A IA cria formas 3D no mapa, mas nunca edita o personagem de outro jogador.</p>' +
      '<p style="margin:6px 0;font-size:14px;line-height:1.55;color:#aab6d6">Voz por proximidade: ligue o microfone (tecla V). Só quem estiver perto escuta — como no Roblox. Use o botão de som para mutar/desmutar.</p>' +
      '<p style="margin:6px 0;font-size:14px;line-height:1.55;color:#aab6d6">Cada novo IP entra com uma bola vermelha no mapa fixo de chão verde. Seu personagem tem skin própria e aparece no mapa global para todos.</p>' +
      '<button id="amxHelpClose" style="margin-top:14px;width:100%;padding:12px;border:0;border-radius:12px;background:linear-gradient(90deg,#00e0ff,#7a5cff,#ff3d81);color:#fff;font-weight:800;letter-spacing:.5px;cursor:pointer">ENTENDI</button>' +
      '</div>';
    document.body.appendChild(box);
    var c = $('amxHelpClose');
    if (c) c.addEventListener('click', function () { box.parentNode.removeChild(box); });
  }

  /* ---------------------------------------------------------
     5) VOZ POR PROXIMIDADE (WebRTC + sinalização no servidor)
     --------------------------------------------------------- */
  var voice = {
    on: false, muted: false, stream: null, peers: {}, pollTimer: null, starting: false
  };
  window.AMX_VOICE = voice;

  var ICE = {
    iceServers: [
      { urls: 'stun:stun.l.google.com:19302' },
      { urls: 'stun:stun1.l.google.com:19302' }
    ]
  };

  function voiceUI() {
    var lbl = $('voiceLbl'), dot = $('voiceDot'), mic = $('voiceMic'), mute = $('voiceMute');
    if (lbl) lbl.textContent = voice.on ? (voice.muted ? 'VOZ MUDO' : 'VOZ ON') : 'VOZ OFF';
    if (dot) dot.style.background = voice.on ? (voice.muted ? '#ffb020' : '#25e08a') : '#6b7691';
    if (mic) mic.classList.toggle('on', voice.on);
    if (mute) mute.innerHTML = (voice.muted ? '🔇' : '🔊') + ' <span>Som</span>';
  }

  function startVoice() {
    if (voice.on || voice.starting) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      if (window.MP && window.MP.toast) window.MP.toast('Este navegador não suporta microfone.');
      return;
    }
    voice.starting = true;
    navigator.mediaDevices.getUserMedia({ audio: true, video: false }).then(function (st) {
      voice.stream = st;
      voice.on = true;
      voice.starting = false;
      voice.muted = false;
      voiceUI();
      startPolling();
      if (window.MP && window.MP.toast) window.MP.toast('Voz ligada — só quem está perto escuta.');
    }).catch(function () {
      voice.starting = false;
      if (window.MP && window.MP.toast) window.MP.toast('Microfone bloqueado pelo navegador.');
    });
  }

  function stopVoice() {
    voice.on = false;
    stopPolling();
    for (var id in voice.peers) closePeer(id);
    voice.peers = {};
    if (voice.stream) {
      try { voice.stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
      voice.stream = null;
    }
    voiceUI();
  }

  function toggleMute() {
    voice.muted = !voice.muted;
    if (voice.stream) {
      try { voice.stream.getAudioTracks().forEach(function (t) { t.enabled = !voice.muted; }); } catch (e) {}
    }
    voiceUI();
    if (window.MP && window.MP.toast) window.MP.toast(voice.muted ? 'Microfone mutado.' : 'Microfone ativo.');
  }

  function rtcSend(to, kind, data) {
    if (!window.MP || !window.MP.id || !to) return;
    fetch('?api=rtc_send', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ from: window.MP.id, to: to, kind: kind, data: data })
    }).catch(function () {});
  }

  function startPolling() {
    if (voice.pollTimer) return;
    voice.pollTimer = setInterval(pollSignals, 1200);
    pollSignals();
  }
  function stopPolling() {
    if (voice.pollTimer) { clearInterval(voice.pollTimer); voice.pollTimer = null; }
  }

  function pollSignals() {
    if (!voice.on || !window.MP || !window.MP.id) return;
    fetch('?api=rtc_poll&id=' + encodeURIComponent(window.MP.id), { method: 'GET' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok || !j.msgs) return;
        for (var i = 0; i < j.msgs.length; i++) handleSignal(j.msgs[i]);
      }).catch(function () {});
  }

  function handleSignal(m) {
    if (!m || !m.from) return;
    var id = m.from;
    if (m.kind === 'offer') {
      var pc = ensurePeer(id);
      pc.setRemoteDescription(new RTCSessionDescription(m.data)).then(function () {
        return pc.createAnswer();
      }).then(function (ans) {
        return pc.setLocalDescription(ans);
      }).then(function () {
        rtcSend(id, 'answer', pc.localDescription);
      }).catch(function () {});
    } else if (m.kind === 'answer') {
      var p2 = voice.peers[id];
      if (p2 && !p2._answered) {
        p2._answered = true;
        p2.setRemoteDescription(new RTCSessionDescription(m.data)).catch(function () {});
      }
    } else if (m.kind === 'ice') {
      var p3 = voice.peers[id];
      if (p3 && m.data) {
        try { p3.addIceCandidate(new RTCIceCandidate(m.data)).catch(function () {}); } catch (e) {}
      }
    } else if (m.kind === 'bye') {
      closePeer(id);
    }
  }

  function ensurePeer(id) {
    if (voice.peers[id]) return voice.peers[id];
    var pc = new RTCPeerConnection(ICE);
    pc._offered = false; pc._answered = false; pc._audio = null;
    voice.peers[id] = pc;

    if (voice.stream) {
      try { voice.stream.getTracks().forEach(function (t) { pc.addTrack(t, voice.stream); }); } catch (e) {}
    }

    pc.onicecandidate = function (e) {
      if (e.candidate) rtcSend(id, 'ice', e.candidate);
    };
    pc.ontrack = function (e) {
      var audio = document.createElement('audio');
      audio.autoplay = true;
      audio.srcObject = e.streams[0];
      audio.style.display = 'none';
      audio.volume = Math.max(0, Math.min(1, CFG.volume / 100));
      document.body.appendChild(audio);
      pc._audio = audio;
      try { audio.play().catch(function () {}); } catch (err) {}
    };
    pc.onconnectionstatechange = function () {
      if (pc.connectionState === 'failed' || pc.connectionState === 'closed') closePeer(id);
    };
    return pc;
  }

  function offerTo(id) {
    var pc = ensurePeer(id);
    if (pc._offered) return;
    pc._offered = true;
    pc.createOffer({ offerToReceiveAudio: true }).then(function (off) {
      return pc.setLocalDescription(off);
    }).then(function () {
      rtcSend(id, 'offer', pc.localDescription);
    }).catch(function () { pc._offered = false; });
  }

  function closePeer(id) {
    var pc = voice.peers[id];
    if (!pc) return;
    try { rtcSend(id, 'bye', null); } catch (e) {}
    try { if (pc._audio) { pc._audio.srcObject = null; pc._audio.parentNode.removeChild(pc._audio); } } catch (e) {}
    try { pc.close(); } catch (e) {}
    delete voice.peers[id];
  }

  /* ativa/desativa áudio conforme a distância (voz por proximidade) */
  function proximityTick() {
    if (!voice.on || !window.MP || !window.MP.remotes) return;
    var me = window.WORLD && window.WORLD.player && window.WORLD.player.node;
    if (!me) return;
    var range = CFG.voiceRange;
    var vistos = {};
    for (var id in window.MP.remotes) {
      var r = window.MP.remotes[id];
      if (!r || !r.node) continue;
      vistos[id] = true;
      var dx = r.node.position.x - me.position.x;
      var dz = r.node.position.z - me.position.z;
      var dist = Math.sqrt(dx * dx + dz * dz);
      var perto = dist <= range;
      var pc = voice.peers[id];
      if (perto) {
        if (!pc) { pc = ensurePeer(id); }
        /* quem tem id menor inicia a oferta, evitando ofertas duplicadas */
        if (String(window.MP.id) < String(id) && !pc._offered) offerTo(id);
      }
      if (pc && pc._audio) {
        pc._audio.muted = !perto || voice.muted;
        pc._audio.volume = Math.max(0.05, Math.min(1, (CFG.volume / 100) * (1 - dist / (range * 1.6))));
      }
      if (!perto && pc) {
        /* longe: mantém conexão, mas silencia */
        if (pc._audio) pc._audio.muted = true;
      }
    }
    for (var pid in voice.peers) {
      if (!vistos[pid]) closePeer(pid);
    }
  }
  setInterval(proximityTick, 900);

  on($('voiceMic'), 'click', function () { if (voice.on) stopVoice(); else startVoice(); });
  on($('voiceMute'), 'click', function () { if (!voice.on) startVoice(); else toggleMute(); });

  document.addEventListener('keydown', function (e) {
    var tag = (e.target && e.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (e.key === 'v' || e.key === 'V') { e.preventDefault(); if (voice.on) toggleMute(); else startVoice(); }
    if (e.key === 't' || e.key === 'T') { e.preventDefault(); toggleChat(true); }
    if (e.key === 'Escape') { toggleChat(false); openSettings(false); }
  });

  /* ---------------------------------------------------------
     6) BOOTSTRAP — aplica configurações salvas
     --------------------------------------------------------- */
  function applySaved() {
    if (window.WORLD && window.WORLD.setQuality) window.WORLD.setQuality(CFG.quality);
    if (window.AMX_SET) {
      window.AMX_SET.volume = CFG.volume / 100;
      window.AMX_SET.voiceRange = CFG.voiceRange;
      window.AMX_SET.sens = CFG.sens / 100;
    }
    if (window.AMX_NAMES) window.AMX_NAMES(CFG.names);
    syncSettingsUI();
    voiceUI();
  }

  /* espera o motor estar pronto (WORLD existe) */
  var tries = 0;
  var readyTimer = setInterval(function () {
    tries++;
    if (window.WORLD && window.AMX_START) {
      clearInterval(readyTimer);
      applySaved();
      if (pct > 92) finishBoot();
    } else if (tries > 100) {
      clearInterval(readyTimer);
    }
  }, 100);

  window.addEventListener('load', function () {
    setTimeout(function () { if (!bootDone) finishBoot(); }, 2600);
  });

  /* expõe helpers para o resto do jogo */
  window.AMX_UI.openSettings = openSettings;
  window.AMX_UI.toggleChat = toggleChat;
  window.AMX_UI.startVoice = startVoice;
  window.AMX_UI.stopVoice = stopVoice;
  window.AMX_UI.toggleMute = toggleMute;
})();
