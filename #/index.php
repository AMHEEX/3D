<?php
/**
 * GAME IA 3D — index.php único.
 * Router da API + página do jogo. Tudo local: PHP, JSON e Babylon.js.
 */
declare(strict_types=1);
@ini_set('display_errors', '0');
error_reporting(E_ALL);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/ia/engine.php';

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

/* ------------------------------------------------------------------ */
/* API                                                                */
/* ------------------------------------------------------------------ */
if ($API !== '') {
    switch ($API) {

        case 'ping':
            amx_out([
                'ok' => true,
                'app' => 'GAME IA 3D',
                'versao' => AMX_IA3D,
                'php' => PHP_VERSION,
                'motor' => 'local',
                'voz' => amx_tts_disponivel() ? 'espeak-ng' : 'navegador',
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
            $wav = amx_tts_wav($texto);
            if ($wav === null) {
                amx_out(['ok' => false, 'motor' => 'navegador', 'aviso' => 'espeak-ng indisponível no servidor; use a voz do navegador.', 'texto' => $texto]);
            }
            header('Content-Type: audio/wav');
            header('Content-Length: ' . strlen($wav));
            header('Cache-Control: public, max-age=86400');
            header('X-Voice-Engine: espeak-ng');
            echo $wav;
            exit;
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
<title>GAME IA 3D — mundo local gerado por IA</title>
<meta name="description" content="GAME IA 3D: mundo 3D local com IA que constrói qualquer objeto, personagem, avatar e fase por voz ou texto. Babylon.js local, PHP local, memória local.">
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
</style>
</head>
<body>
<canvas id="game" touch-action="none" aria-label="Mundo 3D"></canvas>
<div id="vignette"></div>

<div id="hud"></div>
<div id="hint" class="off" style="display:none">Arraste para girar a câmera · toque no chão para mover a bola · fale com a IA no ícone</div>

<div id="amxai-root" data-mode="float" data-open="0">
  <button id="amxai-fab" type="button" aria-label="Abrir GAME IA 3D" title="GAME IA 3D">
    <img src="assets/ia-icon.png" alt="GAME IA 3D" onerror="this.style.display='none'">
    <span class="amxai-pro" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"/></svg></span>
    <span class="amxai-ping"></span>
  </button>

  <section id="amxai-panel" hidden aria-label="GAME IA 3D">
    <header id="amxai-head">
      <div class="amxai-id">
        <span class="amxai-avatar"><img src="assets/ia-icon.png" alt="" onerror="this.style.display='none'"></span>
        <div><b>GAME IA 3D</b><small><i></i><span id="amxai-status">Online · motor local</span></small></div>
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
      <textarea id="amxai-input" rows="1" placeholder="Fale ou escreva: “crie uma casa à direita”…" aria-label="Mensagem para a IA"></textarea>
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
   GAME IA 3D — motor do mundo (Babylon.js local)
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
    var node = build({ cor: opts.cor || '#3b82f6', forma: forma, nome: nomeEnt });
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
    return WORLD.objects.filter(function (o) { return o.ent === ent; });
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
      paintSky('#2f6bd8', '#d7ebff'); scene.clearColor = new BABYLON.Color4(0.03, 0.05, 0.09, 1);
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

  window.addEventListener('keydown', function (e) { keys[e.key.toLowerCase()] = true; });
  window.addEventListener('keyup', function (e) { keys[e.key.toLowerCase()] = false; });

  scene.onPointerObservable.add(function (info) {
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
    var r = spawn('boneco', {
      cor: a.cor || '#4f7cff', x: Number(a.x || 0), z: Number(a.z || 0),
      escala: Number(a.escala || 1), y: Number(a.y || 0)
    });
    if (r.ok) {
      var rec = WORLD.objects[WORLD.objects.length - 1];
      rec.isAvatar = true;
      rec.nome = a.nome || 'Jogador';
      WORLD.avatar = rec;
      WORLD.avatarName = a.nome || 'Jogador';
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

    /* movimento por teclado */
    var sp = 9 * dt;
    if (keys['w'] || keys['arrowup']) ballTarget.z += sp;
    if (keys['s'] || keys['arrowdown']) ballTarget.z -= sp;
    if (keys['a'] || keys['arrowleft']) ballTarget.x -= sp;
    if (keys['d'] || keys['arrowright']) ballTarget.x += sp;

    ball.position.x += (ballTarget.x - ball.position.x) * Math.min(1, dt * 6);
    ball.position.z += (ballTarget.z - ball.position.z) * Math.min(1, dt * 6);
    ball.position.y = 0.58 + Math.sin(performance.now() / 420) * 0.06;
    ball.rotation.z -= (ballTarget.x - ball.position.x) * 0.05;
    ball.rotation.x += (ballTarget.z - ball.position.z) * 0.05;

    camera.target.x += (ball.position.x - camera.target.x) * Math.min(1, dt * 2.2);
    camera.target.z += (ball.position.z - camera.target.z) * Math.min(1, dt * 2.2);
    camera.target.y = 1;

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
      var dx = p.x - ball.position.x, dz = p.z - ball.position.z, dy = p.y - (ball.position.y + 0.6);
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

  /* ---------------- API pública ---------------- */
  window.WORLD = {
    scene: scene, engine: engine, camera: camera, ball: ball,
    state: WORLD, spawn: spawn, apply: applyAction, build: buildWorld,
    setTerrain: setTerrain, setWeather: setWeather, clear: clearWorld,
    setLayout: setLayout, clearLayout: clearLayout, avatar: createAvatar, modify: modifyEnt,
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
/* =====================================================================
   GAME IA 3D — ícone flutuante + chat + voz (TTS local / STT navegador)
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
  /* uma vez, ao atualizar para o GAME IA 3D 2.0, limpa o histórico antigo */
  try {
    if (load('amx3d_ver', '') !== '2.0') {
      store('amx3d_hist', []); store('amx3d_ver', '2.0');
    }
  } catch (e) {}
  var saved = load('amx3d_hist', []);
  if (saved && saved.length) {
    history = saved.slice(-40);
    history.forEach(function (m) { addMsg(m.r === 'user' ? 'user' : 'bot', m.t); });
  } else {
    addMsg('bot', 'Oi! Eu sou a **GAME IA 3D**. O mundo começa vazio de propósito: só **chão verde** e a **bola vermelha**. Todo o resto eu crio quando você pedir.\n\nPosso criar **qualquer coisa 3D** (prédio, casa, guarda-roupa, dinossauro, o que você inventar), **avatares**, **elementos na tela** (título, contador, mira, barra de vida) e **modificar** objetos que já existem. Diga **“crie game”** para eu montar um jogo inteiro. Fale pelo microfone ou escreva.');
  }
  updateCount();
  applyFabPos();

  fetch('?api=ping').then(function (r) { return r.json(); }).then(function (j) {
    if (j && j.ok) toast('Online · ' + (j.voz === 'espeak-ng' ? 'voz local' : 'voz navegador') + ' · PHP ' + j.php);
  }).catch(function () { toast('Motor offline (PHP não respondeu)'); });

  /* API pública para integrar em qualquer página */
  window.AMHEEX_IA3D = {
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
