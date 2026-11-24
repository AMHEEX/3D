/* AMHEEX GAME — HUD: joystick à esquerda, pulo à direita, câmera, correr e ação. */
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
  var max = rect.width / 2 - 10;
  var d = Math.sqrt(dx * dx + dy * dy);
  if (d > max) { dx = dx / d * max; dy = dy / d * max; }
  if (knob) knob.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
  var nx = dx / max, ny = dy / max;
  setMove(ny < -0.22, ny > 0.22, nx < -0.22, nx > 0.22);
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

on($('rbJump'), 'pointerdown', function (e) {
  e.preventDefault();
  if (window.WORLD && window.WORLD.player) window.WORLD.player.jump = true;
});

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
  } else {
    toggleChat(true);
    var inp = $('rbChatInput'); if (inp) inp.focus();
  }
});

on($('rbMenu'), 'click', function () { openSettings(true, 'config'); });
on($('rbChatBtn'), 'click', function () { toggleChat(); });

/* teclado */
document.addEventListener('keydown', function (e) {
  var tag = (e.target && e.target.tagName) || '';
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
  if (e.key === 'v' || e.key === 'V') { e.preventDefault(); if (voice.on) toggleMute(); else startVoice(); }
  if (e.key === 't' || e.key === 'T') { e.preventDefault(); toggleChat(true); }
  if (e.key === 'Escape') { toggleChat(false); openSettings(false); }
});
