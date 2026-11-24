/* AMHEEX GAME — chat embutido: conversa direta com a AMHEEX AI (sem abas). */
var rbChat = $('rbChat'), chatLog = $('rbChatLog'), chatInput = $('rbChatInput');

function toggleChat(force) {
  if (!rbChat) return;
  var open = force == null ? !rbChat.classList.contains('on') : !!force;
  rbChat.classList.toggle('on', open);
  if (open) {
    if (chatLog) chatLog.scrollTop = chatLog.scrollHeight;
    if (chatInput) setTimeout(function () { chatInput.focus(); }, 60);
  }
}

function chatAppend(kind, who, text, acts) {
  if (!chatLog) return null;
  var row = document.createElement('div');
  row.className = 'm ' + (kind || 'ia');
  var b = document.createElement('b');
  b.textContent = (who || 'AMHEEX AI') + ':';
  row.appendChild(b);
  var span = document.createElement('span');
  span.innerHTML = String(text || '').replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\n/g, '<br>');
  row.appendChild(span);
  if (acts && acts.length) {
    var box = document.createElement('div');
    box.className = 'acts';
    acts.forEach(function (a) {
      var s = document.createElement('span');
      if (a.ok === false) s.className = 'er';
      s.textContent = a.label || a;
      box.appendChild(s);
    });
    row.appendChild(box);
  }
  chatLog.appendChild(row);
  chatLog.scrollTop = chatLog.scrollHeight;
  var cc = $('rbChatCount');
  if (cc) cc.textContent = chatLog.children.length;
  return row;
}

function chatClear() {
  if (chatLog) chatLog.innerHTML = '';
  var cc = $('rbChatCount'); if (cc) cc.textContent = '0';
}

function chatSend(text) {
  var t = (text || '').trim();
  if (!t) return;
  if (chatInput) chatInput.value = '';
  if (window.AMHEEX_IA3D && window.AMHEEX_IA3D.chat) window.AMHEEX_IA3D.chat(t);
}

on($('rbChatForm'), 'submit', function (e) {
  e.preventDefault();
  chatSend(chatInput ? chatInput.value : '');
});
on(chatInput, 'keydown', function (e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); chatSend(chatInput.value); }
});
on($('rbMic'), 'click', function () {
  if (window.AMHEEX_IA3D && window.AMHEEX_IA3D.listen) window.AMHEEX_IA3D.listen();
});
on($('rbLive'), 'click', function () {
  if (window.AMHEEX_IA3D && window.AMHEEX_IA3D.live) window.AMHEEX_IA3D.live();
});

var sugBtns = document.querySelectorAll('#rbChatFoot button');
for (var sb = 0; sb < sugBtns.length; sb++) {
  (function (b) {
    b.addEventListener('click', function () {
      var s = b.getAttribute('data-sug');
      if (s) chatSend(s);
    });
  })(sugBtns[sb]);
}

/* mensagens de jogadores próximos aparecem no mesmo fluxo, marcadas */
window.AMX_CHAT = {
  append: chatAppend,
  clear: chatClear,
  send: chatSend,
  player: function (name, msg) { chatAppend('me', name || 'Jogador', esc(msg || '')); },
  status: function (txt) { var s = $('rbChatStatus'); if (s) s.textContent = txt; }
};
