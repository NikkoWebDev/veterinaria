<?php
require_once __DIR__ . '/db.php';

$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$id     = $_GET['id'] ?? null;

switch ($method) {
    // ── GET ──────────────────────────────────────────────────────────────
    case 'GET':
        $soloNoLeidas = $_GET['no_leidas'] ?? null;

        if ($soloNoLeidas === '1') {
            // Unread count + last 5 unread
            $count = $db->query("SELECT COUNT(*) AS total FROM notificaciones WHERE leida = 0")->fetch();
            $list  = $db->query(
                "SELECT * FROM notificaciones WHERE leida = 0 ORDER BY created_at DESC LIMIT 5"
            )->fetchAll();
            jsonResponse(['total_no_leidas' => (int)$count['total'], 'notificaciones' => $list]);
        }

        $limit  = (int)($_GET['limit']  ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);
        $stmt   = $db->prepare(
            "SELECT * FROM notificaciones ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$limit, $offset]);
        $rows  = $stmt->fetchAll();
        $total = $db->query("SELECT COUNT(*) AS c FROM notificaciones")->fetch()['c'];
        jsonResponse(['total' => (int)$total, 'notificaciones' => $rows]);

    // ── POST ─────────────────────────────────────────────────────────────
    case 'POST':
        $data = getBody();
        if (empty($data['titulo']) || empty($data['mensaje'])) {
            jsonResponse(['error' => 'titulo y mensaje son requeridos'], 400);
        }
        $stmt = $db->prepare(
            "INSERT INTO notificaciones (titulo, mensaje, tipo, cita_id)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['titulo'],
            $data['mensaje'],
            $data['tipo'] ?? 'info',
            $data['cita_id'] ?? null,
        ]);
        jsonResponse(['id' => (int)$db->lastInsertId(), 'message' => 'Notificación creada'], 201);

    // ── PUT (mark as read) ───────────────────────────────────────────────
    case 'PUT':
        if ($id === 'all') {
            // Mark all as read
            $db->exec("UPDATE notificaciones SET leida = 1 WHERE leida = 0");
            jsonResponse(['message' => 'Todas marcadas como leídas']);
        }
        if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
        $stmt = $db->prepare('UPDATE notificaciones SET leida = 1 WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(['message' => 'Notificación marcada como leída']);

    // ── DELETE ───────────────────────────────────────────────────────────
    case 'DELETE':
        if ($id === 'all') {
            $db->exec('DELETE FROM notificaciones WHERE leida = 1');
            jsonResponse(['message' => 'Notificaciones leídas eliminadas']);
        }
        if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
        $stmt = $db->prepare('DELETE FROM notificaciones WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(['message' => 'Notificación eliminada']);

    default:
        jsonResponse(['error' => 'Método no permitido'], 405);
}
