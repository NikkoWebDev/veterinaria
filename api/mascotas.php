<?php
require_once __DIR__ . '/db.php';

$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$id     = $_GET['id'] ?? null;

switch ($method) {
    // ── GET ──────────────────────────────────────────────────────────────
    case 'GET':
        if ($id) {
            $stmt = $db->prepare(
                'SELECT m.*, c.nombre AS cliente_nombre, c.apellido AS cliente_apellido
                 FROM mascotas m JOIN clientes c ON c.id = m.cliente_id
                 WHERE m.id = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) jsonResponse(['error' => 'Mascota no encontrada'], 404);
            jsonResponse($row);
        }
        $clienteId = $_GET['cliente_id'] ?? null;
        if ($clienteId) {
            $stmt = $db->prepare(
                'SELECT m.*, c.nombre AS cliente_nombre, c.apellido AS cliente_apellido
                 FROM mascotas m JOIN clientes c ON c.id = m.cliente_id
                 WHERE m.cliente_id = ? ORDER BY m.nombre'
            );
            $stmt->execute([$clienteId]);
        } else {
            $stmt = $db->query(
                'SELECT m.*, c.nombre AS cliente_nombre, c.apellido AS cliente_apellido
                 FROM mascotas m JOIN clientes c ON c.id = m.cliente_id
                 ORDER BY m.nombre'
            );
        }
        jsonResponse($stmt->fetchAll());

    // ── POST ─────────────────────────────────────────────────────────────
    case 'POST':
        $data = getBody();
        foreach (['nombre', 'especie', 'cliente_id'] as $f) {
            if (empty($data[$f])) jsonResponse(['error' => "Campo '$f' es requerido"], 400);
        }
        $stmt = $db->prepare(
            'INSERT INTO mascotas (nombre, especie, raza, fecha_nacimiento, peso, color, cliente_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['nombre'], $data['especie'], $data['raza'] ?? null,
            $data['fecha_nacimiento'] ?? null, $data['peso'] ?? null,
            $data['color'] ?? null, $data['cliente_id']
        ]);
        $newId = (int) $db->lastInsertId();

        $n = $db->prepare("INSERT INTO notificaciones (titulo, mensaje, tipo) VALUES (?,?,'info')");
        $n->execute(["Nueva mascota", "Se registró a {$data['nombre']} ({$data['especie']})."]);

        jsonResponse(['id' => $newId, 'message' => 'Mascota creada'], 201);

    // ── PUT ──────────────────────────────────────────────────────────────
    case 'PUT':
        if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
        $data = getBody();
        $stmt = $db->prepare(
            'UPDATE mascotas SET nombre=?, especie=?, raza=?, fecha_nacimiento=?,
             peso=?, color=?, cliente_id=? WHERE id=?'
        );
        $stmt->execute([
            $data['nombre'], $data['especie'], $data['raza'] ?? null,
            $data['fecha_nacimiento'] ?? null, $data['peso'] ?? null,
            $data['color'] ?? null, $data['cliente_id'], $id
        ]);
        jsonResponse(['message' => 'Mascota actualizada']);

    // ── DELETE ───────────────────────────────────────────────────────────
    case 'DELETE':
        if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
        $stmt = $db->prepare('DELETE FROM mascotas WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(['message' => 'Mascota eliminada']);

    default:
        jsonResponse(['error' => 'Método no permitido'], 405);
}
