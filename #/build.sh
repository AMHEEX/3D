#!/usr/bin/env bash
# AMHEEX GAME — monta o index.php a partir dos módulos.
# Cada sistema tem seu próprio arquivo: mundo, jogador, atores, laço,
# interface (boot/auth/hud/chat/config/voz) e IA (motor/voz).
set -e
cd "$(dirname "$0")"

{
  cat src/top.php
  cat src/head.html
  echo '<script src="lib/babylon.js"></script>'
  echo '<script src="lib/babylonjs.loaders.min.js"></script>'

  # ---- motor 3D (escopo único, um arquivo por sistema) ----
  echo '<script>'
  echo '(function () {'
  echo "  'use strict';"
  for f in src/game/*.js; do echo "/* ===== $f ===== */"; cat "$f"; done
  echo '})();'
  echo '</script>'

  # ---- interface (boot/auth/hud/chat/config/voz) ----
  echo '<script>'
  echo '(function () {'
  echo "  'use strict';"
  for f in src/ui/*.js; do echo "/* ===== $f ===== */"; cat "$f"; done
  echo '})();'
  echo '</script>'

  # ---- AMHEEX AI ----
  echo '<script>'
  echo '(function () {'
  echo "  'use strict';"
  for f in src/ai/*.js; do echo "/* ===== $f ===== */"; cat "$f"; done
  echo '})();'
  echo '</script>'

  echo '</body>'
  echo '</html>'
} > index.php

php -l index.php
