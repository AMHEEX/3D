/* AMHEEX GAME — configurações no estilo Roblox (5 abas + rodapé). */
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
  var tabs = document.querySelectorAll('#rbSetTabs button');
  for (var i = 0; i < tabs.length; i++) tabs[i].classList.toggle('on', tabs[i].getAttribute('data-t') === t);
  var bodies = document.querySelectorAll('#rbSetCard .rbSetBody');
  for (var b = 0; b < bodies.length; b++) bodies[b].classList.toggle('on', bodies[b].getAttribute('data-body') === t);
}
var setTabs = document.querySelectorAll('#rbSetTabs button');
for (var st = 0; st < setTabs.length; st++) {
  (function (b) { b.addEventListener('click', function () { selectSetTab(b.getAttribute('data-t')); }); })(setTabs[st]);
}
on($('rbCont'), 'click', function () { openSettings(false); });
on(setEl, 'click', function (e) { if (e.target === setEl) openSettings(false); });

/* volume em 10 segmentos */
var volBox = $('rbVolSegs');
function buildSegs() {
  if (!volBox || volBox._built) return;
  volBox._built = true;
  for (var i = 1; i <= 10; i++) {
    (function (n) {
      var seg = document.createElement('i');
      seg.addEventListener('click', function () {
        CFG.volume = n * 10; lsSet('amx_vol', CFG.volume); paintSegs(); applyVolume();
      });
      volBox.appendChild(seg);
    })(i);
  }
  paintSegs();
}
function paintSegs() {
  if (!volBox) return;
  var segs = volBox.querySelectorAll('i');
  var lit = Math.round(CFG.volume / 10);
  for (var i = 0; i < segs.length; i++) segs[i].classList.toggle('on', i < lit);
}
function applyVolume() {
  if (window.AMX_SET) window.AMX_SET.volume = CFG.volume / 100;
  var auds = document.querySelectorAll('audio');
  for (var i = 0; i < auds.length; i++) auds[i].volume = Math.max(0, Math.min(1, CFG.volume / 100));
}
buildSegs();

function syncSettingsUI() {
  paintSegs();
  var q = $('rbQuality'); if (q) q.value = CFG.quality;
  var s = $('rbSens'); if (s) s.value = CFG.sens;
  var sv = $('rbSensV'); if (sv) sv.textContent = CFG.sens;
  var vr = $('rbVoiceRange'); if (vr) vr.value = CFG.voiceRange;
  var vrv = $('rbVoiceRangeV'); if (vrv) vrv.textContent = CFG.voiceRange + 'm';
  var nm = $('rbNames'); if (nm) nm.value = CFG.names ? 'on' : 'off';
  var mic = $('rbMicSet');
  if (mic) {
    var onv = window.AMX_VOICE && window.AMX_VOICE.on;
    mic.textContent = onv ? (window.AMX_VOICE.muted ? 'MUDO (V)' : 'LIGADO (V)') : 'LIGAR (V)';
  }
}

on($('rbQuality'), 'change', function () {
  CFG.quality = this.value; lsSet('amx_quality', CFG.quality);
  if (window.WORLD && window.WORLD.setQuality) window.WORLD.setQuality(CFG.quality);
});
on($('rbVoiceRange'), 'input', function () {
  CFG.voiceRange = parseInt(this.value, 10) || 16; lsSet('amx_vrange', CFG.voiceRange);
  var v = $('rbVoiceRangeV'); if (v) v.textContent = CFG.voiceRange + 'm';
  if (window.AMX_SET) window.AMX_SET.voiceRange = CFG.voiceRange;
});
on($('rbSens'), 'input', function () {
  CFG.sens = parseInt(this.value, 10) || 100; lsSet('amx_sens', CFG.sens);
  var v = $('rbSensV'); if (v) v.textContent = CFG.sens;
  if (window.AMX_SET) window.AMX_SET.sens = CFG.sens / 100;
});
on($('rbNames'), 'change', function () {
  CFG.names = this.value === 'on'; lsSet('amx_names', CFG.names ? 'on' : 'off');
  if (window.AMX_NAMES) window.AMX_NAMES(CFG.names);
});
on($('rbMicSet'), 'click', function () { if (voice.on) toggleMute(); else startVoice(); syncSettingsUI(); });

function renderGallery() {
  var box = $('rbGallery'); if (!box) return;
  var objs = (window.WORLD && window.WORLD.state && window.WORLD.state.objects) || [];
  var html = '';
  for (var i = 0; i < objs.length; i++) {
    var o = objs[i];
    if (o.isAvatar) continue;
    html += '<div class="rbCard"><b>' + esc(o.nome || o.ent) + '</b>' + esc(o.forma || o.ent) + '</div>';
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

/* "Novo visual": o personagem é editado pela IA, nunca por botões soltos */
on($('rbRegen'), 'click', function () {
  openSettings(false);
  toggleChat(true);
  chatSend('crie um personagem realista novo para mim');
});

on($('rbSair'), 'click', sair);
