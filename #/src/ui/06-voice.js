/* AMHEEX GAME — voz por proximidade (WebRTC) e mudo. */
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
    CFG.mic = true; lsSet('amx_mic', 'on');
    startPolling(); syncSettingsUI();
    if (window.MP) window.MP.toast('Voz ligada — só quem está perto escuta.');
  }).catch(function () { voice.starting = false; if (window.MP) window.MP.toast('Microfone bloqueado pelo navegador.'); });
}
function stopVoice() {
  voice.on = false; stopPolling();
  for (var id in voice.peers) closePeer(id);
  voice.peers = {};
  if (voice.stream) { try { voice.stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {} voice.stream = null; }
  CFG.mic = false; lsSet('amx_mic', 'off');
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
