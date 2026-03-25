<?php
/**
 * PawCare — Telegram Bot Config
 * Carga el token desde api.env y provee helpers HTTP.
 */

// ── Cargar api.env ──────────────────────────────────────────────
function loadEnv(string $path): void
{
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$key, $val]     = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

loadEnv(__DIR__ . '/../api.env');

define('TELEGRAM_TOKEN', $_ENV['api_telegram'] ?? '');
define('TELEGRAM_API',   'https://api.telegram.org/bot' . TELEGRAM_TOKEN . '/');

// ── Validar token configurado ───────────────────────────────────
function assertTokenConfigured(): void
{
    if (empty(TELEGRAM_TOKEN) || TELEGRAM_TOKEN === 'TU_BOT_TOKEN_AQUI') {
        http_response_code(500);
        error_log('[PawCare Bot] Token no configurado en api.env');
        exit('Token de Telegram no configurado. Edita api.env');
    }
}

// ── Llamada a Telegram Bot API ──────────────────────────────────
function telegramRequest(string $method, array $data = []): ?array
{
    if (empty(TELEGRAM_TOKEN) || TELEGRAM_TOKEN === 'TU_BOT_TOKEN_AQUI') {
        error_log('[PawCare Bot] Token no configurado');
        return null;
    }

    $url = TELEGRAM_API . $method;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[PawCare Bot] cURL error on $method: $error");
        return null;
    }

    $decoded = json_decode($result, true);
    if (!($decoded['ok'] ?? false)) {
        error_log("[PawCare Bot] Telegram API error on $method: " . ($decoded['description'] ?? $result));
    }
    return $decoded;
}

// ── Helpers de alto nivel ───────────────────────────────────────

/**
 * Envía un mensaje de texto al chat.
 */
function sendMessage(int $chatId, string $text, array $extra = []): ?array
{
    return telegramRequest('sendMessage', array_merge([
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ], $extra));
}

/**
 * Edita un mensaje inline existente.
 */
function editMessage(int $chatId, int $messageId, string $text, array $extra = []): ?array
{
    return telegramRequest('editMessageText', array_merge([
        'chat_id'                  => $chatId,
        'message_id'               => $messageId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ], $extra));
}

/**
 * Responde a un callback_query para quitar el spinner del botón.
 */
function answerCallback(string $callbackId, string $text = '', bool $alert = false): void
{
    telegramRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => $text,
        'show_alert'        => $alert,
    ]);
}

/**
 * Construye un inline keyboard JSON listo para usar en reply_markup.
 */
function inlineKeyboard(array $rows): string
{
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

/**
 * Construye un reply keyboard (teclado persistente).
 */
function replyKeyboard(array $rows, bool $resize = true, bool $oneTime = false): string
{
    return json_encode([
        'keyboard'          => $rows,
        'resize_keyboard'   => $resize,
        'one_time_keyboard' => $oneTime,
    ], JSON_UNESCAPED_UNICODE);
}
