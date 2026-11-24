<?php
// =========================================================================
// PHP FILE SERVER - SERVIDOR DE ARQUIVOS E EXECUÇÃO DE SCRIPTS
// =========================================================================

ignore_user_abort(true);
set_time_limit(0);

@ini_set('memory_limit', '4096M');
@ini_set('max_execution_time', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');

// =========================================================================
// FUNÇÃO AUXILIAR: REDIRECIONAR FORA DO IFRAME (TOP WINDOW)
// =========================================================================

function redirecionarParaTop($url) {
    header('Content-Type: text/html; charset=utf-8');
    $safeUrl = json_encode($url);
    echo "<!DOCTYPE html><html><head><title>Redirecionando...</title></head><body>";
    echo "<script type='text/javascript'>
        try {
            if (window.top && window.top !== window) {
                window.top.location.href = {$safeUrl};
            } else {
                window.location.href = {$safeUrl};
            }
        } catch (e) {
            window.location.href = {$safeUrl};
        }
    </script>";
    echo "<noscript><meta http-equiv='refresh' content='0;url=" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "'></noscript>";
    echo "</body></html>";
    exit;
}

// =========================================================================
// 1. DOMÍNIO DO SERVIDOR E ORIGEM DA REQUISIÇÃO
// =========================================================================

$rawHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$rawOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (empty($rawOrigin) && !empty($_SERVER['HTTP_REFERER'])) {
    $rawOrigin = $_SERVER['HTTP_REFERER'];
}

$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    ||
    (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

$currentScheme = $isHttps ? 'https' : 'http';
$fullRequestUrl = $currentScheme . '://' . $rawHost . ($_SERVER['REQUEST_URI'] ?? '');

// =========================================================================
// 2. CORS DINÂMICO
// =========================================================================

$corsOrigin = !empty($rawOrigin) ? $rawOrigin : '*';

header("Access-Control-Allow-Origin: {$corsOrigin}");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, HEAD');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Range');
header('Cross-Origin-Resource-Policy: cross-origin');
header_remove('X-Frame-Options');

// =========================================================================
// 3. OPTIONS
// =========================================================================

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// =========================================================================
// 4. URL.JSON (VALIDAÇÃO AMPLA DE ORIGEM OU URL)
// =========================================================================

$jsonFile = __DIR__ . '/URL.json';
$originAllowed = false;

if (!is_file($jsonFile)) {
    $originAllowed = true;
} else {
    $jsonContent = @file_get_contents($jsonFile);

    if ($jsonContent !== false && trim($jsonContent) !== '') {
        $allowedTexts = json_decode($jsonContent, true);

        if (is_array($allowedTexts) && isset($allowedTexts['domains']) && is_array($allowedTexts['domains'])) {
            $allowedTexts = $allowedTexts['domains'];
        }

        // Fontes de checagem: URL completa atual, Origin, Referer e parâmetro _origin
        $checkTargets = array_filter([
            $fullRequestUrl,
            $rawOrigin,
            $_SERVER['HTTP_REFERER'] ?? '',
            $_GET['_origin'] ?? ''
        ]);

        if (is_array($allowedTexts)) {
            foreach ($allowedTexts as $text) {
                if (!is_string($text) || trim($text) === '') {
                    continue;
                }

                $text = trim($text);

                foreach ($checkTargets as $target) {
                    if (stripos($target, $text) !== false) {
                        $originAllowed = true;
                        break 2;
                    }
                }
            }
        }
    }
}

if (!$originAllowed) {
    redirecionarParaTop('https://amheex.onrender.com/erro/494.html');
}

// =========================================================================
// 5. DIRETÓRIOS BASE
// =========================================================================

$STORAGE_DIRS = [
    __DIR__ . '/#',
    '/var/www/html/#',
    '/app/#',
    '/data/#',
    '/mnt/data/#'
];

// =========================================================================
// 6. SANITIZAR CAMINHO
// =========================================================================

function sanitizaCaminho($path)
{
    $path = rawurldecode($path);
    $path = str_replace('\\', '/', $path);
    $path = explode('?', $path)[0];
    $path = ltrim($path, '/');

    $path = preg_replace('#^(?:[X\#]/)+#i', '', $path);

    $parts = explode('/', $path);
    $safe = [];

    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }

        if (strpos($part, "\0") !== false) {
            continue;
        }

        $part = preg_replace('/[^a-zA-Z0-9_\-.@]/', '', $part);

        if ($part !== '') {
            $safe[] = $part;
        }
    }

    return implode('/', $safe);
}

// =========================================================================
// 7. LOCALIZAR ARQUIVO
// =========================================================================

