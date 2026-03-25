<?php
/**
 * PawCare — Telegram Link Endpoint
 * Retorna la URL del bot de Telegram configurado.
 */

require_once __DIR__ . '/../telegram/config.php';

$meResult = telegramRequest('getMe');
$botName  = $meResult['result']['username'] ?? null;

header('Content-Type: application/json');

if ($botName) {
    echo json_encode(['url' => "https://t.me/" . $botName]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Bot no configurado en api.env']);
}
