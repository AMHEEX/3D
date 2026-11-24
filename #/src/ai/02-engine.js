/* AMHEEX AI — motor de conversa: envia para o PHP, aplica ações com limites e fala. */
var aiBusy = false;
var aiHistory = [];
var aiLastEnt = null;
var aiSentAt = 0;
var aiSentCount = 0;
var aiWindowAt = Date.now();
var AI_MAX_ACTS = 8;
var AI_MIN_GAP = 700;

function aiStore() { try { localStorage.setItem('amx3d_hist', JSON.stringify(aiHistory.slice(-40))); } catch (e) {} }

function aiActionLabel(a, res) {
  var t = a && a.t;
  var nomes = {
    spawn: 'criou ' + (a.nome || a.ent), avatar: 'ajustou seu personagem',
    character: 'ajustou seu personagem', modify: 'alterou ' + (a.ent || 'objeto'),
    remove: 'removeu ' + (a.ent || 'objeto'), recolor: 'pintou ' + (a.ent || 'objeto'),
    scale: 'redimensionou', move: 'moveu', rotate: 'girou', terrain: 'mudou o terreno',
    weather: 'mudou o clima', camera: 'mudou a câmera', control: 'mudou o controle',
    world: 'montou o mundo ' + (a.preset || ''), clear: 'limpou o mapa',
    layout: 'adicionou ' + (a.item || 'elemento'), physics: 'ligou a física',
    multiplayer: 'ligou o multiplayer'
  };
  return nomes[t] || ('ação ' + t);
}

/* o personagem só pode ser editado pelo próprio dono — nunca por outro jogador */
function aiApplyAction(a) {
  if (!a || !a.t) return { ok: false };
  var isAvatarAction = (a.t === 'avatar' || a.t === 'character');
  if (isAvatarAction) {
    var sk = {
      pele: a.pele || SKIN.pele, cor: a.cor || SKIN.cor,
      calca: a.calca || SKIN.calca, cabelo: a.cabelo || SKIN.cabelo,
      seed: Number(a.seed || SKIN.seed || 12345)
    };
    if (window.WORLD && window.WORLD.applySkin) window.WORLD.applySkin(sk);
    SKIN.pele = sk.pele; SKIN.cor = sk.cor; SKIN.calca = sk.calca; SKIN.cabelo = sk.cabelo; SKIN.seed = sk.seed;
    var dot = $('rbAvatarDot');
    if (dot) dot.style.background = 'linear-gradient(135deg,' + SKIN.pele + ',' + SKIN.cor + ')';
    if (USER && USER.token) {
      fetch('?api=salvar_perfil', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: USER.token, skin: SKIN })
      }).catch(function () {});
    }
    return { ok: true };
  }
  /* proteção: nunca mexer em avatar de outro jogador nem em objeto de outro dono */
  if (a.ent && /^avatar[:|]/i.test(String(a.ent))) return { ok: false, erro: 'personagem de outro jogador' };
  if ((a.t === 'modify' || a.t === 'remove' || a.t === 'recolor') && a.ent === 'avatar') return { ok: false, erro: 'protegido' };
  try { return window.WORLD ? window.WORLD.apply(a) : { ok: false }; } catch (e) { return { ok: false }; }
}

