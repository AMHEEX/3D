<?php
/**
 * AMHEEX AI — voz clonada (Fish Audio) + conversão OGG/Opus.
 *
 * Esta biblioteca é usada por:
 *   - ia/voz.php            (endpoint direto ?text=)
 *   - index.php?api=tts     (endpoint usado pelo jogo)
 *
 * O texto pode chegar em texto puro ou Base64. O resultado fica em cache
 * em dados/voz/ para não gastar API a cada fala repetida.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function amx_voz_dir(string $sub = ''): string {
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dados' . DIRECTORY_SEPARATOR . 'voz';
    if (!is_dir($base)) @mkdir($base, 0775, true);
    return $sub === '' ? $base : $base . DIRECTORY_SEPARATOR . $sub;
}

function amx_voz_limpar(string $texto, int $max = 300): string {
    $texto = strip_tags($texto);
    $texto = preg_replace('/[*_`#>]+/u', '', $texto) ?? '';
    $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texto) ?? '';
    $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
    return mb_substr($texto, 0, $max, 'UTF-8');
}

function amx_voz_decodificar(string $input): string {
    $decoded = base64_decode($input, true);
    if ($decoded !== false && $decoded !== '' && base64_encode($decoded) === $input) {
        return $decoded;
    }
    return $input;
}

function amx_voz_ffmpeg(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    if (function_exists('shell_exec')) {
        $p = @shell_exec('command -v ffmpeg 2>/dev/null');
        $ok = is_string($p) && trim($p) !== '';
    }
    return $ok;
}

function amx_voz_curl(): bool {
    return function_exists('curl_init');
}

/**
 * Gera (ou recupera do cache) o áudio da AMHEEX AI.
 *
 * @return array{ok:bool,path?:string,mime?:string,motor?:string,erro?:string,http?:int}
 */
function amx_voz_gerar(string $texto): array {
    $texto = amx_voz_limpar($texto);
    if ($texto === '') return ['ok' => false, 'erro' => 'texto vazio'];
    if (!amx_voz_curl()) return ['ok' => false, 'erro' => 'extensão curl ausente no PHP'];

    $hash = md5($texto . '|' . AMX_FISH_VOICE_ID . '|' . AMX_FISH_MODEL);
    $ogg = amx_voz_dir('voice_' . $hash . '.ogg');
    $mp3 = amx_voz_dir('temp_' . $hash . '.mp3');

    if (is_file($ogg) && filesize($ogg) > 100) {
        return ['ok' => true, 'path' => $ogg, 'mime' => 'audio/ogg; codecs=opus', 'motor' => 'fish-ogg-cache'];
    }
    if (is_file($mp3) && filesize($mp3) > 100) {
        return ['ok' => true, 'path' => $mp3, 'mime' => 'audio/mpeg', 'motor' => 'fish-mp3-cache'];
    }

    $payload = [
        'text' => $texto,
        'format' => 'mp3',
        'reference_id' => AMX_FISH_VOICE_ID,
    ];

    $ch = curl_init(AMX_FISH_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . AMX_FISH_API_KEY,
            'Content-Type: application/json',
            'model: ' . AMX_FISH_MODEL,
            'Accept: audio/mpeg',
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 45,
    ]);
    $data = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($http !== 200 || !is_string($data) || strlen($data) < 100) {
        return [
            'ok' => false,
            'erro' => 'Fish Audio não gerou o áudio',
            'http' => $http,
            'detalhe' => $curlErr !== '' ? $curlErr : mb_substr((string)$data, 0, 300),
        ];
    }

    if (@file_put_contents($mp3, $data, LOCK_EX) === false) {
        return ['ok' => false, 'erro' => 'não foi possível gravar o MP3 temporário'];
    }

    if (!amx_voz_ffmpeg()) {
        return ['ok' => true, 'path' => $mp3, 'mime' => 'audio/mpeg', 'motor' => 'fish-mp3'];
    }

    $cmd = 'ffmpeg -y -i ' . escapeshellarg($mp3)
        . ' -c:a libopus -b:a 96k -vbr on -compression_level 10 -ar 24000 -ac 1 '
        . escapeshellarg($ogg) . ' 2>&1';
    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);

    if ($code === 0 && is_file($ogg) && filesize($ogg) > 100) {
        @unlink($mp3);
        return ['ok' => true, 'path' => $ogg, 'mime' => 'audio/ogg; codecs=opus', 'motor' => 'fish-ogg'];
    }

    /* Se o ffmpeg falhar, entrega o MP3 (toca em qualquer navegador). */
    return ['ok' => true, 'path' => $mp3, 'mime' => 'audio/mpeg', 'motor' => 'fish-mp3-fallback'];
}

function amx_voz_enviar(array $r): void {
    if (!($r['ok'] ?? false) || empty($r['path']) || !is_file($r['path'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => $r['erro'] ?? 'falha ao gerar áudio',
            'http_code' => $r['http'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: ' . ($r['mime'] ?? 'audio/ogg'));
    header('Content-Length: ' . filesize($r['path']));
    header('Content-Disposition: inline; filename="' . basename($r['path']) . '"');
    header('Cache-Control: public, max-age=31536000');
    header('X-Voice-Engine: ' . ($r['motor'] ?? 'fish'));
    readfile($r['path']);
    exit;
}

function amx_voz_status(): array {
    return [
        'ok' => amx_voz_curl(),
        'motor' => 'fish-audio',
        'voice_id' => AMX_FISH_VOICE_ID,
        'model' => AMX_FISH_MODEL,
        'ffmpeg' => amx_voz_ffmpeg(),
        'cache' => amx_voz_dir(),
    ];
}
