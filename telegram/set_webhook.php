<?php
/**
 * PawCare — Registrar Webhook de Telegram
 * Visita una sola vez para activar el bot:
 *   http://localhost:8000/telegram/set_webhook.php?url=https://XXXX.ngrok.io/telegram/webhook.php
 */

require_once __DIR__ . '/config.php';

function renderPage(string $title, string $body, bool $success = false): string
{
    $color = $success ? '#10b981' : '#ef4444';
    return <<<HTML
    <!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
    <title>PawCare — Set Webhook</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
      *{margin:0;padding:0;box-sizing:border-box}
      body{font-family:'Inter',sans-serif;background:#070c18;color:#f1f5f9;
           display:flex;align-items:center;justify-content:center;min-height:100vh}
      .card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);
            border-radius:20px;padding:48px;max-width:600px;width:90%}
      h1{font-size:22px;margin-bottom:16px}
      .msg{padding:14px 18px;border-radius:12px;font-size:14px;margin:16px 0;
           background:rgba(0,0,0,.3);border:1px solid {$color};color:{$color}}
      pre{background:#0b1220;border:1px solid rgba(255,255,255,.1);border-radius:10px;
          padding:16px;font-size:13px;color:#94a3b8;overflow-x:auto;margin:16px 0}
      a{color:#00d4aa;text-decoration:none}
      .back{display:inline-block;margin-top:24px;padding:10px 22px;background:#00d4aa;
            color:#070c18;border-radius:8px;font-weight:600;font-size:14px}
    </style></head><body>
    <div class="card">
      <h1>🐾 PawCare — Telegram Webhook</h1>
      <div class="msg">$body</div>
      $title
      <a class="back" href="/index.html">← Ir al Dashboard</a>
    </div></body></html>
    HTML;
}

// ── Verificar token ────────────────────────────────────────────
if (empty(TELEGRAM_TOKEN) || TELEGRAM_TOKEN === 'TU_BOT_TOKEN_AQUI') {
    echo renderPage(
        '<p style="color:#94a3b8;font-size:13px">Edita <code>api.env</code> y agrega tu token:</p>
         <pre>api_telegram=1234567890:AABBCC...</pre>
         <p style="color:#94a3b8;font-size:13px">Obtenlo con <a href="https://t.me/BotFather">@BotFather</a> en Telegram.</p>',
        '❌ Token no configurado en api.env'
    );
    exit;
}

// ── Obtener info del bot ───────────────────────────────────────
$meResult = telegramRequest('getMe');
$botName  = $meResult['result']['username'] ?? 'desconocido';
$botTitle  = $meResult['result']['first_name'] ?? 'Bot';

// ── Sin parámetro url: mostrar estado actual ───────────────────
if (empty($_GET['url'])) {
    $wh     = telegramRequest('getWebhookInfo');
    $whInfo = $wh['result'] ?? [];
    $whUrl  = $whInfo['url'] ?? '(no configurado)';
    $whPend = $whInfo['pending_update_count'] ?? 0;
    $whErr  = $whInfo['last_error_message'] ?? '—';

    $host       = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    $exampleUrl = "https://TU-NGROK-URL.ngrok.io/telegram/webhook.php";

    echo renderPage(
        "<h2 style='font-size:16px;margin:20px 0 8px'>Estado actual del Webhook</h2>
         <pre>Bot:      @$botName ($botTitle)
URL:      $whUrl
Pendientes: $whPend updates
Último error: $whErr</pre>
         <h2 style='font-size:16px;margin:20px 0 8px'>Registrar Webhook</h2>
         <p style='color:#94a3b8;font-size:13px;margin-bottom:8px'>
           Visita esta URL reemplazando con tu URL pública (ngrok o dominio real):
         </p>
         <pre>http://{$host}/telegram/set_webhook.php?url={$exampleUrl}</pre>
         <p style='color:#94a3b8;font-size:13px'>
           Con ngrok: <code>ngrok http 8000</code> y usa la URL https que te da.
         </p>",
        "ℹ️ Proporciona el parámetro <code>?url=</code> para registrar."
    );
    exit;
}

// ── Registrar webhook ──────────────────────────────────────────
$webhookUrl = filter_var($_GET['url'], FILTER_VALIDATE_URL);
if (!$webhookUrl) {
    echo renderPage('', '❌ La URL proporcionada no es válida.');
    exit;
}

if (!str_starts_with($webhookUrl, 'https://')) {
    echo renderPage(
        "<p style='color:#94a3b8;font-size:13px'>Telegram requiere HTTPS. " .
        "Usa ngrok (<code>ngrok http 8000</code>) para obtener una URL https.</p>",
        '❌ La URL debe empezar con https://'
    );
    exit;
}

$result = telegramRequest('setWebhook', [
    'url'                  => $webhookUrl,
    'allowed_updates'      => ['message', 'callback_query'],
    'drop_pending_updates' => true,
]);

if ($result['ok'] ?? false) {
    echo renderPage(
        "<p style='color:#94a3b8;font-size:13px;margin-bottom:8px'>Webhook registrado:</p>
         <pre>Bot: @$botName
URL: $webhookUrl</pre>
         <p style='color:#94a3b8;font-size:13px'>
           Ahora abre Telegram y escríbele a <a href='https://t.me/$botName'>@$botName</a> → /start
         </p>",
        "✅ " . ($result['description'] ?? 'Webhook registrado correctamente'),
        true
    );
} else {
    $desc = $result['description'] ?? json_encode($result);
    echo renderPage('', "❌ Error de Telegram: $desc");
}
