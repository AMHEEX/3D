/* AMHEEX GAME — construtor procedural: primitivas, personagem humanoide e texturas de skin. */
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

    /* ---- esqueleto com juntas reais: quadril, joelho, ombro, cotovelo ---- */
    function pivot(nome, pai, x, y, z) {
      var n = new BABYLON.TransformNode(nome, scene);
      n.parent = pai; n.position.set(x, y, z);
      return n;
    }
    var hipE = pivot('hipE', r, -.20, 1.23, 0);
    var hipD = pivot('hipD', r, .20, 1.23, 0);
    cyl('coxaE', .26, .23, .62, hipE, 0, -.31, 0, calca, S);
    cyl('coxaD', .26, .23, .62, hipD, 0, -.31, 0, calca, S);
    var kneeE = pivot('kneeE', hipE, 0, -.62, 0);
    var kneeD = pivot('kneeD', hipD, 0, -.62, 0);
    sph('joelhoE', .21, kneeE, 0, 0, 0, calca, 16);
    sph('joelhoD', .21, kneeD, 0, 0, 0, calca, 16);
    cyl('pernaE', .20, .17, .52, kneeE, 0, -.26, 0, pele, S);
    cyl('pernaD', .20, .17, .52, kneeD, 0, -.26, 0, pele, S);
    var ankleE = pivot('ankleE', kneeE, 0, -.52, 0);
    var ankleD = pivot('ankleD', kneeD, 0, -.52, 0);
    box('peE', .28, .15, .46, ankleE, 0, -.06, .09, sapato);
    box('peD', .28, .15, .46, ankleD, 0, -.06, .09, sapato);
    /* quadril e cintura */
    cyl('quadril', .58, .52, .34, r, 0, 1.20, 0, calca, S);
    cyl('cintura', .55, .57, .16, r, 0, 1.39, 0, detalhe, S);
    /* torso com peito e costas */
    cyl('torso', .63, .55, .78, r, 0, 1.80, 0, roupa, S);
    sph('peitoE', .30, r, -.17, 1.92, .16, roupa, 18).scaling.set(1, .8, .55);
    sph('peitoD', .30, r, .17, 1.92, .16, roupa, 18).scaling.set(1, .8, .55);
    /* braços: ombro -> braço -> cotovelo -> antebraço -> mão */
    var shE = pivot('ombroPivE', r, -.50, 2.05, 0);
    var shD = pivot('ombroPivD', r, .50, 2.05, 0);
    sph('ombroE', .24, shE, 0, 0, 0, roupa, 18);
    sph('ombroD', .24, shD, 0, 0, 0, roupa, 18);
    cyl('bracoE', .18, .16, .52, shE, 0, -.28, 0, pele, S);
    cyl('bracoD', .18, .16, .52, shD, 0, -.28, 0, pele, S);
    var elbE = pivot('cotoveloPivE', shE, 0, -.56, 0);
    var elbD = pivot('cotoveloPivD', shD, 0, -.56, 0);
    sph('cotoveloE', .16, elbE, 0, 0, 0, pele, 14);
    sph('cotoveloD', .16, elbD, 0, 0, 0, pele, 14);
    cyl('antebracoE', .15, .13, .46, elbE, 0, -.23, 0, pele, S);
    cyl('antebracoD', .15, .13, .46, elbD, 0, -.23, 0, pele, S);
    var handE = pivot('maoPivE', elbE, 0, -.46, 0);
    var handD = pivot('maoPivD', elbD, 0, -.46, 0);
    sph('maoE', .20, handE, 0, 0, 0, pele, 16).scaling.set(.85, 1.15, .6);
    sph('maoD', .20, handD, 0, 0, 0, pele, 16).scaling.set(.85, 1.15, .6);
    for (var f = 0; f < 4; f++) {
      cyl('dedoE' + f, .028, .024, .16, handE, -.08 + f * .055, -.14, .02, pele, 8);
      cyl('dedoD' + f, .028, .024, .16, handD, -.08 + f * .055, -.14, .02, pele, 8);
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
    r.metadata = {
      humanoid: true, seed: seed, pele: peleHex, cor: roupaHex, calca: calcaHex, cabelo: cabeloCor,
      joints: { hipE: hipE, hipD: hipD, kneeE: kneeE, kneeD: kneeD, ankleE: ankleE, ankleD: ankleD,
                shE: shE, shD: shD, elbE: elbE, elbD: elbD, handE: handE, handD: handD }
    };
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
