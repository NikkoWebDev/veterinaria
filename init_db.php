<?php
/**
 * PawCare - Inicializador de base de datos
 * Visita esta página UNA VEZ para crear la base de datos.
 */

$dbDir  = __DIR__ . '/db';
$dbFile = $dbDir . '/veterinaria.db';
$schema = $dbDir . '/schema.sql';

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode=WAL');

    $sql = file_get_contents($schema);
    // Split by semicolons and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            $pdo->exec($stmt);
        }
    }

    // ── Migraciones para DB ya existente ──────────────────────────
    // Agregar columna telegram_chat_id a clientes (ignora si ya existe)
    try {
        $pdo->exec('ALTER TABLE clientes ADD COLUMN telegram_chat_id INTEGER');
    } catch (Exception) { /* ya existe */ }

    // Crear tabla telegram_sessions si no existe
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS telegram_sessions (
            chat_id    INTEGER PRIMARY KEY,
            estado     TEXT NOT NULL DEFAULT \'idle\',
            datos      TEXT NOT NULL DEFAULT \'{}\',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $success = true;
    $upgrades = ' + migraciones Telegram aplicadas';
    $message = '✅ Base de datos inicializada correctamente en: ' . realpath($dbFile) . $upgrades;
} catch (Exception $e) {
    $success = false;
    $message = '❌ Error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PawCare - Inicializar DB</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Inter',sans-serif; background:#080e1a; color:#f1f5f9;
           display:flex; align-items:center; justify-content:center; min-height:100vh; }
    .card { background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1);
            border-radius:20px; padding:48px; max-width:520px; width:90%; text-align:center; }
    .icon { font-size:56px; margin-bottom:16px; }
    h1 { font-size:22px; font-weight:700; margin-bottom:12px; }
    p  { color:#94a3b8; font-size:14px; margin-bottom:24px; line-height:1.6; }
    .msg { padding:14px 20px; border-radius:12px; font-size:14px; font-weight:500; margin-bottom:24px;
           background:<?= $success ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)' ?>;
           color:<?= $success ? '#10b981' : '#ef4444' ?>;
           border:1px solid <?= $success ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)' ?>; }
    a { display:inline-block; padding:12px 28px; background:#00d4aa; color:#080e1a;
        text-decoration:none; border-radius:10px; font-weight:600; font-size:14px;
        transition:background .2s; }
    a:hover { background:#00b894; }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon"><?= $success ? '🐾' : '⚠️' ?></div>
    <h1>PawCare — Inicialización</h1>
    <p>Script de configuración inicial de la base de datos SQLite.</p>
    <div class="msg"><?= htmlspecialchars($message) ?></div>
    <?php if ($success): ?>
      <a href="index.html">Ir al Dashboard →</a>
    <?php endif; ?>
  </div>
</body>
</html>
