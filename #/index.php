<?php
/**
 * AMHEEX GAME — index.php único (motor AMHEEX AI).
 * Router da API + página do jogo. Tudo local: PHP, JSON e Babylon.js.
 */
declare(strict_types=1);
@ini_set('display_errors', '0');
error_reporting(E_ALL);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/ia/engine.php';
require_once __DIR__ . '/ia/voz_lib.php';

$API = isset($_GET['api']) ? preg_replace('/[^a-z0-9_]/', '', (string)$_GET['api']) : '';

/* ------------------------------------------------------------------ */
/* helpers da API                                                     */
/* ------------------------------------------------------------------ */
function amx_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $j = json_decode($raw, true);
    if (is_array($j)) return $j;
    parse_str($raw, $p);
    return is_array($p) ? $p : [];
}
function amx_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function amx_limpa(string $s, int $max = 1200): string {
    $s = strip_tags($s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

/* ---------------- identidade multiplayer por IP ---------------- */
function amx_mp_ip(): string {
    $candidatos = [];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']) as $p) $candidatos[] = trim($p);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) $candidatos[] = trim((string)$_SERVER['HTTP_X_REAL_IP']);
    if (!empty($_SERVER['REMOTE_ADDR'])) $candidatos[] = trim((string)$_SERVER['REMOTE_ADDR']);
    foreach ($candidatos as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '0.0.0.0';
}

function amx_mp_id(string $ip): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'amheex');
    return substr(hash('sha256', $ip . '|' . $ua . '|amheex-game'), 0, 16);
}

function amx_mp_public(array $players): array {
    $out = [];
    foreach ($players as $p) {
        $out[] = [
            'id' => (string)($p['id'] ?? ''),
            'name' => (string)($p['name'] ?? 'Jogador'),
            'ip' => (string)($p['ip'] ?? ''),
            'usuario' => (string)($p['usuario'] ?? ''),
            'x' => (float)($p['x'] ?? 0),
            'z' => (float)($p['z'] ?? 0),
            'ry' => (float)($p['ry'] ?? 0),
            'anim' => (string)($p['anim'] ?? 'idle'),
            'skin' => is_array($p['skin'] ?? null) ? $p['skin'] : [],
            'last' => (int)($p['last'] ?? 0),
        ];
    }
    return $out;
}


/* ---------------- CONTAS: registro e login (usuário + senha) ---------------- */
function amx_users_file(): string { return amx_path('usuarios.json'); }

function amx_users(): array {
    $db = amx_read_json(amx_users_file(), []);
    if (!is_array($db) || !isset($db['users']) || !is_array($db['users'])) $db = ['users' => []];
    return $db;
}

function amx_user_public(array $u): array {
    return [
        'usuario' => (string)($u['usuario'] ?? ''),
        'nome' => (string)($u['nome'] ?? ($u['usuario'] ?? 'Jogador')),
        'token' => (string)($u['token'] ?? ''),
        'skin' => is_array($u['skin'] ?? null) ? $u['skin'] : [],
        'criado' => (string)($u['criado'] ?? ''),
    ];
}

function amx_user_por_token(string $token): ?array {
    if ($token === '') return null;
    $db = amx_users();
    foreach ($db['users'] as $u) {
        if (!is_array($u)) continue;
        if (hash_equals((string)($u['token'] ?? ''), $token)) return $u;
    }
    return null;
}

function amx_mp_token(): string {
    $t = (string)($_GET['token'] ?? '');
    if ($t === '') {
        $in = amx_body();
        $t = (string)($in['token'] ?? '');
    }
    return preg_replace('/[^a-f0-9]/', '', $t) ?? '';
}

/* ------------------------------------------------------------------ */
/* API                                                                */
/* ------------------------------------------------------------------ */
if ($API !== '') {
    switch ($API) {

        case 'ping':
            $vozFish = amx_voz_status();
            amx_out([
                'ok' => true,
                'app' => 'AMHEEX GAME',
                'ia' => 'AMHEEX AI',
                'versao' => AMX_IA3D,
                'php' => PHP_VERSION,
                'motor' => 'local',
                'voz' => $vozFish['ok'] ? 'fish-clone' : (amx_tts_disponivel() ? 'espeak-ng' : 'navegador'),
                'voz_detalhe' => $vozFish,
                'multiplayer' => true,
                'hora' => date('c')
            ]);

        case 'chat': {
            $in = amx_body();
            $msg = amx_limpa((string)($in['msg'] ?? ($_GET['msg'] ?? '')), 900);
            if ($msg === '') amx_out(['ok' => false, 'erro' => 'mensagem vazia'], 400);
            $ctx = is_array($in['ctx'] ?? null) ? $in['ctx'] : [];
            $t0 = microtime(true);
            $res = amx_ia_responder($msg, $ctx);
            amx_historico_add('user', $msg);
            amx_historico_add('ia', (string)$res['reply']);
            $res['ms'] = (int)round((microtime(true) - $t0) * 1000);
            amx_out($res);
        }

        case 'tts': {
            $texto = amx_limpa((string)($_GET['text'] ?? (amx_body()['text'] ?? '')), 600);
            if ($texto === '') amx_out(['ok' => false, 'erro' => 'texto vazio'], 400);

            /* 1º: voz clonada AMHEEX AI (Fish Audio -> OGG/Opus) */
            $r = amx_voz_gerar($texto);
            if (($r['ok'] ?? false) && !empty($r['path']) && is_file($r['path'])) {
                amx_voz_enviar($r);
            }

            /* 2º: fallback local espeak-ng */
            $wav = amx_tts_wav($texto);
            if ($wav !== null) {
                header('Content-Type: audio/wav');
                header('Content-Length: ' . strlen($wav));
                header('Cache-Control: public, max-age=86400');
                header('X-Voice-Engine: espeak-ng');
                echo $wav;
                exit;
            }

            /* 3º: o cliente usa speechSynthesis do navegador */
            amx_out([
                'ok' => false,
                'motor' => 'navegador',
                'aviso' => 'Voz Fish indisponível e espeak-ng ausente; use a voz do navegador.',
                'detalhe' => $r['erro'] ?? null,
                'texto' => $texto
            ]);
        }

        case 'stt': {
            /* Recebe áudio (blob) e tenta transcrever localmente.
               Se não houver motor no servidor, responde 501 para o cliente
               usar o reconhecimento nativo do navegador (já implementado). */
            $eng = amx_stt_motor();
            if ($eng === null) {
                amx_out([
                    'ok' => false, 'motor' => 'navegador',
                    'aviso' => 'Nenhum motor de STT local no servidor. O cliente usa SpeechRecognition.',
                    'instale' => 'vosk-api (pip install vosk) + modelo pt, ou whisper.cpp'
                ], 501);
            }
            if (!isset($_FILES['audio']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
                amx_out(['ok' => false, 'erro' => 'envie o campo audio'], 400);
            }
            $tmp = $_FILES['audio']['tmp_name'];
            $out = amx_stt_transcrever($tmp, $eng);
            amx_out(['ok' => $out !== null, 'motor' => $eng, 'texto' => $out ?? '']);
        }

        /* ---------------- CONTAS ---------------- */
        case 'registrar': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') amx_out(['ok' => false, 'erro' => 'use POST'], 405);
            $in = amx_body();
            $usuario = strtolower(amx_limpa((string)($in['usuario'] ?? ''), 18));
            $usuario = preg_replace('/[^a-z0-9_.-]/', '', $usuario) ?? '';
            $senha = (string)($in['senha'] ?? '');
            $nome = amx_limpa((string)($in['nome'] ?? $usuario), 18);
            if (strlen($usuario) < 3) amx_out(['ok' => false, 'erro' => 'Usuário precisa de 3 caracteres ou mais.'], 400);
            if (strlen($senha) < 4) amx_out(['ok' => false, 'erro' => 'Senha precisa de 4 caracteres ou mais.'], 400);
            $db = amx_users();
            if (isset($db['users'][$usuario])) amx_out(['ok' => false, 'erro' => 'Esse usuário já existe. Faça login.'], 409);
            $token = bin2hex(random_bytes(16));
            $db['users'][$usuario] = [
                'usuario' => $usuario,
                'nome' => $nome !== '' ? $nome : $usuario,
                'hash' => password_hash($senha, PASSWORD_DEFAULT),
                'token' => $token,
                'skin' => [
                    'pele' => '#c68642', 'cor' => '#4f7cff', 'calca' => '#1f2a44',
                    'cabelo' => '#1b1b1b', 'seed' => random_int(1, 999999),
                ],
                'criado' => date('c'),
            ];
            amx_write_json(amx_users_file(), $db);
            amx_out(['ok' => true, 'user' => amx_user_public($db['users'][$usuario]), 'novo' => true]);
        }

        case 'login': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') amx_out(['ok' => false, 'erro' => 'use POST'], 405);
            $in = amx_body();
            $usuario = strtolower(amx_limpa((string)($in['usuario'] ?? ''), 18));
            $senha = (string)($in['senha'] ?? '');
            $db = amx_users();
            $u = $db['users'][$usuario] ?? null;
            if (!is_array($u) || !password_verify($senha, (string)($u['hash'] ?? ''))) {
                amx_out(['ok' => false, 'erro' => 'Usuário ou senha incorretos.'], 401);
            }
            $u['token'] = bin2hex(random_bytes(16));
            $u['ultimo_login'] = date('c');
            $db['users'][$usuario] = $u;
            amx_write_json(amx_users_file(), $db);
            amx_out(['ok' => true, 'user' => amx_user_public($u)]);
        }

        case 'perfil': {
            $u = amx_user_por_token(amx_mp_token());
            if (!$u) amx_out(['ok' => false, 'erro' => 'sessão expirada'], 401);
            amx_out(['ok' => true, 'user' => amx_user_public($u)]);
        }

        case 'salvar_perfil': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') amx_out(['ok' => false, 'erro' => 'use POST'], 405);
            $in = amx_body();
            $token = preg_replace('/[^a-f0-9]/', '', (string)($in['token'] ?? '')) ?? '';
            $db = amx_users();
            $achou = null;
            foreach ($db['users'] as $k => $u) {
                if (is_array($u) && $token !== '' && hash_equals((string)($u['token'] ?? ''), $token)) { $achou = $k; break; }
            }
            if ($achou === null) amx_out(['ok' => false, 'erro' => 'sessão expirada'], 401);
            $skin = is_array($in['skin'] ?? null) ? $in['skin'] : [];
            $db['users'][$achou]['skin'] = [
                'pele' => amx_limpa((string)($skin['pele'] ?? '#c68642'), 16),
                'cor' => amx_limpa((string)($skin['cor'] ?? '#4f7cff'), 16),
                'calca' => amx_limpa((string)($skin['calca'] ?? '#1f2a44'), 16),
                'cabelo' => amx_limpa((string)($skin['cabelo'] ?? '#1b1b1b'), 16),
                'seed' => (int)($skin['seed'] ?? random_int(1, 999999)),
            ];
            if (!empty($in['nome'])) $db['users'][$achou]['nome'] = amx_limpa((string)$in['nome'], 18);
            amx_write_json(amx_users_file(), $db);
            amx_out(['ok' => true, 'user' => amx_user_public($db['users'][$achou])]);
        }

        case 'sair': {
            $in = amx_body();
            $token = preg_replace('/[^a-f0-9]/', '', (string)($in['token'] ?? '')) ?? '';
            $db = amx_users();
            foreach ($db['users'] as $k => $u) {
                if (is_array($u) && $token !== '' && hash_equals((string)($u['token'] ?? ''), $token)) {
                    $db['users'][$k]['token'] = '';
                }
            }
            amx_write_json(amx_users_file(), $db);
            amx_out(['ok' => true]);
        }

        /* ---------------- MULTIPLAYER POR IP + MAPA COMPARTILHADO ---------------- */
        case 'mp_join':
        case 'mp_move':
        case 'mp_state':
        case 'mp_chat':
        case 'mp_world':
        case 'mp_leave': {
            $mpFile = amx_path('multiplayer.json');
            $mp = amx_read_json($mpFile, []);
            if (!is_array($mp)) $mp = [];
            $mp += ['revision' => 1, 'players' => [], 'chat' => [], 'world_revision' => 1, 'updated' => date('c')];
            if (!is_array($mp['players'])) $mp['players'] = [];
            if (!is_array($mp['chat'])) $mp['chat'] = [];

            $ip = amx_mp_ip();
            $tokenMp = amx_mp_token();
            $conta = $tokenMp !== '' ? amx_user_por_token($tokenMp) : null;
            if ($conta) {
                $id = 'u' . substr(hash('sha256', 'conta|' . (string)$conta['usuario']), 0, 15);
            } else {
                $id = amx_mp_id($ip);
            }
            $now = time();

            /* remove jogadores inativos há mais de 25s */
            foreach ($mp['players'] as $pid => $pl) {
                if (($now - (int)($pl['last'] ?? 0)) > 25) unset($mp['players'][$pid]);
            }

            if ($API === 'mp_join') {
                $in = amx_body();
                $nome = amx_limpa((string)($in['name'] ?? 'Jogador'), 24);
                if ($conta) $nome = amx_limpa((string)($conta['nome'] ?? $conta['usuario']), 24);
                if ($nome === '') $nome = 'Jogador';
                $skin = is_array($in['skin'] ?? null) ? $in['skin'] : [];
                if ($conta && is_array($conta['skin'] ?? null) && !$skin) $skin = $conta['skin'];
                $mp['players'][$id] = [
                    'id' => $id,
                    'name' => $nome,
                    'ip' => $ip,
                    'x' => (float)($in['x'] ?? 0),
                    'z' => (float)($in['z'] ?? 0),
                    'ry' => (float)($in['ry'] ?? 0),
                    'usuario' => $conta ? (string)$conta['usuario'] : '',
                    'skin' => [
                        'pele' => amx_limpa((string)($skin['pele'] ?? '#c68642'), 16),
                        'cor' => amx_limpa((string)($skin['cor'] ?? '#4f7cff'), 16),
                        'calca' => amx_limpa((string)($skin['calca'] ?? '#1f2a44'), 16),
                        'cabelo' => amx_limpa((string)($skin['cabelo'] ?? '#1b1b1b'), 16),
                        'seed' => (int)($skin['seed'] ?? random_int(1, 999999)),
                    ],
                    'last' => $now,
                ];
                $mp['updated'] = date('c');
                amx_write_json($mpFile, $mp);
                amx_out([
                    'ok' => true, 'id' => $id, 'ip' => $ip,
                    'players' => amx_mp_public($mp['players']),
                    'chat' => array_slice($mp['chat'], -40),
                    'world_revision' => (int)$mp['world_revision'],
                ]);
            }

            if ($API === 'mp_move') {
                $in = amx_body();
                if (!isset($mp['players'][$id])) {
                    $mp['players'][$id] = ['id' => $id, 'name' => 'Jogador', 'ip' => $ip, 'x' => 0, 'z' => 0, 'ry' => 0, 'skin' => [], 'last' => $now];
                }
                $mp['players'][$id]['x'] = (float)($in['x'] ?? 0);
                $mp['players'][$id]['z'] = (float)($in['z'] ?? 0);
                $mp['players'][$id]['ry'] = (float)($in['ry'] ?? 0);
                $mp['players'][$id]['anim'] = amx_limpa((string)($in['anim'] ?? 'idle'), 16);
                $mp['players'][$id]['last'] = $now;
                if (isset($in['name'])) $mp['players'][$id]['name'] = amx_limpa((string)$in['name'], 24);
                if (is_array($in['skin'] ?? null)) {
                    $sk = $in['skin'];
                    $mp['players'][$id]['skin'] = [
                        'pele' => amx_limpa((string)($sk['pele'] ?? '#c68642'), 16),
                        'cor' => amx_limpa((string)($sk['cor'] ?? '#4f7cff'), 16),
                        'calca' => amx_limpa((string)($sk['calca'] ?? '#1f2a44'), 16),
                        'cabelo' => amx_limpa((string)($sk['cabelo'] ?? '#1b1b1b'), 16),
                        'seed' => (int)($sk['seed'] ?? 12345),
                    ];
                }
                $mp['updated'] = date('c');
                amx_write_json($mpFile, $mp);
                amx_out(['ok' => true, 'players' => amx_mp_public($mp['players']), 'world_revision' => (int)$mp['world_revision']]);
            }

            if ($API === 'mp_state') {
                amx_out([
                    'ok' => true, 'id' => $id, 'ip' => $ip,
                    'players' => amx_mp_public($mp['players']),
                    'chat' => array_slice($mp['chat'], -40),
                    'world_revision' => (int)$mp['world_revision'],
                    'online' => count($mp['players']),
                ]);
            }

            if ($API === 'mp_chat') {
                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                    $in = amx_body();
                    $msg = amx_limpa((string)($in['msg'] ?? ''), 240);
                    if ($msg === '') amx_out(['ok' => false, 'erro' => 'mensagem vazia'], 400);
                    $nome = amx_limpa((string)($in['name'] ?? ($mp['players'][$id]['name'] ?? 'Jogador')), 24);
                    $mp['chat'][] = ['id' => $id, 'name' => $nome, 'ip' => $ip, 'msg' => $msg, 'h' => date('c')];
                    if (count($mp['chat']) > 120) $mp['chat'] = array_slice($mp['chat'], -120);
                    $mp['updated'] = date('c');
                    amx_write_json($mpFile, $mp);
                }
                amx_out(['ok' => true, 'chat' => array_slice($mp['chat'], -40), 'players' => amx_mp_public($mp['players'])]);
            }

            if ($API === 'mp_world') {
                $worldFile = amx_path('cenas/mundo.json');
                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                    $in = amx_body();
                    $cena = $in['cena'] ?? null;
                    if (!is_array($cena)) amx_out(['ok' => false, 'erro' => 'cena inválida'], 400);
                    $cena['_preset'] = amx_limpa((string)($in['preset'] ?? ''), 40);
                    $cena['_rev'] = (int)$mp['world_revision'] + 1;
                    amx_write_json($worldFile, $cena);
                    $mp['world_revision'] = (int)$cena['_rev'];
                    $mp['updated'] = date('c');
                    amx_write_json($mpFile, $mp);
                    amx_out(['ok' => true, 'world_revision' => $mp['world_revision']]);
                }
                amx_out([
                    'ok' => true,
                    'world_revision' => (int)$mp['world_revision'],
                    'cena' => amx_read_json($worldFile, null),
                ]);
            }

            if ($API === 'mp_leave') {
                unset($mp['players'][$id]);
                $mp['updated'] = date('c');
                amx_write_json($mpFile, $mp);
                amx_out(['ok' => true]);
            }

            amx_out(['ok' => false, 'erro' => 'ação multiplayer desconhecida'], 400);
        }

        /* ---------- VOZ POR PROXIMIDADE (WebRTC, sinalização por arquivo) ---------- */
        case 'rtc_send': {
            $in = amx_body();
            $de = amx_limpa((string)($in['from'] ?? ''), 40);
            $para = amx_limpa((string)($in['to'] ?? ''), 40);
            $tipo = amx_limpa((string)($in['kind'] ?? ''), 12);
            if ($de === '' || $para === '' || !in_array($tipo, ['offer', 'answer', 'ice', 'bye'], true)) {
                amx_out(['ok' => false, 'erro' => 'sinal inválido'], 400);
            }
            $f = amx_path('rtc.json');
            $db = amx_read_json($f, ['msgs' => []]);
            if (!is_array($db) || !isset($db['msgs']) || !is_array($db['msgs'])) $db = ['msgs' => []];
            $db['msgs'][] = [
                'from' => $de, 'to' => $para, 'kind' => $tipo,
                'data' => $in['data'] ?? null, 'ts' => time()
            ];
            $agora = time();
            $db['msgs'] = array_values(array_filter($db['msgs'], function ($m) use ($agora) {
                return ($agora - (int)($m['ts'] ?? 0)) < 40;
            }));
            if (count($db['msgs']) > 300) $db['msgs'] = array_slice($db['msgs'], -300);
            amx_write_json($f, $db);
            amx_out(['ok' => true]);
        }

        case 'rtc_poll': {
            $id = amx_limpa((string)($_GET['id'] ?? ''), 40);
            if ($id === '') amx_out(['ok' => false, 'erro' => 'id ausente'], 400);
            $f = amx_path('rtc.json');
            $db = amx_read_json($f, ['msgs' => []]);
            if (!is_array($db) || !isset($db['msgs']) || !is_array($db['msgs'])) $db = ['msgs' => []];
            $meus = [];
            $resto = [];
            foreach ($db['msgs'] as $m) {
                if (($m['to'] ?? '') === $id) $meus[] = $m; else $resto[] = $m;
            }
            if ($meus) { $db['msgs'] = $resto; amx_write_json($f, $db); }
            amx_out(['ok' => true, 'msgs' => $meus]);
        }

        case 'treino': {
            $a = amx_aprendizado();
            $kb = amx_read_json(amx_path('knowledge.json'), []);
            amx_out([
                'ok' => true,
                'ia' => 'AMHEEX AI',
                'fatos_aprendidos' => count($a['fatos'] ?? []),
                'treinos' => (int)($a['treinos'] ?? 0),
                'areas_conhecimento' => count($kb['areas'] ?? []),
                'fatos' => $a['fatos'] ?? [],
            ]);
        }

        case 'memoria':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $in = amx_body();
                $mem = amx_memoria();
                if (isset($in['nome'])) $mem['nome'] = amx_limpa((string)$in['nome'], 60);
                if (isset($in['cor_favorita'])) $mem['cor_favorita'] = amx_limpa((string)$in['cor_favorita'], 20);
                amx_memoria_salvar($mem);
                amx_out(['ok' => true, 'memoria' => $mem]);
            }
            amx_out(['ok' => true, 'memoria' => amx_memoria()]);

        case 'cena':
            amx_out(['ok' => true, 'cena' => amx_read_json(amx_path('cenas/cena.json'), null)]);

        case 'salvar': {
            $in = amx_body();
            $cena = $in['cena'] ?? null;
            if (!is_array($cena)) amx_out(['ok' => false, 'erro' => 'cena inválida'], 400);
            $ok = amx_write_json(amx_path('cenas/cena.json'), $cena);
            amx_out(['ok' => $ok]);
        }

        case 'modelo':
            amx_out(['ok' => true, 'modelo' => amx_modelo()]);

        case 'upload': {
            if (!isset($_FILES['file'])) amx_out(['ok' => false, 'erro' => 'arquivo ausente'], 400);
            $f = $_FILES['file'];
            if (($f['error'] ?? 1) !== 0) amx_out(['ok' => false, 'erro' => 'falha no upload'], 400);
            if ($f['size'] > 24 * 1024 * 1024) amx_out(['ok' => false, 'erro' => 'máx. 24 MB'], 413);
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            $permitidas = ['png','jpg','jpeg','webp','gif','glb','gltf','mp4','webm','mp3','wav','ogg','json','txt'];
            if (!in_array($ext, $permitidas, true)) amx_out(['ok' => false, 'erro' => 'extensão não permitida'], 415);
            $nome = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
            $destino = amx_path('../uploads/' . $nome);
            if (!is_dir(dirname($destino))) @mkdir(dirname($destino), 0775, true);
            if (!move_uploaded_file($f['tmp_name'], $destino)) amx_out(['ok' => false, 'erro' => 'não foi possível gravar'], 500);
            amx_out(['ok' => true, 'arquivo' => 'uploads/' . $nome, 'tipo' => $ext]);
        }

        case 'reset': {
            amx_write_json(amx_path('memoria.json'), ['nome' => '', 'cor_favorita' => '', 'criados' => [], 'historico' => [], 'atualizado' => date('c')]);
            @unlink(amx_path('cenas/cena.json'));
            amx_out(['ok' => true]);
        }

        default:
            amx_out(['ok' => false, 'erro' => 'endpoint desconhecido', 'api' => $API], 404);
    }
}

