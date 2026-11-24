# GAME IA 3D — mundo 3D local gerado por IA

Um único `index.php` que já vem com **chão verde + bola vermelha** e uma IA que
cria **qualquer coisa 3D** — prédios, casas, móveis, animais, avatares, objetos
inventados — além de **elementos de layout na tela** (título, contador, mira,
barra de vida, relógio), mapas, skins, clima e fases completas, usando
**Babylon.js local**.

O estado inicial é **fixo e limpo**: só o chão verde, a bola vermelha e o ícone
flutuante da IA. Nada mais aparece na tela até você pedir (ex.: **“crie game”**).
Tudo roda no seu servidor: sem chave de API, sem serviço externo, sem banco de
dados externo. A memória da IA e a cena ficam em arquivos JSON na própria pasta.

---

## 1. O que tem dentro

```
GAME/
├── index.php                  ← aplicação inteira (PHP + HTML + CSS + JS)
├── ia/
│   ├── engine.php             ← motor de linguagem local (português)
│   ├── modelo.json            ← "cérebro": entidades, cores, sinônimos, presets
│   ├── memoria.json           ← memória da IA (nome, cor favorita, histórico)
│   └── cenas/cena.json        ← cena salva no servidor
├── lib/
│   ├── babylon.js             ← Babylon.js 8.x (local, 8,9 MB)
│   └── babylonjs.loaders.min.js ← carregador de GLB/GLTF (local)
├── assets/
│   └── ia-icon.png            ← ícone flutuante da IA
├── dados/tts/                 ← cache dos áudios de voz (WAV)
├── uploads/                   ← imagens/vídeos/modelos enviados pelo usuário
└── LEIA-ME.md
```

Nada é carregado de CDN. Se o servidor não tiver internet, o jogo continua
funcionando.

---

## 2. Requisitos

- **PHP 8.0+** com as extensões padrão (`json`, `mbstring` recomendada).
- Permissão de escrita nas pastas `ia/`, `ia/cenas/`, `dados/` e `uploads/`.
- Para a voz do servidor: **espeak-ng** (opcional; se não existir, o navegador
  fala com `speechSynthesis`).
- Navegador com WebGL (qualquer celular atual).

Instalar o espeak-ng no Linux:

```bash
sudo apt-get install -y espeak-ng
```

No Windows, instale o espeak-ng e coloque o executável no PATH, ou deixe sem —
o fallback do navegador assume a voz.

---

## 3. Como rodar

### No seu computador (teste rápido)

```bash
cd amheex-ia3d
php -S 0.0.0.0:8080
```

Abra `http://localhost:8080`.

### No seu servidor (produção)

1. Envie a pasta `GAME/` para o servidor (FTP, gerenciador de arquivos,
   ou pelo seu `https://amheex.web.app/#/G.php`).
2. Aponte o domínio/subpasta para `index.php`.
3. Dê permissão de escrita:

```bash
chmod -R 775 ia dados uploads
```

4. Se usar Apache, o `.htaccess` não é obrigatório: todas as chamadas usam
   `index.php?api=...`.

> **Microfone:** o navegador só libera `SpeechRecognition` em **HTTPS** ou em
> `localhost`. No seu domínio com HTTPS funciona; em HTTP puro o botão do
> microfone avisa e você continua pelo chat normalmente.

---

## 4. Como falar com a IA

Abra o ícone flutuante no canto. Você pode **digitar** ou **falar**.

Exemplos que já funcionam:

| Você diz | O que acontece |
|---|---|
| `crie uma casa à direita` | cria 1 casa |
| `crie 3 casas à direita e pinte a bola de azul` | duas ações na mesma frase |
| `faça uma floresta com 12 árvores` | 12 árvores ao redor |
| `monte um jogo de plataforma` | fase completa com moedas |
| `faça uma ilha` / `monte uma cidade` | presets de mundo |
| `pinte a bola de verde` | troca a cor da bola |
| `aumente a casa` / `gire a casa` | escala e rotação |
| `chova e anoiteça` | clima + céu |
| `troque o chão para areia` | terreno |
| `câmera no topo` / `aproxime` | câmera |
| `deixe o boneco com skin de ouro` | skin/cor |
| `ligue a física` | objetos caem |
| `meu nome é João` → `qual meu nome` | memória local |
| `limpe tudo` | volta ao chão verde + bola vermelha |

A IA responde em texto **e em voz** (o botão `voz` liga/desliga). O botão
`tempo real` deixa o microfone ouvindo continuamente, como uma conversa.

