<?php
require_once __DIR__ . '/db.php';

$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$id     = $_GET['id'] ?? null;

switch ($method) {
    // ── GET ──────────────────────────────────────────────────────────────
    case 'GET':
        if ($id) {
            $stmt = $db->prepare('SELECT * FROM clientes WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) jsonResponse(['error' => 'Cliente no encontrado'], 404);
            jsonResponse($row);
        }
        $search = trim($_GET['search'] ?? '');
        if ($search !== '') {
            $stmt = $db->prepare(
                "SELECT * FROM clientes
                 WHERE nombre LIKE :q OR apellido LIKE :q OR telefono LIKE :q OR email LIKE :q
                 ORDER BY nombre"
            );
            $stmt->execute([':q' => "%$search%"]);
        } else {
            $stmt = $db->query('SELECT * FROM clientes ORDER BY nombre, apellido');
        }
        jsonResponse($stmt->fetchAll());

    // ── POST ─────────────────────────────────────────────────────────────
    case 'POST':
        $data = getBody();
        foreach (['nombre', 'apellido', 'telefono'] as $f) {
            if (empty($data[$f])) jsonResponse(['error' => "Campo '$f' es requerido"], 400);
        }
        try {
            $stmt = $db->prepare(
                'INSERT INTO clientes (nombre, apellido, telefono, email, direccion)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$data['nombre'], $data['apellido'], $data['telefono'],
                            $data['email'] ?? null, $data['direccion'] ?? null]);
            $newId = (int) $db->lastInsertId();

            // Crear notificación
            $n = $db->prepare("INSERT INTO notificaciones (titulo, mensaje, tipo) VALUES (?,?,'success')");
            $n->execute(["Nuevo cliente", "Se registró a {$data['nombre']} {$data['apellido']}."]);

            jsonResponse(['id' => $newId, 'message' => 'Cliente creado'], 201);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                jsonResponse(['error' => 'El email ya está registrado'], 409);
            }
            jsonResponse(['error' => $e->getMessage()], 500);
        }

    // ── PUT ──────────────────────────────────────────────────────────────
    case 'PUT':
        if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
        $data = getBody();
        try {
            $stmt = $db->prepare(
                'UPDATE clientes SET nombre=?, apellido=?, telefono=?, email=?, direccion=?
                 WHERE id=?'
            );
            $stmt->execute([$data['nombre'], $data['apellido'], $data['telefono'],
                            $data['email'] ?? null, $data['direccion'] ?? null, $id]);
            jsonResponse(['message' => 'Cliente actualizado']);
        } catch (PDOException $e) {
            jsonResponse(['error' => $e->getMessage()], 500);
        }

    // ── DELETE ───────────────────────────────────────────────────────────
    case 'DELETE':
        if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
        $stmt = $db->prepare('DELETE FROM clientes WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(['message' => 'Cliente eliminado']);

    default:
        jsonResponse(['error' => 'Método no permitido'], 405);
}
