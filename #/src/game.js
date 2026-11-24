/* =====================================================================
   AMHEEX GAME — motor do mundo (Babylon.js local) + AMHEEX AI
   Chão verde + bola vermelha por padrão. A IA adiciona tudo por comandos.
   Qualidade adaptativa por hardware + watchdog de FPS.
   ===================================================================== */
(function () {
  'use strict';
  if (!window.BABYLON) { document.getElementById('hint').textContent = 'Babylon.js não carregou (lib/babylon.js).'; return; }

  var canvas = document.getElementById('game');
  var $ = function (id) { return document.getElementById(id); };

  /* ---------------- qualidade adaptativa ---------------- */
  var TIERS = {
    low:  { label: 'qualidade leve',  scaling: 1.75, shadows: false, particles: 450,  segments: 6,  maxObjs: 140 },
    mid:  { label: 'qualidade média', scaling: 1.30, shadows: false, particles: 1100, segments: 10, maxObjs: 320 },
    high: { label: 'qualidade alta',  scaling: 1.00, shadows: true,  particles: 2200, segments: 16, maxObjs: 800 }
  };
  function detectTier() {
    var mem = navigator.deviceMemory || 4;
    var cores = navigator.hardwareConcurrency || 4;
    var dpr = window.devicePixelRatio || 1;
    var w = Math.min(screen.width, screen.height);
    if (mem <= 2 || cores <= 2 || (w < 420 && dpr > 2.5)) return 'low';
    if (mem <= 4 || cores <= 4 || w < 700) return 'mid';
    return 'high';
  }
  var tier = detectTier();
  var T = TIERS[tier];

  var engine = new BABYLON.Engine(canvas, true, {
    preserveDrawingBuffer: false, stencil: true, antialias: tier === 'high',
    powerPreference: 'high-performance', failIfMajorPerformanceCaveat: false
  }, true);
  engine.setHardwareScalingLevel(T.scaling);

  var scene = new BABYLON.Scene(engine);
  scene.clearColor = new BABYLON.Color4(0.03, 0.05, 0.09, 1);
  scene.ambientColor = new BABYLON.Color3(0.25, 0.28, 0.35);
  scene.fogMode = BABYLON.Scene.FOGMODE_EXP2;
  scene.fogDensity = 0.006;
  scene.fogColor = new BABYLON.Color3(0.55, 0.68, 0.85);

  var camera = new BABYLON.ArcRotateCamera('cam', -Math.PI / 2.1, 1.02, 13, new BABYLON.Vector3(0, 1, 0), scene);
  camera.attachControl(canvas, true);
  camera.lowerRadiusLimit = 4;
  camera.upperRadiusLimit = 46;
  camera.lowerBetaLimit = 0.22;
  camera.upperBetaLimit = 1.46;
  camera.wheelPrecision = 26;
  camera.pinchPrecision = 70;
  camera.panningSensibility = 0;
  camera.inertia = 0.82;
  camera.angularSensibilityX = 900;
  camera.angularSensibilityY = 900;

  var hemi = new BABYLON.HemisphericLight('hemi', new BABYLON.Vector3(0.2, 1, 0.1), scene);
  hemi.intensity = 0.95;
  hemi.diffuse = new BABYLON.Color3(1, 1, 1);
  hemi.groundColor = new BABYLON.Color3(0.28, 0.38, 0.30);

  var sun = new BABYLON.DirectionalLight('sun', new BABYLON.Vector3(-0.5, -1, -0.35), scene);
  sun.position = new BABYLON.Vector3(24, 34, 20);
  sun.intensity = 1.05;
  sun.diffuse = new BABYLON.Color3(1, 0.96, 0.88);

  var shadowGen = null;
  if (T.shadows) {
    shadowGen = new BABYLON.ShadowGenerator(1024, sun);
    shadowGen.useBlurExponentialShadowMap = true;
    shadowGen.blurKernel = 24;
    shadowGen.darkness = 0.35;
  }

  /* ---------------- materiais ---------------- */
  var matCache = {};
  function mat(hex, opt) {
    opt = opt || {};
    var key = hex + '|' + (opt.emissive || 0) + '|' + (opt.alpha == null ? 1 : opt.alpha) + '|' + (opt.spec == null ? 0.18 : opt.spec);
    if (matCache[key]) return matCache[key];
    var m = new BABYLON.StandardMaterial('m' + key, scene);
    var c = BABYLON.Color3.FromHexString(hex);
    m.diffuseColor = c;
    m.specularColor = new BABYLON.Color3(opt.spec == null ? 0.18 : opt.spec, opt.spec == null ? 0.18 : opt.spec, opt.spec == null ? 0.18 : opt.spec);
    m.specularPower = 64;
    if (opt.emissive) m.emissiveColor = c.scale(opt.emissive);
    if (opt.alpha != null && opt.alpha < 1) { m.alpha = opt.alpha; m.backFaceCulling = false; }
    matCache[key] = m;
    return m;
  }

  /* ---------------- helpers de geometria ---------------- */
  function box(name, w, h, d, parent, x, y, z, material) {
    var m = BABYLON.MeshBuilder.CreateBox(name, { width: w, height: h, depth: d }, scene);
    m.position.set(x, y, z); m.material = material; m.parent = parent; return m;
  }
  function cyl(name, dTop, dBot, h, parent, x, y, z, material, tess) {
    var m = BABYLON.MeshBuilder.CreateCylinder(name, { diameterTop: dTop, diameterBottom: dBot, height: h, tessellation: tess || T.segments }, scene);
    m.position.set(x, y, z); m.material = material; m.parent = parent; return m;
  }
  function sph(name, d, parent, x, y, z, material, seg) {
    var m = BABYLON.MeshBuilder.CreateSphere(name, { diameter: d, segments: seg || T.segments }, scene);
    m.position.set(x, y, z); m.material = material; m.parent = parent; return m;
  }
  function torus(name, d, th, parent, x, y, z, material) {
    var m = BABYLON.MeshBuilder.CreateTorus(name, { diameter: d, thickness: th, tessellation: T.segments + 8 }, scene);
    m.position.set(x, y, z); m.material = material; m.parent = parent; return m;
  }
  function poly(name, type, size, parent, x, y, z, material) {
    var m = BABYLON.MeshBuilder.CreatePolyhedron(name, { type: type, size: size }, scene);
    m.position.set(x, y, z); m.material = material; m.parent = parent; return m;
  }
  function root(name) {
    var n = new BABYLON.TransformNode(name, scene);
    return n;
  }

  /* ---------------- construtores procedurais ---------------- */
  var B = {};

  B.bola = function (o) {
    var r = root('bola'); var m = sph('b', 1.15, r, 0, 0.58, 0, mat(o.cor, { spec: 0.5 }), 16);
    m.metadata = { spin: 0.012 };
    return r;
  };
  B.cubo = function (o) {
    var r = root('cubo'); box('c', 1.4, 1.4, 1.4, r, 0, 0.7, 0, mat(o.cor)); return r;
  };
  B.casa = function (o) {
    var r = root('casa'); var parede = mat(o.cor), telha = mat('#8b3a2f'), madeira = mat('#6b4423');
    box('p', 3.2, 2.4, 3, r, 0, 1.2, 0, parede);
    var roof = cyl('t', 0.05, 4.6, 1.5, r, 0, 3.05, 0, telha, 4); roof.rotation.y = Math.PI / 4;
    box('porta', 0.7, 1.35, 0.1, r, 0, 0.68, 1.52, madeira);
    box('j1', 0.62, 0.62, 0.1, r, -1, 1.55, 1.52, mat('#7dd3fc', { emissive: 0.35 }));
    box('j2', 0.62, 0.62, 0.1, r, 1, 1.55, 1.52, mat('#7dd3fc', { emissive: 0.35 }));
    box('ch', 0.4, 1.1, 0.4, r, 1.05, 3.1, -0.6, mat('#9a9a9a'));
    return r;
  };
  B.arvore = function (o) {
    var r = root('arvore'); var tronco = mat('#6b4423');
    cyl('t', 0.32, 0.42, 2.2, r, 0, 1.1, 0, tronco, 8);
    sph('c1', 1.9, r, 0, 2.6, 0, mat(o.cor), 8);
    sph('c2', 1.35, r, 0.55, 2.15, 0.35, mat(o.cor), 8);
    sph('c3', 1.25, r, -0.5, 2.3, -0.3, mat(o.cor), 8);
    return r;
  };
  B.pinheiro = function (o) {
    var r = root('pinheiro'); cyl('t', 0.26, 0.34, 1.2, r, 0, 0.6, 0, mat('#5b3a1e'), 7);
    var m = mat(o.cor);
    cyl('c1', 0.1, 2.6, 1.8, r, 0, 1.9, 0, m, 9);
    cyl('c2', 0.1, 2.0, 1.6, r, 0, 2.9, 0, m, 9);
    cyl('c3', 0.1, 1.3, 1.4, r, 0, 3.8, 0, m, 9);
    return r;
  };
  B.palmeira = function (o) {
    var r = root('palmeira'); var tr = mat('#8b5e34'), folha = mat(o.cor);
    for (var i = 0; i < 5; i++) {
      var s = cyl('t' + i, 0.24, 0.3, 0.85, r, i * 0.06, 0.42 + i * 0.8, 0, tr, 7);
      s.rotation.z = i * 0.035;
    }
    for (var f = 0; f < 6; f++) {
      var leaf = box('f' + f, 2.2, 0.08, 0.5, r, 0, 4.15, 0, folha);
      leaf.rotation.y = (f / 6) * Math.PI * 2;
      leaf.rotation.z = -0.35;
      leaf.position.x = Math.cos(leaf.rotation.y) * 0.9;
      leaf.position.z = Math.sin(leaf.rotation.y) * 0.9;
    }
    sph('coco', 0.3, r, 0.2, 4.0, 0.15, mat('#6b4423'), 6);
    return r;
  };
  B.boneco = function (o) {
    var r = root('boneco'); var pele = mat('#f0c39a'), roupa = mat(o.cor), escuro = mat('#1f2937');
    cyl('perna1', 0.22, 0.22, 0.9, r, -0.18, 0.45, 0, escuro, 8);
    cyl('perna2', 0.22, 0.22, 0.9, r, 0.18, 0.45, 0, escuro, 8);
    cyl('torso', 0.62, 0.5, 1.05, r, 0, 1.42, 0, roupa, 10);
    cyl('braco1', 0.16, 0.16, 0.85, r, -0.44, 1.45, 0, roupa, 7);
    cyl('braco2', 0.16, 0.16, 0.85, r, 0.44, 1.45, 0, roupa, 7);
    sph('cabeca', 0.62, r, 0, 2.22, 0, pele, 10);
    box('olho1', 0.09, 0.09, 0.05, r, -0.12, 2.28, 0.29, escuro);
    box('olho2', 0.09, 0.09, 0.05, r, 0.12, 2.28, 0.29, escuro);
    return r;
  };
  /* ---------------- personagem realista procedural ----------------
     Corpo em polígonos geométricos + textura de pele/roupa gerada em canvas.
     Sem GLB externo: cada seed cria uma variação única. */
  function skinTexture(hex, seed) {
    var dt = new BABYLON.DynamicTexture('skin' + seed, { width: 256, height: 256 }, scene, true);
    var ctx = dt.getContext();
    ctx.fillStyle = hex || '#c68642';
    ctx.fillRect(0, 0, 256, 256);
    var rnd = (function (s) { return function () { s = (s * 9301 + 49297) % 233280; return s / 233280; }; })(seed || 1);
    for (var i = 0; i < 1600; i++) {
      var a = 0.025 + rnd() * 0.07;
      ctx.fillStyle = rnd() > 0.5 ? 'rgba(255,235,215,' + a + ')' : 'rgba(70,35,20,' + a + ')';
      var s = 1 + rnd() * 3;
      ctx.fillRect(rnd() * 256, rnd() * 256, s, s);
    }
    /* sombra suave nas laterais (dá volume à pele) */
    var g = ctx.createLinearGradient(0, 0, 256, 0);
    g.addColorStop(0, 'rgba(0,0,0,.16)'); g.addColorStop(.5, 'rgba(255,255,255,.05)'); g.addColorStop(1, 'rgba(0,0,0,.16)');
    ctx.fillStyle = g; ctx.fillRect(0, 0, 256, 256);
    dt.update();
    return dt;
  }
  function clothTexture(hex, seed) {
    var dt = new BABYLON.DynamicTexture('cloth' + seed, { width: 256, height: 256 }, scene, true);
    var ctx = dt.getContext();
    ctx.fillStyle = hex || '#4f7cff'; ctx.fillRect(0, 0, 256, 256);
    var rnd = (function (s) { return function () { s = (s * 1103515245 + 12345) % 2147483648; return s / 2147483648; }; })(seed || 7);
    ctx.globalAlpha = 0.16;
    for (var y = 0; y < 256; y += 8) {
      ctx.fillStyle = rnd() > 0.5 ? '#ffffff' : '#000000';
      ctx.fillRect(0, y, 256, 2);
    }
    ctx.globalAlpha = 1;
    dt.update();
    return dt;
  }
  B.personagem = function (o) {
    var r = root('personagem');
    var seed = Number(o.seed || 1);
    var peleHex = o.pele || '#c68642';
    var roupaHex = o.cor || '#4f7cff';
    var calcaHex = o.calca || '#1f2a44';
    var cabeloHex = o.cabelo || '';
    var peleTex = skinTexture(peleHex, seed);
    var roupaTex = clothTexture(roupaHex, seed + 11);
    var calcaTex = clothTexture(calcaHex, seed + 23);
    var pele = new BABYLON.StandardMaterial('pele' + seed, scene);
    pele.diffuseTexture = peleTex; pele.specularColor = new BABYLON.Color3(.10, .10, .10); pele.specularPower = 48;
    var roupa = new BABYLON.StandardMaterial('roupa' + seed, scene);
    roupa.diffuseTexture = roupaTex; roupa.specularColor = new BABYLON.Color3(.06, .06, .06);
    var calca = new BABYLON.StandardMaterial('calca' + seed, scene);
    calca.diffuseTexture = calcaTex; calca.specularColor = new BABYLON.Color3(.06, .06, .06);
    var cabeloCor = cabeloHex || ['#1b1b1b', '#3b2314', '#6b4423', '#d4a24c', '#8b2f2f'][seed % 5];
    var cabelo = mat(cabeloCor);
    var olho = mat('#f8fafc', { emissive: .05 });
    var iris = mat(['#3b82f6', '#22c55e', '#a16207', '#0f172a'][seed % 4], { emissive: .12 });
    var pupila = mat('#0b0f18');
    var brilho = mat('#ffffff', { emissive: 1 });
    var boca = mat('#8f2f3a');
    var sapato = mat('#111827');
    var detalhe = mat('#e2e8f0');
    var S = 24, SP = 28;

    /* pernas articuladas: coxa, joelho, canela, pé */
    cyl('coxaE', .26, .23, .62, r, -.20, .92, 0, calca, S);
    cyl('coxaD', .26, .23, .62, r, .20, .92, 0, calca, S);
    sph('joelhoE', .21, r, -.20, .60, 0, calca, 16);
    sph('joelhoD', .21, r, .20, .60, 0, calca, 16);
    cyl('pernaE', .20, .17, .52, r, -.20, .30, 0, pele, S);
    cyl('pernaD', .20, .17, .52, r, .20, .30, 0, pele, S);
    box('peE', .28, .15, .46, r, -.20, .08, .09, sapato);
    box('peD', .28, .15, .46, r, .20, .08, .09, sapato);
    /* quadril e cintura */
    cyl('quadril', .58, .52, .34, r, 0, 1.20, 0, calca, S);
    cyl('cintura', .55, .57, .16, r, 0, 1.39, 0, detalhe, S);
    /* torso com peito e costas */
    cyl('torso', .63, .55, .78, r, 0, 1.80, 0, roupa, S);
    sph('peitoE', .30, r, -.17, 1.92, .16, roupa, 18).scaling.set(1, .8, .55);
    sph('peitoD', .30, r, .17, 1.92, .16, roupa, 18).scaling.set(1, .8, .55);
    /* ombros e braços articulados */
    sph('ombroE', .24, r, -.50, 2.05, 0, roupa, 18);
    sph('ombroD', .24, r, .50, 2.05, 0, roupa, 18);
    cyl('bracoE', .18, .16, .52, r, -.52, 1.76, 0, pele, S);
    cyl('bracoD', .18, .16, .52, r, .52, 1.76, 0, pele, S);
    sph('cotoveloE', .16, r, -.52, 1.49, 0, pele, 14);
    sph('cotoveloD', .16, r, .52, 1.49, 0, pele, 14);
    cyl('antebracoE', .15, .13, .46, r, -.52, 1.24, 0, pele, S);
    cyl('antebracoD', .15, .13, .46, r, .52, 1.24, 0, pele, S);
    sph('maoE', .20, r, -.52, .98, 0, pele, 16).scaling.set(.85, 1.15, .6);
    sph('maoD', .20, r, .52, .98, 0, pele, 16).scaling.set(.85, 1.15, .6);
    /* dedos (geometria fina) */
    for (var f = 0; f < 4; f++) {
      cyl('dedoE' + f, .028, .024, .16, r, -.60 + f * .055, .84, .02, pele, 8);
      cyl('dedoD' + f, .028, .024, .16, r, .44 + f * .055, .84, .02, pele, 8);
    }
    /* pescoço e cabeça */
    cyl('pescoco', .21, .24, .22, r, 0, 2.30, 0, pele, S);
    sph('cabeca', .64, r, 0, 2.70, 0, pele, SP).scaling.set(.94, 1.08, .98);
    sph('mandibula', .50, r, 0, 2.50, .06, pele, SP).scaling.set(.88, .62, .92);
    /* cabelo em camadas */
    var topo = sph('cabelo', .68, r, 0, 2.78, -.02, cabelo, SP);
    topo.scaling.y = .66; topo.scaling.z = 1.03;
    box('franja', .52, .13, .14, r, 0, 2.96, .27, cabelo);
    sph('nuca', .40, r, 0, 2.62, -.30, cabelo, 18).scaling.set(1.05, .9, .6);
    for (var h = 0; h < 5; h++) {
      cyl('fio' + h, .05, .03, .30, r, -.30 + h * .15, 2.92, .18, cabelo, 8).rotation.z = (h - 2) * .18;
    }
    /* olhos: esclera, íris, pupila, brilho, pálpebra */
    sph('olhoE', .115, r, -.145, 2.74, .275, olho, 16);
    sph('olhoD', .115, r, .145, 2.74, .275, olho, 16);
    sph('irisE', .062, r, -.145, 2.74, .355, iris, 14);
    sph('irisD', .062, r, .145, 2.74, .355, iris, 14);
    sph('pupilaE', .032, r, -.145, 2.74, .385, pupila, 12);
    sph('pupilaD', .032, r, .145, 2.74, .385, pupila, 12);
    sph('brilhoE', .013, r, -.128, 2.765, .405, brilho, 8);
    sph('brilhoD', .013, r, .128, 2.765, .405, brilho, 8);
    box('palpebraE', .20, .045, .07, r, -.145, 2.845, .30, pele).rotation.z = -.12;
    box('palpebraD', .20, .045, .07, r, .145, 2.845, .30, pele).rotation.z = .12;
    box('sobrancelhaE', .16, .038, .05, r, -.145, 2.88, .30, cabelo).rotation.z = -.10;
    box('sobrancelhaD', .16, .038, .05, r, .145, 2.88, .30, cabelo).rotation.z = .10;
    /* nariz, boca, orelhas */
    box('narizPonte', .075, .18, .10, r, 0, 2.70, .31, pele);
    sph('narizPonta', .075, r, 0, 2.63, .35, pele, 12).scaling.set(1, .8, .9);
    box('labioSup', .19, .04, .05, r, 0, 2.50, .315, boca);
    box('labioInf', .17, .045, .05, r, 0, 2.455, .312, boca);
    sph('orelhaE', .13, r, -.33, 2.70, 0, pele, 14).scaling.z = .55;
    sph('orelhaD', .13, r, .33, 2.70, 0, pele, 14).scaling.z = .55;
    r.metadata = { humanoid: true, seed: seed, pele: peleHex, cor: roupaHex, calca: calcaHex, cabelo: cabeloCor };
    return r;
  };

  B.robo = function (o) {
    var r = root('robo'); var metal = mat(o.cor, { spec: 0.55 }), olho = mat('#22d3ee', { emissive: 1 });
    box('torso', 0.95, 1.1, 0.6, r, 0, 1.35, 0, metal);
    box('cabeca', 0.7, 0.55, 0.6, r, 0, 2.22, 0, metal);
    box('olho', 0.42, 0.1, 0.05, r, 0, 2.26, 0.31, olho);
    cyl('braco1', 0.16, 0.16, 0.9, r, -0.62, 1.4, 0, metal, 7);
    cyl('braco2', 0.16, 0.16, 0.9, r, 0.62, 1.4, 0, metal, 7);
    cyl('perna1', 0.2, 0.2, 0.8, r, -0.25, 0.4, 0, metal, 7);
    cyl('perna2', 0.2, 0.2, 0.8, r, 0.25, 0.4, 0, metal, 7);
    return r;
  };
  B.cachorro = function (o) {
    var r = root('cachorro'); var c = mat(o.cor);
    cyl('corpo', 0.55, 0.55, 1.15, r, 0, 0.62, 0, c, 9).rotation.z = Math.PI / 2;
    sph('cabeca', 0.62, r, 0.62, 0.78, 0, c, 9);
    cyl('focinho', 0.24, 0.3, 0.35, r, 0.92, 0.72, 0, c, 7).rotation.z = Math.PI / 2;
    cyl('orelha1', 0.05, 0.28, 0.34, r, 0.5, 1.05, 0.18, c, 6);
    cyl('orelha2', 0.05, 0.28, 0.34, r, 0.5, 1.05, -0.18, c, 6);
    for (var i = 0; i < 4; i++) cyl('p' + i, 0.13, 0.13, 0.45, r, (i < 2 ? 0.42 : -0.42), 0.22, (i % 2 ? 0.2 : -0.2), c, 6);
    cyl('rabo', 0.07, 0.14, 0.55, r, -0.62, 0.85, 0, c, 6).rotation.z = -0.7;
    return r;
  };
  B.gato = function (o) {
    var r = root('gato'); var c = mat(o.cor);
    sph('corpo', 0.72, r, 0, 0.55, 0, c, 9).scaling.set(1.25, 0.9, 1);
    sph('cabeca', 0.55, r, 0.5, 0.92, 0, c, 9);
    cyl('o1', 0.02, 0.2, 0.3, r, 0.42, 1.18, 0.14, c, 5);
    cyl('o2', 0.02, 0.2, 0.3, r, 0.42, 1.18, -0.14, c, 5);
    cyl('rabo', 0.05, 0.09, 0.75, r, -0.55, 0.75, 0, c, 6).rotation.z = -0.9;
    return r;
  };
  B.dinossauro = function (o) {
    var r = root('dinossauro'); var c = mat(o.cor);
    sph('corpo', 1.5, r, 0, 1.4, 0, c, 10).scaling.set(1.5, 1, 0.9);
    cyl('pescoco', 0.4, 0.62, 1.3, r, 0.85, 2.15, 0, c, 8).rotation.z = -0.45;
    sph('cabeca', 0.72, r, 1.35, 2.7, 0, c, 9);
    cyl('cauda', 0.12, 0.7, 1.9, r, -1.55, 1.3, 0, c, 8).rotation.z = 1.15;
    for (var i = 0; i < 4; i++) cyl('p' + i, 0.24, 0.3, 0.95, r, (i < 2 ? 0.6 : -0.55), 0.48, (i % 2 ? 0.5 : -0.5), c, 7);
    return r;
  };
  B.dragao = function (o) {
    var r = root('dragao'); var c = mat(o.cor), asa = mat(o.cor, { alpha: 0.85 });
    sph('corpo', 1.3, r, 0, 1.6, 0, c, 10).scaling.set(1.4, 1, 0.9);
    sph('cabeca', 0.7, r, 1.15, 2.25, 0, c, 9);
    cyl('cauda', 0.1, 0.6, 1.8, r, -1.35, 1.35, 0, c, 8).rotation.z = 1.2;
    var a1 = box('asa1', 1.9, 0.08, 1.1, r, -0.2, 2.15, 0.85, asa); a1.rotation.x = -0.35;
    var a2 = box('asa2', 1.9, 0.08, 1.1, r, -0.2, 2.15, -0.85, asa); a2.rotation.x = 0.35;
    sph('olho', 0.1, r, 1.45, 2.35, 0.2, mat('#facc15', { emissive: 1 }), 6);
    return r;
  };
  B.carro = function (o) {
    var r = root('carro'); var c = mat(o.cor, { spec: 0.55 }), vidro = mat('#0f172a', { spec: 0.7 }), pneu = mat('#111827');
    box('corpo', 1.7, 0.55, 3.2, r, 0, 0.62, 0, c);
    box('cabine', 1.5, 0.55, 1.6, r, 0, 1.12, -0.15, vidro);
    for (var i = 0; i < 4; i++) {
      var w = cyl('r' + i, 0.62, 0.62, 0.28, r, (i < 2 ? 0.85 : -0.85), 0.32, (i % 2 ? 1.0 : -1.0), pneu, 10);
      w.rotation.z = Math.PI / 2;
    }
    box('farol1', 0.3, 0.14, 0.06, r, -0.55, 0.68, 1.61, mat('#fff7cc', { emissive: 1 }));
    box('farol2', 0.3, 0.14, 0.06, r, 0.55, 0.68, 1.61, mat('#fff7cc', { emissive: 1 }));
    return r;
  };
  B.caminhao = function (o) {
    var r = root('caminhao'); var c = mat(o.cor), pneu = mat('#111827');
    box('cab', 1.9, 1.5, 1.5, r, 0, 1.05, 1.2, c);
    box('bau', 2.1, 1.9, 3.1, r, 0, 1.25, -1.2, mat('#e5e7eb'));
    for (var i = 0; i < 6; i++) {
      var w = cyl('r' + i, 0.6, 0.6, 0.3, r, (i % 2 ? 0.95 : -0.95), 0.32, (i < 2 ? 1.3 : (i < 4 ? -0.7 : -2.0)), pneu, 10);
      w.rotation.z = Math.PI / 2;
    }
    return r;
  };
  B.nave = function (o) {
    var r = root('nave'); var c = mat(o.cor, { spec: 0.6 });
    cyl('corpo', 0.15, 0.85, 2.6, r, 0, 1.4, 0, c, 12).rotation.x = Math.PI / 2;
    cyl('asa1', 0.1, 0.9, 1.4, r, -0.75, 1.4, -0.4, c, 4).rotation.z = Math.PI / 2;
    cyl('asa2', 0.1, 0.9, 1.4, r, 0.75, 1.4, -0.4, c, 4).rotation.z = Math.PI / 2;
    sph('cabo', 0.55, r, 0, 1.65, 0.45, mat('#7dd3fc', { emissive: 0.6 }), 9);
    cyl('fogo', 0.05, 0.5, 0.9, r, 0, 1.4, -1.6, mat('#f97316', { emissive: 1 }), 8);
    return r;
  };
  B.montanha = function (o) {
    var r = root('montanha'); var c = mat(o.cor);
    cyl('m', 0.2, 9, 6.5, r, 0, 3.25, 0, c, 7);
    cyl('neve', 0.18, 2.6, 1.9, r, 0, 6.1, 0, mat('#f8fafc'), 7);
    return r;
  };
  B.lago = function (o) {
    var r = root('lago');
    var d = BABYLON.MeshBuilder.CreateDisc('agua', { radius: 3.4, tessellation: 28 }, scene);
    d.rotation.x = Math.PI / 2; d.position.y = 0.03; d.material = mat(o.cor, { alpha: 0.72, spec: 0.8 }); d.parent = r;
    return r;
  };
  B.ilha = function (o) {
    var r = root('ilha');
    cyl('areia', 8.5, 9.5, 0.55, r, 0, 0.27, 0, mat(o.cor), 20);
    var p = B.palmeira({ cor: '#22c55e' }); p.parent = r; p.position.set(1.2, 0.5, 0.6);
    var p2 = B.palmeira({ cor: '#16a34a' }); p2.parent = r; p2.position.set(-1.6, 0.5, -1.0); p2.rotation.y = 1.1;
    return r;
  };
  B.pedra = function (o) {
    var r = root('pedra'); var m = poly('p', 1, 1.1, r, 0, 0.55, 0, mat(o.cor));
    m.scaling.set(1.3, 0.85, 1.1); m.rotation.y = Math.random() * 3;
    return r;
  };
  B.flor = function (o) {
    var r = root('flor'); cyl('c', 0.05, 0.07, 0.55, r, 0, 0.27, 0, mat('#166534'), 5);
    var m = mat(o.cor);
    for (var i = 0; i < 5; i++) {
      var p = sph('p' + i, 0.22, r, Math.cos(i / 5 * 6.28) * 0.14, 0.6, Math.sin(i / 5 * 6.28) * 0.14, m, 5);
      p.scaling.y = 0.55;
    }
    sph('miolo', 0.12, r, 0, 0.62, 0, mat('#facc15', { emissive: 0.4 }), 5);
    return r;
  };
  B.castelo = function (o) {
    var r = root('castelo'); var c = mat(o.cor), telha = mat('#7f1d1d');
    box('muro', 6, 2.6, 6, r, 0, 1.3, 0, c);
    [[-3, -3], [3, -3], [-3, 3], [3, 3]].forEach(function (p, i) {
      cyl('t' + i, 1.5, 1.6, 4.2, r, p[0], 2.1, p[1], c, 10);
      cyl('r' + i, 0.05, 1.9, 1.2, r, p[0], 4.7, p[1], telha, 10);
    });
    box('portao', 1.5, 1.9, 0.2, r, 0, 0.95, 3.02, mat('#3b2412'));
    return r;
  };
  B.torre = function (o) {
    var r = root('torre'); var c = mat(o.cor);
    cyl('t', 1.5, 1.9, 6, r, 0, 3, 0, c, 12);
    cyl('r', 0.05, 2.1, 1.4, r, 0, 6.6, 0, mat('#7f1d1d'), 12);
    box('j', 0.4, 0.5, 0.1, r, 0, 4.4, 0.95, mat('#facc15', { emissive: 0.8 }));
    return r;
  };
  B.ponte = function (o) {
    var r = root('ponte'); var m = mat(o.cor);
    box('tab', 3, 0.22, 8, r, 0, 1.2, 0, m);
    for (var i = 0; i < 7; i++) {
      box('p' + i, 0.25, 1.2, 0.25, r, -1.4, 0.6, -3.5 + i * 1.16, m);
      box('p2' + i, 0.25, 1.2, 0.25, r, 1.4, 0.6, -3.5 + i * 1.16, m);
    }
    return r;
  };
  B.cerca = function (o) {
    var r = root('cerca'); var m = mat(o.cor);
    for (var i = 0; i < 7; i++) box('p' + i, 0.16, 1.1, 0.16, r, -3 + i, 0.55, 0, m);
    box('r1', 6.2, 0.12, 0.1, r, 0, 0.85, 0, m);
    box('r2', 6.2, 0.12, 0.1, r, 0, 0.45, 0, m);
    return r;
  };
  B.estrada = function (o) {
    var r = root('estrada'); var m = mat(o.cor);
    box('a', 6, 0.08, 22, r, 0, 0.05, 0, m);
    for (var i = 0; i < 7; i++) box('l' + i, 0.18, 0.02, 1.4, r, 0, 0.1, -9 + i * 3, mat('#f8fafc', { emissive: 0.15 }));
    return r;
  };
  B.tocha = function (o) {
    var r = root('tocha'); cyl('p', 0.12, 0.16, 1.5, r, 0, 0.75, 0, mat('#5b3a1e'), 7);
    var f = sph('f', 0.42, r, 0, 1.65, 0, mat(o.cor, { emissive: 1 }), 7);
    f.metadata = { flicker: true };
    return r;
  };
  B.cristal = function (o) {
    var r = root('cristal'); var m = poly('c', 1, 1.0, r, 0, 1.05, 0, mat(o.cor, { emissive: 0.55 }));
    m.scaling.set(0.8, 1.7, 0.8); m.rotation.y = 0.4;
    return r;
  };
  B.portal = function (o) {
    var r = root('portal'); var m = mat(o.cor, { emissive: 0.8 });
    torus('a', 3.4, 0.34, r, 0, 2.1, 0, m);
    var d = BABYLON.MeshBuilder.CreateDisc('luz', { radius: 1.6, tessellation: 24 }, scene);
    d.position.set(0, 2.1, 0); d.material = mat(o.cor, { emissive: 0.9, alpha: 0.55 }); d.parent = r;
    return r;
  };
  B.moeda = function (o) {
    var r = root('moeda'); var m = cyl('m', 0.55, 0.55, 0.1, r, 0, 0.75, 0, mat(o.cor, { emissive: 0.35, spec: 0.7 }), 14);
    m.rotation.x = Math.PI / 2; m.metadata = { coin: true };
    return r;
  };
  B.bau = function (o) {
    var r = root('bau'); var m = mat(o.cor);
    box('b', 1.1, 0.7, 0.8, r, 0, 0.35, 0, m);
    box('t', 1.15, 0.25, 0.85, r, 0, 0.8, 0, m);
    box('f', 0.12, 0.3, 0.05, r, 0, 0.45, 0.42, mat('#f5b301', { emissive: 0.4 }));
    return r;
  };
  B.nuvem = function (o) {
    var r = root('nuvem'); var m = mat(o.cor, { emissive: 0.35 });
    sph('a', 2.2, r, 0, 0, 0, m, 8); sph('b', 1.6, r, 1.3, -0.15, 0.2, m, 8); sph('c', 1.5, r, -1.2, -0.2, -0.2, m, 8);
    return r;
  };
  B.sol = function (o) {
    var r = root('sol'); sph('s', 2.6, r, 0, 0, 0, mat(o.cor, { emissive: 1 }), 12); return r;
  };
  B.lua = function (o) {
    var r = root('lua'); sph('l', 2.0, r, 0, 0, 0, mat(o.cor, { emissive: 0.55 }), 12); return r;
  };
  B.estrela = function (o) {
    var r = root('estrela'); var m = poly('e', 0, 0.55, r, 0, 1.2, 0, mat(o.cor, { emissive: 1 })); m.metadata = { spin: 0.01 }; return r;
  };
  B.banco = function (o) {
    var r = root('banco'); var m = mat(o.cor);
    box('a', 1.8, 0.12, 0.6, r, 0, 0.55, 0, m);
    box('e', 1.8, 0.5, 0.1, r, 0, 0.85, -0.28, m);
    box('p1', 0.12, 0.55, 0.55, r, -0.75, 0.27, 0, m);
    box('p2', 0.12, 0.55, 0.55, r, 0.75, 0.27, 0, m);
    return r;
  };
  B.poste = function (o) {
    var r = root('poste'); var m = mat(o.cor);
    cyl('p', 0.14, 0.18, 3.6, r, 0, 1.8, 0, m, 8);
    cyl('b', 0.5, 0.35, 0.4, r, 0, 3.7, 0, mat('#facc15', { emissive: 0.9 }), 8);
    return r;
  };
  B.placa = function (o) {
    var r = root('placa'); cyl('p', 0.09, 0.11, 1.6, r, 0, 0.8, 0, mat('#6b4423'), 6);
    box('t', 1.3, 0.7, 0.08, r, 0, 1.6, 0, mat(o.cor)); return r;
  };
  B.moinho = function (o) {
    var r = root('moinho'); var m = mat(o.cor);
    cyl('c', 1.1, 1.5, 3.4, r, 0, 1.7, 0, m, 10);
    cyl('t', 0.05, 1.6, 1.0, r, 0, 3.9, 0, mat('#7f1d1d'), 10);
    var hub = new BABYLON.TransformNode('hub', scene); hub.parent = r; hub.position.set(0, 3.1, 1.2);
    for (var i = 0; i < 4; i++) {
      var a = box('a' + i, 0.25, 2.4, 0.06, hub, 0, 0, 0, mat('#f8fafc'));
      a.rotation.z = i * Math.PI / 2;
      a.position.set(Math.cos(i * Math.PI / 2) * 1.2, Math.sin(i * Math.PI / 2) * 1.2, 0);
    }
    hub.metadata = { spinY: 0.01 };
    return r;
  };
  B.poco = function (o) {
    var r = root('poco'); var m = mat(o.cor);
    cyl('a', 1.5, 1.5, 0.9, r, 0, 0.45, 0, m, 12);
    cyl('b', 1.7, 1.7, 0.12, r, 0, 0.95, 0, m, 12);
    box('p1', 0.12, 1.4, 0.12, r, -0.7, 1.6, 0, mat('#6b4423'));
    box('p2', 0.12, 1.4, 0.12, r, 0.7, 1.6, 0, mat('#6b4423'));
    box('t', 1.9, 0.12, 1.2, r, 0, 2.3, 0, mat('#7f1d1d'));
    return r;
  };
  B.boneco_neve = function (o) {
    var r = root('boneco_neve'); var m = mat(o.cor);
    sph('a', 1.1, r, 0, 0.55, 0, m, 10); sph('b', 0.8, r, 0, 1.35, 0, m, 10); sph('c', 0.58, r, 0, 2.0, 0, m, 10);
    cyl('n', 0.06, 0.1, 0.35, r, 0, 2.02, 0.3, mat('#f97316'), 5).rotation.x = Math.PI / 2;
    return r;
  };
  B.piramide = function (o) {
    var r = root('piramide'); cyl('p', 0.05, 7, 5, r, 0, 2.5, 0, mat(o.cor), 4).rotation.y = Math.PI / 4; return r;
  };
  B.estatua = function (o) {
    var r = root('estatua'); var m = mat(o.cor);
    box('b', 1.4, 0.4, 1.4, r, 0, 0.2, 0, m);
    cyl('c', 0.55, 0.7, 2.4, r, 0, 1.6, 0, m, 9);
    sph('h', 0.62, r, 0, 3.05, 0, m, 9);
    return r;
  };
  B.cogumelo = function (o) {
    var r = root('cogumelo'); cyl('c', 0.28, 0.36, 0.8, r, 0, 0.4, 0, mat('#f8fafc'), 8);
    var cap = sph('t', 1.1, r, 0, 0.85, 0, mat(o.cor), 9); cap.scaling.y = 0.55;
    return r;
  };
  B.arbusto = function (o) {
    var r = root('arbusto'); sph('a', 1.1, r, 0, 0.5, 0, mat(o.cor), 7); sph('b', 0.8, r, 0.5, 0.35, 0.2, mat(o.cor), 7);
    return r;
  };
  B.bola_fogo = function (o) { return B.tocha(o); };

  /* ---------------- construtor UNIVERSAL: qualquer coisa 3D ---------------- */
  B.livre = function (o) {
    o = o || {};
    var forma = o.forma || 'box';
    var r = root(o.nome || 'livre');
    var m = mat(o.cor || '#7dd3fc', { spec: 0.35 });
    if (forma === 'sphere') {
      sph('g', 1.2, r, 0, 0.6, 0, m, 14);
    } else if (forma === 'cylinder') {
      cyl('g', 0.9, 0.9, 1.4, r, 0, 0.7, 0, m, 14);
    } else if (forma === 'cone') {
      cyl('g', 0.04, 1.2, 1.6, r, 0, 0.8, 0, m, 14);
    } else if (forma === 'torus') {
      torus('g', 1.4, 0.38, r, 0, 0.7, 0, m);
    } else if (forma === 'pyramid') {
      var pg = cyl('g', 0.04, 1.5, 1.5, r, 0, 0.75, 0, m, 4);
      pg.rotation.y = Math.PI / 4;
    } else if (forma === 'poly') {
      poly('g', 2, 0.72, r, 0, 0.8, 0, m);
    } else if (forma === 'capsule') {
      var cg = BABYLON.MeshBuilder.CreateCapsule('g', { height: 1.7, radius: 0.45, tessellation: 12 }, scene);
      cg.position.set(0, 0.85, 0); cg.material = m; cg.parent = r;
    } else if (forma === 'plane') {
      box('g', 1.7, 1.25, 0.09, r, 0, 0.95, 0, m);
    } else {
      box('g', 1.3, 1.3, 1.3, r, 0, 0.65, 0, m);
    }
    return r;
  };

  /* forma usada por entidades do catálogo que não têm construtor dedicado */
  B.formaDe = {
    predio: 'box', guarda_roupa: 'box', geladeira: 'box', tv: 'box', monitor: 'box',
    livro: 'box', mesa: 'box', cadeira: 'box', cama: 'box', sofa: 'box', estante: 'box',
    parede: 'box', arma: 'box', escudo: 'box', espada: 'box', tanque: 'box', onibus: 'box',
    trem: 'box', aviao: 'box', helicoptero: 'box', nave_mae: 'box', satelite: 'box',
    plataforma: 'box', rampa: 'box', escada: 'box', quadro: 'box', tela: 'box',
    diamante: 'poly', prisma: 'poly', dado: 'poly', cristal: 'poly',
    capsula: 'capsule', ovo: 'sphere', planeta: 'sphere', asteroide: 'poly',
    donut: 'torus', roda: 'torus', anel: 'torus',
    barril: 'cylinder', cano: 'cylinder', poste: 'cylinder', fonte: 'cylinder',
    piramide: 'pyramid', montanha: 'cone', arvore: 'cone', pinheiro: 'cone',
    tapete: 'plane', folha: 'plane', bandeira: 'plane', alvo: 'plane',
    mola: 'cylinder', trofeu: 'cylinder', coracao: 'sphere', caveira: 'sphere',
    slime: 'sphere', golem: 'box', esqueleto: 'box', mumia: 'box',
    fantasma: 'sphere', zumbi: 'box', vampiro: 'box', bruxa: 'cone', mago: 'cone',
    cavaleiro: 'box', soldado: 'box', ninja: 'box', pirata: 'box', sereia: 'cone',
    fada: 'sphere', anjo: 'box', demonio: 'box', alien: 'sphere', monstro: 'box',
    tigre: 'box', leao: 'box', elefante: 'box', cavalo: 'box', vaca: 'box',
    porco: 'box', galinha: 'sphere', pato: 'sphere', peixe: 'box', tubarao: 'cone',
    polvo: 'sphere', passaro: 'sphere', coruja: 'sphere', morcego: 'box',
    abelha: 'sphere', borboleta: 'plane', aranha: 'sphere', cobra: 'cylinder',
    sapo: 'sphere', tartaruga: 'sphere', macaco: 'box', urso: 'box', lobo: 'box',
    raposa: 'box', coelho: 'sphere', pinguim: 'box', baleia: 'box',
    bicicleta: 'torus', moto: 'box', canhao: 'cylinder'
  };

  /* tamanho aproximado de cada objeto, usado para espaçar no anel */
  B.tam = {
    casa: 3.2, castelo: 5, torre: 2.2, moinho: 2.8, montanha: 5, piramide: 4,
    estatua: 2, portal: 2.6, ilha: 4.5, lago: 4.5, nave: 2.4, carro: 2,
    caminhao: 2.6, dinossauro: 2.4, dragao: 2.6, arvore: 1.8, pinheiro: 1.8,
    palmeira: 1.8, ponte: 3, estrada: 3, cerca: 2, banco: 1.6, poste: 1.4,
    placa: 1.6, poco: 2, bau: 1.2, pedra: 1.6, cristal: 1.6, tocha: 1.2
  };

  /* ---------------- estado do mundo ---------------- */
  var WORLD = {
    objects: [], coins: [], score: 0, physics: false, preset: null,
    terrain: 'grama', weather: 'dia', quality: tier, ball: null
  };
  var seq = 0;
  var animated = [];

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
  function buildWorld(preset) {
    if (preset === 'surpresa' || !preset) {
      var _opts = ['plataforma', 'corrida', 'labirinto', 'fazenda', 'cidade', 'ilha', 'floresta', 'deserto', 'espaco', 'medieval'];
      preset = _opts[Math.floor(Math.random() * _opts.length)];
    }
    clearWorld();
    WORLD.preset = preset;
    var i;
    if (preset === 'plataforma') {
      setTerrain('grama'); setWeather('dia');
      for (i = 0; i < 7; i++) {
        var h = 0.6 + i * 0.75;
        var p = spawn('cubo', { x: (i % 2 ? 2.2 : -2.2), z: -3 - i * 3.2, cor: '#334155', escala: 1 });
        var rec = WORLD.objects[WORLD.objects.length - 1];
        rec.node.position.y = h; rec.node.scaling.set(1.9, 0.35, 1.9);
        spawn('moeda', { x: (i % 2 ? 2.2 : -2.2), z: -3 - i * 3.2, cor: '#f5b301', escala: 1 }).ok && (WORLD.objects[WORLD.objects.length - 1].node.position.y = h + 1.2);
      }
      spawn('portal', { x: 0, z: -26, cor: '#8b5cf6' });
      spawn('arvore', { x: -9, z: -6, cor: '#22c55e', n: 4 });
      spawn('arvore', { x: 9, z: -10, cor: '#22c55e', n: 4 });
    } else if (preset === 'corrida') {
      setTerrain('asfalto'); setWeather('por_do_sol');
      spawn('estrada', { x: 0, z: 0, cor: '#334155' });
      spawn('carro', { x: -1.6, z: 4, cor: '#e63946', escala: 1 });
      spawn('carro', { x: 1.6, z: -2, cor: '#3b82f6', escala: 1 });
      spawn('carro', { x: -1.6, z: -8, cor: '#facc15', escala: 1 });
      spawn('poste', { x: -4.4, z: 6, cor: '#94a3b8', n: 4 });
      spawn('poste', { x: 4.4, z: 6, cor: '#94a3b8', n: 4 });
      spawn('arvore', { x: -12, z: 0, cor: '#22c55e', n: 6 });
      spawn('arvore', { x: 12, z: 0, cor: '#22c55e', n: 6 });
    } else if (preset === 'labirinto') {
      setTerrain('madeira'); setWeather('dia');
      for (i = 0; i < 6; i++) {
        spawn('cubo', { x: -7 + i * 2.8, z: -8, cor: '#8b5e34' });
        WORLD.objects[WORLD.objects.length - 1].node.scaling.set(2.6, 2.2, 0.4);
        WORLD.objects[WORLD.objects.length - 1].node.position.y = 1.1;
      }
      for (i = 0; i < 5; i++) {
        spawn('cubo', { x: -7, z: -8 + i * 3.2, cor: '#8b5e34' });
        WORLD.objects[WORLD.objects.length - 1].node.scaling.set(0.4, 2.2, 3);
        WORLD.objects[WORLD.objects.length - 1].node.position.y = 1.1;
      }
      spawn('moeda', { x: 6, z: -12, cor: '#f5b301', n: 5 });
      spawn('portal', { x: 6, z: -16, cor: '#22d3ee' });
    } else if (preset === 'fazenda') {
      setTerrain('grama'); setWeather('dia');
      spawn('casa', { x: -4, z: -4, cor: '#e7d3b3' });
      spawn('cerca', { x: 4, z: 2, cor: '#8b5e34' });
      spawn('cerca', { x: 4, z: -4, cor: '#8b5e34' });
      spawn('poco', { x: -8, z: 3, cor: '#94a3b8' });
      spawn('moinho', { x: 9, z: -8, cor: '#e7d3b3' });
      spawn('cachorro', { x: 1, z: 2, cor: '#8b5e34' });
      spawn('arvore', { x: 10, z: 4, cor: '#22c55e', n: 5 });
      spawn('cogumelo', { x: -10, z: -6, cor: '#e63946', n: 3 });
    } else if (preset === 'cidade') {
      setTerrain('asfalto'); setWeather('noite');
      for (i = 0; i < 8; i++) {
        spawn('casa', { x: (i % 2 ? 8 : -8), z: -14 + i * 4.2, cor: i % 2 ? '#e7d3b3' : '#cbd5e1' });
        WORLD.objects[WORLD.objects.length - 1].node.scaling.setAll(1.6);
      }
      spawn('torre', { x: 0, z: -18, cor: '#cbd5e1', escala: 1.8 });
      spawn('poste', { x: -5, z: 2, cor: '#94a3b8', n: 3 });
      spawn('poste', { x: 5, z: 2, cor: '#94a3b8', n: 3 });
      spawn('carro', { x: 0, z: 4, cor: '#facc15' });
    } else if (preset === 'ilha') {
      setTerrain('agua'); setWeather('dia');
      spawn('ilha', { x: 0, z: 0, cor: '#e7d3b3', escala: 1.6 });
      spawn('palmeira', { x: 4, z: 3, cor: '#22c55e', n: 5 });
      spawn('pedra', { x: -5, z: -3, cor: '#94a3b8', n: 3 });
      spawn('bau', { x: 1, z: -2, cor: '#8b5e34' });
    } else if (preset === 'floresta') {
      setTerrain('grama'); setWeather('neblina');
      spawn('arvore', { x: 0, z: 0, cor: '#22c55e', n: 18 });
      spawn('pinheiro', { x: 0, z: 0, cor: '#166534', n: 10 });
      spawn('pedra', { x: 0, z: 0, cor: '#94a3b8', n: 5 });
      spawn('cogumelo', { x: 0, z: 0, cor: '#e63946', n: 6 });
      spawn('tocha', { x: 2, z: 2, cor: '#f97316' });
    } else if (preset === 'deserto') {
      setTerrain('areia'); setWeather('por_do_sol');
      spawn('piramide', { x: -6, z: -8, cor: '#e7d3b3', escala: 1.6 });
      spawn('piramide', { x: 6, z: -12, cor: '#e7d3b3', escala: 1.2 });
      spawn('palmeira', { x: 3, z: 4, cor: '#22c55e', n: 3 });
      spawn('pedra', { x: 0, z: 0, cor: '#d9c08a', n: 6 });
    } else if (preset === 'espaco') {
      setTerrain('asfalto'); setWeather('noite');
      spawn('estrela', { x: 0, z: 0, cor: '#facc15', n: 14 });
      WORLD.objects.slice(-14).forEach(function (o) { o.node.position.y = 8 + Math.random() * 12; o.node.position.x = (Math.random() - 0.5) * 50; o.node.position.z = (Math.random() - 0.5) * 50; });
      spawn('nave', { x: 0, z: -4, cor: '#cbd5e1', escala: 1.4 });
      spawn('pedra', { x: -10, z: -14, cor: '#94a3b8', n: 4 });
    } else if (preset === 'medieval') {
      setTerrain('grama'); setWeather('nublado');
      spawn('castelo', { x: 0, z: -12, cor: '#cbd5e1', escala: 1.5 });
      spawn('casa', { x: -8, z: 2, cor: '#e7d3b3', n: 3 });
      spawn('tocha', { x: -2, z: 0, cor: '#f97316', n: 4 });
      spawn('ponte', { x: 8, z: 2, cor: '#8b5e34' });
      spawn('arvore', { x: -14, z: -4, cor: '#22c55e', n: 5 });
    }
    updateScore();
    saveScene();
    return { ok: true, preset: preset };
  }

  /* ---------------- persistência local ---------------- */
  var saveTimer = null;
  function saveScene() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      var data = {
        preset: WORLD.preset, terrain: WORLD.terrain, weather: WORLD.weather,
        objects: WORLD.objects.map(function (o) {
          return { ent: o.ent, cor: o.cor, x: +o.node.position.x.toFixed(2), z: +o.node.position.z.toFixed(2), s: +o.base.s.toFixed(2), y: +o.node.position.y.toFixed(2), ry: +o.node.rotation.y.toFixed(2), nome: o.nome || o.ent, forma: o.forma || '' };
        }),
        quando: new Date().toISOString()
      };
      try { localStorage.setItem('amx3d_cena', JSON.stringify(data)); } catch (e) {}
      try {
        fetch('?api=salvar', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ cena: data }) });
      } catch (e) {}
      if (window.MP && window.MP.active) { try { window.MP.saveWorld(); } catch (e) {} }
    }, 1200);
  }

  function restoreScene(data) {
    if (!data || !Array.isArray(data.objects) || !data.objects.length) return false;
    clearWorld();
    if (data.terrain) setTerrain(data.terrain);
    if (data.weather) setWeather(data.weather);
    data.objects.forEach(function (o) {
      var r = spawn(o.ent, { cor: o.cor, x: o.x, z: o.z, escala: o.s || 1, forma: o.forma, nome: o.nome, y: o.y || 0 });
      if (r.ok) {
        var rec = WORLD.objects[WORLD.objects.length - 1];
        rec.node.position.y = Number(o.y || 0);
        rec.node.rotation.y = Number(o.ry || 0);
      }
    });
    WORLD.preset = data.preset || null;
    return true;
  }

  /* ---------------- loop ---------------- */
  var lastT = performance.now(), fpsAcc = 0, fpsN = 0, lowStreak = 0, highStreak = 0;
  scene.registerBeforeRender(function () {
    var dt = Math.min(0.05, (performance.now() - lastT) / 1000); lastT = performance.now();

    /* movimento do jogador (teclado + botões virtuais) */
    var node = player.node || ball;
    var mx = 0, mz = 0;
    if (keys['w'] || keys['arrowup'] || moveInput.f) mz += 1;
    if (keys['s'] || keys['arrowdown'] || moveInput.b) mz -= 1;
    if (keys['a'] || keys['arrowleft'] || moveInput.l) mx -= 1;
    if (keys['d'] || keys['arrowright'] || moveInput.r) mx += 1;
    var moving = !!(mx || mz);
    if (moving) {
      /* direção relativa à câmera */
      var camA = camera.alpha;
      var dirX = Math.sin(camA) * mz + Math.cos(camA) * mx;
      var dirZ = Math.cos(camA) * mz - Math.sin(camA) * mx;
      var len = Math.sqrt(dirX * dirX + dirZ * dirZ) || 1;
      dirX /= len; dirZ /= len;
      var spd = (player.run ? 13.5 : player.speed) * dt;
      ballTarget.x += dirX * spd;
      ballTarget.z += dirZ * spd;
      var targetRot = Math.atan2(dirX, dirZ);
      node.rotation.y += (targetRot - node.rotation.y) * Math.min(1, dt * 10);
    }
    ballTarget.x = BABYLON.Scalar.Clamp(ballTarget.x, -70, 70);
    ballTarget.z = BABYLON.Scalar.Clamp(ballTarget.z, -70, 70);
    node.position.x += (ballTarget.x - node.position.x) * Math.min(1, dt * 9);
    node.position.z += (ballTarget.z - node.position.z) * Math.min(1, dt * 9);

    /* gravidade / pulo */
    if (!player.grounded) {
      player.vy -= 18 * dt;
      player.y += player.vy * dt;
      if (player.y <= 0) { player.y = 0; player.vy = 0; player.grounded = true; }
    }
    node.position.y = player.y + (player.isCharacter ? 0 : 0.58 + Math.sin(performance.now() / 420) * 0.06);

    /* animação de caminhada do personagem */
    if (player.isCharacter && node.metadata && node.metadata.humanoid) {
      var tt = performance.now() / 1000;
      var amp = moving ? (player.run ? 0.95 : 0.55) : 0;
      var swing = Math.sin(tt * (player.run ? 14 : 9)) * amp;
      var parts = node.getChildMeshes();
      for (var pi = 0; pi < parts.length; pi++) {
        var pn = parts[pi].name;
        if (pn === 'pernaE') parts[pi].rotation.x = swing;
        else if (pn === 'pernaD') parts[pi].rotation.x = -swing;
        else if (pn === 'bracoE') parts[pi].rotation.x = -swing * 0.8;
        else if (pn === 'bracoD') parts[pi].rotation.x = swing * 0.8;
      }
    }

    /* câmera segue o jogador */
    var camLerp = player.mode === 'shooter' ? 14 : 2.6;
    var camY = node.position.y + (player.mode === 'shooter' ? 1.65 : 1.2);
    camera.target.x += (node.position.x - camera.target.x) * Math.min(1, dt * camLerp);
    camera.target.z += (node.position.z - camera.target.z) * Math.min(1, dt * camLerp);
    camera.target.y += (camY - camera.target.y) * Math.min(1, dt * camLerp);

    /* NPC seguidor */
    tickNPC(dt);

    /* multiplayer: envia posição e atualiza jogadores remotos */
    if (window.MP && window.MP.active) window.MP.tick(dt, node);

    /* objetos caindo (gravidade simples) */
    if (WORLD.physics) {
      for (var i = 0; i < WORLD.objects.length; i++) {
        var o = WORLD.objects[i];
        if (o.node.position.y > 0) {
          o.node.position.y = Math.max(0, o.node.position.y - 9 * dt);
        }
      }
    }

    /* moedas */
    for (var c = WORLD.coins.length - 1; c >= 0; c--) {
      var coin = WORLD.coins[c];
      if (!coin || coin.isDisposed) { WORLD.coins.splice(c, 1); continue; }
      coin.rotation.y += dt * 3;
      var p = coin.getAbsolutePosition();
      var pnode = player.node || ball;
      var dx = p.x - pnode.position.x, dz = p.z - pnode.position.z, dy = p.y - (pnode.position.y + 0.6);
      if (dx * dx + dz * dz + dy * dy < 2.2) {
        coin.dispose();
        WORLD.coins.splice(c, 1);
        WORLD.score++;
        updateScore();
      }
    }

    /* animações leves */
    if (tier !== 'low') {
      for (var a = 0; a < animated.length; a++) {
        var an = animated[a];
        if (an.m && !an.m.isDisposed) an.m.rotation.y += an.v;
      }
    }

    /* watchdog de FPS */
    fpsAcc += engine.getFps(); fpsN++;
    if (fpsN >= 120) {
      var avg = fpsAcc / fpsN; fpsAcc = 0; fpsN = 0;
      if (avg < 38) {
        lowStreak++; highStreak = 0;
        if (lowStreak >= 2) {
          var lvl = engine.getHardwareScalingLevel();
          if (lvl < 2.2) engine.setHardwareScalingLevel(Math.min(2.2, lvl + 0.2));
          if (weatherPS) weatherPS.maxParticles = Math.floor(weatherPS.maxParticles * 0.8);
          lowStreak = 0;
        }
      } else if (avg > 55) {
        highStreak++; lowStreak = 0;
        if (highStreak >= 4) {
          var lv = engine.getHardwareScalingLevel();
          if (lv > T.scaling) engine.setHardwareScalingLevel(Math.max(T.scaling, lv - 0.1));
          highStreak = 0;
        }
      }
    }
  });

  engine.runRenderLoop(function () { scene.render(); });
  window.addEventListener('resize', function () { engine.resize(); });
  window.addEventListener('orientationchange', function () { setTimeout(function () { engine.resize(); }, 250); });

  /* ---------------- NPC AMHEEX (segue o jogador, anda e pula) ---------------- */
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
    if (camera) { camera.beta = 1.18; camera.radius = 5.4; camera.alpha = Math.PI / 2 + 0.35; }
    return r;
  }
  window.AMX_PREVIEW = previewSkin;

  /* ---------------- início do jogo ---------------- */
  function startGame(nome) {
    var n = (nome || '').trim() || MP.nome();
    try { localStorage.setItem('amx_nome', n); } catch (e) {}
    MP.name = n;
    if (!player.isCharacter) {
      createAvatar({ t: 'character', nome: n, pele: player.skin.pele, cor: player.skin.cor, seed: player.skin.seed, x: 0, z: 0 });
    }
    setControlMode('third');
    if (!npc) spawnNPC('AMHEEX BOT');
    MP._quiet = true;
    setTimeout(function () { MP._quiet = false; }, 2600);
    MP.enable(true);
    var hud = $('rblx'); if (hud) hud.classList.add('on');
    var u = $('rbUser'); if (u) u.textContent = n;
    return { ok: true, nome: n };
  }
  window.AMX_START = startGame;

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

  MP.renderChat = function () {
    var box = $('rbChatLog');
    if (!box) return;
    var html = '';
    var arr = MP.chat || [];
    for (var i = Math.max(0, arr.length - 40); i < arr.length; i++) {
      var c = arr[i];
      var me = c.id && c.id === MP.id;
      html += '<div class="m' + (me ? ' me' : '') + '"><b>' + (c.name || 'Jogador') + ':</b> ' + (c.msg || '') + '</div>';
    }
    box.innerHTML = html || '<div class="sys">Ninguém falou nada ainda. Diga oi!</div>';
    box.scrollTop = box.scrollHeight;
    var cc = $('rbChatCount'); if (cc) cc.textContent = arr.length;
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
})();
