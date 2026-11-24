<?php
/**
 * AMHEEX AI — endpoint de voz clonada.
 *
 * Uso:
 *   /ia/voz.php?text=Olá%20AMHEEX
 *   /ia/voz.php?text=<base64>
 *   /ia/voz.php?text=...&debug=1   (retorna JSON com status, sem áudio)
 */
declare(strict_types=1);
@ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Range');
header('Accept-Ranges: bytes');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/voz_lib.php';

$raw = isset($_GET['text']) ? (string)$_GET['text'] : '';
if ($raw === '' && isset($_POST['text'])) $raw = (string)$_POST['text'];
if ($raw === '') {
    $body = file_get_contents('php://input');
    if (is_string($body) && $body !== '') {
        $j = json_decode($body, true);
        if (is_array($j) && isset($j['text'])) $raw = (string)$j['text'];
    }
}

$texto = amx_voz_decodificar($raw);

if (isset($_GET['debug'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'ok',
        'config' => amx_voz_status(),
        'texto_recebido' => mb_substr($texto, 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if (trim($texto) === '') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Parâmetro ?text= não informado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$r = amx_voz_gerar($texto);
amx_voz_enviar($r);
