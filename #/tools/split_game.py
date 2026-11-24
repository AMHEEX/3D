#!/usr/bin/env python3
"""Divide src/game.js em módulos por responsabilidade, preservando o escopo
compartilhado (o build.sh embrulha todos em um único IIFE)."""
import os, re

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
src = os.path.join(BASE, 'src', 'game.js')
lines = open(src, encoding='utf-8').read().split('\n')

# cortes: (linha_inicial_1based, arquivo)
cuts = [
    (1,    '01-core.js'),
    (77,   '02-build.js'),
    (653,  '03-world.js'),
    (953,  '04-player.js'),
    (1229, '05-scene.js'),
    (1497, '06-actors.js'),
    (1625, '07-loop.js'),
]
outdir = os.path.join(BASE, 'src', 'game')
os.makedirs(outdir, exist_ok=True)

# valida que cada corte começa numa declaração de função de nível superior
for ln, name in cuts[1:]:
    assert re.match(r'^  function ', lines[ln - 1]), (ln, lines[ln - 1][:60])

# remove o cabeçalho do IIFE (linhas 6-7: "(function () {" e "'use strict';")
body_start = 0
for i, l in enumerate(lines[:15]):
    if l.strip().startswith("'use strict'"):
        body_start = i + 1
        break
# e o fechamento final "})();"
body_end = len(lines)
for i in range(len(lines) - 1, len(lines) - 12, -1):
    if lines[i].strip() == '})();':
        body_end = i
        break

lines = lines[:body_end]
# zera as linhas do cabeçalho dentro do primeiro módulo
for i in range(body_start):
    lines[i] = ''

header = {
    '01-core.js': '/* AMHEEX GAME — núcleo: qualidade adaptativa, materiais e helpers de malha. */\n',
    '02-build.js': '/* AMHEEX GAME — construtor procedural: primitivas, personagem humanoide e texturas de skin. */\n',
    '03-world.js': '/* AMHEEX GAME — mundo: entidades, terreno, céu, clima e partículas. */\n',
    '04-player.js': '/* AMHEEX GAME — jogador: controles, câmera, pulo, tiro, layout e avatar. */\n',
    '05-scene.js': '/* AMHEEX GAME — cena: presets de mundo, salvar e restaurar. */\n',
    '06-actors.js': '/* AMHEEX GAME — atores: NPC seguidor, skins e início de jogo. */\n',
    '07-loop.js': '/* AMHEEX GAME — laço: animação, câmera, multiplayer e inicialização. */\n',
}

for idx, (start, name) in enumerate(cuts):
    end = cuts[idx + 1][0] - 1 if idx + 1 < len(cuts) else len(lines)
    chunk = lines[start - 1:end]
    # remove linhas vazias do topo
    while chunk and not chunk[0].strip():
        chunk.pop(0)
    path = os.path.join(outdir, name)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(header[name] + '\n'.join(chunk).rstrip() + '\n')
    print(name, len(chunk), 'linhas')