/* ------------------------------------------------------------------ */
/* TTS local (espeak-ng)                                              */
/* ------------------------------------------------------------------ */
function amx_tts_disponivel(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    if (function_exists('shell_exec')) {
        $p = @shell_exec('command -v espeak-ng 2>/dev/null');
        $ok = is_string($p) && trim($p) !== '';
    }
    return $ok;
}

function amx_tts_wav(string $texto): ?string {
    if (!amx_tts_disponivel()) return null;
    $dir = amx_path('../dados/tts');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $hash = md5($texto . '|pt-br|165');
    $cache = $dir . '/' . $hash . '.wav';
    if (is_file($cache) && filesize($cache) > 44) {
        $d = @file_get_contents($cache);
        if ($d !== false) return $d;
    }
    $txt = tempnam(sys_get_temp_dir(), 'amx');
    if ($txt === false) return null;
    file_put_contents($txt, $texto);
    $cmd = 'espeak-ng -v pt-br -s 165 -p 48 -a 160 -w ' . escapeshellarg($cache . '.tmp') . ' -f ' . escapeshellarg($txt) . ' 2>/dev/null';
    @shell_exec($cmd);
    @unlink($txt);
    if (!is_file($cache . '.tmp') || filesize($cache . '.tmp') < 100) { @unlink($cache . '.tmp'); return null; }
    @rename($cache . '.tmp', $cache);
    $d = @file_get_contents($cache);
    return $d === false ? null : $d;
}

/* ------------------------------------------------------------------ */
/* STT local opcional                                                 */
/* ------------------------------------------------------------------ */
function amx_stt_motor(): ?string {
    if (!function_exists('shell_exec')) return null;
    $w = @shell_exec('command -v whisper 2>/dev/null');
    if (is_string($w) && trim($w) !== '') return 'whisper';
    $v = @shell_exec('command -v vosk-transcriber 2>/dev/null');
    if (is_string($v) && trim($v) !== '') return 'vosk';
    return null;
}

