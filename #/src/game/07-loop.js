/* AMHEEX GAME — laço: animação, câmera, multiplayer e inicialização. */
  function setNames(on) {
    settings.names = !!on;
    for (var id in MP.remotes) { var r = MP.remotes[id]; if (r.label) r.label.setEnabled(settings.names); }
    if (npc && npc.label) npc.label.setEnabled(settings.names);
    return { ok: true };
  }
  window.AMX_NAMES = setNames;

  /* ---------------- MULTIPLAYER (por IP, mapa compartilhado) ---------------- */
  var MP = {
    active: false, id: null, ip: '', name: 'Jogador', players: [], remotes: {},
    lastMove: 0, lastPoll: 0, lastChat: 0, worldRev: 0, panelOpen: false
  };
  window.MP = MP;

  MP.nome = function () {
    var n = '';
    try { n = localStorage.getItem('amx_nome') || ''; } catch (e) {}
    if (!n) {
      n = 'Jogador-' + Math.floor(1000 + Math.random() * 9000);
      try { localStorage.setItem('amx_nome', n); } catch (e) {}
    }
    return n;
  };

  MP.api = function (action, body, cb) {
    var opt = body ? {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    } : { method: 'GET' };
    var tk = (window.AMX_USER && window.AMX_USER.token) ? '&token=' + encodeURIComponent(window.AMX_USER.token) : '';
    fetch('?api=' + action + tk, opt).then(function (r) { return r.json(); })
      .then(function (j) { if (cb) cb(j); }).catch(function () {});
  };

  MP.enable = function (on) {
    MP.active = !!on;
    if (on) {
      MP.name = MP.nome();
      MP.join();
      MP.toast('Conectado ao mundo global.');
    } else {
      MP.api('mp_leave', { id: MP.id });
      MP.clearRemotes();
    }
    return { ok: true, multiplayer: MP.active };
  };

  MP.join = function () {
    var skin = player.skin || { pele: '#c68642', cor: '#4f7cff', calca: '#1f2a44', cabelo: '', seed: 12345 };
    MP.api('mp_join', {
      name: MP.name, x: player.node ? player.node.position.x : 0,
      z: player.node ? player.node.position.z : 0,
      ry: player.node ? player.node.rotation.y : 0,
      skin: skin,
      user: (window.AMX_USER && window.AMX_USER.usuario) || '',
      token: (window.AMX_USER && window.AMX_USER.token) || ''
    }, function (j) {
      if (!j || !j.ok) return;
      MP.id = j.id; MP.ip = j.ip || ''; MP.worldRev = j.world_revision || 0;
      MP.applyState(j);
      if (j.chat && j.chat.length) { MP.chat = j.chat; MP.renderChat(); }
      MP.syncWorld();
      MP.toast('Bem-vindo, ' + MP.name + '!');
    });
  };

  MP.tick = function (dt, node) {
    var now = performance.now();
    if (now - MP.lastMove > 130) {
      MP.lastMove = now;
      MP.api('mp_move', {
        id: MP.id, name: MP.name, x: +node.position.x.toFixed(2),
        z: +node.position.z.toFixed(2), ry: +node.rotation.y.toFixed(2),
        anim: (moveInput.f || moveInput.b || moveInput.l || moveInput.r || keys['w'] || keys['a'] || keys['s'] || keys['d']) ? 'walk' : 'idle'
      }, function (j) { if (j && j.ok) { MP.applyState(j); if (j.world_revision && j.world_revision !== MP.worldRev) { MP.worldRev = j.world_revision; MP.syncWorld(); } } });
    }
    if (now - MP.lastPoll > 1400) {
      MP.lastPoll = now;
      MP.api('mp_state', null, function (j) {
        if (!j || !j.ok) return;
        MP.applyState(j);
        if (j.chat) { MP.chat = j.chat; MP.renderChat(); }
        if (j.world_revision && j.world_revision !== MP.worldRev) { MP.worldRev = j.world_revision; MP.syncWorld(); }
      });
    }
    /* anima avatares remotos */
    for (var id in MP.remotes) {
      var r = MP.remotes[id];
      if (!r || !r.node || r.node.isDisposed) continue;
      var dx = r.node.position.x - (r.px || r.node.position.x);
      var dz = r.node.position.z - (r.pz || r.node.position.z);
      r.moving = (dx * dx + dz * dz) > 0.0004;
      r.px = r.node.position.x; r.pz = r.node.position.z;
      if (r.node.metadata && r.node.metadata.humanoid) {
        var t = performance.now() / 1000;
        var sw = r.moving ? Math.sin(t * 9) * 0.55 : 0;
        var parts = r.node.getChildMeshes();
        for (var i = 0; i < parts.length; i++) {
          var nm = parts[i].name;
          if (nm === 'pernaE') parts[i].rotation.x = sw;
          else if (nm === 'pernaD') parts[i].rotation.x = -sw;
          else if (nm === 'bracoE') parts[i].rotation.x = -sw * 0.8;
          else if (nm === 'bracoD') parts[i].rotation.x = sw * 0.8;
        }
      }
      if (r.label) r.label.position.y = 2.95;
    }
  };

  MP.applyState = function (j) {
    if (!j) return;
    var list = j.players || [];
    MP.players = list;
    var seen = {};
    for (var i = 0; i < list.length; i++) {
      var p = list[i];
      if (!p || p.id === MP.id) continue;
      seen[p.id] = true;
      if (!MP.remotes[p.id]) MP.addRemote(p);
      var r = MP.remotes[p.id];
      if (!r) continue;
      var tx = Number(p.x || 0), tz = Number(p.z || 0), ty = Number(p.ry || 0);
      r.node.position.x += (tx - r.node.position.x) * 0.35;
      r.node.position.z += (tz - r.node.position.z) * 0.35;
      r.node.rotation.y += (ty - r.node.rotation.y) * 0.35;
      if (r.label) {
        r.label.position.x = r.node.position.x;
        r.label.position.z = r.node.position.z;
      }
    }
    for (var id in MP.remotes) {
      if (!seen[id]) MP.removeRemote(id);
    }
    var cnt = $('mpCount');
    if (cnt) cnt.textContent = Math.max(0, list.length);
    var tc = $('rbOnline'); if (tc) tc.textContent = Math.max(0, list.length);
    MP.renderPlayers();
  };

  MP.addRemote = function (p) {
    try {
      var skin = p.skin || {};
      var node = B.personagem({
        pele: skin.pele || '#c68642', cor: skin.cor || '#4f7cff',
        calca: skin.calca || '#1f2a44', cabelo: skin.cabelo || '',
        seed: Number(skin.seed || 12345)
      });
      node.position.set(Number(p.x || 0), 0, Number(p.z || 0));
      node.rotation.y = Number(p.ry || 0);
      node.getChildMeshes().forEach(function (m) { m.isPickable = false; });
      /* etiqueta com nome */
      var dt = new BABYLON.DynamicTexture('lbl' + p.id, { width: 512, height: 128 }, scene, true);
      dt.hasAlpha = true;
      dt.drawText((p.name || 'Jogador'), 20, 84, 'bold 56px Arial', '#ffffff', 'rgba(0,0,0,0)', true);
      var lm = new BABYLON.StandardMaterial('lblm' + p.id, scene);
      lm.diffuseTexture = dt; lm.emissiveColor = new BABYLON.Color3(1, 1, 1);
      lm.opacityTexture = dt; lm.disableLighting = true; lm.backFaceCulling = false;
      var label = BABYLON.MeshBuilder.CreatePlane('lblp' + p.id, { width: 2.6, height: 0.65 }, scene);
      label.material = lm; label.billboardMode = BABYLON.Mesh.BILLBOARDMODE_ALL;
      label.position.set(node.position.x, 2.95, node.position.z);
      label.isPickable = false;
      MP.remotes[p.id] = { node: node, label: label, name: p.name, px: node.position.x, pz: node.position.z };
    } catch (e) {}
  };

  MP.removeRemote = function (id) {
    var r = MP.remotes[id];
    if (!r) return;
    try { if (r.node) r.node.dispose(); } catch (e) {}
    try { if (r.label) r.label.dispose(); } catch (e) {}
    delete MP.remotes[id];
  };

  MP.clearRemotes = function () {
    for (var id in MP.remotes) MP.removeRemote(id);
  };

  MP.renderPlayers = function () {
    var box = $('rbPeople');
    if (!box) return;
    var html = '';
    for (var i = 0; i < MP.players.length; i++) {
      var p = MP.players[i];
      var me = p.id === MP.id;
      html += '<div class="rbPerson"><i></i><b>' + (p.name || 'Jogador') + '</b>' +
        (me ? ' (você)' : '') + '<small>' + (p.usuario ? '@' + p.usuario : 'visitante') + '</small></div>';
    }
    box.innerHTML = html || '<div class="rbPerson"><i></i><b>Ninguém conectado ainda</b></div>';
    var sel = $('rbReportWho');
    if (sel) {
      var opt = '';
      for (var k = 0; k < MP.players.length; k++) {
        var q = MP.players[k];
        if (q.id === MP.id) continue;
        opt += '<option value="' + q.id + '">' + (q.name || 'Jogador') + '</option>';
      }
      sel.innerHTML = opt || '<option value="">Nenhum jogador</option>';
    }
  };

  /* o chat agora é um só: conversa com a AMHEEX AI. Mensagens de jogadores
     próximos entram no mesmo fluxo sem nunca apagar o histórico. */
  MP.renderChat = function () {
    var arr = MP.chat || [];
    if (MP._chatSeen == null) MP._chatSeen = arr.length; /* não repete histórico ao entrar */
    if (arr.length < MP._chatSeen) MP._chatSeen = 0;
    for (var i = MP._chatSeen; i < arr.length; i++) {
      var c = arr[i];
      var me = c.id && c.id === MP.id;
      if (window.AMX_CHAT && window.AMX_CHAT.append) {
        window.AMX_CHAT.append(me ? 'me' : 'ia', c.name || 'Jogador', c.msg || '');
      }
    }
    MP._chatSeen = arr.length;
  };

  MP.sendChat = function (msg) {
    if (!msg) return;
    MP.api('mp_chat', { id: MP.id, name: MP.name, msg: msg }, function (j) {
      if (j && j.ok) { MP.chat = j.chat || MP.chat; MP.renderChat(); }
    });
  };

  MP.syncWorld = function () {
    MP.api('mp_world', null, function (j) {
      if (!j || !j.ok) return;
      if (j.world_revision && j.world_revision !== MP.worldRev) MP.worldRev = j.world_revision;
      if (j.cena && Array.isArray(j.cena.objects) && j.cena.objects.length && !WORLD.objects.length) {
        restoreScene(j.cena);
        MP.toast('Mapa compartilhado carregado (' + j.cena.objects.length + ' objetos).');
      }
    });
  };

  MP.saveWorld = function () {
    var data = {
      preset: WORLD.preset, terrain: WORLD.terrain, weather: WORLD.weather,
      objects: WORLD.objects.filter(function (o) { return !o.isAvatar; }).map(function (o) {
        return { ent: o.ent, cor: o.cor, x: +o.node.position.x.toFixed(2), z: +o.node.position.z.toFixed(2), s: +o.base.s.toFixed(2), y: +o.node.position.y.toFixed(2), ry: +o.node.rotation.y.toFixed(2), nome: o.nome || o.ent, forma: o.forma || '' };
      })
    };
    MP.api('mp_world', { cena: data }, function (j) {
      if (j && j.ok) {
        MP.worldRev = j.world_revision || MP.worldRev;
        if (!MP._quiet) MP.toast('Mapa compartilhado salvo para todos.');
      }
    });
  };

  MP.toast = function (txt) {
    var el = $('mpToast');
    if (!el) { el = document.createElement('div'); el.id = 'mpToast'; document.body.appendChild(el); }
    el.textContent = txt; el.classList.add('on');
    clearTimeout(MP._tt); MP._tt = setTimeout(function () { el.classList.remove('on'); }, 3200);
  };

  /* UI do painel multiplayer */
  (function bindMP() {
    window.addEventListener('beforeunload', function () { if (MP.active) MP.api('mp_leave', { id: MP.id }); });
  })();

  /* ---------------- API pública ---------------- */
  window.WORLD = {
    scene: scene, engine: engine, camera: camera, ball: ball,
    state: WORLD, spawn: spawn, apply: applyAction, build: buildWorld,
    setTerrain: setTerrain, setWeather: setWeather, clear: clearWorld,
    setLayout: setLayout, clearLayout: clearLayout, avatar: createAvatar, modify: modifyEnt,
    multiplayer: MP, control: setControlMode, player: player, start: startGame, npc: spawnNPC, settings: settings,
    skin: applySkin, preview: previewSkin,
    save: saveScene, restore: restoreScene, setQuality: function (t) {
      if (!TIERS[t]) return;
      tier = t; T = TIERS[t];
      engine.setHardwareScalingLevel(T.scaling);
      var q = $('qual'); if (q) q.textContent = T.label;
    }
  };

  var qEl = $('qual');
  if (qEl) qEl.textContent = T.label;

  /* ---------------- estado inicial FIXO ----------------
     Nada é restaurado. O mundo começa SEMPRE assim:
       • chão verde
       • uma bola vermelha (você)
       • nada mais na tela além do ícone flutuante da IA
     Tudo o resto só aparece quando a IA cria (ex.: "crie game", "crie um prédio"). */
  try { localStorage.removeItem('amx3d_cena'); } catch (e) {}
  setTerrain('grama');
  setWeather('dia');
  if (WORLD.ball) { WORLD.ball.position.set(0, 0, 0); WORLD.ball.scaling.setAll(1); }
