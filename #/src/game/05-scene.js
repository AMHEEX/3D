/* AMHEEX GAME — cena: presets de mundo, salvar e restaurar. */
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
    /* mundo infinito: o mapa não tem borda, só um limite técnico bem largo */
    ballTarget.x = BABYLON.Scalar.Clamp(ballTarget.x, -560, 560);
    ballTarget.z = BABYLON.Scalar.Clamp(ballTarget.z, -560, 560);
    node.position.x += (ballTarget.x - node.position.x) * Math.min(1, dt * 9);
    node.position.z += (ballTarget.z - node.position.z) * Math.min(1, dt * 9);

    /* gravidade / pulo */
    if (!player.grounded) {
      player.vy -= 18 * dt;
      player.y += player.vy * dt;
      if (player.y <= 0) { player.y = 0; player.vy = 0; player.grounded = true; }
    }
    node.position.y = player.y + (player.isCharacter ? 0 : 0.58 + Math.sin(performance.now() / 420) * 0.06);

    /* ---- animação humana: passada com joelhos, braços, tronco e respiração ---- */
    if (player.isCharacter && node.metadata && node.metadata.humanoid) {
      var tt = performance.now() / 1000;
      var J = node.metadata.joints || {};
      var vel = moving ? (player.run ? 1.7 : 1.0) : 0;
      var step = tt * (player.run ? 11.5 : 7.4);
      var s = Math.sin(step), c = Math.cos(step);
      var amp = vel * (player.run ? 0.85 : 0.55);
      var idle = Math.sin(tt * 1.9);

      if (J.hipE) J.hipE.rotation.x = s * amp;
      if (J.hipD) J.hipD.rotation.x = -s * amp;
      if (J.kneeE) J.kneeE.rotation.x = Math.max(0, -c) * amp * 1.2;
      if (J.kneeD) J.kneeD.rotation.x = Math.max(0, c) * amp * 1.2;
      if (J.ankleE) J.ankleE.rotation.x = -s * amp * 0.35;
      if (J.ankleD) J.ankleD.rotation.x = s * amp * 0.35;

      if (J.shE) { J.shE.rotation.x = -s * amp * 0.85; J.shE.rotation.z = 0.06 + idle * 0.012; }
      if (J.shD) { J.shD.rotation.x = s * amp * 0.85; J.shD.rotation.z = -0.06 - idle * 0.012; }
      if (J.elbE) J.elbE.rotation.x = -0.18 - Math.max(0, -s) * amp * 0.75;
      if (J.elbD) J.elbD.rotation.x = -0.18 - Math.max(0, s) * amp * 0.75;
      if (J.handE) J.handE.rotation.x = -0.08;
      if (J.handD) J.handD.rotation.x = -0.08;

      /* tronco e cabeça (cache dos meshes para não varrer a árvore todo frame) */
      if (!node.metadata._torso) {
        var kids = node.getChildMeshes();
        for (var ki = 0; ki < kids.length; ki++) {
          if (kids[ki].name === 'torso') node.metadata._torso = kids[ki];
          if (kids[ki].name === 'cabeca') node.metadata._cabeca = kids[ki];
        }
      }
      if (node.metadata._torso) node.metadata._torso.rotation.y = s * amp * 0.10;
      if (node.metadata._cabeca) node.metadata._cabeca.rotation.y = -s * amp * 0.06;
      node.position.y += Math.abs(c) * amp * 0.055 + (moving ? 0 : idle * 0.008);
    }

    /* câmera: segue com amortecimento e se alinha atrás do personagem ao andar */
    var camLerp = player.mode === 'shooter' ? 14 : 4.2;
    var camY = node.position.y + (player.mode === 'shooter' ? 1.65 : 1.35);
    camera.target.x += (node.position.x - camera.target.x) * Math.min(1, dt * camLerp);
    camera.target.z += (node.position.z - camera.target.z) * Math.min(1, dt * camLerp);
    camera.target.y += (camY - camera.target.y) * Math.min(1, dt * camLerp);
    if (moving && player.mode === 'third' && performance.now() > (window.__camManual || 0)) {
      var want = node.rotation.y - Math.PI / 2;
      var dif = Math.atan2(Math.sin(want - camera.alpha), Math.cos(want - camera.alpha));
      camera.alpha += dif * Math.min(1, dt * 1.5);
    }

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