Se o microfone estiver desligado, é só usar o chat — o cérebro é o mesmo.

---

## 5. Como a IA funciona (sem API externa)

`ia/engine.php` é um motor de linguagem procedural em PHP:

1. normaliza o texto (tira acentos, minúsculas);
2. reconhece **entidades** (casa, árvore, boneco, dragão, castelo…),
   **cores**, **números**, **posições** (direita, esquerda, fundo, centro…),
   **clima**, **terreno**, **câmera** e **presets**;
3. monta uma lista de **ações** no protocolo:

```json
{"t":"spawn","ent":"casa","n":3,"cor":"#e7d3b3","x":6,"z":0,"escala":1}
```

4. o JavaScript aplica cada ação no Babylon.js (`spawn`, `remove`, `clear`,
   `recolor`, `scale`, `move`, `rotate`, `terrain`, `weather`, `camera`,
   `physics`, `skin`, `world`).

Frases compostas com “e” são divididas e executadas em sequência.

### Ensinar coisas novas

Abra `ia/modelo.json` e acrescente:

```json
"entidades": {
  "meu_objeto": {
    "tags": ["meu objeto", "objeto x"],
    "cor": "#ff8800",
    "escala": 1,
    "categoria": "decoracao"
  }
}
```

Depois crie o construtor em `src/game.js` (ou direto no `index.php`):

```js
B.meu_objeto = function (o) {
  var r = root('meu_objeto');
  box('corpo', 1.5, 1.5, 1.5, r, 0, 0.75, 0, mat(o.cor));
  return r;
};
```

Pronto: “crie 4 meu objeto à esquerda” já funciona.

---

## 6. Desempenho adaptativo (não trava no celular)

O app detecta a memória, os núcleos e a tela do aparelho e escolhe 3 níveis:

| Nível | Hardware scaling | Sombras | Partículas | Máx. objetos |
|---|---|---|---|---|
| leve | 1.75 | não | 450 | 140 |
| médio | 1.30 | não | 1100 | 320 |
| alto | 1.00 | 1024 ESM | 2200 | 800 |

Além disso existe um **vigia de FPS**: se a média cair abaixo de ~38 FPS, ele
aumenta o hardware scaling e reduz efeitos; se sobrar folga, melhora a
qualidade. Malhas estáticas são congeladas (`freezeWorldMatrix`) e materiais
são reaproveitados por cor.

---

## 7. API interna (tudo em `index.php`)

| Rota | Método | Para que serve |
|---|---|---|
| `?api=ping` | GET | status, versão do PHP, motor de voz |
| `?api=chat` | POST `{msg, ctx}` | conversa com a IA → JSON com `actions` |
| `?api=tts&text=...` | GET | gera WAV com espeak-ng (cache em `dados/tts`) |
| `?api=stt` | POST | STT no servidor (501 se não houver Vosk/Whisper) |
| `?api=memoria` | GET/POST | lê/grava a memória da IA |
| `?api=cena` | GET | devolve a cena salva |
| `?api=salvar` | POST | salva a cena atual |
| `?api=modelo` | GET | devolve `modelo.json` |
| `?api=upload` | POST multipart | envia imagem/vídeo/GLB para `uploads/` |
| `?api=reset` | POST | apaga cena + memória |

### Voz (TTS)

O servidor usa `espeak-ng -v pt-br` e guarda o WAV em cache pelo hash do texto.
Se o binário não existir, o front-end cai para `speechSynthesis` com voz pt-BR.

### Áudio → texto (STT)

O caminho principal é o `SpeechRecognition` do navegador (grátis, sem chave,
precisa de HTTPS). Para STT 100% no servidor, instale o Vosk:

```bash
pip install vosk
# baixe um modelo pt e aponte em amx_stt_motor()
```

---

## 8. Backup e limpeza

- Memória: `ia/memoria.json`
- Cena: `ia/cenas/cena.json`
- Áudios: `dados/tts/*.wav` (pode apagar a qualquer momento)
- Uploads: `uploads/`

Para zerar tudo: `?api=reset` ou apague esses arquivos.

---

## 9. Segurança

- O `upload` tem lista branca de extensões e limite de 24 MB.
- O motor não executa código enviado pelo usuário: só interpreta texto.
- Recomendado servir por **HTTPS** (obrigatório para microfone).
- Se o servidor for público, proteja `ia/` e `dados/` contra listagem direta
  (o `index.php` já responde tudo; os JSONs não precisam ser acessíveis).

---

Feito para o seu ambiente AMHEEX. Basta subir a pasta e abrir o `index.php`.
