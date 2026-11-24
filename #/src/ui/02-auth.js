/* AMHEEX GAME — contas: tela de entrar, tela de criar conta e sessão. */
var loginEl = $('login'), regEl = $('register');
var loginMsg = $('loginMsg'), regMsg = $('registerMsg');

function showLogin() {
  if (regEl) regEl.classList.remove('on');
  if (loginEl) loginEl.classList.add('on');
  setTimeout(function () { var u = $('loginUser'); if (u) u.focus(); }, 120);
}
function showRegister() {
  if (loginEl) loginEl.classList.remove('on');
  if (regEl) regEl.classList.add('on');
  setTimeout(function () { var u = $('regUser'); if (u) u.focus(); }, 120);
}
function openLogin() {
  if (USER && USER.token) { enterWorld(USER); return; }
  var tok = lsGet('amx_token', '');
  if (!tok) { showLogin(); return; }
  fetch('?api=perfil&token=' + encodeURIComponent(tok)).then(function (r) { return r.json(); })
    .then(function (j) {
      if (j && j.ok && j.user) { USER = j.user; window.AMX_USER = USER; enterWorld(USER); }
      else { lsDel('amx_token'); showLogin(); }
    })
    .catch(function () { showLogin(); });
}

function loginMsgSet(txt, ok) { if (loginMsg) { loginMsg.textContent = txt; loginMsg.className = ok ? 'ok' : ''; } }
function regMsgSet(txt, ok) { if (regMsg) { regMsg.textContent = txt; regMsg.className = ok ? 'ok' : ''; } }

function applyUserSkin(u) {
  if (u && u.skin) {
    SKIN.pele = u.skin.pele || SKIN.pele;
    SKIN.cor = u.skin.cor || SKIN.cor;
    SKIN.calca = u.skin.calca || SKIN.calca;
    SKIN.cabelo = u.skin.cabelo || SKIN.cabelo;
    SKIN.seed = u.skin.seed || SKIN.seed;
  }
}

/* entra no mundo: personagem padrão já pronto, sem editor na tela inicial */
function enterWorld(u) {
  USER = u; window.AMX_USER = USER;
  applyUserSkin(u);
  if (loginEl) loginEl.classList.remove('on');
  if (regEl) regEl.classList.remove('on');
  var hud = $('rblx'); if (hud) hud.classList.add('on');
  var who = $('rbUser'); if (who) who.textContent = (u && (u.nome || u.usuario)) || 'Jogador';
  var dot = $('rbAvatarDot');
  if (dot) dot.style.background = 'linear-gradient(135deg,' + SKIN.pele + ',' + SKIN.cor + ')';
  if (window.AMX_START) window.AMX_START((u && (u.nome || u.usuario)) || 'Jogador');
  setTimeout(function () { if (CFG.mic) startVoice(); }, 900);
}

function doLogin() {
  var u = $('loginUser'), p = $('loginPass');
  var usuario = u ? u.value.trim() : '', senha = p ? p.value : '';
  if (!usuario || !senha) { loginMsgSet('Preencha usuário e senha.', false); return; }
  loginMsgSet('Entrando…', true);
  fetch('?api=login', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ usuario: usuario, senha: senha })
  }).then(function (r) { return r.json(); }).then(function (j) {
    if (!j || !j.ok) { loginMsgSet((j && j.erro) || 'Não foi possível entrar.', false); return; }
    lsSet('amx_token', j.user.token);
    loginMsgSet('Bem-vindo de volta!', true);
    setTimeout(function () { enterWorld(j.user); }, 300);
  }).catch(function () { loginMsgSet('Servidor não respondeu. Tente de novo.', false); });
}

function doRegister() {
  var u = $('regUser'), p = $('regPass'), n = $('regNome');
  var usuario = u ? u.value.trim() : '', senha = p ? p.value : '', nome = n ? n.value.trim() : '';
  if (usuario.length < 3) { regMsgSet('Usuário precisa de 3 caracteres ou mais.', false); return; }
  if (senha.length < 4) { regMsgSet('Senha precisa de 4 caracteres ou mais.', false); return; }
  regMsgSet('Criando sua conta…', true);
  fetch('?api=registrar', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ usuario: usuario, senha: senha, nome: nome || usuario })
  }).then(function (r) { return r.json(); }).then(function (j) {
    if (!j || !j.ok) { regMsgSet((j && j.erro) || 'Não foi possível criar a conta.', false); return; }
    lsSet('amx_token', j.user.token);
    regMsgSet('Conta criada! Entrando no mundo…', true);
    setTimeout(function () { enterWorld(j.user); }, 350);
  }).catch(function () { regMsgSet('Servidor não respondeu. Tente de novo.', false); });
}

function sair() {
  if (USER && USER.token) {
    fetch('?api=sair', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token: USER.token }) }).catch(function () {});
  }
  lsDel('amx_token');
  USER = null; window.AMX_USER = null;
  openSettings(false);
  var hud = $('rblx'); if (hud) hud.classList.remove('on');
  if (window.MP && window.MP.enable) window.MP.enable(false);
  if (window.AMX_UI && window.AMX_UI.stopVoice) window.AMX_UI.stopVoice();
  showLogin();
}

on($('loginGo'), 'click', doLogin);
on($('registerGo'), 'click', doRegister);
on($('goRegister'), 'click', showRegister);
on($('goLogin'), 'click', showLogin);
on($('loginPass'), 'keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doLogin(); } });
on($('loginUser'), 'keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); var p = $('loginPass'); if (p) p.focus(); } });
on($('regPass'), 'keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doRegister(); } });
