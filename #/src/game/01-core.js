/* AMHEEX GAME — núcleo: qualidade adaptativa, materiais e helpers de malha. */
  if (!window.BABYLON) {
    var _h = document.getElementById('hint');
    if (_h) _h.textContent = 'Babylon.js não carregou (lib/babylon.js).';
    console.error('Babylon.js não carregou (lib/babylon.js).');
    return;
  }

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