function aiSend(text) {
  var t = String(text || '').trim();
  if (!t || aiBusy) return;
  var now = Date.now();
  if (now - aiSentAt < AI_MIN_GAP) return;
  if (now - aiWindowAt > 60000) { aiWindowAt = now; aiSentCount = 0; }
  if (aiSentCount >= 30) { aiSay('ia', 'Calma aí! Muitos pedidos em pouco tempo. Espera alguns segundos.'); return; }
  aiSentAt = now; aiSentCount++;

  aiSay('me', t);
  aiHistory.push({ r: 'user', t: t }); aiStore();
  aiBusy = true;
  var pending = aiSay('ia', '');
  if (pending) {
    var sp = pending.querySelector('span');
    if (sp) sp.innerHTML = '<i class="dots">…</i>';
  }
  aiStatus('pensando…');

  var ctx = {
    eu: (USER && (USER.usuario || USER.nome)) || 'jogador',
    ultimo: aiLastEnt,
    objetos: (window.WORLD ? window.WORLD.state.objects.map(function (o) { return o.nome || o.ent; }) : [])
  };

  fetch('?api=chat', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ msg: t, ctx: ctx })
  }).then(function (r) { return r.json(); }).then(function (j) {
    if (!j || !j.ok) throw new Error((j && j.erro) || 'falha');
    var results = [];
    var acts = (j.actions || []).slice(0, AI_MAX_ACTS);
    acts.forEach(function (a) {
      if (a && a.t === 'spawn' && !a.preset && window.WORLD && WORLD.player && WORLD.player.node) {
        var pp = WORLD.player.node.position;
        var pry = WORLD.player.node.rotation.y || 0;
        var dd = 6.5 + Math.random() * 3.5;
        a.x = Math.round((pp.x + Math.sin(pry) * dd) * 100) / 100;
        a.z = Math.round((pp.z + Math.cos(pry) * dd) * 100) / 100;
      }
      var res = aiApplyAction(a);
      results.push({ label: aiActionLabel(a, res), ok: !res || res.ok !== false });
      if (a.t === 'spawn') aiLastEnt = a.nome || a.ent;
      if (a.t === 'modify') aiLastEnt = a.ent;
    });

    var sp2 = pending && pending.querySelector('span');
    if (sp2) sp2.innerHTML = String(j.reply || '').replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\n/g, '<br>');
    if (pending && results.length) {
      var box = document.createElement('div');
      box.className = 'acts';
      results.forEach(function (r) {
        var s = document.createElement('span');
        if (!r.ok) s.className = 'er';
        s.textContent = r.label;
        box.appendChild(s);
      });
      pending.appendChild(box);
    }
    if (window.AMX_CHAT) window.AMX_CHAT.append; /* mantém o contador */
    var cc = $('rbChatCount');
    var log = $('rbChatLog');
    if (cc && log) cc.textContent = log.children.length;

    aiHistory.push({ r: 'bot', t: j.reply }); aiStore();
    if (j.sugestoes && j.sugestoes.length) {
      var foot = $('rbChatFoot');
      if (foot) {
        foot.innerHTML = '';
        j.sugestoes.slice(0, 4).forEach(function (s) {
          var b = document.createElement('button');
          b.type = 'button'; b.textContent = s;
          b.addEventListener('click', function () { aiSend(s); });
          foot.appendChild(b);
        });
      }
    }
    speak(j.fala || j.reply);
    if (window.WORLD && window.WORLD.save) window.WORLD.save();
    aiStatus('online');
  }).catch(function (err) {
    var sp3 = pending && pending.querySelector('span');
    if (sp3) sp3.textContent = 'Não consegui falar com o motor local. Verifique se o PHP está rodando. (' + err.message + ')';
    aiStatus('offline');
  }).then(function () { aiBusy = false; });
}

/* ---------------- inicialização ---------------- */
(function aiInit() {
  var saved = null;
  try { saved = JSON.parse(localStorage.getItem('amx3d_hist') || 'null'); } catch (e) {}
  if (saved && saved.length) {
    aiHistory = saved.slice(-40);
    aiHistory.forEach(function (m) { aiSay(m.r === 'user' ? 'me' : 'ia', m.t); });
  } else {
    aiSay('ia', 'Oi! Eu sou a **AMHEEX AI**. O mundo é um só: **chão verde** e **céu azul**, e a comunidade constrói junto.\n\nEu edito o **mapa global** e o **seu personagem** — nunca o personagem de outro jogador. Peça coisas como “crie uma torre”, “faça uma casa”, “deixe minha camisa vermelha”. Fale pelo microfone ou escreva; no botão de onda eu converso com você em tempo real.');
  }
  fetch('?api=ping').then(function (r) { return r.json(); }).then(function (j) {
    if (j && j.ok) aiStatus(j.voz === 'fish-clone' ? 'voz AMHEEX' : 'online');
  }).catch(function () { aiStatus('offline'); });
})();

window.AMHEEX_IA3D = {
  version: '3.1.0',
  chat: function (text) { aiSend(text); },
  speak: speak,
  listen: startListen,
  live: toggleLive,
  open: function () { toggleChat(true); },
  close: function () { toggleChat(false); },
  setVoice: function (v) { voiceOn = !!v; lsSet('amx3d_voz', voiceOn ? 'on' : 'off'); },
  history: function () { return aiHistory.slice(); }
};
