#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"
{
  cat src/top.php
  cat src/head.html
  echo '<script src="lib/babylon.js"></script>'
  echo '<script src="lib/babylonjs.loaders.min.js"></script>'
  echo '<script>'
  cat src/game.js
  echo '</script>'
  echo '<script>'
  cat src/ui.js
  echo '</script>'
  echo '<script>'
  cat src/ai.js
  echo '</script>'
  echo '</body>'
  echo '</html>'
} > index.php
php -l index.php
