/* AMHEEX AI — voz: Fish Audio (servidor) com fallback do navegador e escuta contínua. */
var voiceOn = lsGet('amx3d_voz', 'on') !== 'off';
var audioEl = null;
var ttsCache = {};
var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
var rec = null, listening = false, live = false, liveWanted = false;

function aiStatus(txt) { if (window.AMX_CHAT) window.AMX_CHAT.status(txt); }
function aiSay(role, text, acts) { return window.AMX_CHAT ? window.AMX_CHAT.append(role, role === 'me' ? (USER && USER.nome) || 'Você' : 'AMHEEX AI', text, acts) : null; }

function stopSpeak() {
  if (audioEl) { try { audioEl.pause(); } catch (e) {} audioEl = null; }
  try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (e) {}
}

function browserSpeak(text) {
  if (!text || !window.speechSynthesis) return;
  try {
    var u = new SpeechSynthesisUtterance(text);
    u.lang = 'pt-BR'; u.rate = 1.02; u.pitch = 1;
    window.speechSynthesis.speak(u);
  } catch (e) {}
}

function playAudio(url) {
  stopSpeak();
  audioEl = new Audio(url);
  audioEl.volume = Math.max(0, Math.min(1, (window.AMX_SET ? window.AMX_SET.volume : 0.8)));
  audioEl.play().catch(function () {});
}

function speak(text) {
  if (!voiceOn) return;
  var clean = String(text || '').replace(/\*\*/g, '').replace(/[#`>]/g, '').slice(0, 300).trim();
  if (!clean) return;
  if (ttsCache[clean]) { playAudio(ttsCache[clean]); return; }
  fetch('?api=tts&text=' + encodeURIComponent(clean))
    .then(function (r) {
      var ct = r.headers.get('Content-Type') || '';
      if (ct.indexOf('audio') === 0) return r.blob();
      return r.json().then(function (j) { throw new Error((j && j.aviso) || 'sem audio'); });
    })
    .then(function (blob) {
      var url = URL.createObjectURL(blob);
      ttsCache[clean] = url;
      playAudio(url);
    })
    .catch(function () { browserSpeak(clean); });
}

/* ---------------- escuta (voz → texto) ---------------- */
function buildRec() {
  if (!SR) return null;
  var r = new SR();
  r.lang = 'pt-BR';
  r.continuous = false;
  r.interimResults = true;
  r.onstart = function () { listening = true; var b = $('rbMic'); if (b) b.classList.add('on'); aiStatus('ouvindo…'); };
  r.onend = function () {
    listening = false;
    var b = $('rbMic'); if (b) b.classList.remove('on');
    aiStatus('online');
    if (liveWanted) setTimeout(function () { try { r.start(); } catch (e) {} }, 350);
  };
  r.onerror = function (ev) {
    listening = false;
    var b = $('rbMic'); if (b) b.classList.remove('on');
    if (ev && ev.error === 'not-allowed') aiStatus('microfone bloqueado');
    else aiStatus('online');
  };
  r.onresult = function (ev) {
    var finalTxt = '', interim = '';
    for (var i = ev.resultIndex; i < ev.results.length; i++) {
      var t = ev.results[i][0].transcript;
      if (ev.results[i].isFinal) finalTxt += t; else interim += t;
    }
    var inp = $('rbChatInput');
    if (interim && inp) inp.value = interim;
    if (finalTxt) {
      if (inp) inp.value = '';
      aiSend(finalTxt.trim());
    }
  };
  return r;
}

function startListen() {
  if (!SR) { aiSay('ia', 'Este navegador não tem reconhecimento de voz. Escreva no chat que eu respondo igual.'); return; }
  if (listening) { liveWanted = false; live = false; try { rec.stop(); } catch (e) {} return; }
  if (!rec) rec = buildRec();
  if (!rec) return;
  liveWanted = false;
  try { rec.start(); } catch (e) {}
}

/* conversa em tempo real: escuta contínua + resposta falada */
function toggleLive() {
  var b = $('rbLive');
  if (live) {
    live = false; liveWanted = false;
    if (b) b.classList.remove('on');
    try { if (rec) rec.stop(); } catch (e) {}
    stopSpeak();
    aiStatus('online');
    aiSay('ia', 'Modo conversa encerrado. Pode escrever quando quiser.');
    return;
  }
  if (!SR) { aiSay('ia', 'Conversa por voz precisa do Chrome/Edge com microfone. Aqui você pode escrever — eu respondo e falo.'); return; }
  live = true; liveWanted = true;
  if (b) b.classList.add('on');
  if (!rec) rec = buildRec();
  aiSay('ia', '**Modo conversa ao vivo.** Fale normalmente: eu escuto, respondo e falo em tempo real. Toque no botão de onda para encerrar.');
  try { rec.start(); } catch (e) {}
}
