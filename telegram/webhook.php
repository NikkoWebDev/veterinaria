<?php
/**
 * PawCare — Telegram Webhook Endpoint
 * Configura en BotFather y registra la URL con set_webhook.php
 * POST https://tudominio.com/telegram/webhook.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/BotHandler.php';

// Solo acepta POST de Telegram
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Leer update de Telegram
$raw    = file_get_contents('php://input');
$update = json_decode($raw, true);

if (!$update || !is_array($update)) {
    http_response_code(400);
    exit('Bad Request');
}

// Extraer datos según tipo de update
$chatId       = null;
$text         = '';
$firstName    = '';
$callbackData = null;
$callbackId   = null;

if (isset($update['message'])) {
    $msg       = $update['message'];
    $chatId    = (int) ($msg['chat']['id'] ?? 0);
    $text      = $msg['text'] ?? '';
    $firstName = $msg['from']['first_name'] ?? '';
} elseif (isset($update['callback_query'])) {
    $cb           = $update['callback_query'];
    $chatId       = (int) ($cb['message']['chat']['id'] ?? 0);
    $callbackData = $cb['data'] ?? '';
    $callbackId   = $cb['id']   ?? null;
    $firstName    = $cb['from']['first_name'] ?? '';
}

// Ignorar si no hay chat_id válido
if (!$chatId) {
    http_response_code(200);
    exit('OK');
}

// Procesar el update
try {
    $handler = new BotHandler($chatId, $firstName);
    $handler->handle($text, $callbackData, $callbackId);
} catch (\Throwable $e) {
    error_log(
        '[PawCare Bot] Webhook error for chat ' . $chatId . ': ' .
        $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
    );
}

// Siempre responde 200 para que Telegram no reintente
http_response_code(200);
echo 'OK';
