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
            $id = amx_mp_id($ip);
            $now = time();

            /* remove jogadores inativos há mais de 25s */
            foreach ($mp['players'] as $pid => $pl) {
                if (($now - (int)($pl['last'] ?? 0)) > 25) unset($mp['players'][$pid]);
            }

            if ($API === 'mp_join') {
                $in = amx_body();
                $nome = amx_limpa((string)($in['name'] ?? 'Jogador'), 24);
                if ($nome === '') $nome = 'Jogador';
                $skin = is_array($in['skin'] ?? null) ? $in['skin'] : [];
                $mp['players'][$id] = [
                    'id' => $id,
                    'name' => $nome,
                    'ip' => $ip,
                    'x' => (float)($in['x'] ?? 0),
                    'z' => (float)($in['z'] ?? 0),
                    'ry' => (float)($in['ry'] ?? 0),
                    'skin' => [
                        'pele' => amx_limpa((string)($skin['pele'] ?? '#c68642'), 16),
                        'cor' => amx_limpa((string)($skin['cor'] ?? '#4f7cff'), 16),
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
