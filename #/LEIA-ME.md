# AMHEEX GAME — mundo 3D local com AMHEEX AI

**AMHEEX GAME** é um jogo 3D que roda inteiro no seu servidor (PHP + Babylon.js
local). A inteligência do jogo é a **AMHEEX AI**: ela entende português, cria
**qualquer coisa 3D** (prédios, casas, móveis, animais, personagens, objetos
inventados), monta **elementos de tela** (título, contador, mira, barra de vida),
gera **personagens realistas com skin procedural**, adiciona **controles**
(andar, correr, pular, câmera em 1ª/3ª pessoa, modo tiro) e ativa o
**multiplayer por IP com mapa compartilhado e chat**.

O estado inicial é **fixo e limpo**: só o chão verde, a bola vermelha e o ícone
flutuante da AMHEEX AI. Nada mais aparece até você pedir (ex.: **“crie game”**).

A AMHEEX AI é **local e adaptativa**: ela guarda o que você ensina em
`ia/aprendizado.json`, consulta a base `ia/knowledge.json` e nunca depende de um
LLM externo. A voz usa a **API Fish Audio** com a voz clonada da AMHEEX (chave
mantida no servidor), com fallback para `espeak-ng` e para o navegador.

---

## 1. O que tem dentro

```
#/
├── index.php                  ← aplicação inteira (PHP + HTML + CSS + JS)
├── build.sh                   ← junta src/ em index.php
├── src/
│   ├── top.php                ← API PHP (chat, TTS, multiplayer, treino)
│   ├── head.html              ← HTML + CSS da interface
│   ├── game.js                ← motor do mundo (Babylon.js) + AMHEEX AI actions
│   └── ai.js                  ← ícone flutuante, chat, voz e STT
├── ia/
│   ├── engine.php             ← motor de linguagem natural local (português)
│   ├── modelo.json            ← "cérebro": entidades, cores, sinônimos, presets
│   ├── knowledge.json         ← base de conhecimento de jogos/3D
│   ├── memoria.json           ← memória da IA (nome, cor favorita, histórico)
│   ├── aprendizado.json       ← tudo que o usuário ensinou (fatos/notas)
│   ├── multiplayer.json       ← jogadores, chat e revisão do mundo
│   ├── config.php             ← configuração da voz Fish (server-side)
│   ├── voz_lib.php            ← geração/cache de voz (OGG Opus + MP3)
│   ├── voz.php                ← endpoint TTS independente
│   └── cenas/
│       ├── cena.json          ← cena salva no servidor
│       └── mundo.json         ← mapa compartilhado do multiplayer
├── lib/
│   ├── babylon.js             ← Babylon.js 8.x (local)
│   └── babylonjs.loaders.min.js
├── assets/
│   └── ia-icon.png            ← ícone flutuante da AMHEEX AI
├── dados/voz/                 ← cache dos áudios de voz (OGG/MP3)
├── uploads/                   ← imagens/vídeos/modelos enviados pelo usuário
└── LEIA-ME.md
```

Nada é carregado de CDN. Se o servidor não tiver internet, o jogo continua
funcionando (a voz cai para o navegador).

---

## 2. Requisitos

- **PHP 8.0+** com `json`, `mbstring` e `curl`.
- Permissão de escrita em `ia/`, `ia/cenas/`, `dados/voz/` e `uploads/`.
- **ffmpeg** para converter a voz Fish em OGG Opus (recomendado).
- **espeak-ng** como fallback de voz local (opcional).
- Navegador com WebGL.

```bash
sudo apt-get install -y ffmpeg espeak-ng
```

---

## 3. Como rodar

```bash
cd '#'
php -S 0.0.0.0:8080
```

Abra `http://localhost:8080/`. Para reconstruir o `index.php` depois de editar
`src/`:

```bash
./build.sh
```

---

## 4. Voz da AMHEEX AI (Fish Audio)

A chave e a voz ficam em `ia/config.php` e podem ser trocadas por variáveis de
ambiente no Render:

| Variável        | Padrão                                  |
|-----------------|-----------------------------------------|
| `FISH_API_KEY`  | chave fornecida (server-side)           |
| `FISH_VOICE_ID` | `beb5077c45e141118fc52be3d2b3c9cf`      |
| `FISH_MODEL`    | `s2.1-pro-free`                         |

Endpoints:

- `?api=tts&text=...` — voz usada pelo chat (OGG Opus, cache MD5).
- `?api=ping` — status do motor, da voz e do multiplayer.
- `ia/voz.php?text=...&debug=1` — endpoint TTS independente.

---

## 5. Multiplayer por IP

Cada jogador é identificado por **IP + User-Agent** (hash SHA-256). O personagem,
a skin, a posição e o nome ficam em `ia/multiplayer.json`. O cliente faz polling
em `?api=mp_state` e envia posição em `?api=mp_move`.

| Endpoint        | Função                                        |
|-----------------|-----------------------------------------------|
| `mp_join`       | entra na partida, recebe id/IP e jogadores    |
| `mp_move`       | envia posição/rotação/animação                |
| `mp_state`      | lista jogadores, chat e revisão do mundo      |
| `mp_chat`       | envia mensagem de chat                        |
| `mp_world`      | salva/carrega o mapa compartilhado            |
| `mp_leave`      | sai da partida                                |

Jogadores inativos por mais de 25 s são removidos automaticamente.

---

## 6. O que pedir para a AMHEEX AI

- **“crie game”** — monta um jogo completo.
- **“crie um personagem realista”** — humanoide com skin procedural.
- **“adicione botão de andar para frente”** — ativa os controles na tela.
- **“câmera primeira pessoa”** / **“modo tiro”** — muda a câmera e a mira.
- **“ative o multiplayer”** — liga o modo por IP com chat.
- **“crie um mapa compartilhado de floresta”** — mapa sincronizado.
- **“aprenda que meu jogo favorito é Minecraft”** — a IA guarda o fato.
- **“o que você aprendeu?”** — lista os fatos ensinados.

---

## 7. Deploy no Render

O `Dockerfile` na raiz instala PHP 8.2 + Apache, Node 20, **ffmpeg** e
**espeak-ng**, respeita a variável `PORT` do Render e garante escrita em
`#/ia`, `#/dados` e `#/uploads`. Basta conectar o repositório e dar deploy.

---

© 2026 **AMHEEX** — Soluções em Tecnologia e Programação.
