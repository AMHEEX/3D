/* AMHEEX GAME — atores: NPC seguidor, skins e início de jogo. */
  function spawnNPC(nome) {
    if (npc && npc.node && !npc.node.isDisposed) { try { npc.node.dispose(); } catch (e) {} }
    var pele = ['#f1c27d', '#c68642', '#8d5524', '#e0ac69'][Math.floor(Math.random() * 4)];
    var cor = ['#ef4444', '#22c55e', '#a855f7', '#f59e0b'][Math.floor(Math.random() * 4)];
    var node = B.personagem({ pele: pele, cor: cor, seed: Math.floor(Math.random() * 999999) });
    var base = player.node ? player.node.position : BABYLON.Vector3.Zero();
    node.position.set(base.x + 2.4, 0, base.z - 2.4);
    node.getChildMeshes().forEach(function (m) { m.isPickable = false; registerShadows(m); });
    var dt = new BABYLON.DynamicTexture('npclbl', { width: 512, height: 128 }, scene, true);
    dt.hasAlpha = true;
    dt.drawText(nome || 'AMHEEX BOT', 20, 84, 'bold 54px Arial', '#a5f3fc', 'rgba(0,0,0,0)', true);
    var lm = new BABYLON.StandardMaterial('npclblm', scene);
    lm.diffuseTexture = dt; lm.emissiveColor = new BABYLON.Color3(1, 1, 1);
    lm.opacityTexture = dt; lm.disableLighting = true; lm.backFaceCulling = false;
    var label = BABYLON.MeshBuilder.CreatePlane('npclblp', { width: 2.4, height: 0.6 }, scene);
    label.material = lm; label.billboardMode = BABYLON.Mesh.BILLBOARDMODE_ALL;
    label.position.set(node.position.x, 3.15, node.position.z);
    label.isPickable = false;
    label.setEnabled(settings.names);
    npc = { node: node, label: label, nome: nome || 'AMHEEX BOT', vy: 0, y: 0, grounded: true, nextJump: 0 };
    WORLD.npc = npc;
    return { ok: true, npc: npc.nome };
  }
  window.spawnNPC = spawnNPC;

  function tickNPC(dt) {
    if (!npc || !npc.node || npc.node.isDisposed) return;
    var alvo = player.node || ball;
    var dx = alvo.position.x - npc.node.position.x;
    var dz = alvo.position.z - npc.node.position.z;
    var dist = Math.sqrt(dx * dx + dz * dz);
    var andando = false;
    if (dist > 2.6) {
      var vel = (dist > 8 ? 6.4 : 4.2) * dt;
      npc.node.position.x += (dx / dist) * vel;
      npc.node.position.z += (dz / dist) * vel;
      npc.node.rotation.y += (Math.atan2(dx, dz) - npc.node.rotation.y) * Math.min(1, dt * 8);
      andando = true;
    }
    /* pulo ocasional para acompanhar */
    var now = performance.now();
    if (andando && now > npc.nextJump && dist > 5) {
      npc.vy = 5.4; npc.grounded = false; npc.nextJump = now + 3200;
    }
    if (!npc.grounded) {
      npc.vy -= 18 * dt; npc.y += npc.vy * dt;
      if (npc.y <= 0) { npc.y = 0; npc.vy = 0; npc.grounded = true; }
    }
    npc.node.position.y = npc.y;
    var t = performance.now() / 1000;
    var sw = andando ? Math.sin(t * 9) * .55 : 0;
    var parts = npc.node.getChildMeshes();
    for (var i = 0; i < parts.length; i++) {
      var nm = parts[i].name;
      if (nm === 'coxaE' || nm === 'pernaE') parts[i].rotation.x = sw;
      else if (nm === 'coxaD' || nm === 'pernaD') parts[i].rotation.x = -sw;
      else if (nm === 'bracoE' || nm === 'antebracoE') parts[i].rotation.x = -sw * .75;
      else if (nm === 'bracoD' || nm === 'antebracoD') parts[i].rotation.x = sw * .75;
    }
    if (npc.label) {
      npc.label.position.x = npc.node.position.x;
      npc.label.position.z = npc.node.position.z;
      npc.label.position.y = 3.15 + npc.y;
    }
  }

  /* ---------------- skin do jogador ---------------- */
  function applySkin(skin) {
    skin = skin || {};
    var pos = player.node ? { x: player.node.position.x, z: player.node.position.z, ry: player.node.rotation.y } : { x: 0, z: 0, ry: 0 };
    var atual = player.skin || {};
    var novo = {
      pele: skin.pele || atual.pele || '#c68642',
      cor: skin.cor || atual.cor || '#4f7cff',
      calca: skin.calca || atual.calca || '#1f2a44',
      cabelo: skin.cabelo != null ? skin.cabelo : (atual.cabelo || ''),
      seed: Number(skin.seed || atual.seed || 12345)
    };
    if (player.node && player.node.dispose) {
      try { player.node.dispose(); } catch (e) {}
    }
    player.isCharacter = false;
    var r = createAvatar({
      t: 'character', nome: (window.AMX_USER && window.AMX_USER.nome) || MP.name || 'Jogador',
      pele: novo.pele, cor: novo.cor, calca: novo.calca, cabelo: novo.cabelo, seed: novo.seed,
      x: pos.x, z: pos.z
    });
    if (r && r.ok && player.node) {
      player.node.rotation.y = pos.ry;
      player.node.unfreezeWorldMatrix();
    }
    player.skin = novo;
    if (window.MP && MP.active && MP.id) {
      MP.api('mp_move', { id: MP.id, name: MP.name, x: pos.x, z: pos.z, ry: pos.ry, skin: novo });
    }
    return { ok: true, skin: novo };
  }
  window.AMX_SKIN = applySkin;

  function previewSkin(skin) {
    var r = applySkin(skin);
    setControlMode('third');
    if (npc && npc.node) npc.node.setEnabled(false);
    if (camera) { camera.beta = 1.22; camera.radius = 6.6; camera.alpha = Math.PI / 2 + 0.32; }
    return r;
  }
  window.AMX_PREVIEW = previewSkin;

  /* ---------------- início do jogo ---------------- */
  function startGame(nome) {
    var n = (nome || '').trim() || MP.nome();
    try { localStorage.setItem('amx_nome', n); } catch (e) {}
    MP.name = n;
    /* personagem padrão da conta: pele, camisa, calça e cabelo salvos no perfil */
    var sk = (window.AMX_UI && window.AMX_UI.skin) || player.skin || {};
    player.skin = {
      pele: sk.pele || '#c68642', cor: sk.cor || '#4f7cff',
      calca: sk.calca || '#1f2a44', cabelo: sk.cabelo || '', seed: Number(sk.seed || 12345)
    };
    if (!player.isCharacter) {
      createAvatar({
        t: 'character', nome: n, pele: player.skin.pele, cor: player.skin.cor,
        calca: player.skin.calca, cabelo: player.skin.cabelo, seed: player.skin.seed, x: 0, z: 0
      });
    }
    setControlMode('third');
    if (!npc) spawnNPC('AMHEEX BOT');
    if (npc && npc.node) npc.node.setEnabled(true);
    MP._quiet = true;
    setTimeout(function () { MP._quiet = false; }, 2600);
    MP.enable(true);
    var hud = $('rblx'); if (hud) hud.classList.add('on');
    var u = $('rbUser'); if (u) u.textContent = n;
    return { ok: true, nome: n };
  }
  window.AMX_START = startGame;
