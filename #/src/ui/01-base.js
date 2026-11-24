/* AMHEEX GAME — interface: base compartilhada, estado local e carregamento (boot). */
function $(id) { return document.getElementById(id); }
function on(el, ev, fn) { if (el) el.addEventListener(ev, fn); }
function lsGet(k, d) { try { var v = localStorage.getItem(k); return v == null ? d : v; } catch (e) { return d; } }
function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
function lsDel(k) { try { localStorage.removeItem(k); } catch (e) {} }
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

/* helpers e estado compartilhados com o IIFE da IA e do motor 3D */
window.$ = $; window.on = on; window.esc = esc;
window.lsGet = lsGet; window.lsSet = lsSet; window.lsDel = lsDel;

/* ---------------- estado ---------------- */
var USER = null;
var SKIN = { pele: '#c68642', cor: '#4f7cff', calca: '#1f2a44', cabelo: '#1b1b1b', seed: 12345 };
var CFG = {
  volume: parseInt(lsGet('amx_vol', '80'), 10),
  quality: lsGet('amx_quality', 'mid'),
  sens: parseInt(lsGet('amx_sens', '100'), 10),
  voiceRange: parseInt(lsGet('amx_vrange', '16'), 10),
  names: lsGet('amx_names', 'on') === 'on',
  mic: lsGet('amx_mic', 'off') === 'on'
};
window.AMX_UI = { cfg: CFG, skin: SKIN, user: function () { return USER; } };
/* acesso ao vivo (o IIFE da IA lê USER/SKIN como globais) */
Object.defineProperty(window, 'USER', { configurable: true, get: function () { return USER; }, set: function (v) { USER = v; } });
Object.defineProperty(window, 'SKIN', { configurable: true, get: function () { return SKIN; }, set: function (v) { SKIN = v; } });
Object.defineProperty(window, 'CFG', { configurable: true, get: function () { return CFG; }, set: function (v) { CFG = v; } });

/* ---------------- boot ---------------- */
var boot = $('boot'), bootFill = $('bootFill'), bootPct = $('bootPct'), bootDone = false;
var pct = 0;
var TIPS = [
  'Carregando o mundo global…',
  'Preparando a AMHEEX AI…',
  'Ajustando a câmera e os controles…',
  'Quase lá — abrindo o login…'
];
function paintBoot(p) {
  pct = Math.max(pct, Math.min(100, p));
  if (bootFill) bootFill.style.width = pct + '%';
  if (bootPct) bootPct.textContent = Math.round(pct) + '%';
  var tip = boot && boot.querySelector('.bootTip, #bootTip');
  if (tip && TIPS.length) tip.textContent = TIPS[Math.min(TIPS.length - 1, Math.floor(pct / 26))];
}
var bootTimer = setInterval(function () { paintBoot(pct + 2 + Math.random() * 7); }, 160);

function finishBoot() {
  if (bootDone) return;
  bootDone = true;
  clearInterval(bootTimer);
  paintBoot(100);
  setTimeout(function () { if (boot) boot.classList.add('off'); openLogin(); }, 420);
}