function amx_stt_transcrever(string $arquivo, string $motor): ?string {
    $cmd = $motor === 'whisper'
        ? 'whisper ' . escapeshellarg($arquivo) . ' --language Portuguese --model tiny --output_format txt --output_dir ' . escapeshellarg(sys_get_temp_dir()) . ' 2>/dev/null'
        : 'vosk-transcriber -i ' . escapeshellarg($arquivo) . ' -l pt 2>/dev/null';
    $out = @shell_exec($cmd);
    if (!is_string($out)) return null;
    $out = trim(preg_replace('/\s+/', ' ', $out) ?? '');
    return $out === '' ? null : mb_substr($out, 0, 800);
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#05070d">
<title>AMHEEX GAME — mundo 3D com AMHEEX AI</title>
<meta name="description" content="AMHEEX GAME: mundo 3D com a AMHEEX AI. Crie qualquer objeto, personagem realista, controles, câmera, multiplayer por IP e mapas compartilhados por voz ou texto.">
<link rel="icon" href="assets/ia-icon.png">
<style>
:root{
  --bg:#05070d; --bg2:#0b1020; --panel:#0d1220; --bd:rgba(255,255,255,.10);
  --tx:#e8eefc; --mut:#8b97b4; --acc:#4f7cff; --acc2:#22d3ee; --ok:#22c55e;
  --font:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
}
*{box-sizing:border-box}
html,body{height:100%;margin:0;background:var(--bg);color:var(--tx);font-family:var(--font);overflow:hidden;overscroll-behavior:none}
#game{position:fixed;inset:0;width:100%;height:100%;display:block;touch-action:none;outline:none}
#vignette{position:fixed;inset:0;pointer-events:none;z-index:2;background:radial-gradient(120% 90% at 50% 40%,transparent 55%,rgba(0,0,0,.45) 100%)}

/* ---------- HUD discreto (sem botões) ---------- */
#hud{position:fixed;left:14px;top:14px;z-index:5;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:rgba(8,11,20,.62);border:1px solid var(--bd);backdrop-filter:blur(10px);font-size:12px;color:#cfd8ee;letter-spacing:.01em;box-shadow:0 10px 30px -12px rgba(0,0,0,.8);width:max-content}
.pill b{font-weight:800;color:#fff}
.dot{width:7px;height:7px;border-radius:50%;background:var(--ok);box-shadow:0 0 10px var(--ok);animation:pulse 2.2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
#score{display:none}
#score.on{display:inline-flex}
#hint{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:4;padding:9px 16px;border-radius:999px;background:rgba(8,11,20,.66);border:1px solid var(--bd);backdrop-filter:blur(10px);font-size:12.5px;color:#c7d2e8;white-space:nowrap;transition:opacity .8s;pointer-events:none}
#hint.off{opacity:0}
@media (max-width:560px){#hint{font-size:11px;padding:8px 12px;max-width:92vw;overflow:hidden;text-overflow:ellipsis}}

/* ---------- elementos de LAYOUT criados pela IA (HUD dinâmico) ---------- */
.lay{position:fixed;z-index:6;pointer-events:none;font-family:var(--font);color:#e8eefc;text-shadow:0 2px 14px rgba(0,0,0,.8);transition:opacity .3s,transform .2s;line-height:1.25}
.lay-titulo{font-size:22px;font-weight:900;letter-spacing:.02em}
.lay-texto{font-size:14px;font-weight:600;opacity:.95}
.lay-contador{font-size:15px;font-weight:800;padding:6px 13px;border-radius:999px;background:rgba(8,11,20,.62);border:1px solid var(--bd);backdrop-filter:blur(10px);text-shadow:none}
.lay-contador b{color:#fff}
.lay-icone{font-size:28px;filter:drop-shadow(0 0 12px rgba(79,124,255,.8))}
.lay-relogio{font-size:15px;font-weight:800;font-variant-numeric:tabular-nums;padding:6px 13px;border-radius:999px;background:rgba(8,11,20,.62);border:1px solid var(--bd);backdrop-filter:blur(10px);text-shadow:none}
.lay-painel{font-size:13px;padding:10px 14px;border-radius:12px;background:rgba(8,11,20,.66);border:1px solid var(--bd);backdrop-filter:blur(10px);max-width:min(320px,80vw);text-shadow:none}
.lay-barra{width:180px;height:10px;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid var(--bd);overflow:hidden}
.lay-barra i{display:block;height:100%;background:linear-gradient(90deg,#22c55e,#4ade80);border-radius:999px;transition:width .3s}
.lay-mira{width:26px;height:26px;border:2px solid rgba(255,255,255,.85);border-radius:50%;box-shadow:0 0 12px rgba(0,0,0,.6)}
.lay-mira span{position:absolute;left:50%;top:50%;width:4px;height:4px;margin:-2px 0 0 -2px;border-radius:50%;background:#fff}
.lay-botao{font-size:13px;font-weight:700;padding:8px 14px;border-radius:10px;background:rgba(79,124,255,.22);border:1px solid rgba(79,124,255,.5);text-shadow:none}
.pos-topo{left:50%;top:16px;transform:translateX(-50%);text-align:center}
.pos-base{left:50%;bottom:96px;transform:translateX(-50%);text-align:center}
.pos-centro{left:50%;top:50%;transform:translate(-50%,-50%)}
.pos-esq{left:16px;top:50%;transform:translateY(-50%)}
.pos-dir{right:16px;top:50%;transform:translateY(-50%)}


/* ---------- AMHEEX GAME: controles virtuais ---------- */
#controls{position:fixed;left:0;right:0;bottom:0;z-index:8;pointer-events:none;display:none;justify-content:space-between;align-items:flex-end;padding:0 16px 18px;gap:12px}
#controls.on{display:flex}
#controls .dpad{display:grid;grid-template-columns:repeat(3,54px);grid-template-rows:repeat(3,54px);gap:6px;pointer-events:auto}
#controls .dpad button,#controls .acts button{width:54px;height:54px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,24,.62);backdrop-filter:blur(10px);color:#e8eefc;font-size:20px;font-weight:800;display:grid;place-items:center;touch-action:none;user-select:none;-webkit-user-select:none;cursor:pointer;box-shadow:0 8px 24px -10px #000}
#controls .dpad button:active,#controls .acts button:active{background:rgba(79,124,255,.45);transform:scale(.96)}
#controls .dpad .up{grid-column:2;grid-row:1}#controls .dpad .left{grid-column:1;grid-row:2}#controls .dpad .down{grid-column:2;grid-row:3}#controls .dpad .right{grid-column:3;grid-row:2}
#controls .acts{display:flex;flex-direction:column;gap:8px;pointer-events:auto;align-items:flex-end;margin-right:72px}
#controls .acts button{width:64px;height:48px;font-size:12px;letter-spacing:.04em}
#controls .acts .jump{background:rgba(34,211,238,.24);border-color:rgba(34,211,238,.5)}
#controls .acts .run{background:rgba(34,197,94,.20);border-color:rgba(34,197,94,.45)}
#controls .acts .fire{background:rgba(239,68,68,.28);border-color:rgba(239,68,68,.55);display:none}
#controls.shooter .acts .fire{display:grid}
#controls .cam{position:fixed;right:16px;bottom:250px;pointer-events:auto}
#controls .cam button{width:48px;height:40px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,24,.62);color:#e8eefc;font-size:11px;font-weight:800;backdrop-filter:blur(10px)}
#crosshair{position:fixed;left:50%;top:50%;width:26px;height:26px;margin:-13px 0 0 -13px;z-index:7;pointer-events:none;display:none}
#crosshair.on{display:block}
#crosshair:before,#crosshair:after{content:'';position:absolute;background:rgba(255,255,255,.9);box-shadow:0 0 8px #000}
#crosshair:before{left:12px;top:0;width:2px;height:26px}#crosshair:after{top:12px;left:0;height:2px;width:26px}
#health{position:fixed;left:16px;bottom:18px;z-index:7;display:none;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:rgba(8,11,20,.62);border:1px solid var(--bd);backdrop-filter:blur(10px);font-size:12px;color:#cfd8ee}
#health.on{display:flex}#health b{color:#fff}
#health .bar{width:110px;height:8px;border-radius:99px;background:rgba(255,255,255,.14);overflow:hidden}#health .bar i{display:block;height:100%;width:100%;background:linear-gradient(90deg,#22c55e,#4ade80)}

/* ---------- AMHEEX GAME: multiplayer ---------- */
#mpPanel{position:fixed;right:16px;top:16px;z-index:9;width:min(300px,calc(100vw - 32px));display:none;flex-direction:column;background:rgba(10,14,24,.82);border:1px solid var(--bd);border-radius:16px;backdrop-filter:blur(14px);box-shadow:0 20px 60px -20px #000;overflow:hidden}
#mpPanel.on{display:flex}
#mpHead{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--bd);font-size:12px;font-weight:800;color:#fff}
#mpHead small{font-weight:500;color:var(--mut);font-size:10.5px}
#mpPlayers{max-height:150px;overflow:auto;padding:8px 10px;display:flex;flex-direction:column;gap:6px}
.mp-player{display:flex;align-items:center;gap:8px;font-size:11.5px;color:#cfd8ee}
.mp-player .sw{width:10px;height:10px;border-radius:50%;flex:0 0 auto}
.mp-player b{color:#fff;font-weight:700}.mp-player small{color:#7f8db3;margin-left:auto;font-size:10px}
#mpChat{max-height:130px;overflow:auto;padding:8px 10px;border-top:1px solid var(--bd);display:flex;flex-direction:column;gap:5px;font-size:11.5px;line-height:1.4}
#mpChat .who{color:#22d3ee;font-weight:700}#mpChat .msg{color:#dbe4f7}
#mpForm{display:flex;gap:6px;padding:8px 10px;border-top:1px solid var(--bd)}
#mpForm input{flex:1;min-width:0;background:#0a0e18;border:1px solid var(--bd);border-radius:9px;color:#e8eefc;padding:7px 9px;font-size:12px;font-family:inherit;outline:none}
#mpForm button{border:1px solid rgba(79,124,255,.5);background:rgba(79,124,255,.2);color:#fff;border-radius:9px;padding:0 10px;font-size:12px;font-weight:700;cursor:pointer}
#mpBtn{position:fixed;right:16px;top:16px;z-index:8;display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;border:1px solid rgba(34,211,238,.4);background:rgba(34,211,238,.14);color:#9fe9f7;font-size:11.5px;font-weight:700;backdrop-filter:blur(10px);cursor:pointer}
#mpBtn.on{display:inline-flex}
#mpBtn b{color:#fff}
#mpToast{position:fixed;left:50%;top:18px;transform:translate(-50%,-16px);z-index:20;padding:10px 16px;border-radius:12px;background:rgba(10,14,24,.9);border:1px solid rgba(34,211,238,.45);color:#dff6fb;font-size:12.5px;font-weight:600;opacity:0;pointer-events:none;transition:.25s;max-width:calc(100vw - 32px);text-align:center;backdrop-filter:blur(12px)}
#mpToast.on{opacity:1;transform:translate(-50%,0)}
.mp-player{display:flex;align-items:center;gap:7px;font-size:11.5px;color:#c7d2e8}
.mp-player .dot{width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 8px #22c55e}
.mp-player.me .dot{background:#22d3ee;box-shadow:0 0 8px #22d3ee}
.mp-player b{color:#fff;font-size:11.5px}.mp-player small{color:#7c89a8;margin-left:auto;font-size:10px}
.mp-msg{color:#dbe4f7}.mp-msg b{color:#22d3ee}

@media (max-width:560px){#controls .dpad{grid-template-columns:repeat(3,48px);grid-template-rows:repeat(3,48px)}#controls .dpad button{width:48px;height:48px}#controls .acts button{width:56px;height:44px}#mpPanel{top:auto;bottom:90px;right:8px;width:calc(100vw - 16px)}#mpBtn{top:auto;bottom:88px;right:10px}}

/* ---------- FAB (ícone flutuante extraído do AMHEEX DRIVE) ---------- */
#amxai-root{position:fixed;inset:0;z-index:2147483000;pointer-events:none;font-family:var(--font)}
#amxai-root *{box-sizing:border-box}
#amxai-fab{pointer-events:auto;position:fixed;right:18px;bottom:18px;width:60px;height:60px;border-radius:17px;border:1px solid rgba(255,255,255,.16);padding:0;cursor:pointer;background:#0a0c14;box-shadow:0 16px 40px rgba(0,0,0,.55),0 0 0 3px rgba(255,255,255,.06),0 0 32px -6px var(--acc);display:grid;place-items:center;transition:transform .18s,box-shadow .18s;overflow:visible;touch-action:none;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none}
#amxai-fab:hover{transform:translateY(-3px) scale(1.03)}
#amxai-fab:active{transform:scale(.97)}
#amxai-fab img{width:100%;height:100%;object-fit:cover;border-radius:14px;display:block}
#amxai-fab .amxai-ping{position:absolute;right:-2px;top:-2px;width:13px;height:13px;border-radius:50%;background:var(--ok);border:2px solid #fff;box-shadow:0 0 10px var(--ok)}
#amxai-root[data-open="1"] #amxai-fab{opacity:0;pointer-events:none;transform:scale(.7)}
#amxai-root[data-mode="fixed"] #amxai-fab{right:0;top:50%;bottom:auto;transform:translateY(-50%);width:48px;height:88px;border-radius:16px 0 0 16px;background:linear-gradient(160deg,#0a0c14,#171b2a);box-shadow:-8px 0 30px rgba(0,0,0,.3)}
#amxai-root[data-mode="fixed"] #amxai-fab img{display:none}
#amxai-root[data-mode="fixed"] #amxai-fab .amxai-pro{display:grid}
#amxai-fab .amxai-pro{display:none;place-items:center;width:100%;height:100%;color:#fff}

#amxai-panel{pointer-events:auto;position:fixed;right:18px;bottom:18px;width:min(404px,calc(100vw - 24px));height:min(660px,calc(100dvh - 40px));display:flex;flex-direction:column;background:linear-gradient(180deg,#0d1220,#0a0d16 60%);border:1px solid var(--bd);border-radius:20px;box-shadow:0 40px 100px -20px rgba(0,0,0,.8),0 0 0 1px rgba(255,255,255,.03) inset;overflow:hidden;color:var(--tx);animation:amxaiIn .18s ease both}
#amxai-panel[hidden]{display:none!important}
@keyframes amxaiIn{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
#amxai-root[data-mode="fixed"] #amxai-panel{right:0;bottom:0;top:0;width:min(430px,100vw);height:100dvh;border-radius:0;border-right:0}
#amxai-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 12px 12px 14px;background:linear-gradient(180deg,rgba(255,255,255,.05),transparent);border-bottom:1px solid var(--bd);cursor:grab;touch-action:none;flex:0 0 auto}
#amxai-root[data-mode="fixed"] #amxai-head{cursor:default}
.amxai-id{display:flex;align-items:center;gap:10px;min-width:0}
.amxai-avatar{width:34px;height:34px;border-radius:10px;overflow:hidden;background:#0a0c14;border:1px solid rgba(255,255,255,.14);flex:0 0 auto;display:grid;place-items:center}
.amxai-avatar img{width:100%;height:100%;object-fit:cover}
.amxai-id b{display:block;font-size:13.5px;font-weight:800;letter-spacing:.01em}
.amxai-id small{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--mut);margin-top:1px}
.amxai-id small i{width:6px;height:6px;border-radius:50%;background:var(--ok);box-shadow:0 0 8px var(--ok);display:inline-block}
.amxai-head-btns{display:flex;align-items:center;gap:4px;flex:0 0 auto}
.amxai-head-btns button{width:32px;height:32px;border-radius:9px;border:1px solid transparent;background:transparent;color:var(--mut);display:grid;place-items:center;cursor:pointer;font-size:20px;line-height:1;transition:.15s;padding:0}
.amxai-head-btns button:hover{background:rgba(255,255,255,.08);color:#fff;border-color:var(--bd)}
#amxai-pin.on{color:var(--acc2);background:rgba(34,211,238,.12);border-color:rgba(34,211,238,.35)}
#amxai-close{font-size:22px!important}

#amxai-msgs{flex:1 1 auto;overflow-y:auto;overflow-x:hidden;padding:14px 12px 6px;display:flex;flex-direction:column;gap:12px;scrollbar-width:thin;scrollbar-color:#2a3350 transparent}
#amxai-msgs::-webkit-scrollbar{width:7px}
#amxai-msgs::-webkit-scrollbar-thumb{background:#2a3350;border-radius:8px}
.amxai-msg{display:flex;flex-direction:column;max-width:100%;animation:msgIn .18s ease both}
@keyframes msgIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.amxai-msg.user{align-items:flex-end}
.amxai-msg.bot{align-items:flex-start}
.amxai-bubble{max-width:92%;padding:10px 12px;border-radius:14px;font-size:13.2px;line-height:1.55;word-wrap:break-word;overflow-wrap:anywhere}
.amxai-msg.user .amxai-bubble{background:linear-gradient(135deg,#3b63f0,#5b8bff);color:#fff;border-bottom-right-radius:5px;white-space:pre-wrap}
.amxai-msg.bot .amxai-bubble{background:#151b2b;border:1px solid var(--bd);color:var(--tx);border-bottom-left-radius:5px;max-width:100%;width:100%}
.amxai-bubble code{background:rgba(255,255,255,.08);padding:1px 5px;border-radius:5px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:#a5d6ff}
.amxai-bubble b{color:#fff}
.amxai-acts{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.amxai-act{font-size:11px;padding:4px 8px;border-radius:7px;background:rgba(34,211,238,.10);border:1px solid rgba(34,211,238,.30);color:#9fe9f7;display:inline-flex;align-items:center;gap:5px}
.amxai-act.ok{background:rgba(34,197,94,.10);border-color:rgba(34,197,94,.32);color:#9df0bb}
.amxai-act.er{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.32);color:#ffb4b4}
.amxai-typing{display:inline-flex;gap:4px;padding:3px 2px}
.amxai-typing i{width:6px;height:6px;border-radius:50%;background:#7f8db3;animation:tp 1.1s infinite}
.amxai-typing i:nth-child(2){animation-delay:.15s}
.amxai-typing i:nth-child(3){animation-delay:.3s}
@keyframes tp{0%,60%,100%{opacity:.25;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}

#amxai-chips{display:flex;gap:6px;overflow-x:auto;padding:6px 12px 8px;flex:0 0 auto;scrollbar-width:none}
#amxai-chips::-webkit-scrollbar{display:none}
#amxai-chips button{white-space:nowrap;font-size:11.5px;padding:7px 10px;border-radius:9px;border:1px solid var(--bd);background:rgba(255,255,255,.04);color:#c3cfe8;cursor:pointer;transition:.15s;font-family:inherit}
#amxai-chips button:hover{background:rgba(79,124,255,.16);border-color:rgba(79,124,255,.45);color:#fff}

#amxai-form{display:flex;align-items:flex-end;gap:6px;padding:8px 10px 10px;border-top:1px solid var(--bd);background:rgba(0,0,0,.18);flex:0 0 auto}
#amxai-input{flex:1 1 auto;resize:none;max-height:110px;min-height:40px;padding:10px 12px;border-radius:12px;border:1px solid var(--bd);background:#0a0e18;color:var(--tx);font-size:13.5px;font-family:inherit;line-height:1.4;outline:none;transition:border-color .15s}
#amxai-input:focus{border-color:rgba(79,124,255,.65);box-shadow:0 0 0 3px rgba(79,124,255,.14)}
#amxai-input::placeholder{color:#64719a}
#amxai-form button{width:40px;height:40px;border-radius:12px;border:1px solid var(--bd);background:#111827;color:#cfd8ee;display:grid;place-items:center;cursor:pointer;transition:.15s;flex:0 0 auto;padding:0}
#amxai-form button:hover{background:#1a2338;color:#fff}
#amxai-form button svg{width:18px;height:18px}
#amxai-mic.listening{background:rgba(239,68,68,.18);border-color:rgba(239,68,68,.55);color:#ff9d9d;animation:micPulse 1.2s infinite}
@keyframes micPulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.35)}50%{box-shadow:0 0 0 7px rgba(239,68,68,0)}}
#amxai-send{background:linear-gradient(135deg,#3b63f0,#5b8bff)!important;border-color:transparent!important;color:#fff!important}
#amxai-send:disabled{opacity:.45;cursor:not-allowed}

.amxai-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:0 12px 9px;font-size:10.5px;color:#6b7899;flex:0 0 auto}
.amxai-foot .sw{display:inline-flex;align-items:center;gap:5px;cursor:pointer;border:1px solid var(--bd);background:rgba(255,255,255,.03);border-radius:8px;padding:4px 7px;color:#93a1c2;font-size:10.5px;font-family:inherit}
.amxai-foot .sw.on{color:#9fe9f7;border-color:rgba(34,211,238,.4);background:rgba(34,211,238,.10)}
.amxai-foot .sw svg{width:12px;height:12px}
.amxai-foot .left{display:flex;gap:5px;align-items:center}
@media (max-width:560px){
  #amxai-panel{right:8px;bottom:8px;width:calc(100vw - 16px);height:min(72dvh,620px);border-radius:18px}
  #amxai-fab{right:14px;bottom:14px;width:56px;height:56px}
  #amxai-chips button{font-size:11px}
}

/* ================= AMHEEX GAME 3.1 — boot, menu, HUD estilo Roblox ================= */
#boot{position:fixed;inset:0;z-index:9000;background:radial-gradient(120% 120% at 50% 0%,#101a33 0%,#05070d 60%,#000 100%);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:26px;transition:opacity .6s}
#boot.off{opacity:0;pointer-events:none}
#bootLogo{width:min(230px,46vw);height:auto;filter:drop-shadow(0 18px 50px rgba(79,124,255,.55));animation:bootFloat 3.4s ease-in-out infinite}
@keyframes bootFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-9px)}}
#bootName{font-size:clamp(20px,4.4vw,34px);font-weight:900;letter-spacing:.16em;color:#fff;text-transform:uppercase;text-align:center}
#bootName span{background:linear-gradient(90deg,#4f7cff,#22d3ee,#a855f7,#4f7cff);background-size:300% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:rgbText 6s linear infinite}
@keyframes rgbText{to{background-position:300% 0}}
#bootBarWrap{width:min(420px,78vw);display:flex;flex-direction:column;gap:9px;align-items:center}
#bootBar{width:100%;height:10px;border-radius:999px;background:rgba(255,255,255,.09);overflow:hidden;border:1px solid rgba(255,255,255,.10)}
#bootFill{display:block;height:100%;width:0%;border-radius:999px;background:linear-gradient(90deg,#ff004c,#ff8a00,#ffe600,#33ff66,#00e5ff,#4f7cff,#c400ff,#ff004c);background-size:300% 100%;animation:rgbBar 3.2s linear infinite;transition:width .35s ease;box-shadow:0 0 18px rgba(79,124,255,.6)}
@keyframes rgbBar{to{background-position:300% 0}}
#bootPct{font-size:12.5px;font-weight:800;letter-spacing:.14em;color:#9fb0d4}
#bootTip{font-size:11.5px;color:#64719a;max-width:min(420px,80vw);text-align:center;line-height:1.5}

#menu{position:fixed;inset:0;z-index:8000;display:none;flex-direction:column;align-items:center;justify-content:center;gap:18px;background:radial-gradient(120% 120% at 50% 10%,rgba(16,26,51,.94),rgba(4,6,12,.97) 65%,#000);backdrop-filter:blur(14px);padding:24px}
#menu.on{display:flex}
#menuLogo{width:min(180px,40vw);filter:drop-shadow(0 16px 44px rgba(79,124,255,.5))}
#menuTitle{font-size:clamp(26px,6vw,46px);font-weight:900;letter-spacing:.1em;margin:0;text-align:center;background:linear-gradient(90deg,#fff,#9fc3ff,#22d3ee,#fff);background-size:250% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:rgbText 8s linear infinite}
#menuSub{font-size:12.5px;color:#8b97b4;letter-spacing:.18em;text-transform:uppercase;margin-top:-8px}
#menuCard{width:min(420px,92vw);background:rgba(13,18,32,.78);border:1px solid rgba(255,255,255,.10);border-radius:20px;padding:18px;display:flex;flex-direction:column;gap:12px;box-shadow:0 40px 100px -30px #000}
#menuCard label{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#7f8db3;font-weight:800}
#menuName{width:100%;padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#0a0e18;color:#fff;font-size:14px;font-family:inherit;outline:none}
#menuName:focus{border-color:rgba(79,124,255,.65);box-shadow:0 0 0 3px rgba(79,124,255,.15)}
#menuPlay{width:100%;padding:15px;border-radius:14px;border:0;cursor:pointer;font-family:inherit;font-size:16px;font-weight:900;letter-spacing:.1em;color:#fff;background:linear-gradient(135deg,#3b63f0,#5b8bff 45%,#22d3ee);box-shadow:0 16px 40px -14px rgba(59,99,240,.9);transition:.18s}
#menuPlay:hover{transform:translateY(-2px);box-shadow:0 22px 50px -14px rgba(34,211,238,.85)}
#menuRow{display:flex;gap:8px}
#menuRow button{flex:1;padding:11px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);color:#c3cfe8;font-family:inherit;font-size:12px;font-weight:700;cursor:pointer}
#menuRow button:hover{background:rgba(79,124,255,.16);color:#fff}
#menuStats{font-size:11.5px;color:#7f8db3;text-align:center}
#menuStats b{color:#22d3ee}

#topbar{position:fixed;left:0;right:0;top:0;z-index:9;display:none;align-items:center;gap:8px;padding:10px 12px;pointer-events:none}
#topbar.on{display:flex}
#topbar .tb{pointer-events:auto;display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 12px;border-radius:11px;border:1px solid rgba(255,255,255,.12);background:rgba(8,11,20,.66);backdrop-filter:blur(12px);color:#dbe4f7;font-family:inherit;font-size:12px;font-weight:700;cursor:pointer;transition:.15s}
#topbar .tb:hover{background:rgba(79,124,255,.22);border-color:rgba(79,124,255,.45);color:#fff}
#topbar .tb.icon{width:38px;padding:0;justify-content:center;font-size:16px}
#tbBrand{display:flex;align-items:center;gap:8px;pointer-events:none;margin-right:auto;padding-left:2px}
#tbBrand img{width:28px;height:28px;border-radius:8px;object-fit:cover}
#tbBrand b{font-size:12.5px;letter-spacing:.12em;color:#fff}
#tbBrand small{display:block;font-size:9px;letter-spacing:.16em;color:#7f8db3}

#chatPanel{position:fixed;left:12px;bottom:96px;z-index:9;width:min(340px,calc(100vw - 24px));max-height:min(46vh,360px);display:none;flex-direction:column;background:rgba(10,14,24,.86);border:1px solid rgba(255,255,255,.12);border-radius:16px;backdrop-filter:blur(14px);overflow:hidden;box-shadow:0 24px 70px -24px #000}
#chatPanel.on{display:flex}
#chatHead{display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border-bottom:1px solid rgba(255,255,255,.08);font-size:11px;letter-spacing:.14em;font-weight:800;color:#9fb0d4}
#chatHead button{background:none;border:0;color:#8b97b4;font-size:17px;cursor:pointer;line-height:1}
#chatLog{flex:1;overflow-y:auto;padding:9px 12px;display:flex;flex-direction:column;gap:6px;font-size:12px;line-height:1.45;min-height:80px}
#chatLog .cm b{color:#22d3ee}
#chatLog .cm.sys{color:#7f8db3;font-style:italic}
#chatForm{display:flex;gap:6px;padding:8px;border-top:1px solid rgba(255,255,255,.08)}
#chatInput{flex:1;min-width:0;background:#0a0e18;border:1px solid rgba(255,255,255,.12);border-radius:10px;color:#e8eefc;padding:9px 11px;font-size:12.5px;font-family:inherit;outline:none}
#chatForm button{border:1px solid rgba(79,124,255,.5);background:rgba(79,124,255,.22);color:#fff;border-radius:10px;padding:0 12px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit}

#settings{position:fixed;inset:0;z-index:9500;display:none;align-items:center;justify-content:center;background:rgba(3,5,10,.72);backdrop-filter:blur(8px);padding:20px}
#settings.on{display:flex}
#settingsCard{width:min(430px,94vw);max-height:86vh;overflow:auto;background:linear-gradient(180deg,#0d1220,#0a0d16);border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:18px;box-shadow:0 40px 100px -24px #000}
#settingsCard h3{margin:0 0 4px;font-size:15px;letter-spacing:.1em;text-transform:uppercase}
#settingsCard p.hint{margin:0 0 14px;font-size:11.5px;color:#7f8db3}
.setRow{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 0;border-top:1px solid rgba(255,255,255,.07);font-size:12.5px;color:#c7d2e8}
.setRow select,.setRow input[type=range]{accent-color:#4f7cff}
.setRow select{background:#0a0e18;color:#e8eefc;border:1px solid rgba(255,255,255,.14);border-radius:9px;padding:7px 9px;font-family:inherit;font-size:12px}
.setRow input[type=range]{width:130px}
.setRow .val{min-width:34px;text-align:right;color:#22d3ee;font-weight:800;font-size:11.5px}
#settingsClose{width:100%;margin-top:14px;padding:12px;border-radius:12px;border:1px solid rgba(79,124,255,.45);background:rgba(79,124,255,.18);color:#fff;font-family:inherit;font-weight:800;font-size:12.5px;letter-spacing:.08em;cursor:pointer}

#voiceBar{position:fixed;right:12px;bottom:88px;z-index:9;display:none;flex-direction:column;gap:6px;align-items:flex-end}
#voiceBar.on{display:flex}
#voiceBar button{pointer-events:auto;display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 12px;border-radius:11px;border:1px solid rgba(255,255,255,.12);background:rgba(8,11,20,.7);backdrop-filter:blur(12px);color:#dbe4f7;font-family:inherit;font-size:11.5px;font-weight:700;cursor:pointer}
#voiceBar button.on{border-color:rgba(34,197,94,.55);background:rgba(34,197,94,.2);color:#b6f7cd}
#voiceBar button.muted{border-color:rgba(239,68,68,.5);background:rgba(239,68,68,.18);color:#ffb4b4}
#voiceDot{width:7px;height:7px;border-radius:50%;background:#64719a}
#voiceBar button.on #voiceDot{background:#22c55e;box-shadow:0 0 9px #22c55e}

@media (max-width:560px){
  #chatPanel{bottom:82px;width:calc(100vw - 20px)}
  #voiceBar{bottom:80px}
  #topbar .tb span.lbl{display:none}
}

/* ================= AMHEEX — UI ESTILO ROBLOX ================= */
#login{position:fixed;inset:0;z-index:99990;display:none;align-items:center;justify-content:center;padding:18px;
  background:radial-gradient(120% 100% at 50% 0%,#123b6b 0%,#0a1a33 45%,#05070d 100%);font-family:var(--font)}
#login.on{display:flex}
#loginCard{width:min(392px,100%);background:linear-gradient(170deg,rgba(18,25,42,.97),rgba(8,12,22,.98));
  border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:24px 22px 20px;text-align:center;
  box-shadow:0 40px 90px -30px #000,0 0 0 1px rgba(255,255,255,.04) inset}
#loginLogo{width:78px;height:78px;border-radius:20px;object-fit:cover;box-shadow:0 14px 40px -12px rgba(0,200,255,.55)}
#loginCard h1{margin:12px 0 2px;font-size:25px;letter-spacing:.06em;font-weight:900;
  background:linear-gradient(90deg,#00e0ff,#7a5cff,#ff3d81);-webkit-background-clip:text;background-clip:text;color:transparent}
#loginCard .sub{margin:0 0 16px;font-size:12px;color:#93a1c2}
#loginTabs{display:flex;gap:6px;background:rgba(255,255,255,.05);border-radius:12px;padding:4px;margin-bottom:14px}
#loginTabs button{flex:1;padding:9px;border:0;border-radius:9px;background:transparent;color:#9aa8c7;font:800 11.5px var(--font);letter-spacing:.06em;cursor:pointer}
#loginTabs button.on{background:linear-gradient(180deg,#2b6cff,#1b4fd8);color:#fff;box-shadow:0 8px 20px -8px #2b6cff}
#loginCard label{display:block;text-align:left;font-size:10.5px;font-weight:800;letter-spacing:.1em;color:#7f8db0;margin:10px 0 5px}
#loginCard input{width:100%;padding:12px 13px;border-radius:11px;border:1px solid rgba(255,255,255,.13);
  background:#0a0f1c;color:#eaf0ff;font:600 14px var(--font);outline:none}
#loginCard input:focus{border-color:#2b6cff;box-shadow:0 0 0 3px rgba(43,108,255,.18)}
#loginMsg{min-height:18px;margin:10px 0 4px;font-size:12px;font-weight:700;color:#ff7a8a}
#loginMsg.ok{color:#3ee08a}
#loginGo{width:100%;padding:13px;border:0;border-radius:12px;cursor:pointer;color:#fff;font:900 14px var(--font);letter-spacing:.08em;
  background:linear-gradient(90deg,#00c2ff,#2b6cff 55%,#7a5cff);box-shadow:0 16px 34px -14px #2b6cff}
#loginGo:active{transform:scale(.985)}
#loginCard .tiny{margin-top:10px;font-size:10.5px;color:#6f7d9e;line-height:1.5}

/* editor de personagem */
#avatar{position:fixed;inset:0;z-index:99980;display:none;font-family:var(--font);pointer-events:none}
#avatar.on{display:block}
#avHead{position:absolute;left:50%;top:16px;transform:translateX(-50%);text-align:center;pointer-events:none}
#avHead b{display:block;font-size:13px;letter-spacing:.16em;color:#fff;text-shadow:0 3px 14px #000}
#avHead small{font-size:10.5px;color:#b9c6e2}
#avPanel{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:min(280px,calc(100vw - 28px));
  pointer-events:auto;background:rgba(8,12,22,.82);border:1px solid rgba(255,255,255,.12);border-radius:16px;
  padding:14px;backdrop-filter:blur(14px);box-shadow:0 24px 60px -24px #000}
.avRow{margin-bottom:10px}
.avRow>span{display:block;font-size:10px;font-weight:800;letter-spacing:.12em;color:#8391b3;margin-bottom:6px}
.avRow .sw{display:flex;gap:6px;flex-wrap:wrap}
.avRow .sw i{width:26px;height:26px;border-radius:8px;cursor:pointer;border:2px solid transparent;box-shadow:0 0 0 1px rgba(255,255,255,.18) inset}
.avRow .sw i.on{border-color:#fff;transform:scale(1.08)}
.avBtns{display:flex;gap:8px;margin-top:14px}
.avBtns button{flex:1;padding:11px 6px;border-radius:11px;border:1px solid rgba(255,255,255,.16);
  background:rgba(255,255,255,.07);color:#e8eefc;font:800 11px var(--font);letter-spacing:.05em;cursor:pointer}
.avBtns #avPlay{background:linear-gradient(90deg,#00c2ff,#2b6cff);border:0;color:#fff;font-size:12px}
#avHint{position:absolute;left:50%;bottom:22px;transform:translateX(-50%);font-size:11px;color:#c3cfe8;
  background:rgba(8,12,22,.7);padding:7px 14px;border-radius:999px;border:1px solid rgba(255,255,255,.1)}

/* HUD */
#rblx{position:fixed;inset:0;z-index:8;display:none;pointer-events:none;font-family:var(--font)}
#rblx.on{display:block}
#rbTopLeft{position:absolute;left:12px;top:12px;display:flex;align-items:center;gap:8px;pointer-events:auto}
.rbCircle{width:40px;height:40px;border-radius:50%;border:0;background:rgba(10,14,24,.55);color:#fff;font-size:17px;
  display:grid;place-items:center;cursor:pointer;backdrop-filter:blur(8px);box-shadow:0 6px 18px -8px #000}
.rbCircle:active{transform:scale(.94)}
#rbAccount{display:flex;align-items:center;gap:6px;padding:6px 11px 6px 7px;border-radius:999px;
  background:rgba(10,14,24,.55);backdrop-filter:blur(8px);font-size:11px;color:#dfe7ff;max-width:150px}
#rbAccount b{font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#rbAvatarDot{width:18px;height:18px;border-radius:50%;background:linear-gradient(135deg,#00e0ff,#7a5cff);flex:0 0 auto}

#rbStats{position:absolute;left:50%;top:10px;transform:translateX(-50%);display:flex;gap:8px;pointer-events:none}
.rbStat{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:10px;background:rgba(10,14,24,.55);
  backdrop-filter:blur(8px);font-size:12px;color:#cfe0ff;font-weight:800}
.rbStat b{color:#fff;font-size:13px}

#rbChat{position:absolute;left:12px;top:62px;width:min(330px,calc(100vw - 100px));pointer-events:auto;display:none;
  background:rgba(8,12,22,.78);border:1px solid rgba(255,255,255,.1);border-radius:12px;overflow:hidden;backdrop-filter:blur(12px)}
#rbChat.on{display:block}
#rbChatTabs{display:flex;background:rgba(255,255,255,.05)}
#rbChatTabs button{flex:1;padding:8px;border:0;background:transparent;color:#93a1c2;font:800 11px var(--font);cursor:pointer}
#rbChatTabs button.on{background:rgba(255,255,255,.1);color:#fff}
#rbChatLog{height:150px;overflow:auto;padding:8px 10px;display:flex;flex-direction:column;gap:5px;font-size:11.5px;line-height:1.4;color:#dbe4f7}
#rbChatLog .m b{color:#22d3ee}
#rbChatLog .m.me b{color:#3ee08a}
#rbChatLog .sys{color:#7f8db3;font-style:italic}
#rbChatForm{display:flex;gap:6px;padding:7px;border-top:1px solid rgba(255,255,255,.08)}
#rbChatForm input{flex:1;min-width:0;padding:8px 10px;border-radius:9px;border:1px solid rgba(255,255,255,.12);
  background:#0a0f1c;color:#eaf0ff;font:500 12px var(--font);outline:none}
#rbChatForm button{width:36px;border:0;border-radius:9px;background:#2b6cff;color:#fff;font-size:14px;cursor:pointer}

#rbLeft{position:absolute;left:12px;bottom:96px;display:flex;flex-direction:column;gap:6px;pointer-events:auto}
.rbSide{width:118px;padding:9px 8px;border:0;border-radius:9px;cursor:pointer;color:#fff;font:900 11.5px var(--font);
  letter-spacing:.05em;text-align:center;box-shadow:0 6px 16px -8px #000,0 0 0 2px rgba(0,0,0,.28) inset;transform:skewX(-4deg)}
.rbSide:active{transform:skewX(-4deg) scale(.96)}
.rbSide.loja{background:linear-gradient(180deg,#39d353,#1f9d3a)}
.rbSide.skin{background:linear-gradient(180deg,#b06bff,#7a3fd6)}
.rbSide.inv{background:linear-gradient(180deg,#c9d1de,#8d97a8);color:#20283a}
.rbSide.cfg{background:linear-gradient(180deg,#4f8cff,#2b5fd6)}
.rbSide.sair{background:linear-gradient(180deg,#ff5a6e,#c92b40)}

#rbJoy{position:absolute;right:22px;bottom:74px;width:96px;height:96px;border-radius:50%;pointer-events:auto;
  background:rgba(255,255,255,.10);border:2px solid rgba(255,255,255,.28);backdrop-filter:blur(6px);touch-action:none}
#rbJoyKnob{position:absolute;left:50%;top:50%;width:44px;height:44px;margin:-22px 0 0 -22px;border-radius:50%;
  background:rgba(255,255,255,.85);box-shadow:0 6px 16px rgba(0,0,0,.35);transition:transform .06s linear}
#rbJump{position:absolute;right:34px;bottom:186px;width:62px;height:62px;border-radius:50%;pointer-events:auto;
  border:2px solid rgba(255,255,255,.35);background:rgba(255,255,255,.14);color:#fff;font-size:24px;cursor:pointer;backdrop-filter:blur(6px)}
#rbJump:active{background:rgba(255,255,255,.32);transform:scale(.94)}
#rbCam{position:absolute;right:150px;bottom:86px;width:44px;height:44px;border-radius:50%;pointer-events:auto;
  border:2px solid rgba(255,255,255,.28);background:rgba(10,14,24,.5);color:#fff;font-size:17px;cursor:pointer;backdrop-filter:blur(6px)}
#rbBottom{position:absolute;left:50%;bottom:16px;transform:translateX(-50%);display:flex;gap:8px;pointer-events:auto}
#rbBottom button{padding:10px 18px;border-radius:10px;border:1px solid rgba(255,255,255,.18);
  background:rgba(10,14,24,.6);color:#e8eefc;font:800 11px var(--font);letter-spacing:.06em;cursor:pointer;backdrop-filter:blur(8px)}
#rbBottom #rbRun.on{background:rgba(34,197,94,.35);border-color:rgba(34,197,94,.6)}

/* modal de configurações */
#rbSettings{position:fixed;inset:0;z-index:99985;display:none;align-items:center;justify-content:center;padding:14px;
  background:rgba(4,7,14,.55);backdrop-filter:blur(6px);font-family:var(--font)}
#rbSettings.on{display:flex}
#rbSetCard{width:min(660px,100%);max-height:calc(100dvh - 28px);display:flex;flex-direction:column;
  background:linear-gradient(180deg,rgba(26,28,36,.97),rgba(16,18,26,.98));border:1px solid rgba(255,255,255,.12);
  border-radius:16px;overflow:hidden;box-shadow:0 40px 100px -30px #000}
#rbSetTabs{display:flex;gap:2px;padding:8px 8px 0;border-bottom:1px solid rgba(255,255,255,.08);overflow:auto}
#rbSetTabs button{flex:1;min-width:86px;padding:10px 8px;border:0;background:transparent;color:#9aa6bd;
  font:700 11.5px var(--font);cursor:pointer;border-bottom:3px solid transparent;white-space:nowrap}
#rbSetTabs button.on{color:#fff;border-bottom-color:#fff}
.rbSetBody{display:none;padding:14px 18px;overflow:auto;color:#e6ecfa}
.rbSetBody.on{display:block}
.rbSetBody h4{margin:6px 0 8px;font-size:15px;font-weight:900;color:#fff}
.rbRow{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 0;
  border-bottom:1px solid rgba(255,255,255,.06);font-size:12.5px;color:#c6d0e6}
.rbRow b{font-weight:700;color:#fff;font-size:12px}
.rbRow select,.rbSetBody select{padding:7px 9px;border-radius:8px;border:1px solid rgba(255,255,255,.16);
  background:#12161f;color:#e8eefc;font:600 12px var(--font);outline:none}
.rbRow input[type=range]{width:120px;accent-color:#2b6cff}
#rbVolSegs{display:flex;gap:3px}
#rbVolSegs i{width:13px;height:18px;border-radius:3px;background:rgba(255,255,255,.18);cursor:pointer}
#rbVolSegs i.on{background:#fff}
.rbHint{font-size:12px;line-height:1.6;color:#a8b4cc;margin:6px 0}
#rbPeople{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
.rbPerson{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:9px;background:rgba(255,255,255,.05);font-size:12px;color:#dfe7ff}
.rbPerson i{width:10px;height:10px;border-radius:50%;background:#3ee08a}
.rbPerson small{margin-left:auto;color:#7f8db3;font-size:10px}
#rbGallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;margin-top:8px}
.rbCard{padding:10px;border-radius:10px;background:rgba(255,255,255,.05);font-size:11px;color:#cfd9ef;text-align:center}
.rbCard b{display:block;color:#fff;font-size:11.5px;margin-bottom:2px}
#rbSetFooter{display:flex;gap:8px;padding:10px 14px;border-top:1px solid rgba(255,255,255,.08);background:rgba(0,0,0,.25)}
#rbSetFooter button{flex:1;padding:11px;border-radius:9px;border:1px solid rgba(255,255,255,.16);
  background:rgba(255,255,255,.06);color:#dfe7ff;font:800 12px var(--font);cursor:pointer}
#rbSetFooter #rbCont{background:linear-gradient(180deg,#2b6cff,#1b4fd8);border:0;color:#fff}
#rbReportGo{margin-top:10px;width:100%;padding:11px;border:0;border-radius:9px;background:#c92b40;color:#fff;font:800 12px var(--font);cursor:pointer}

@media (max-width:560px){
  #rbLeft{bottom:92px;gap:5px}
  .rbSide{width:104px;font-size:10.5px;padding:8px 6px}
  #rbJoy{width:88px;height:88px;right:16px;bottom:70px}
  #rbJump{right:28px;bottom:172px;width:56px;height:56px}
  #rbCam{right:132px;bottom:78px}
  #avPanel{left:10px;right:10px;top:auto;bottom:56px;transform:none;width:auto}
  #avHint{bottom:12px}
}


/* ---- legado escondido: HUD Roblox substitui ---- */
#controls,#health,#mpBtn,#mpPanel,#hint,#crosshair{display:none!important}
</style>
</head>
<body>
<canvas id="game" touch-action="none" aria-label="Mundo 3D"></canvas>
<div id="vignette"></div>

<div id="hud"></div>

<!-- ===== BOOT / CARREGAMENTO ===== -->
<div id="boot">
  <img id="bootLogo" src="https://storage.amheex.workers.dev/AMHEEX/icon.png" alt="AMHEEX GAME">
  <div id="bootName">AMHEEX <span>GAME</span></div>
  <div id="bootBarWrap">
    <div id="bootBar"><i id="bootFill"></i></div>
    <div id="bootPct">0%</div>
  </div>
  <div id="bootTip">Preparando o mundo, carregando a AMHEEX AI…</div>
</div>

<!-- ================= LOGIN / REGISTRO ================= -->
<div id="login">
  <div id="loginCard">
    <img id="loginLogo" src="https://storage.amheex.workers.dev/AMHEEX/icon.png" alt="AMHEEX">
    <h1>AMHEEX GAME</h1>
    <p class="sub">Entre na sua conta para jogar no mundo global</p>
    <div id="loginTabs">
      <button type="button" data-tab="entrar" class="on">ENTRAR</button>
      <button type="button" data-tab="criar">CRIAR CONTA</button>
    </div>
    <label for="loginUser">Usuário</label>
    <input id="loginUser" maxlength="18" autocomplete="username" placeholder="seu usuário">
    <label for="loginPass">Senha</label>
    <input id="loginPass" type="password" maxlength="40" autocomplete="current-password" placeholder="sua senha">
    <div id="loginNomeWrap" hidden>
      <label for="loginNome">Nome no jogo</label>
      <input id="loginNome" maxlength="18" placeholder="como quer aparecer">
    </div>
    <div id="loginMsg"></div>
    <button id="loginGo" type="button">ENTRAR NO MUNDO</button>
    <div class="tiny">Sua conta guarda personagem, skin e posição no mapa global.</div>
  </div>
</div>

<!-- ================= EDITOR DE PERSONAGEM ================= -->
<div id="avatar">
  <div id="avHead">
    <b>EDITOR DE PERSONAGEM</b>
    <small>Monte seu avatar antes de entrar no mundo</small>
  </div>
  <div id="avPanel">
    <div class="avRow"><span>Tom de pele</span><div class="sw" id="avPele"></div></div>
    <div class="avRow"><span>Camisa</span><div class="sw" id="avCamisa"></div></div>
    <div class="avRow"><span>Calça</span><div class="sw" id="avCalca"></div></div>
    <div class="avRow"><span>Cabelo</span><div class="sw" id="avCabelo"></div></div>
    <div class="avBtns">
      <button id="avRandom" type="button">🎲 ALEATÓRIO</button>
      <button id="avPlay" type="button">▶ JOGAR</button>
    </div>
  </div>
  <div id="avHint">Arraste para girar o personagem</div>
</div>

<!-- ================= HUD ESTILO ROBLOX ================= -->
<div id="rblx">
  <div id="rbTopLeft">
    <button id="rbMenu" class="rbCircle" type="button" title="Menu">☰</button>
    <button id="rbChatBtn" class="rbCircle" type="button" title="Chat">💬</button>
    <div id="rbAccount"><i id="rbAvatarDot"></i><b id="rbUser">Jogador</b></div>
  </div>

  <div id="rbStats">
    <div class="rbStat"><span>👥</span><b id="rbOnline">0</b></div>
    <div class="rbStat"><span>💬</span><b id="rbChatCount">0</b></div>
  </div>

  <div id="rbChat">
    <div id="rbChatTabs">
      <button type="button" data-tab="aqui" class="on">Aqui</button>
      <button type="button" data-tab="global">Global</button>
    </div>
    <div id="rbChatLog"></div>
    <form id="rbChatForm" autocomplete="off">
      <input id="rbChatInput" maxlength="180" placeholder="Toque aqui para escrever">
      <button type="submit">➤</button>
    </form>
  </div>

  <div id="rbLeft">
    <button class="rbSide loja" type="button" data-open="loja">LOJA</button>
    <button class="rbSide skin" type="button" data-open="skin">SKINS</button>
    <button class="rbSide inv" type="button" data-open="inv">INVENTÁRIO</button>
    <button class="rbSide cfg" type="button" data-open="config">CONFIG.</button>
    <button class="rbSide sair" type="button" data-open="sair">SAIR</button>
  </div>

  <div id="rbJoy"><div id="rbJoyKnob"></div></div>
  <button id="rbJump" type="button" title="Pular">⬆</button>
  <button id="rbCam" type="button" title="Câmera">🎥</button>
  <div id="rbBottom">
    <button id="rbRun" type="button">CORRER</button>
    <button id="rbAction" type="button">AÇÃO</button>
  </div>
</div>

<!-- ================= CONFIGURAÇÕES ESTILO ROBLOX ================= -->
<div id="rbSettings">
  <div id="rbSetCard">
    <div id="rbSetTabs">
      <button type="button" data-t="pessoas">👥 Pessoas</button>
      <button type="button" data-t="config" class="on">⚙ Config.</button>
      <button type="button" data-t="galeria">🖼 Galeria</button>
      <button type="button" data-t="denunciar">🚩 Denunciar</button>
      <button type="button" data-t="ajuda">❓ Ajuda</button>
    </div>

    <div class="rbSetBody on" data-body="config">
      <h4>Áudio</h4>
      <div class="rbRow"><span>Volume</span><div id="rbVolSegs"></div></div>
      <div class="rbRow"><span>Dispositivo de saída</span><b>Padrão (alto-falante)</b></div>
      <h4>Chat e idioma</h4>
      <div class="rbRow"><span>Mensagens reformuladas</span><b>Ligado</b></div>
      <div class="rbRow"><span>Alcance da voz</span><input id="rbVoiceRange" type="range" min="5" max="40" value="16"><b id="rbVoiceRangeV">16m</b></div>
      <h4>Gráficos</h4>
      <div class="rbRow"><span>Qualidade</span><select id="rbQuality"><option value="low">Baixa</option><option value="mid" selected>Média</option><option value="high">Alta</option></select></div>
      <div class="rbRow"><span>Sensibilidade</span><input id="rbSens" type="range" min="20" max="200" value="100"><b id="rbSensV">100</b></div>
      <div class="rbRow"><span>Mostrar nomes</span><select id="rbNames"><option value="on" selected>Sim</option><option value="off">Não</option></select></div>
      <h4>Microfone</h4>
      <div class="rbRow"><span>Voz por proximidade</span><button id="rbMic" type="button">LIGAR (V)</button></div>
    </div>

    <div class="rbSetBody" data-body="pessoas">
      <h4>Neste servidor</h4>
      <div id="rbPeople"></div>
      <div class="rbRow"><span>Convidar amigos</span><b>Compartilhe o link do jogo</b></div>
    </div>

    <div class="rbSetBody" data-body="galeria">
      <h4>Galeria do mundo</h4>
      <p class="rbHint">Tudo que a AMHEEX AI cria no mapa aparece aqui. Peça no chat da IA: “crie uma torre”, “coloque uma casa”, “mude para floresta”.</p>
      <div id="rbGallery"></div>
    </div>

    <div class="rbSetBody" data-body="denunciar">
      <h4>Denunciar jogador</h4>
      <p class="rbHint">Escolha um jogador e o motivo. A denúncia fica registrada no servidor.</p>
      <select id="rbReportWho"></select>
      <select id="rbReportWhy"><option>Linguagem ofensiva</option><option>Assédio</option><option>Spam</option><option>Outro</option></select>
      <button id="rbReportGo" type="button">ENVIAR DENÚNCIA</button>
    </div>

    <div class="rbSetBody" data-body="ajuda">
      <h4>Como jogar</h4>
      <p class="rbHint"><b>Mover:</b> WASD/setas ou o joystick flutuante. <b>Pular:</b> Espaço ou botão ⬆. <b>Correr:</b> Shift ou CORRER. <b>Câmera:</b> arraste na tela ou botão 🎥.</p>
      <p class="rbHint"><b>IA:</b> toque no ícone da AMHEEX AI e peça em português. Ela cria formas 3D no mapa, mas nunca edita o personagem de outro jogador.</p>
      <p class="rbHint"><b>Voz:</b> tecla V liga/desliga. Só quem está perto escuta, como no Roblox.</p>
    </div>

    <div id="rbSetFooter">
      <button id="rbSair" type="button">Sair</button>
      <button id="rbRegen" type="button">Regenerar</button>
      <button id="rbCont" type="button">Continuar</button>
    </div>
  </div>
</div>

<div id="crosshair"></div>
<div id="health"><span>HP</span><div class="bar"><i id="healthBar"></i></div><b id="healthN">100</b></div>
<button id="mpBtn" type="button">🌐 <b id="mpCount">0</b> online</button>
<div id="mpPanel">
  <div id="mpHead"><span>MULTIPLAYER <small>por IP</small></span><button id="mpClose" type="button" style="background:none;border:0;color:#8b97b4;font-size:18px;cursor:pointer">×</button></div>
  <div id="mpPlayers"></div>
  <div id="mpChat"></div>
  <form id="mpForm" autocomplete="off"><input id="mpMsg" maxlength="180" placeholder="Chat do mundo…"><button type="submit">Enviar</button></form>
</div>
<div id="controls">
  <div class="dpad">
    <button type="button" class="up" data-move="f">▲</button>
    <button type="button" class="left" data-move="l">◀</button>
    <button type="button" class="down" data-move="b">▼</button>
    <button type="button" class="right" data-move="r">▶</button>
  </div>
  <div class="acts">
    <button type="button" class="run" data-act="run">CORRER</button>
    <button type="button" class="jump" data-act="jump">PULAR</button>
    <button type="button" class="fire" data-act="fire">ATIRAR</button>
  </div>
  <div class="cam"><button type="button" id="camToggle">CÂMERA</button></div>
</div>

<div id="hint" class="off" style="display:none">Arraste para girar a câmera · toque no chão para mover a bola · fale com a IA no ícone</div>

<div id="amxai-root" data-mode="float" data-open="0">
  <button id="amxai-fab" type="button" aria-label="Abrir AMHEEX AI" title="AMHEEX AI">
    <img src="assets/ia-icon.png" alt="AMHEEX AI" onerror="this.style.display='none'">
    <span class="amxai-pro" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"/></svg></span>
    <span class="amxai-ping"></span>
  </button>

  <section id="amxai-panel" hidden aria-label="AMHEEX AI">
    <header id="amxai-head">
      <div class="amxai-id">
        <span class="amxai-avatar"><img src="assets/ia-icon.png" alt="" onerror="this.style.display='none'"></span>
        <div><b>AMHEEX AI</b><small><i></i><span id="amxai-status">Online · motor local</span></small></div>
      </div>
      <div class="amxai-head-btns">
        <button id="amxai-pin" type="button" title="Fixar / voltar a flutuar" aria-label="Fixar painel">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4h6l-1 6 4 3v2H6v-2l4-3-1-6z"/><path d="M12 15v6"/></svg>
        </button>
        <button id="amxai-clear" type="button" title="Limpar conversa" aria-label="Limpar conversa">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M4 7h16M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>
        </button>
        <button id="amxai-close" type="button" title="Fechar" aria-label="Fechar chat">&times;</button>
      </div>
    </header>
    <div id="amxai-msgs" aria-live="polite"></div>
    <div id="amxai-chips"></div>
    <form id="amxai-form" autocomplete="off">
      <textarea id="amxai-input" rows="1" placeholder="Fale ou escreva: “crie game”, “ative o multiplayer”…" aria-label="Mensagem para a IA"></textarea>
      <button id="amxai-mic" type="button" aria-label="Falar com a IA" title="Falar com a IA" aria-pressed="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3z"/><path d="M19 11a7 7 0 0 1-14 0"/><path d="M12 18v3"/><path d="M8.5 21h7"/></svg>
      </button>
      <button id="amxai-send" type="submit" aria-label="Enviar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
      </button>
    </form>
    <div class="amxai-foot">
      <div class="left">
        <button type="button" class="sw on" id="amxai-voice" title="Ligar/desligar a voz da IA">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5L6 9H3v6h3l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/></svg>
          <span>voz</span>
        </button>
        <button type="button" class="sw" id="amxai-live" title="Conversa em tempo real (microfone contínuo)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="2.5"/><path d="M6.5 6.5a7.8 7.8 0 0 0 0 11M17.5 6.5a7.8 7.8 0 0 1 0 11"/></svg>
          <span>tempo real</span>
        </button>
      </div>
      <span id="amxai-count">0 msg</span>
    </div>
  </section>
</div>
<script src="lib/babylon.js"></script>
<script src="lib/babylonjs.loaders.min.js"></script>
<script>
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
</script>
<script>
/* ============================================================
   AMHEEX GAME — UI estilo Roblox
   boot • login/registro • editor de personagem • HUD • joystick
   configurações em abas • voz por proximidade (WebRTC)
   ============================================================ */
(function () {
  'use strict';

  function $(id) { return document.getElementById(id); }
  function on(el, ev, fn) { if (el) el.addEventListener(ev, fn); }
  function lsGet(k, d) { try { var v = localStorage.getItem(k); return v == null ? d : v; } catch (e) { return d; } }
  function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function lsDel(k) { try { localStorage.removeItem(k); } catch (e) {} }

  var CFG = {
    volume: Number(lsGet('amx_volume', 80)),
    voiceRange: Number(lsGet('amx_voice_range', 16)),
    sens: Number(lsGet('amx_sens', 100)),
    names: lsGet('amx_names', 'on') === 'on',
    mic: lsGet('amx_mic', 'off') === 'on',
    quality: lsGet('amx_quality', 'mid')
  };

  var SKIN = { pele: '#c68642', cor: '#4f7cff', calca: '#1f2a44', cabelo: '#1b1b1b', seed: 12345 };
  var USER = null;
  window.AMX_UI = { cfg: CFG, skin: SKIN, user: function () { return USER; } };
  window.AMX_USER = null;

  /* ---------------- 1) BOOT ---------------- */
  var boot = $('boot'), bootFill = $('bootFill'), bootPct = $('bootPct'), bootTip = $('bootTip');
  var pct = 0, bootDone = false;
  var tips = [
    'Preparando o mundo, carregando a AMHEEX AI…',
    'Gerando o chão verde e o céu azul…',
    'Conectando o mundo global…',
    'Acordando o NPC AMHEEX BOT…',
    'Quase lá — abrindo o login…'
  ];
  function paintBoot(p) {
    pct = Math.max(0, Math.min(100, p));
    if (bootFill) bootFill.style.width = pct + '%';
    if (bootPct) bootPct.textContent = Math.round(pct) + '%';
    if (bootTip) bootTip.textContent = tips[Math.min(tips.length - 1, Math.floor(pct / 22))];
  }
  var bootTimer = setInterval(function () {
    if (bootDone) return;
    var step = pct < 55 ? 2.6 : pct < 85 ? 1.4 : 0.6;
    paintBoot(pct + step);
    if (pct >= 100) finishBoot();
  }, 55);
  function finishBoot() {
    if (bootDone) return;
    bootDone = true; clearInterval(bootTimer); paintBoot(100);
    setTimeout(function () { if (boot) boot.classList.add('off'); openLogin(); }, 420);
  }

  /* ---------------- 2) LOGIN ---------------- */
  var loginEl = $('login'), loginMsg = $('loginMsg'), modoLogin = 'entrar';

  function openLogin() {
    if (loginEl) loginEl.classList.add('on');
    var u = lsGet('amx_usuario', '');
    if (u && $('loginUser')) $('loginUser').value = u;
    setTimeout(function () { var el = $('loginUser'); if (el) el.focus(); }, 120);
    var tok = lsGet('amx_token', '');
    if (tok) {
      fetch('?api=perfil&token=' + encodeURIComponent(tok)).then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok && j.user) { USER = j.user; window.AMX_USER = USER; if (loginEl) loginEl.classList.remove('on'); enterAvatar(); } })
        .catch(function () {});
    }
  }
  function setLoginTab(t) {
    modoLogin = t;
    var tabs = document.querySelectorAll('#loginTabs button');
    for (var i = 0; i < tabs.length; i++) tabs[i].classList.toggle('on', tabs[i].getAttribute('data-tab') === t);
    var wrap = $('loginNomeWrap'); if (wrap) wrap.hidden = (t !== 'criar');
    var go = $('loginGo'); if (go) go.textContent = t === 'criar' ? 'CRIAR CONTA E JOGAR' : 'ENTRAR NO MUNDO';
    if (loginMsg) { loginMsg.textContent = ''; loginMsg.className = ''; }
  }
  var tabBtns = document.querySelectorAll('#loginTabs button');
  for (var tb = 0; tb < tabBtns.length; tb++) {
    (function (b) { b.addEventListener('click', function () { setLoginTab(b.getAttribute('data-tab')); }); })(tabBtns[tb]);
  }
  function msg(txt, ok) { if (!loginMsg) return; loginMsg.textContent = txt; loginMsg.className = ok ? 'ok' : ''; }

  function doAuth() {
    var usuario = (($('loginUser') && $('loginUser').value) || '').trim().toLowerCase();
    var senha = (($('loginPass') && $('loginPass').value) || '');
    var nome = (($('loginNome') && $('loginNome').value) || '').trim();
    if (usuario.length < 3) { msg('Usuário precisa de 3 caracteres ou mais.'); return; }
    if (senha.length < 4) { msg('Senha precisa de 4 caracteres ou mais.'); return; }
    var go = $('loginGo'); if (go) { go.disabled = true; go.textContent = 'CONECTANDO…'; }
    var acao = modoLogin === 'criar' ? 'registrar' : 'login';
    fetch('?api=' + acao, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ usuario: usuario, senha: senha, nome: nome || usuario })
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok) {
        msg((j && j.erro) || 'Não foi possível entrar.');
        if (go) { go.disabled = false; go.textContent = modoLogin === 'criar' ? 'CRIAR CONTA E JOGAR' : 'ENTRAR NO MUNDO'; }
        return;
      }
      USER = j.user; window.AMX_USER = USER;
      lsSet('amx_token', USER.token);
      lsSet('amx_usuario', USER.usuario);
      lsSet('amx_nome', USER.nome || USER.usuario);
      msg(j.novo ? 'Conta criada! Montando seu personagem…' : 'Bem-vindo de volta!', true);
      if (j.user.skin && j.user.skin.pele) {
        SKIN = {
          pele: j.user.skin.pele, cor: j.user.skin.cor || '#4f7cff',
          calca: j.user.skin.calca || '#1f2a44', cabelo: j.user.skin.cabelo || '#1b1b1b',
          seed: Number(j.user.skin.seed || 12345)
        };
        window.AMX_UI.skin = SKIN;
      }
      setTimeout(function () { if (loginEl) loginEl.classList.remove('on'); enterAvatar(); }, 420);
    }).catch(function () {
      msg('Servidor indisponível. Tente novamente.');
      if (go) { go.disabled = false; go.textContent = 'ENTRAR NO MUNDO'; }
    });
  }
  on($('loginGo'), 'click', doAuth);
  on($('loginPass'), 'keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doAuth(); } });
  on($('loginUser'), 'keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); var p = $('loginPass'); if (p) p.focus(); } });

  /* ---------------- 3) EDITOR DE PERSONAGEM ---------------- */
  var avatarEl = $('avatar');
  var PALETAS = {
    pele: ['#f6d5b8', '#e8b98f', '#c68642', '#a5673f', '#8d5524', '#5c3a21', '#f1c9a5', '#3f2a1c'],
    cor: ['#4f7cff', '#ff3d81', '#22c55e', '#f59e0b', '#a855f7', '#ef4444', '#0ea5e9', '#e2e8f0', '#111827'],
    calca: ['#1f2a44', '#111827', '#3b4a6b', '#6b7280', '#7c2d12', '#14532d', '#4c1d95', '#e5e7eb'],
    cabelo: ['#1b1b1b', '#3b2314', '#6b4423', '#d4a24c', '#8b2f2f', '#e5e7eb', '#7c3aed', '#0ea5e9']
  };
  function swId(k) { return 'av' + k.charAt(0).toUpperCase() + k.slice(1); }
  function buildSwatches() {
    ['pele', 'cor', 'calca', 'cabelo'].forEach(function (k) {
      var box = $(swId(k)); if (!box) return;
      box.innerHTML = '';
      PALETAS[k].forEach(function (hex) {
        var i = document.createElement('i');
        i.style.background = hex; i.setAttribute('data-hex', hex);
        i.addEventListener('click', function () {
          SKIN[k] = hex; paintSwatches();
          if (window.AMX_PREVIEW) window.AMX_PREVIEW(SKIN);
        });
        box.appendChild(i);
      });
    });
    paintSwatches();
  }
  function paintSwatches() {
    ['pele', 'cor', 'calca', 'cabelo'].forEach(function (k) {
      var box = $(swId(k)); if (!box) return;
      var kids = box.querySelectorAll('i');
      for (var i = 0; i < kids.length; i++) kids[i].classList.toggle('on', kids[i].getAttribute('data-hex') === SKIN[k]);
    });
  }
  function enterAvatar() {
    if (avatarEl) avatarEl.classList.add('on');
    if (avatarEl && !avatarEl._built) { buildSwatches(); avatarEl._built = true; }
    paintSwatches();
    var nome = (USER && (USER.nome || USER.usuario)) || 'Jogador';
    if (window.AMX_SET) window.AMX_SET.nome = nome;
    setTimeout(function () { if (window.AMX_PREVIEW) window.AMX_PREVIEW(SKIN); }, 220);
  }
  on($('avRandom'), 'click', function () {
    function pick(a) { return a[Math.floor(Math.random() * a.length)]; }
    SKIN.pele = pick(PALETAS.pele); SKIN.cor = pick(PALETAS.cor);
    SKIN.calca = pick(PALETAS.calca); SKIN.cabelo = pick(PALETAS.cabelo);
    SKIN.seed = Math.floor(1 + Math.random() * 999999);
    paintSwatches();
    if (window.AMX_PREVIEW) window.AMX_PREVIEW(SKIN);
  });
  on($('avPlay'), 'click', function () {
    var nome = (USER && (USER.nome || USER.usuario)) || 'Jogador';
    if (avatarEl) avatarEl.classList.remove('on');
    if (USER && USER.token) {
      fetch('?api=salvar_perfil', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: USER.token, skin: SKIN, nome: nome })
      }).then(function (r) { return r.json(); }).then(function (j) { if (j && j.ok && j.user) { USER = j.user; window.AMX_USER = USER; } }).catch(function () {});
    }
    if (window.AMX_SKIN) window.AMX_SKIN(SKIN);
    if (window.AMX_START) window.AMX_START(nome);
    setTimeout(function () { if (CFG.mic) startVoice(); }, 900);
  });

  /* ---------------- 4) HUD ---------------- */
  var rbChat = $('rbChat');
  function toggleChat(force) {
    if (!rbChat) return;
    var open = force == null ? !rbChat.classList.contains('on') : !!force;
    rbChat.classList.toggle('on', open);
    if (open) { var ci = $('rbChatInput'); if (ci) setTimeout(function () { ci.focus(); }, 60); }
  }
  on($('rbChatBtn'), 'click', function () { toggleChat(); });
  on($('rbMenu'), 'click', function () { openSettings(true, 'config'); });

  var chatTab = 'global';
  var chatTabs = document.querySelectorAll('#rbChatTabs button');
  for (var ct = 0; ct < chatTabs.length; ct++) {
    (function (b) {
      b.addEventListener('click', function () {
        chatTab = b.getAttribute('data-tab');
        for (var i = 0; i < chatTabs.length; i++) chatTabs[i].classList.toggle('on', chatTabs[i] === b);
        if (window.MP && window.MP.renderChat) window.MP.renderChat();
      });
    })(chatTabs[ct]);
  }
  on($('rbChatForm'), 'submit', function (e) {
    e.preventDefault();
    var inp = $('rbChatInput');
    var t = inp && inp.value ? inp.value.trim() : '';
    if (!t) return;
    if (window.MP && window.MP.sendChat) window.MP.sendChat(t);
    if (inp) inp.value = '';
  });

  var sides = document.querySelectorAll('#rbLeft .rbSide');
  for (var sd = 0; sd < sides.length; sd++) {
    (function (b) {
      b.addEventListener('click', function () {
        var o = b.getAttribute('data-open');
        if (o === 'sair') { sair(); return; }
        if (o === 'skin') { if (avatarEl) avatarEl.classList.add('on'); return; }
        openSettings(true, (o === 'loja' || o === 'inv') ? 'galeria' : 'config');
      });
    })(sides[sd]);
  }

  /* ---------------- 5) JOYSTICK ---------------- */
  var joy = $('rbJoy'), knob = $('rbJoyKnob');
  var joyActive = false, joyId = null;
  function setMove(f, b, l, r) {
    var mi = window.WORLD && window.WORLD.player && window.WORLD.player.moveInput;
    if (!mi) return;
    mi.f = f; mi.b = b; mi.l = l; mi.r = r;
  }
  function joyStart(e) {
    joyActive = true; joyId = e.pointerId;
    if (joy && joy.setPointerCapture) { try { joy.setPointerCapture(e.pointerId); } catch (err) {} }
    joyMove(e);
  }
  function joyMove(e) {
    if (!joyActive || (joyId != null && e.pointerId !== joyId)) return;
    var rect = joy.getBoundingClientRect();
    var cx = rect.left + rect.width / 2, cy = rect.top + rect.height / 2;
    var dx = e.clientX - cx, dy = e.clientY - cy;
    var max = rect.width / 2 - 8;
    var d = Math.sqrt(dx * dx + dy * dy);
    if (d > max) { dx = dx / d * max; dy = dy / d * max; }
    if (knob) knob.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
    var nx = dx / max, ny = dy / max;
    setMove(ny < -0.25, ny > 0.25, nx < -0.25, nx > 0.25);
    e.preventDefault();
  }
  function joyEnd(e) {
    if (joyId != null && e.pointerId !== joyId) return;
    joyActive = false; joyId = null;
    if (knob) knob.style.transform = 'translate(0,0)';
    setMove(false, false, false, false);
  }
  if (joy) {
    joy.addEventListener('pointerdown', joyStart);
    joy.addEventListener('pointermove', joyMove);
    joy.addEventListener('pointerup', joyEnd);
    joy.addEventListener('pointercancel', joyEnd);
  }
  on($('rbJump'), 'pointerdown', function (e) { e.preventDefault(); if (window.WORLD && window.WORLD.player) window.WORLD.player.jump = true; });
  var correndo = false;
  on($('rbRun'), 'click', function () {
    correndo = !correndo;
    this.classList.toggle('on', correndo);
    if (window.WORLD && window.WORLD.player) window.WORLD.player.run = correndo;
  });
  on($('rbCam'), 'click', function () {
    if (!window.WORLD || !window.WORLD.control) return;
    var m = (window.WORLD.player && window.WORLD.player.mode) || 'third';
    window.WORLD.control(m === 'third' ? 'first' : m === 'first' ? 'shooter' : 'third');
  });
  on($('rbAction'), 'click', function () {
    if (window.WORLD && window.WORLD.player && window.WORLD.player.mode === 'shooter') {
      if (window.WORLD.player.fire) window.WORLD.player.fire();
    } else if (window.AMX_UI && window.AMX_UI.openAI) window.AMX_UI.openAI();
  });

  /* ---------------- 6) CONFIGURAÇÕES ---------------- */
  var setEl = $('rbSettings');
  function openSettings(force, tab) {
    if (!setEl) return;
    var open = force == null ? !setEl.classList.contains('on') : !!force;
    setEl.classList.toggle('on', open);
    if (open) {
      if (tab) selectSetTab(tab);
      syncSettingsUI();
      if (window.MP && window.MP.renderPlayers) window.MP.renderPlayers();
      renderGallery();
    }
  }
  function selectSetTab(t) {
    var bs = document.querySelectorAll('#rbSetTabs button');
    for (var i = 0; i < bs.length; i++) bs[i].classList.toggle('on', bs[i].getAttribute('data-t') === t);
    var bodies = document.querySelectorAll('.rbSetBody');
    for (var k = 0; k < bodies.length; k++) bodies[k].classList.toggle('on', bodies[k].getAttribute('data-body') === t);
  }
  var setTabs = document.querySelectorAll('#rbSetTabs button');
  for (var st = 0; st < setTabs.length; st++) {
    (function (b) { b.addEventListener('click', function () { selectSetTab(b.getAttribute('data-t')); }); })(setTabs[st]);
  }
  on($('rbCont'), 'click', function () { openSettings(false); });
  on(setEl, 'click', function (e) { if (e.target === setEl) openSettings(false); });

  var segs = $('rbVolSegs');
  if (segs) {
    segs.innerHTML = '';
    for (var si = 0; si < 10; si++) {
      var seg = document.createElement('i');
      seg.setAttribute('data-v', String((si + 1) * 10));
      seg.addEventListener('click', function () {
        CFG.volume = Number(this.getAttribute('data-v'));
        lsSet('amx_volume', CFG.volume);
        if (window.AMX_SET) window.AMX_SET.volume = CFG.volume / 100;
        paintSegs();
      });
      segs.appendChild(seg);
    }
  }
  function paintSegs() {
    if (!segs) return;
    var kids = segs.querySelectorAll('i');
    for (var i = 0; i < kids.length; i++) kids[i].classList.toggle('on', (i + 1) * 10 <= CFG.volume);
  }
  function syncSettingsUI() {
    var q = $('rbQuality'); if (q) q.value = CFG.quality;
    var vr = $('rbVoiceRange'); if (vr) vr.value = CFG.voiceRange;
    var vrv = $('rbVoiceRangeV'); if (vrv) vrv.textContent = CFG.voiceRange + 'm';
    var s = $('rbSens'); if (s) s.value = CFG.sens;
    var sv = $('rbSensV'); if (sv) sv.textContent = CFG.sens;
    var nm = $('rbNames'); if (nm) nm.value = CFG.names ? 'on' : 'off';
    var mic = $('rbMic'); if (mic) mic.textContent = voice.on ? (voice.muted ? 'MUDO (V)' : 'LIGADO (V)') : 'LIGAR (V)';
    paintSegs();
  }
  on($('rbQuality'), 'change', function () {
    CFG.quality = this.value; lsSet('amx_quality', CFG.quality);
    if (window.WORLD && window.WORLD.setQuality) window.WORLD.setQuality(CFG.quality);
  });
  on($('rbVoiceRange'), 'input', function () {
    CFG.voiceRange = Number(this.value); lsSet('amx_voice_range', CFG.voiceRange);
    var v = $('rbVoiceRangeV'); if (v) v.textContent = CFG.voiceRange + 'm';
    if (window.AMX_SET) window.AMX_SET.voiceRange = CFG.voiceRange;
  });
  on($('rbSens'), 'input', function () {
    CFG.sens = Number(this.value); lsSet('amx_sens', CFG.sens);
    var v = $('rbSensV'); if (v) v.textContent = CFG.sens;
    if (window.AMX_SET) window.AMX_SET.sens = CFG.sens / 100;
  });
  on($('rbNames'), 'change', function () {
    CFG.names = this.value === 'on'; lsSet('amx_names', CFG.names ? 'on' : 'off');
    if (window.AMX_NAMES) window.AMX_NAMES(CFG.names);
  });
  on($('rbMic'), 'click', function () { if (voice.on) toggleMute(); else startVoice(); syncSettingsUI(); });

  function renderGallery() {
    var box = $('rbGallery'); if (!box) return;
    var objs = (window.WORLD && window.WORLD.state && window.WORLD.state.objects) || [];
    var html = '';
    for (var i = 0; i < objs.length; i++) {
      var o = objs[i];
      if (o.isAvatar) continue;
      html += '<div class="rbCard"><b>' + (o.nome || o.ent) + '</b>' + (o.forma || o.ent) + '</div>';
    }
    box.innerHTML = html || '<div class="rbCard"><b>Mapa vazio</b>peça algo para a IA</div>';
  }
  on($('rbReportGo'), 'click', function () {
    var who = $('rbReportWho'), why = $('rbReportWhy');
    if (!who || !who.value) { if (window.MP) window.MP.toast('Nenhum jogador para denunciar.'); return; }
    fetch('?api=mp_chat', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: 'denuncia', name: 'DENÚNCIA', msg: 'Denúncia contra ' + who.options[who.selectedIndex].text + ': ' + (why ? why.value : '') })
    }).catch(function () {});
    if (window.MP) window.MP.toast('Denúncia registrada.');
  });
  on($('rbRegen'), 'click', function () {
    openSettings(false);
    if (avatarEl) avatarEl.classList.add('on');
    buildSwatches();
    if (window.AMX_PREVIEW) window.AMX_PREVIEW(SKIN);
  });

  function sair() {
    if (USER && USER.token) {
      fetch('?api=sair', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token: USER.token }) }).catch(function () {});
    }
    lsDel('amx_token');
    USER = null; window.AMX_USER = null;
    openSettings(false);
    if (avatarEl) avatarEl.classList.remove('on');
    var hud = $('rblx'); if (hud) hud.classList.remove('on');
    if (window.MP && window.MP.enable) window.MP.enable(false);
    if (loginEl) loginEl.classList.add('on');
  }
  on($('rbSair'), 'click', sair);

  /* ---------------- 7) VOZ POR PROXIMIDADE ---------------- */
  var voice = { on: false, muted: false, stream: null, peers: {}, pollTimer: null, starting: false };
  window.AMX_VOICE = voice;
  var ICE = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }, { urls: 'stun:stun1.l.google.com:19302' }] };

  function startVoice() {
    if (voice.on || voice.starting) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      if (window.MP) window.MP.toast('Este navegador não suporta microfone.'); return;
    }
    voice.starting = true;
    navigator.mediaDevices.getUserMedia({ audio: true, video: false }).then(function (st) {
      voice.stream = st; voice.on = true; voice.starting = false; voice.muted = false;
      startPolling(); syncSettingsUI();
      if (window.MP) window.MP.toast('Voz ligada — só quem está perto escuta.');
    }).catch(function () { voice.starting = false; if (window.MP) window.MP.toast('Microfone bloqueado pelo navegador.'); });
  }
  function stopVoice() {
    voice.on = false; stopPolling();
    for (var id in voice.peers) closePeer(id);
    voice.peers = {};
    if (voice.stream) { try { voice.stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {} voice.stream = null; }
    syncSettingsUI();
  }
  function toggleMute() {
    voice.muted = !voice.muted;
    if (voice.stream) { try { voice.stream.getAudioTracks().forEach(function (t) { t.enabled = !voice.muted; }); } catch (e) {} }
    syncSettingsUI();
    if (window.MP) window.MP.toast(voice.muted ? 'Microfone mutado.' : 'Microfone ativo.');
  }
  function rtcSend(to, kind, data) {
    if (!window.MP || !window.MP.id || !to) return;
    fetch('?api=rtc_send', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ from: window.MP.id, to: to, kind: kind, data: data })
    }).catch(function () {});
  }
  function startPolling() { if (voice.pollTimer) return; voice.pollTimer = setInterval(pollSignals, 1200); pollSignals(); }
  function stopPolling() { if (voice.pollTimer) { clearInterval(voice.pollTimer); voice.pollTimer = null; } }
  function pollSignals() {
    if (!voice.on || !window.MP || !window.MP.id) return;
    fetch('?api=rtc_poll&id=' + encodeURIComponent(window.MP.id)).then(function (r) { return r.json(); })
      .then(function (j) { if (!j || !j.ok || !j.msgs) return; for (var i = 0; i < j.msgs.length; i++) handleSignal(j.msgs[i]); })
      .catch(function () {});
  }
  function handleSignal(m) {
    if (!m || !m.from) return;
    var id = m.from;
    if (m.kind === 'offer') {
      var pc = ensurePeer(id);
      pc.setRemoteDescription(new RTCSessionDescription(m.data)).then(function () { return pc.createAnswer(); })
        .then(function (ans) { return pc.setLocalDescription(ans); })
        .then(function () { rtcSend(id, 'answer', pc.localDescription); }).catch(function () {});
    } else if (m.kind === 'answer') {
      var p2 = voice.peers[id];
      if (p2 && !p2._answered) { p2._answered = true; p2.setRemoteDescription(new RTCSessionDescription(m.data)).catch(function () {}); }
    } else if (m.kind === 'ice') {
      var p3 = voice.peers[id];
      if (p3 && m.data) { try { p3.addIceCandidate(new RTCIceCandidate(m.data)).catch(function () {}); } catch (e) {} }
    } else if (m.kind === 'bye') { closePeer(id); }
  }
  function ensurePeer(id) {
    if (voice.peers[id]) return voice.peers[id];
    var pc = new RTCPeerConnection(ICE);
    pc._offered = false; pc._answered = false; pc._audio = null;
    voice.peers[id] = pc;
    if (voice.stream) { try { voice.stream.getTracks().forEach(function (t) { pc.addTrack(t, voice.stream); }); } catch (e) {} }
    pc.onicecandidate = function (e) { if (e.candidate) rtcSend(id, 'ice', e.candidate); };
    pc.ontrack = function (e) {
      var audio = document.createElement('audio');
      audio.autoplay = true; audio.srcObject = e.streams[0]; audio.style.display = 'none';
      audio.volume = Math.max(0, Math.min(1, CFG.volume / 100));
      document.body.appendChild(audio); pc._audio = audio;
      try { audio.play().catch(function () {}); } catch (err) {}
    };
    pc.onconnectionstatechange = function () { if (pc.connectionState === 'failed' || pc.connectionState === 'closed') closePeer(id); };
    return pc;
  }
  function offerTo(id) {
    var pc = ensurePeer(id);
    if (pc._offered) return;
    pc._offered = true;
    pc.createOffer({ offerToReceiveAudio: true }).then(function (off) { return pc.setLocalDescription(off); })
      .then(function () { rtcSend(id, 'offer', pc.localDescription); })
      .catch(function () { pc._offered = false; });
  }
  function closePeer(id) {
    var pc = voice.peers[id];
    if (!pc) return;
    try { rtcSend(id, 'bye', null); } catch (e) {}
    try { if (pc._audio) { pc._audio.srcObject = null; pc._audio.parentNode.removeChild(pc._audio); } } catch (e) {}
    try { pc.close(); } catch (e) {}
    delete voice.peers[id];
  }
  function proximityTick() {
    if (!voice.on || !window.MP || !window.MP.remotes) return;
    var me = window.WORLD && window.WORLD.player && window.WORLD.player.node;
    if (!me) return;
    var range = CFG.voiceRange, vistos = {};
    for (var id in window.MP.remotes) {
      var r = window.MP.remotes[id];
      if (!r || !r.node) continue;
      vistos[id] = true;
      var dx = r.node.position.x - me.position.x, dz = r.node.position.z - me.position.z;
      var dist = Math.sqrt(dx * dx + dz * dz);
      var perto = dist <= range;
      var pc = voice.peers[id];
      if (perto) {
        if (!pc) pc = ensurePeer(id);
        if (String(window.MP.id) < String(id) && !pc._offered) offerTo(id);
      }
      if (pc && pc._audio) {
        pc._audio.muted = !perto || voice.muted;
        pc._audio.volume = Math.max(0.05, Math.min(1, (CFG.volume / 100) * (1 - dist / (range * 1.6))));
      }
    }
    for (var pid in voice.peers) { if (!vistos[pid]) closePeer(pid); }
  }
  setInterval(proximityTick, 900);

  document.addEventListener('keydown', function (e) {
    var tag = (e.target && e.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (e.key === 'v' || e.key === 'V') { e.preventDefault(); if (voice.on) toggleMute(); else startVoice(); }
    if (e.key === 't' || e.key === 'T') { e.preventDefault(); toggleChat(true); }
    if (e.key === 'Escape') { toggleChat(false); openSettings(false); }
  });

  /* ---------------- 8) BOOTSTRAP ---------------- */
  function applySaved() {
    if (window.WORLD && window.WORLD.setQuality) window.WORLD.setQuality(CFG.quality);
    if (window.AMX_SET) {
      window.AMX_SET.volume = CFG.volume / 100;
      window.AMX_SET.voiceRange = CFG.voiceRange;
      window.AMX_SET.sens = CFG.sens / 100;
    }
    if (window.AMX_NAMES) window.AMX_NAMES(CFG.names);
    syncSettingsUI();
  }
  var tries = 0;
  var readyTimer = setInterval(function () {
    tries++;
    if (window.WORLD && window.AMX_START) { clearInterval(readyTimer); applySaved(); if (pct > 92) finishBoot(); }
    else if (tries > 120) { clearInterval(readyTimer); }
  }, 100);
  window.addEventListener('load', function () { setTimeout(function () { if (!bootDone) finishBoot(); }, 2800); });

  window.AMX_UI.openSettings = openSettings;
  window.AMX_UI.toggleChat = toggleChat;
  window.AMX_UI.startVoice = startVoice;
  window.AMX_UI.stopVoice = stopVoice;
  window.AMX_UI.toggleMute = toggleMute;
  window.AMX_UI.sair = sair;
})();
</script>
<script>
/* =====================================================================
   AMHEEX AI — ícone flutuante + chat + voz (Fish Audio / TTS local / STT navegador)
   Layout e lógica do FAB extraídos do AMHEEX DRIVE e adaptados ao mundo 3D.
   ===================================================================== */
(function () {
  'use strict';
  if (window.__amxaiInit) return;
  window.__amxaiInit = true;

  var root = document.getElementById('amxai-root');
  if (!root) return;
  var fab = document.getElementById('amxai-fab');
  var panel = document.getElementById('amxai-panel');
  var head = document.getElementById('amxai-head');
  var msgs = document.getElementById('amxai-msgs');
  var form = document.getElementById('amxai-form');
  var input = document.getElementById('amxai-input');
  var send = document.getElementById('amxai-send');
  var mic = document.getElementById('amxai-mic');
  var statusEl = document.getElementById('amxai-status');
  var countEl = document.getElementById('amxai-count');
  var pin = document.getElementById('amxai-pin');
  var clearBtn = document.getElementById('amxai-clear');
  var chips = document.getElementById('amxai-chips');
  var voiceBtn = document.getElementById('amxai-voice');
  var liveBtn = document.getElementById('amxai-live');

  var busy = false;
  var history = [];
  var drag = null;
  var lastEnt = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function store(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function load(k, d) { try { var v = JSON.parse(localStorage.getItem(k)); return v == null ? d : v; } catch (e) { return d; } }
  function toast(msg) { if (statusEl) statusEl.textContent = msg; }

  /* ---------------- modo flutuante / fixo ---------------- */
  var mode = load('amx3d_mode', 'float');
  if (mode !== 'fixed') mode = 'float';
  root.dataset.mode = mode;
  pin.classList.toggle('on', mode === 'fixed');

  function setMode(m) {
    mode = m === 'fixed' ? 'fixed' : 'float';
    root.dataset.mode = mode;
    pin.classList.toggle('on', mode === 'fixed');
    store('amx3d_mode', mode);
    if (mode === 'fixed') {
      panel.style.left = panel.style.top = panel.style.right = panel.style.bottom = '';
      panel.style.width = panel.style.height = '';
      fab.style.left = fab.style.top = fab.style.right = fab.style.bottom = '';
    } else {
      applySavedPos(); applyFabPos();
    }
  }

  function openPanel() {
    panel.hidden = false;
    root.dataset.open = '1';
    fab.setAttribute('aria-expanded', 'true');
    if (mode === 'float') applySavedPos();
    setTimeout(function () { try { input.focus(); } catch (e) {} }, 120);
    scrollBottom();
  }
  function closePanel() {
    panel.hidden = true;
    root.dataset.open = '0';
    fab.setAttribute('aria-expanded', 'false');
  }

  /* ---------------- arrastar FAB e painel ---------------- */
  function applyFabPos() {
    var p = load('amx3d_fabpos', null);
    if (!p) return;
    fab.style.left = p.x + 'px'; fab.style.top = p.y + 'px';
    fab.style.right = 'auto'; fab.style.bottom = 'auto';
  }
  function applySavedPos() {
    var p = load('amx3d_pos', null);
    if (!p) return;
    panel.style.left = p.x + 'px'; panel.style.top = p.y + 'px';
    panel.style.right = 'auto'; panel.style.bottom = 'auto';
    if (p.w) panel.style.width = p.w + 'px';
    if (p.h) panel.style.height = p.h + 'px';
  }

  function makeDraggable(el, kind) {
    el.addEventListener('pointerdown', function (e) {
      if (e.target.closest('button') && kind === 'fab') { /* deixa o clique */ }
      if (kind === 'panel' && e.target.closest('button,textarea,input,a')) return;
      if (mode === 'fixed') return;
      var r = el.getBoundingClientRect();
      drag = { kind: kind, id: e.pointerId, ox: e.clientX - r.left, oy: e.clientY - r.top, moved: false, w: r.width, h: r.height };
      try { el.setPointerCapture(e.pointerId); } catch (err) {}
    });
    el.addEventListener('pointermove', function (e) {
      if (!drag || drag.id !== e.pointerId) return;
      var dx = e.clientX - drag.ox, dy = e.clientY - drag.oy;
      if (Math.abs(e.clientX - (drag.ox + el.getBoundingClientRect().left)) > 3) drag.moved = true;
      var x = Math.max(4, Math.min(window.innerWidth - (drag.kind === 'fab' ? 64 : 120), dx));
      var y = Math.max(4, Math.min(window.innerHeight - (drag.kind === 'fab' ? 64 : 90), dy));
      el.style.left = x + 'px'; el.style.top = y + 'px';
      el.style.right = 'auto'; el.style.bottom = 'auto';
      if (drag.kind === 'panel') { el.style.width = drag.w + 'px'; el.style.height = drag.h + 'px'; }
    });
    el.addEventListener('pointerup', function (e) {
      if (!drag || drag.id !== e.pointerId) return;
      var r = el.getBoundingClientRect();
      if (drag.kind === 'fab') store('amx3d_fabpos', { x: r.left, y: r.top });
      else store('amx3d_pos', { x: r.left, y: r.top, w: Math.round(r.width), h: Math.round(r.height) });
      if (drag.kind === 'fab' && drag.moved) window.__amxDragMoved = Date.now();
      drag = null;
    });
  }
  makeDraggable(fab, 'fab');
  makeDraggable(head, 'panel');

  /* ---------------- eventos ---------------- */
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    if (t.closest('#amxai-close')) { e.preventDefault(); closePanel(); return; }
    if (t.closest('#amxai-fab')) {
      e.preventDefault();
      if (Date.now() - (window.__amxDragMoved || 0) < 400) return;
      openPanel(); return;
    }
    if (t.closest('#amxai-pin')) { e.preventDefault(); setMode(mode === 'fixed' ? 'float' : 'fixed'); return; }
    if (t.closest('#amxai-clear')) { e.preventDefault(); limpar(); return; }
    if (t.closest('#amxai-voice')) { e.preventDefault(); toggleVoice(); return; }
    if (t.closest('#amxai-live')) { e.preventDefault(); toggleLive(); return; }
    var chip = t.closest('[data-chip]');
    if (chip) { e.preventDefault(); input.value = chip.getAttribute('data-chip'); sendMsg(); }
  }, true);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) closePanel();
  });

  /* ---------------- render ---------------- */
  function scrollBottom() { msgs.scrollTop = msgs.scrollHeight; }

  function addMsg(role, text, acts) {
    var wrap = document.createElement('div');
    wrap.className = 'amxai-msg ' + role;
    var b = document.createElement('div');
    b.className = 'amxai-bubble';
    b.innerHTML = '<span class="amxai-txt">' + fmt(text) + '</span>';
    if (acts && acts.length) {
      var box = document.createElement('div');
      box.className = 'amxai-acts';
      acts.forEach(function (a) {
        var s = document.createElement('span');
        s.className = 'amxai-act ' + (a.ok ? 'ok' : 'er');
        s.textContent = a.label;
        box.appendChild(s);
      });
      b.appendChild(box);
    }
    wrap.appendChild(b);
    msgs.appendChild(wrap);
    scrollBottom();
    return wrap;
  }

  function fmt(t) {
    var s = esc(t);
    s = s.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
    s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
    s = s.replace(/\n/g, '<br>');
    return s;
  }

  function updateCount() {
    if (countEl) countEl.textContent = history.length + ' msg';
  }

  function actionLabel(a, res) {
    var ok = res && res.ok !== false;
    var icon = ok ? '✓' : '✕';
    switch (a.t) {
      case 'spawn': return icon + ' ' + (a.n || 1) + '× ' + (a.nome || a.ent);
      case 'layout': return icon + ' tela: ' + a.item;
      case 'avatar': return icon + ' avatar ' + (a.nome || '');
      case 'modify': return icon + ' modificar ' + a.ent;
      case 'remove': return icon + ' remover ' + a.ent;
      case 'clear': return icon + ' cena limpa';
      case 'recolor': return icon + ' ' + a.ent + ' → ' + a.cor;
      case 'scale': return icon + ' escala ' + a.ent;
      case 'move': return icon + ' mover ' + a.ent;
      case 'rotate': return icon + ' girar ' + a.ent;
      case 'terrain': return icon + ' chão: ' + a.tipo;
      case 'weather': return icon + ' clima: ' + String(a.tipo).replace('_', ' ');
      case 'camera': return icon + ' câmera: ' + a.cmd;
      case 'physics': return icon + ' gravidade ' + (a.on ? 'on' : 'off');
      case 'skin': return icon + ' skin ' + a.ent;
      case 'world': return icon + ' mundo: ' + a.preset;
      case 'character': return icon + ' personagem ' + (a.nome || 'realista');
      case 'control': return icon + ' controle: ' + (a.mode || 'pad');
      case 'multiplayer': return icon + ' multiplayer ' + (a.on === false ? 'off' : 'on');
      default: return icon + ' ' + a.t;
    }
  }

  /* ---------------- voz: TTS ---------------- */
  var voiceOn = load('amx3d_voz', true);
  if (voiceBtn) voiceBtn.classList.toggle('on', voiceOn);
  var audioEl = null;
  var ttsCache = {};

  function toggleVoice() {
    voiceOn = !voiceOn;
    if (voiceBtn) voiceBtn.classList.toggle('on', voiceOn);
    store('amx3d_voz', voiceOn);
    if (!voiceOn) stopSpeak();
  }

  function stopSpeak() {
    try { if (audioEl) { audioEl.pause(); audioEl.currentTime = 0; } } catch (e) {}
    try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (e) {}
  }

  function speak(text) {
    if (!voiceOn || !text) return;
    stopSpeak();
    var clean = String(text).replace(/[*_`#>]/g, '').slice(0, 420);
    if (ttsCache[clean]) { playAudio(ttsCache[clean]); return; }
    fetch('?api=tts&text=' + encodeURIComponent(clean))
      .then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('audio') === 0) return r.blob();
        throw new Error('sem audio');
      })
      .then(function (blob) {
        var url = URL.createObjectURL(blob);
        ttsCache[clean] = url;
        playAudio(url);
      })
      .catch(function () { browserSpeak(clean); });
  }

  function playAudio(url) {
    try {
      if (!audioEl) audioEl = new Audio();
      audioEl.src = url;
      audioEl.play().catch(function () { browserSpeak(''); });
    } catch (e) { browserSpeak(''); }
  }

  function browserSpeak(text) {
    if (!voiceOn || !text || !window.speechSynthesis) return;
    try {
      var u = new SpeechSynthesisUtterance(text);
      u.lang = 'pt-BR'; u.rate = 1.02; u.pitch = 1;
      var vs = window.speechSynthesis.getVoices();
      for (var i = 0; i < vs.length; i++) {
        if ((vs[i].lang || '').toLowerCase().indexOf('pt') === 0) { u.voice = vs[i]; break; }
      }
      window.speechSynthesis.cancel();
      window.speechSynthesis.speak(u);
    } catch (e) {}
  }

  /* ---------------- voz: STT ---------------- */
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  var rec = null, listening = false, live = false, liveWanted = false;

  function buildRec() {
    if (!SR) return null;
    var r = new SR();
    r.lang = 'pt-BR';
    r.interimResults = true;
    r.continuous = true;
    r.maxAlternatives = 1;
    r.onstart = function () { listening = true; mic.classList.add('listening'); mic.setAttribute('aria-pressed', 'true'); toast('Ouvindo…'); };
    r.onend = function () {
      listening = false; mic.classList.remove('listening'); mic.setAttribute('aria-pressed', 'false');
      if (liveWanted) { setTimeout(function () { try { r.start(); } catch (e) {} }, 350); }
      else toast('Online · motor local');
    };
    r.onerror = function (ev) {
      listening = false; mic.classList.remove('listening');
      if (ev.error === 'not-allowed' || ev.error === 'service-not-allowed') {
        toast('Microfone bloqueado');
        liveWanted = false; live = false;
        if (liveBtn) liveBtn.classList.remove('on');
      } else if (ev.error === 'no-speech') {
        /* silêncio: continua no modo tempo real */
      } else {
        toast('Voz: ' + ev.error);
      }
    };
    r.onresult = function (ev) {
      var interim = '', final = '';
      for (var i = ev.resultIndex; i < ev.results.length; i++) {
        var res = ev.results[i];
        if (res.isFinal) final += res[0].transcript;
        else interim += res[0].transcript;
      }
      if (interim) input.value = interim;
      if (final) {
        input.value = final.trim();
        if (live || final.trim().length > 2) sendMsg();
      }
    };
    return r;
  }

  function startListen() {
    if (!SR) { toast('Este navegador não tem reconhecimento de voz. Use o Chrome/Edge.'); return; }
    if (!rec) rec = buildRec();
    stopSpeak();
    try { rec.start(); } catch (e) { /* já ativo */ }
  }
  function stopListen() {
    liveWanted = false;
    if (rec) { try { rec.stop(); } catch (e) {} }
    listening = false; mic.classList.remove('listening');
  }

  mic.addEventListener('click', function (e) {
    e.preventDefault();
    if (listening) { stopListen(); toast('Microfone pausado'); }
    else { liveWanted = false; startListen(); }
  });

  function toggleLive() {
    live = !live;
    liveWanted = live;
    if (liveBtn) liveBtn.classList.toggle('on', live);
    if (live) { startListen(); toast('Conversa em tempo real'); }
    else { stopListen(); toast('Online · motor local'); }
  }

  /* ---------------- envio ---------------- */
  function sendMsg() {
    var text = (input.value || '').trim();
    if (!text || busy) return;
    input.value = '';
    autoGrow();
    addMsg('user', text);
    history.push({ r: 'user', t: text });
    store('amx3d_hist', history.slice(-60));
    updateCount();

    busy = true; send.disabled = true;
    var pending = addMsg('bot', '');
    pending.querySelector('.amxai-bubble').innerHTML = '<span class="amxai-typing"><i></i><i></i><i></i></span>';
    scrollBottom();

    var ctx = { ultimo: lastEnt, objetos: (window.WORLD ? window.WORLD.state.objects.map(function (o) { return o.nome || o.ent; }) : []) };

    fetch('?api=chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ msg: text, ctx: ctx })
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok) throw new Error((j && j.erro) || 'falha');
      var results = [];
      var acts = j.actions || [];
      acts.forEach(function (a) {
        /* posiciona construções à frente do jogador, sem sobrepor */
        if (a && a.t === 'spawn' && !a.preset && window.WORLD && WORLD.player && WORLD.player.node) {
          var pp = WORLD.player.node.position;
          var pry = WORLD.player.node.rotation.y || 0;
          var dd = 6.5 + Math.random() * 3.5;
          a.x = Math.round((pp.x + Math.sin(pry) * dd) * 100) / 100;
          a.z = Math.round((pp.z + Math.cos(pry) * dd) * 100) / 100;
        }
        var res = null;
        try { res = window.WORLD ? window.WORLD.apply(a) : null; } catch (e) { res = { ok: false }; }
        results.push({ label: actionLabel(a, res), ok: !res || res.ok !== false });
        if (a.t === 'spawn') lastEnt = a.nome || a.ent;
        if (a.t === 'avatar') lastEnt = 'avatar';
        if (a.t === 'modify') lastEnt = a.ent;
      });
      var bubble = pending.querySelector('.amxai-bubble');
      bubble.innerHTML = '<span class="amxai-txt">' + fmt(j.reply) + '</span>';
      if (results.length) {
        var box = document.createElement('div');
        box.className = 'amxai-acts';
        results.forEach(function (r) {
          var s = document.createElement('span');
          s.className = 'amxai-act ' + (r.ok ? 'ok' : 'er');
          s.textContent = r.label;
          box.appendChild(s);
        });
        bubble.appendChild(box);
      }
      history.push({ r: 'bot', t: j.reply });
      store('amx3d_hist', history.slice(-60));
      updateCount();
      scrollBottom();
      if (j.sugestoes && j.sugestoes.length) renderChips(j.sugestoes);
      speak(j.fala || j.reply);
      if (window.WORLD) window.WORLD.save();
      toast('Online · ' + (j.ms || 0) + ' ms');
    }).catch(function (err) {
      pending.querySelector('.amxai-bubble').innerHTML = '<span class="amxai-txt">Não consegui falar com o motor local. Verifique se o PHP está rodando (php -S) e recarregue. <code>' + esc(err.message) + '</code></span>';
      toast('Motor offline');
    }).then(function () {
      busy = false; send.disabled = false;
    });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); sendMsg(); });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); }
  });
  function autoGrow() {
    input.style.height = 'auto';
    input.style.height = Math.min(110, input.scrollHeight) + 'px';
  }
  input.addEventListener('input', autoGrow);

  /* ---------------- chips ---------------- */
  var SUG = [
    'Crie um prédio à direita',
    'Crie um guarda-roupa',
    'Crie um avatar chamado Leo',
    'Adicione um título na tela',
    'Adicione um contador de pontos',
    'Modifique a casa para azul e brilhante',
    'Crie 5 dinossauros no centro',
    'Crie game'
  ];
  function renderChips(list) {
    chips.innerHTML = '';
    list.slice(0, 8).forEach(function (t) {
      var b = document.createElement('button');
      b.type = 'button'; b.setAttribute('data-chip', t); b.textContent = t;
      chips.appendChild(b);
    });
  }
  renderChips(SUG);

  /* ---------------- limpar ---------------- */
  function limpar() {
    history = []; store('amx3d_hist', []); msgs.innerHTML = ''; updateCount();
    addMsg('bot', 'Conversa limpa. O mundo 3D continua como está. Peça algo novo — ou diga “limpe a cena”.');
  }

  /* ---------------- boot ---------------- */
  /* uma vez, ao atualizar para o AMHEEX GAME 3.0, limpa o histórico antigo */
  try {
    if (load('amx3d_ver', '') !== '3.0') {
      store('amx3d_hist', []); store('amx3d_ver', '3.0');
    }
  } catch (e) {}
  var saved = load('amx3d_hist', []);
  if (saved && saved.length) {
    history = saved.slice(-40);
    history.forEach(function (m) { addMsg(m.r === 'user' ? 'user' : 'bot', m.t); });
  } else {
    addMsg('bot', 'Oi! Eu sou a **AMHEEX AI**, a inteligência do **AMHEEX GAME**. O mundo começa vazio de propósito: só **chão verde** e a **bola vermelha**. Todo o resto eu crio quando você pedir.\n\nPosso criar **qualquer coisa 3D** (prédio, casa, dinossauro, o que você inventar), **personagens realistas com skin procedural**, **controles** (andar, correr, pular, câmera em 1ª/3ª pessoa, modo tiro), **multiplayer por IP com mapa compartilhado e chat**, **elementos na tela** e **modificar** objetos que já existem. Diga **“crie game”** para montar um jogo inteiro, ou **“ative o multiplayer”**. Fale pelo microfone ou escreva.');
  }
  updateCount();
  applyFabPos();

  fetch('?api=ping').then(function (r) { return r.json(); }).then(function (j) {
    if (j && j.ok) {
      var vm = j.voz === 'fish-clone' ? 'voz AMHEEX (Fish)' : (j.voz === 'espeak-ng' ? 'voz local' : 'voz navegador');
      toast('Online · ' + vm + ' · PHP ' + j.php + (j.multiplayer ? ' · multiplayer' : ''));
    }
  }).catch(function () { toast('Motor offline (PHP não respondeu)'); });

  /* API pública para integrar em qualquer página */
  window.AMHEEX_IA3D = {
    version: '3.0.0',
    chat: function (text) { input.value = text; sendMsg(); },
    speak: speak,
    listen: startListen,
    stopListen: stopListen,
    open: openPanel,
    close: closePanel,
    setVoice: function (on) { voiceOn = !!on; if (voiceBtn) voiceBtn.classList.toggle('on', voiceOn); store('amx3d_voz', voiceOn); },
    history: function () { return history.slice(); }
  };
})();
</script>
</body>
</html>
