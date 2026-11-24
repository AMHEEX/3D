/* AMHEEX GAME — jogador: controles, câmera, pulo, tiro, layout e avatar. */
  function updateHealth() {
    var bar = $('healthBar'), n = $('healthN'), box = $('health');
    if (bar) bar.style.width = Math.max(0, Math.min(100, player.health)) + '%';
    if (n) n.textContent = Math.round(player.health);
    if (box) box.classList.toggle('on', controlOn || player.mode === 'shooter');
  }

  function setPlayerNode(node, isChar) {
    player.node = node || ball;
    player.isCharacter = !!isChar;
    if (ball && ball !== player.node) ball.setEnabled(false);
    if (player.node && player.node !== ball) {
      player.node.setEnabled(true);
      try { player.node.unfreezeWorldMatrix(); } catch (e) {}
    }
    updateHealth();
  }

  function setControlMode(mode) {
    mode = mode || 'pad';
    if (mode === 'shooter') {
      player.mode = 'shooter'; controlOn = true;
      camera.beta = 1.45; camera.radius = 0.55; camera.lowerRadiusLimit = 0.3;
      if (player.isCharacter && player.node) player.node.setEnabled(false);
      var ch = $('crosshair'); if (ch) ch.classList.add('on');
      var ctl = $('controls'); if (ctl) ctl.classList.add('shooter');
    } else if (mode === 'third') {
      player.mode = 'third'; controlOn = true;
      camera.radius = 9; camera.beta = 1.22; camera.lowerRadiusLimit = 4;
      if (player.isCharacter && player.node) player.node.setEnabled(true);
      var ch2 = $('crosshair'); if (ch2) ch2.classList.remove('on');
      var ctl2 = $('controls'); if (ctl2) ctl2.classList.remove('shooter');
    } else if (mode === 'run') {
      player.run = true; controlOn = true;
    } else if (mode === 'jump') {
      doJump(); controlOn = true;
    } else {
      /* pad: controles na tela em terceira pessoa */
      player.mode = 'third'; controlOn = true;
      camera.radius = 9; camera.beta = 1.22; camera.lowerRadiusLimit = 4;
      if (player.isCharacter && player.node) player.node.setEnabled(true);
      var chp = $('crosshair'); if (chp) chp.classList.remove('on');
      var ctlp = $('controls'); if (ctlp) ctlp.classList.remove('shooter');
    }
    var c = $('controls'); if (c) c.classList.add('on');
    updateHealth();
    return { ok: true, mode: mode };
  }

  function doJump() {
    if (!player.grounded) return;
    player.vy = 6.2; player.grounded = false;
  }

  var lastShot = 0;
  function doFire() {
    if (player.mode !== 'shooter') return;
    var now = performance.now();
    if (now - lastShot < 180) return;
    lastShot = now;
    /* flash + raycast simples: acerta objetos e reduz "vida" deles */
    var ray = new BABYLON.Ray(camera.position.clone(), camera.getForwardRay().direction, 80);
    var pick = scene.pickWithRay(ray, function (m) { return m.isPickable && m.name !== 'ground'; });
    if (pick && pick.hit && pick.pickedMesh) {
      var mesh = pick.pickedMesh;
      var rec = WORLD.objects.find(function (o) { return o.node === mesh.parent || o.node === mesh; });
      if (rec) {
        rec.hits = (rec.hits || 0) + 1;
        if (rec.hits >= 3) { rec.node.getChildMeshes().forEach(function (m) { m.material = mat('#ef4444', { emissive: .7 }); }); }
      }
      try { mesh.material = mat('#f97316', { emissive: 1 }); } catch (e) {}
    }
    var ch = $('crosshair');
    if (ch) { ch.style.transform = 'scale(1.35)'; setTimeout(function () { ch.style.transform = ''; }, 90); }
  }

  /* botões virtuais */
  (function bindControls() {
    var c = $('controls');
    if (!c) return;
    c.querySelectorAll('[data-move]').forEach(function (btn) {
      var dir = btn.getAttribute('data-move');
      var on = function (e) { e.preventDefault(); moveInput[dir] = 1; btn.classList.add('active'); };
      var off = function (e) { e.preventDefault(); moveInput[dir] = 0; btn.classList.remove('active'); };
      btn.addEventListener('pointerdown', on);
      btn.addEventListener('pointerup', off);
      btn.addEventListener('pointercancel', off);
      btn.addEventListener('pointerleave', off);
    });
    c.querySelectorAll('[data-act]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var a = btn.getAttribute('data-act');
        if (a === 'jump') doJump();
        else if (a === 'run') { player.run = !player.run; btn.classList.toggle('active', player.run); }
        else if (a === 'fire') doFire();
      });
    });
    var cam = $('camToggle');
    if (cam) cam.addEventListener('click', function () {
      if (player.mode === 'shooter') setControlMode('third');
      else setControlMode('shooter');
    });
  })();

  scene.onPointerObservable.add(function (info) {
    if (info.type === BABYLON.PointerEventTypes.POINTERMOVE && settings.sens !== 1 && camera.inputs && camera.inputs.attached.pointers) {
      /* sensibilidade ajustada nos inputs da câmera */
    }
    if (info.type !== BABYLON.PointerEventTypes.POINTERTAP) return;
    var pick = scene.pick(scene.pointerX, scene.pointerY, function (m) { return m === ground; });
    if (pick && pick.hit && pick.pickedPoint) {
      ballTarget.x = BABYLON.Scalar.Clamp(pick.pickedPoint.x, -60, 60);
      ballTarget.z = BABYLON.Scalar.Clamp(pick.pickedPoint.z, -60, 60);
      hideHint();
    }
  });

  var hintEl = $('hint'), hintTimer = setTimeout(hideHint, 9000);
  function hideHint() { if (hintEl) { hintEl.classList.add('off'); clearTimeout(hintTimer); } }

  function updateScore() {
    var el = $('score'), n = $('scoreN');
    if (!el) return;
    if (WORLD.score > 0) { el.classList.add('on'); n.textContent = WORLD.score; }
    else el.classList.remove('on');
  }

  /* ---------------- layout / HUD criado pela IA ---------------- */
  var layoutEls = {}, layoutOrder = [];
  function setLayout(item, texto, cor, pos) {
    var hud = $('hud');
    if (!hud) return { ok: false, erro: 'hud ausente' };
    item = item || 'texto';
    var id = 'lay-' + item;
    var el = layoutEls[id];
    if (!el) {
      el = document.createElement('div');
      el.id = id;
      el.className = 'lay lay-' + item;
      hud.appendChild(el);
      layoutEls[id] = el;
      layoutOrder.push(id);
    }
    pos = pos || 'topo';
    el.classList.remove('pos-topo', 'pos-base', 'pos-esq', 'pos-dir', 'pos-centro');
    el.classList.add('pos-' + pos);
    el.style.color = cor || '';
    /* empilha elementos que dividem a mesma posição para não se sobreporem */
    var idx = layoutOrder.indexOf(id);
    el.style.top = ''; el.style.bottom = ''; el.style.left = ''; el.style.right = '';
    if (pos === 'topo') { el.style.top = (16 + idx * 42) + 'px'; }
    else if (pos === 'base') { el.style.bottom = (96 + idx * 42) + 'px'; }
    else if (pos === 'esq') { el.style.top = (16 + idx * 46) + 'px'; el.style.transform = 'none'; }
    else if (pos === 'dir') { el.style.top = (16 + idx * 46) + 'px'; el.style.transform = 'none'; }
    if (item === 'barra') {
      var pct = Math.max(0, Math.min(100, parseFloat(texto) || 100));
      el.innerHTML = '<i style="width:' + pct + '%"></i>';
    } else if (item === 'mira') {
      el.innerHTML = '<span></span>';
    } else if (item === 'contador') {
      el.innerHTML = '<b>' + (texto || '0') + '</b>';
    } else {
      el.textContent = texto || (item === 'icone' ? '✦' : '');
    }
    return { ok: true, item: item };
  }

  function clearLayout() {
    Object.keys(layoutEls).forEach(function (k) {
      if (layoutEls[k] && layoutEls[k].parentNode) layoutEls[k].parentNode.removeChild(layoutEls[k]);
    });
    layoutEls = {};
    layoutOrder = [];
    return { ok: true };
  }

  /* ---------------- avatar / personagem ---------------- */
  function createAvatar(a) {
    a = a || {};
    var ent = a.t === 'character' || a.pele || a.seed ? 'personagem' : 'boneco';
    var r = spawn(ent, {
      cor: a.cor || '#4f7cff', x: Number(a.x || 0), z: Number(a.z || 0),
      escala: Number(a.escala || 1), y: Number(a.y || 0),
      pele: a.pele || '#c68642', seed: Number(a.seed || 12345),
      calca: a.calca || '#1f2a44', cabelo: a.cabelo || ''
    });
    if (r.ok) {
      var rec = WORLD.objects[WORLD.objects.length - 1];
      rec.isAvatar = true;
      rec.nome = a.nome || 'Jogador';
      WORLD.avatar = rec;
      WORLD.avatarName = a.nome || 'Jogador';
      if (ent === 'personagem') {
        player.skin = {
          pele: a.pele || '#c68642', cor: a.cor || '#4f7cff', seed: Number(a.seed || 12345),
          calca: a.calca || '#1f2a44', cabelo: a.cabelo || ''
        };
        setPlayerNode(rec.node, true);
        ballTarget.x = rec.node.position.x; ballTarget.z = rec.node.position.z;
        setControlMode('third');
      }
      saveScene();
    }
    return r;
  }

  /* ---------------- modificar objeto existente ---------------- */
  function modifyEnt(ent, props) {
    props = props || {};
    var list = (ent === 'bola' && WORLD.ball) ? [{ node: WORLD.ball, base: { s: 1 }, cor: '#e63946' }] : findByEnt(ent);
    if (!list.length) return { ok: false, erro: 'nada de ' + ent + ' na cena' };
    list.forEach(function (o) {
      var n = o.node;
      n.unfreezeWorldMatrix();
      if (props.cor) o.cor = props.cor;
      if (props.cor || props.emissivo || props.alpha != null || props.metal) {
        var mm = mat(o.cor || '#3b82f6', {
          spec: props.metal ? 1 : 0.2,
          emissive: props.emissivo || 0,
          alpha: props.alpha == null ? 1 : props.alpha
        });
        n.getChildMeshes().forEach(function (m) { m.material = mm; });
      }
      if (props.escala != null) {
        if (o.base) o.base.s = props.escala;
        n.scaling.setAll(props.escala);
      }
      if (props.rotY != null) n.rotation.y += props.rotY * Math.PI / 180;
      if (props.y != null) n.position.y = props.y;
      if (props.dx != null) { n.position.x = props.dx; if (o.base) o.base.x = props.dx; }
      if (props.dz != null) { n.position.z = props.dz; if (o.base) o.base.z = props.dz; }
      n.computeWorldMatrix(true);
      n.freezeWorldMatrix();
    });
    saveScene();
    return { ok: true, n: list.length };
  }

  /* ---------------- ações vindas da IA ---------------- */
  function applyAction(a) {
    if (!a || !a.t) return { ok: false };
    switch (a.t) {
      case 'spawn': return spawn(a.ent, a);
      case 'layout': return setLayout(a.item, a.texto, a.cor, a.pos);
      case 'avatar': return createAvatar(a);
      case 'modify': return modifyEnt(a.ent, a.props);
      case 'remove': return removeEnt(a.ent);
      case 'clear': clearWorld(); setWeather('dia'); setTerrain('grama'); return { ok: true };
      case 'recolor': return recolor(a.ent, a.cor);
      case 'scale': return scaleEnt(a.ent, Number(a.f || 1.3));
      case 'move': return moveEnt(a.ent, Number(a.dx || 0), Number(a.dz || 0));
      case 'rotate': return rotateEnt(a.ent, Number(a.deg || 45));
      case 'terrain': return setTerrain(a.tipo);
      case 'weather': return setWeather(a.tipo);
      case 'camera': return cameraCmd(a.cmd);
      case 'physics': WORLD.physics = !!a.on; return { ok: true };
      case 'skin': return recolor(a.ent, a.cor);
      case 'world': return buildWorld(a.preset);
      case 'character': return createAvatar(Object.assign({}, a, { t: 'character' }));
      case 'control': return setControlMode(a.mode || 'third');
      case 'multiplayer': return (window.MP ? window.MP.enable(a.on !== false) : { ok: false, erro: 'multiplayer indisponivel' });
      default: return { ok: false, erro: 'ação desconhecida' };
    }
  }

  function cameraCmd(cmd) {
    if (cmd === 'topo') { camera.beta = 0.28; camera.radius = 22; }
    else if (cmd === 'aproximar') { camera.radius = Math.max(5, camera.radius * 0.7); }
    else if (cmd === 'afastar') { camera.radius = Math.min(44, camera.radius * 1.35); }
    else if (cmd === 'girar') { camera.alpha += Math.PI / 4; }
    else { camera.alpha = -Math.PI / 2.1; camera.beta = 1.02; camera.radius = 13; }
    return { ok: true };
  }

  /* ---------------- presets de mundo ---------------- */
