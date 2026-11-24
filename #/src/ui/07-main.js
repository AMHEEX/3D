/* AMHEEX GAME — inicialização da interface: espera o motor, aplica ajustes e abre o login. */
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
  if (window.WORLD && window.AMX_START) {
    clearInterval(readyTimer);
    applySaved();
    if (pct > 92) finishBoot();
  } else if (tries > 120) {
    clearInterval(readyTimer);
  }
}, 100);

window.addEventListener('load', function () { setTimeout(function () { if (!bootDone) finishBoot(); }, 2800); });

window.AMX_UI.openSettings = openSettings;
window.AMX_UI.toggleChat = toggleChat;
window.AMX_UI.startVoice = startVoice;
window.AMX_UI.stopVoice = stopVoice;
window.AMX_UI.toggleMute = toggleMute;
window.AMX_UI.sair = sair;
window.AMX_UI.enterWorld = enterWorld;