function localizarArquivoLocal($relativePath, &$foundPath = null) {
    global $STORAGE_DIRS;

    $foundPath = null;

    foreach ($STORAGE_DIRS as $baseDir) {
        $baseReal = realpath($baseDir);
        if ($baseReal === false) {
            if (is_dir($baseDir)) {
                $baseReal = rtrim($baseDir, '/\\');
            } else {
                continue;
            }
        }

        $normalizedRelative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $candidate = rtrim($baseReal, '/\\') . DIRECTORY_SEPARATOR . $normalizedRelative;

        clearstatcache(true, $candidate);

        $realCandidate = realpath($candidate);
        if ($realCandidate === false) {
            $realCandidate = $candidate;
        }

        if (is_dir($realCandidate)) {
            $indexes = ['index.js', 'index.html', 'index.htm', 'index.php'];
            foreach ($indexes as $indexFile) {
                $indexPath = rtrim($realCandidate, '/\\') . DIRECTORY_SEPARATOR . $indexFile;
                if (is_file($indexPath)) {
                    $realCandidate = $indexPath;
                    break;
                }
            }
        }

        if (is_file($realCandidate) && is_readable($realCandidate)) {
            $prefix = rtrim($baseReal, '/\\') . DIRECTORY_SEPARATOR;
            $resolvedPath = realpath($realCandidate);

            if ($resolvedPath !== false && str_starts_with($resolvedPath, $prefix)) {
                $foundPath = $resolvedPath;
                return true;
            } elseif (str_starts_with($realCandidate, $prefix)) {
                $foundPath = $realCandidate;
                return true;
            }
        }
    }

    return false;
}

// =========================================================================
// 8. PROCESSAMENTO DA REQUISIÇÃO E ENTREGA DO ARQUIVO
// =========================================================================

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);

if (!is_string($requestPath)) {
    $requestPath = '/';
}

$cleanPath = preg_replace('#^/(?:X|\#)/?#i', '', $requestPath);
$fileRelPath = sanitizaCaminho($cleanPath);

if ($fileRelPath === '') {
    $fileRelPath = 'index.html';
}

$foundPath = null;

if (localizarArquivoLocal($fileRelPath, $foundPath)) {

    $extension = strtolower(pathinfo($foundPath, PATHINFO_EXTENSION));

    if ($extension === 'php') {
        include $foundPath;
        exit;
    }

    // Suporte expandido para múltiplos tipos de arquivos
    $mimeTypes = [
        // Áudios
        'mp3'  => 'audio/mpeg',
        'ogg'  => 'audio/ogg',
        'oga'  => 'audio/ogg',
        'wav'  => 'audio/wav',
        'm4a'  => 'audio/mp4',
        'aac'  => 'audio/aac',
        'flac' => 'audio/flac',
        // Vídeos
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'ogv'  => 'video/ogg',
        // Imagens
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        // Web & Código
        'js'   => 'application/javascript; charset=utf-8',
        'mjs'  => 'application/javascript; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm'  => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'xml'  => 'application/xml; charset=utf-8',
        // Documentos e Fontes
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain; charset=utf-8',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'otf'  => 'font/otf'
    ];

    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
    $size = @filesize($foundPath);

    header("Content-Type: {$mimeType}");
    header('Accept-Ranges: bytes');

    if ($method === 'HEAD') {
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }
        exit;
    }

    // Suporte para Range Requests (necessário para mídias como MP3/OGG/MP4 no WebView e Browsers)
    if (isset($_SERVER['HTTP_RANGE']) && $size !== false) {
        $range = $_SERVER['HTTP_RANGE'];
        list($param, $range) = explode('=', $range, 2);
        
        if (strtolower($param) === 'bytes') {
            list($fileStart, $fileEnd) = explode('-', $range, 2);
            $fileStart = intval($fileStart);
            $fileEnd = ($fileEnd === '') ? ($size - 1) : intval($fileEnd);

            if ($fileStart <= $fileEnd && $fileEnd < $size) {
                $length = $fileEnd - $fileStart + 1;
                http_response_code(206);
                header("Content-Range: bytes {$fileStart}-{$fileEnd}/{$size}");
                header("Content-Length: {$length}");

                $fp = @fopen($foundPath, 'rb');
                if ($fp !== false) {
                    fseek($fp, $fileStart);
                    $bufferSize = 8192;
                    while (!feof($fp) && ($pos = ftell($fp)) <= $fileEnd) {
                        if ($pos + $bufferSize > $fileEnd) {
                            $bufferSize = $fileEnd - $pos + 1;
                        }
                        echo fread($fp, $bufferSize);
                        flush();
                    }
                    fclose($fp);
                    exit;
                }
            }
        }
    }

    if ($size !== false) {
        header('Content-Length: ' . $size);
    }

    @readfile($foundPath);
    exit;
}

redirecionarParaTop('https://amheex.onrender.com/erro/404.html');
