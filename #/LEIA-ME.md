# AMHEEX GAME — mundo 3D local com AMHEEX AI

**AMHEEX GAME** é um jogo 3D que roda inteiro no seu servidor (PHP + Babylon.js
local). A inteligência do jogo é a **AMHEEX AI**: ela entende português, cria
**qualquer coisa 3D** (prédios, casas, móveis, animais, personagens, objetos
inventados), edita o **mapa global** e o **seu personagem** — e nunca o
personagem de outro jogador.

O mundo é **único e infinito**: chão verde sob céu azul, sem borda, e a
comunidade constrói junto. Não existe mais editor de personagem na tela
inicial: você entra com **usuário e senha** e o personagem padrão da conta
aparece direto no mundo. Quem muda o visual é a IA, pelo chat.

A AMHEEX AI é **local e adaptativa**: ela guarda o que você ensina em
`ia/aprendizado.json`, consulta a base `ia/knowledge.json` e nunca depende de um
LLM externo. A voz usa a **API Fish Audio** com a voz clonada da AMHEEX (chave
mantida no servidor), com fallback para `espeak-ng` e para o navegador.

## O que mudou nesta versão (Fase 4)

- **Telas separadas**: carregamento (boot com logo + barra RGB), **login** e
  **registro** são telas independentes, cada uma com seu formulário.
- **Sem editor de personagem na entrada**: o personagem padrão da conta entra
  direto no mundo.
- **Sem botões laterais empilhados** (LOJA/SKINS/INVENTÁRIO/CONFIG./SAIR) e
  **sem menu flutuante da IA**: o ícone da IA agora está embutido no jogo.
- **Chat único com a AMHEEX AI** (sem abas “Aqui/Global”): você escreve ou cola a
  pergunta e a IA responde e executa. O microfone ativa o **modo conversa em
  tempo real** (escuta, responde e fala), não é só gravação.
- **Controles estilo Roblox**: joystick de mover à **esquerda**, botão de pulo à
  **direita**, botão de câmera e arraste para girar o ângulo.
- **Personagem com esqueleto articulado**: quadril, joelho, tornozelo, ombro,
  cotovelo, mão, tronco e cabeça animados (caminhada, corrida, respiração,
  parado), com câmera que segue e se alinha atrás do personagem.
- **Ícones SVG** no lugar de emojis em todos os botões e abas.
- **Código modular**: cada sistema tem seu próprio arquivo (motor 3D, interface
  e IA), montados pelo `build.sh`.

## Arquitetura dos arquivos

```
src/
  top.php          API PHP (login, registro, perfil, chat, tts, mp_*, rtc_*)
  head.html        CSS + HTML das telas e do HUD
  game/            motor 3D (um arquivo por sistema)
    01-core.js       qualidade adaptativa, materiais, helpers de malha
    02-build.js      construtor de personagem com juntas reais
    03-world.js      mundo, terreno, objetos, presets
    04-player.js     jogador, controles, câmera
    05-scene.js      loop de animação, câmera e mundo infinito
    06-actors.js     NPC seguidor e skin do jogador
    07-loop.js       multiplayer, chat e persistência
  ui/              interface (um arquivo por sistema)
    01-base.js       helpers, estado, boot
    02-auth.js       telas de login e registro
    03-hud.js        joystick, pulo, câmera, botões do HUD
    04-chat.js       chat único com a IA
    05-settings.js   configurações (5 abas)
    06-voice.js      voz por proximidade (WebRTC)
    07-main.js       inicialização da interface
  ai/              AMHEEX AI no cliente
    01-speech.js     voz (Fish Audio) e reconhecimento de fala
    02-engine.js     motor de conversa e aplicação de ações
build.sh           concatena tudo em index.php e valida com php -l
```

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
│   ├── ui.js                  ← boot, menu, chat, configurações e voz por proximidade
│   └── ai.js                  ← ícone flutuante, chat, voz e STT
├── ia/
│   ├── engine.php             ← motor de linguagem natural local (português)
│   ├── modelo.json            ← "cérebro": entidades, cores, sinônimos, presets
│   ├── knowledge.json         ← base de conhecimento de jogos/3D
│   ├── memoria.json           ← memória da IA (nome, cor favorita, histórico)
│   ├── aprendizado.json       ← tudo que o usuário ensinou (fatos/notas)
│   ├── multiplayer.json       ← jogadores, chat e revisão do mundo
│   ├── rtc.json               ← sinalização WebRTC da voz por proximidade
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

---

## 8. Conta, interface estilo Roblox e mundo global

### Login e registro (não é mais por IP)

O acesso agora é por **conta**: usuário e senha. Não existe mais identificação
por IP nem sistema de "upar" nível — é um mundo social estilo Roblox, onde cada
pessoa entra com seu próprio personagem.

- `?api=registrar` — cria a conta (`usuario`, `senha`, `nome` opcional).
  A senha é gravada com `password_hash()` (bcrypt) e **nunca** em texto puro.
- `?api=login` — valida com `password_verify()` e devolve um **token** novo.
- `?api=perfil&token=...` — restaura a sessão (personagem, skin, posição).
- `?api=salvar_perfil` — salva nome e skin do personagem.
- `?api=sair` — invalida o token.

