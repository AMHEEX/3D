/* AMHEEX GAME — mundo: entidades, terreno, céu, clima e partículas. */
  function registerShadows(mesh) {
    if (!shadowGen) return;
    if (mesh.getTotalVertices && mesh.getTotalVertices() > 0) shadowGen.addShadowCaster(mesh, false);
  }

  function spawn(ent, opts) {
    opts = opts || {};
    if (WORLD.objects.length >= T.maxObjs) {
      return { ok: false, erro: 'limite de objetos da qualidade ' + tier };
    }
    var forma = opts.forma || (B.formaDe[ent] || 'box');
    var nomeEnt = opts.nome || ent;
    var build = B[ent];
    if (!build) {
      build = function (o) { return B.livre({ cor: o.cor, forma: o.forma || forma, nome: o.nome || nomeEnt }); };
    }
    var node = build({ cor: opts.cor || '#3b82f6', forma: forma, nome: nomeEnt, pele: opts.pele, seed: opts.seed });
    var baseX = Number(opts.x || 0), baseZ = Number(opts.z || 0);
    var n = Math.max(1, Math.min(Number(opts.n || 1), 24));
    var made = [];
    for (var i = 0; i < n; i++) {
      var inst = i === 0 ? node : build({ cor: opts.cor || '#3b82f6', forma: forma, nome: nomeEnt });
      var ang = (i / Math.max(1, n)) * Math.PI * 2;
      var tam = (B.tam && B.tam[ent]) || (opts.forma ? 1.5 : 1.2);
      var rad = n > 1 ? (tam * 0.95 + 1.0) + Math.floor(i / 6) * (tam * 1.15 + 0.8) : 0;
      inst.position.x = baseX + Math.cos(ang) * rad + (n > 1 ? 0 : 0);
      inst.position.z = baseZ + Math.sin(ang) * rad;
      inst.position.y = Number(opts.y || 0);
      var s = Number(opts.escala || 1);
      inst.scaling.setAll(s);
      if (opts.rotY) inst.rotation.y = opts.rotY;
      inst.getChildMeshes().forEach(function (m) {
        m.isPickable = false;
        registerShadows(m);
        if (m.metadata && m.metadata.spin) animated.push({ m: m, kind: 'spin', v: m.metadata.spin });
        if (m.metadata && m.metadata.coin) { WORLD.coins.push(m); }
      });
      inst.freezeWorldMatrix();
      var rec = { id: 'o' + (++seq), ent: ent, node: inst, cor: opts.cor || '#3b82f6', base: { x: inst.position.x, z: inst.position.z, s: s }, dynamic: false, nome: nomeEnt, forma: forma };
      WORLD.objects.push(rec);
      made.push(rec);
      if (WORLD.physics) {
        inst.position.y = 6 + i * 0.4;
        inst.unfreezeWorldMatrix();
        rec.dynamic = true;
      }
    }
    WORLD.lastEnt = ent;
    return { ok: true, n: n, ent: ent, ids: made.map(function (m) { return m.id; }) };
  }

  function findByEnt(ent) {
    return WORLD.objects.filter(function (o) { return o.ent === ent && !o.remoto; });
  }

  function recolor(ent, cor) {
    if (ent === 'bola' && WORLD.ball) {
      WORLD.ball.getChildMeshes().forEach(function (m) { m.material = mat(cor, { spec: 0.5 }); });
      return { ok: true, n: 1 };
    }
    var list = findByEnt(ent);
    if (!list.length) return { ok: false, erro: 'nada de ' + ent + ' na cena' };
    list.forEach(function (o) {
      o.cor = cor;
      o.node.getChildMeshes().forEach(function (m) { m.material = mat(cor); });
    });
    return { ok: true, n: list.length };
  }

  function scaleEnt(ent, f) {
    if (ent === 'bola' && WORLD.ball) {
      var s0 = (WORLD.ball.metadata && WORLD.ball.metadata.s) || 1;
      s0 = Math.max(0.4, Math.min(3, s0 * f));
      WORLD.ball.metadata = WORLD.ball.metadata || {}; WORLD.ball.metadata.s = s0;
      WORLD.ball.scaling.setAll(s0);
      return { ok: true, n: 1 };
    }
    var list = findByEnt(ent);
    if (!list.length) return { ok: false, erro: 'nada de ' + ent + ' na cena' };
    list.forEach(function (o) {
      o.base.s = Math.max(0.15, Math.min(6, o.base.s * f));
      o.node.scaling.setAll(o.base.s);
      o.node.unfreezeWorldMatrix();
      o.node.computeWorldMatrix(true);
    });
    return { ok: true, n: list.length };
  }

  function moveEnt(ent, dx, dz) {
    if (ent === 'bola' && WORLD.ball) {
      ballTarget.x = dx; ballTarget.z = dz;
      return { ok: true, n: 1 };
    }
    var list = findByEnt(ent);
    if (!list.length) return { ok: false, erro: 'nada de ' + ent + ' na cena' };
    list.forEach(function (o) {
      o.base.x = dx; o.base.z = dz;
      o.node.unfreezeWorldMatrix();
      o.node.position.x = dx; o.node.position.z = dz;
    });
    return { ok: true };
  }

  function rotateEnt(ent, deg) {
    if (ent === 'bola' && WORLD.ball) {
      WORLD.ball.rotation.y += deg * Math.PI / 180;
      return { ok: true, n: 1 };
    }
    var list = findByEnt(ent);
    if (!list.length) return { ok: false, erro: 'nada de ' + ent + ' na cena' };
    list.forEach(function (o) { o.node.unfreezeWorldMatrix(); o.node.rotation.y += deg * Math.PI / 180; });
    return { ok: true };
  }

  function removeEnt(ent) {
    if (ent === 'bola') return { ok: false, erro: 'a bola e o jogador, nao posso remover' };
    var list = findByEnt(ent);
    list.forEach(function (o) {
      o.node.dispose(false, true);
      WORLD.objects.splice(WORLD.objects.indexOf(o), 1);
    });
    WORLD.coins = WORLD.coins.filter(function (c) { return c.isDisposed ? false : true; });
    return { ok: true, n: list.length };
  }

  function clearWorld(keepBall) {
    WORLD.objects.slice().forEach(function (o) { o.node.dispose(false, true); });
    WORLD.objects = [];
    WORLD.coins = [];
    WORLD.score = 0; updateScore();
    WORLD.preset = null;
    WORLD.avatar = null;
    if (typeof clearLayout === 'function') clearLayout();
    if (!keepBall) { /* nada */ }
  }

  /* ---------------- terreno ---------------- */
  var groundTex = {};
  function makeGroundTexture(kind) {
    if (groundTex[kind]) return groundTex[kind];
    var size = tier === 'low' ? 256 : 512;
    var dt = new BABYLON.DynamicTexture('gt' + kind, { width: size, height: size }, scene, true);
    var ctx = dt.getContext();
    var base = { grama: '#3f8f3a', areia: '#d9c08a', asfalto: '#3b4252', madeira: '#8b5e34', neve: '#eef4ff', agua: '#1e6f8f', lava: '#7f1d1d' }[kind] || '#3f8f3a';
    ctx.fillStyle = base; ctx.fillRect(0, 0, size, size);
    var dots = tier === 'low' ? 900 : 2600;
    for (var i = 0; i < dots; i++) {
      var x = Math.random() * size, y = Math.random() * size, r = Math.random() * 2.4 + 0.4;
      var a = 0.05 + Math.random() * 0.12;
      ctx.fillStyle = Math.random() > 0.5 ? 'rgba(255,255,255,' + a + ')' : 'rgba(0,0,0,' + a + ')';
      ctx.beginPath(); ctx.arc(x, y, r, 0, 6.283); ctx.fill();
    }
    dt.update(false);
    dt.uScale = 14; dt.vScale = 14;
    groundTex[kind] = dt;
    return dt;
  }

  var ground = BABYLON.MeshBuilder.CreateGround('chao', { width: 220, height: 220, subdivisions: 2 }, scene);
  ground.position.y = 0;
  ground.receiveShadows = true;
  var groundMat = new BABYLON.StandardMaterial('chaoMat', scene);
  groundMat.diffuseTexture = makeGroundTexture('grama');
  groundMat.specularColor = new BABYLON.Color3(0.05, 0.05, 0.05);
  ground.material = groundMat;
  ground.isPickable = true;

  function setTerrain(kind) {
    WORLD.terrain = kind;
    groundMat.diffuseTexture = makeGroundTexture(kind);
    if (kind === 'agua') { groundMat.alpha = 0.85; } else { groundMat.alpha = 1; }
    return { ok: true };
  }

  /* ---------------- céu ---------------- */
  var sky = BABYLON.MeshBuilder.CreateSphere('ceu', { diameter: 400, segments: 12, sideOrientation: BABYLON.Mesh.BACKSIDE }, scene);
  sky.isPickable = false;
  var skyMat = new BABYLON.StandardMaterial('ceuMat', scene);
  skyMat.disableLighting = true;
  skyMat.backFaceCulling = false;
  var skyTex = new BABYLON.DynamicTexture('ceuTex', { width: 16, height: 256 }, scene, true);
  skyMat.emissiveTexture = skyTex;
  sky.material = skyMat;
  function paintSky(top, bottom) {
    var ctx = skyTex.getContext();
    var g = ctx.createLinearGradient(0, 0, 0, 256);
    g.addColorStop(0, top); g.addColorStop(1, bottom);
    ctx.fillStyle = g; ctx.fillRect(0, 0, 16, 256);
    skyTex.update(false);
  }
  paintSky('#2f6bd8', '#cfe6ff');

  /* ---------------- partículas de clima ---------------- */
  var weatherPS = null;
  function particleTexture() {
    var dt = new BABYLON.DynamicTexture('pt', { width: 64, height: 64 }, scene, false);
    var ctx = dt.getContext();
    var g = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
    g.addColorStop(0, 'rgba(255,255,255,1)');
    g.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = g; ctx.fillRect(0, 0, 64, 64);
    dt.update(false);
    dt.hasAlpha = true;
    return dt;
  }
  var pTex = null;
  function stopWeather() {
    if (weatherPS) { weatherPS.stop(); weatherPS.dispose(); weatherPS = null; }
  }
  function setWeather(kind) {
    WORLD.weather = kind;
    stopWeather();
    if (kind === 'dia') {
      paintSky('#2f6bd8', '#d7ebff'); scene.clearColor = new BABYLON.Color4(0.72, 0.84, 0.98, 1);
      hemi.intensity = 0.95; sun.intensity = 1.05; scene.fogDensity = 0.005;
      scene.fogColor = new BABYLON.Color3(0.62, 0.75, 0.92);
    } else if (kind === 'noite') {
      paintSky('#02030a', '#101a3a'); hemi.intensity = 0.5; sun.intensity = 0.3;
      scene.fogDensity = 0.012; scene.fogColor = new BABYLON.Color3(0.04, 0.06, 0.14);
    } else if (kind === 'por_do_sol') {
      paintSky('#2b1a4a', '#ff9a5c'); hemi.intensity = 0.6; sun.intensity = 0.95;
      sun.diffuse = new BABYLON.Color3(1, 0.65, 0.4); scene.fogDensity = 0.008;
      scene.fogColor = new BABYLON.Color3(0.95, 0.6, 0.42);
    } else if (kind === 'nublado') {
      paintSky('#6b7280', '#cbd5e1'); hemi.intensity = 0.75; sun.intensity = 0.5;
      scene.fogDensity = 0.01; scene.fogColor = new BABYLON.Color3(0.7, 0.74, 0.8);
    } else if (kind === 'neblina') {
      paintSky('#8b97b4', '#dfe7f5'); hemi.intensity = 0.8; sun.intensity = 0.4;
      scene.fogDensity = 0.045; scene.fogColor = new BABYLON.Color3(0.82, 0.87, 0.95);
    } else if (kind === 'tempestade') {
      paintSky('#0b1020', '#2b3448'); hemi.intensity = 0.4; sun.intensity = 0.3;
      scene.fogDensity = 0.02; scene.fogColor = new BABYLON.Color3(0.2, 0.24, 0.32);
    }
    if (kind === 'chuva' || kind === 'tempestade') {
      paintSky(kind === 'tempestade' ? '#0b1020' : '#3b4a63', '#8ea3c0');
      hemi.intensity = 0.55; sun.intensity = 0.35;
      scene.fogDensity = 0.014; scene.fogColor = new BABYLON.Color3(0.45, 0.52, 0.62);
      spawnPS('chuva');
    } else if (kind === 'neve') {
      paintSky('#9fb3d1', '#eef4ff'); hemi.intensity = 0.85; sun.intensity = 0.55;
      scene.fogDensity = 0.016; scene.fogColor = new BABYLON.Color3(0.9, 0.94, 1);
      spawnPS('neve');
    }
    if (kind !== 'por_do_sol') sun.diffuse = new BABYLON.Color3(1, 0.96, 0.88);
    return { ok: true };
  }
  function spawnPS(kind) {
    if (!pTex) pTex = particleTexture();
    var ps = new BABYLON.ParticleSystem('ps', T.particles, scene);
    ps.particleTexture = pTex;
    ps.emitter = new BABYLON.Vector3(0, 22, 0);
    ps.minEmitBox = new BABYLON.Vector3(-30, 0, -30);
    ps.maxEmitBox = new BABYLON.Vector3(30, 2, 30);
    if (kind === 'chuva') {
      ps.color1 = new BABYLON.Color4(0.7, 0.82, 1, 0.65); ps.color2 = new BABYLON.Color4(0.55, 0.7, 1, 0.45);
      ps.minSize = 0.06; ps.maxSize = 0.12; ps.minLifeTime = 0.5; ps.maxLifeTime = 0.9;
      ps.emitRate = T.particles * 0.7; ps.gravity = new BABYLON.Vector3(0, -55, 0);
      ps.direction1 = new BABYLON.Vector3(-1, -12, -1); ps.direction2 = new BABYLON.Vector3(1, -12, 1);
      ps.minEmitPower = 4; ps.maxEmitPower = 8;
    } else {
      ps.color1 = new BABYLON.Color4(1, 1, 1, 0.95); ps.color2 = new BABYLON.Color4(0.85, 0.92, 1, 0.8);
      ps.minSize = 0.14; ps.maxSize = 0.3; ps.minLifeTime = 4; ps.maxLifeTime = 8;
      ps.emitRate = T.particles * 0.25; ps.gravity = new BABYLON.Vector3(0, -1.6, 0);
      ps.direction1 = new BABYLON.Vector3(-0.4, -0.6, -0.4); ps.direction2 = new BABYLON.Vector3(0.4, -0.2, 0.4);
      ps.minEmitPower = 0.4; ps.maxEmitPower = 1.2;
    }
    ps.start();
    weatherPS = ps;
  }

  /* ---------------- bola / jogador ---------------- */
  var ball = B.bola({ cor: '#e63946' });
  ball.position.set(0, 0, 0);
  ball.getChildMeshes().forEach(function (m) { m.isPickable = false; registerShadows(m); });
  WORLD.ball = ball;
  var ballTarget = new BABYLON.Vector3(0, 0, 0);
  var keys = {};
  var moveInput = { f: 0, b: 0, l: 0, r: 0 };
  var player = {
    node: ball, y: 0, vy: 0, grounded: true, mode: 'third', run: false,
    health: 100, speed: 7.2, skin: { pele: '#c68642', cor: '#4f7cff', seed: 12345 },
    isCharacter: false, moveInput: moveInput
  };
  var controlOn = false;
  var npc = null;
  var settings = { volume: 0.8, sens: 1, names: true, voiceRange: 16 };
  window.AMX_SET = settings;

  window.addEventListener('keydown', function (e) {
    var k = e.key.toLowerCase();
    keys[k] = true;
    if (k === ' ' || k === 'arrowup' || k === 'arrowdown' || k === 'arrowleft' || k === 'arrowright') {
      if (document.activeElement && /input|textarea/i.test(document.activeElement.tagName)) return;
      e.preventDefault();
    }
    if (k === ' ') doJump();
    if (k === 'f') doFire();
  });
  window.addEventListener('keyup', function (e) { keys[e.key.toLowerCase()] = false; });