As contas ficam em `#/ia/usuarios.json` (sem banco de dados). O token é
guardado no `localStorage` do navegador, então ao voltar você cai direto no seu
personagem.

Quando há token válido, a identidade no multiplayer passa a ser a **conta**
(`u` + hash do usuário), e não o IP. Assim, o mesmo jogador mantém o personagem,
a skin e a posição mesmo trocando de rede.

### Tela de carregamento

Logo oficial (`https://storage.amheex.workers.dev/AMHEEX/icon.png`), nome
**AMHEEX GAME**, **barra RGB animada com porcentagem** (0–100%) e dicas
rotativas ("Gerando o chão verde e o céu azul…").

### Editor de personagem (antes de entrar)

Depois do login o jogador monta o avatar uma única vez: **tom de pele, camisa,
calça e cabelo** (paletas com várias cores), botão **ALEATÓRIO** e **JOGAR**.
O personagem é procedural, realista, com mais de **37 mil triângulos** (rosto,
olhos, nariz, boca, dedos, cabelo, tênis e roupas com textura). A escolha é
salva na conta.

### HUD estilo Roblox

- **Canto superior esquerdo**: menu, chat e cartão da conta com avatar e nome.
- **Centro superior**: contador de pessoas online e de mensagens.
- **Lateral esquerda**: botões inclinados coloridos **LOJA**, **SKINS**,
  **INVENTÁRIO**, **CONFIG.** e **SAIR**.
- **Canto inferior direito**: **joystick circular flutuante** (arraste para
  andar em qualquer direção), botão redondo de **pulo**, botão de **câmera**
  (1ª pessoa / 3ª pessoa / modo tiro) e botões **CORRER** e **AÇÃO**.
  Teclado: WASD/setas, Espaço para pular, Shift para correr.
- **Chat** com abas **Aqui** (perto de você) e **Global** (todo o mundo).

### Configurações (5 abas, igual ao Roblox)

1. **Pessoas** — lista de quem está no servidor, com ações de perfil e denúncia.
2. **Config.** — Áudio (volume em **segmentos**, dispositivo de saída), Chat e
   idioma (mensagens reformuladas), Alcance da voz, Gráficos (qualidade
   Baixa/Média/Alta e sensibilidade) e Microfone.
3. **Galeria** — tudo o que a AMHEEX AI criou no mundo.
4. **Denunciar** — escolhe a pessoa e envia a denúncia pelo chat global.
5. **Ajuda** — comandos e dicas.

Rodapé fixo com **Sair**, **Regenerar** (volta ao editor de personagem) e
**Continuar**.

### Mapa global único

O servidor sobe com **um único mapa**: **chão verde e céu azul**. Não existe
tela de escolha de mapa nem de personagem no início do jogo. A comunidade
constrói o mundo junto: tudo o que a IA cria é salvo em `#/ia/cenas/mundo.json`
e sincronizado para todos (`world_revision`).

### Voz por proximidade (como Roblox)

A voz usa **WebRTC** com sinalização feita pelo próprio servidor PHP:

- `?api=rtc_send` — envia `offer`, `answer`, `ice` ou `bye`.
- `?api=rtc_poll&id=SEU_ID` — recebe e remove as mensagens destinadas a você.

O cliente abre o microfone com `getUserMedia`, negocia peer a peer e **só libera
o áudio de quem está dentro do alcance** (`Alcance da voz`, padrão 16 m). A
tecla `V` liga/desliga a voz e muta/desmuta.

### NPC AMHEEX BOT

Ao entrar no mundo nasce um **NPC fixo** (`AMHEEX BOT`) com o mesmo sistema
procedural do jogador (mais de 20 mil polígonos somando os segmentos). Ele
**segue o jogador andando**, pula para acompanhar e anima braços e pernas em
tempo real.

### O que pedir para a AMHEEX AI

A IA **só edita o personagem do próprio jogador e o mapa global**. Ela não mexe
no layout fixo do jogo (botões, HUD, configurações) nem no personagem de outras
pessoas.

Exemplos de pedidos:

- `crie uma torre de pedra alta` / `crie uma casa de madeira` / `crie um castelo`
- `crie 5 dinossauros no centro` / `crie um lago` / `mude para floresta`
- `deixe o chão de areia` / `coloque um pôr do sol` / `volte para o dia`
- `adicione uma skin azul no meu personagem` / `mude minha camisa para vermelho`
- `crie um personagem chamado Leo` / `me dê cabelo loiro`
- `adicione um contador de pontos` / `adicione um título na tela`

As construções aparecem **à frente do jogador** (cerca de 7–10 m), sem sobrepor
quem pediu, e ficam salvas no mapa global para todos.

### Regras de edição

- A IA pode **criar e modificar formas geométricas 3D** no mapa compartilhado.
- A IA **não edita o personagem de outros jogadores**: avatares remotos não
  entram em `WORLD.objects` e `findByEnt()` filtra `o.remoto`.
- O **layout fixo do jogo** não é alterado pela IA.
- Cada conta entra no **mapa único de chão verde** e passa a aparecer no mapa
  global para todos os jogadores.

---

© 2026 **AMHEEX** — Soluções em Tecnologia e Programação.
