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
                "SELECT c.*, m.nombre AS mascota_nombre, m.especie,
                        cl.nombre AS cliente_nombre, cl.apellido AS cliente_apellido,
                        cl.telefono
                 FROM citas c
                 JOIN mascotas m  ON m.id  = c.mascota_id
                 JOIN clientes cl ON cl.id = c.cliente_id
                 WHERE c.id = ?"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) jsonResponse(['error' => 'Cita no encontrada'], 404);
            jsonResponse($row);
        }

        // Filters
        $fecha     = $_GET['fecha']     ?? null;
        $estado    = $_GET['estado']    ?? null;
        $mascotaId = $_GET['mascota_id'] ?? null;
        $clienteId = $_GET['cliente_id'] ?? null;
        $hoy       = $_GET['hoy']       ?? null; // upcoming today

        $where  = [];
        $params = [];

        if ($fecha)     { $where[] = 'c.fecha = ?';        $params[] = $fecha; }
        if ($estado)    { $where[] = 'c.estado = ?';       $params[] = $estado; }
        if ($mascotaId) { $where[] = 'c.mascota_id = ?';  $params[] = $mascotaId; }
        if ($clienteId) { $where[] = 'c.cliente_id = ?';  $params[] = $clienteId; }
        if ($hoy === '1') {
            $where[] = "c.fecha = date('now','localtime')";
            $where[] = "c.estado != 'cancelada'";
            $where[] = "c.estado != 'completada'";
        }

        $sql = "SELECT c.*, m.nombre AS mascota_nombre, m.especie,
                       cl.nombre AS cliente_nombre, cl.apellido AS cliente_apellido, cl.telefono
                FROM citas c
                JOIN mascotas m  ON m.id  = c.mascota_id
                JOIN clientes cl ON cl.id = c.cliente_id";

        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY c.fecha, c.hora_inicio';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonResponse($stmt->fetchAll());

    // ── POST ─────────────────────────────────────────────────────────────
    case 'POST':
        $data = getBody();
        foreach (['mascota_id', 'cliente_id', 'fecha', 'hora_inicio'] as $f) {
            if (empty($data[$f])) jsonResponse(['error' => "Campo '$f' es requerido"], 400);
        }

        // Calculate hora_fin (+30 min)
        $horaFin = date('H:i', strtotime($data['hora_inicio']) + 1800);

        // Check slot availability
        $check = $db->prepare(
            "SELECT id FROM citas WHERE fecha = ? AND hora_inicio = ? AND estado != 'cancelada'"
        );
        $check->execute([$data['fecha'], $data['hora_inicio']]);
        if ($check->fetch()) {
            jsonResponse(['error' => 'El slot ya está ocupado. Elige otro horario.'], 409);
        }

        try {
            $stmt = $db->prepare(
                'INSERT INTO citas
                 (mascota_id, cliente_id, fecha, hora_inicio, hora_fin, motivo, estado, veterinario, notas)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['mascota_id'], $data['cliente_id'],
                $data['fecha'], $data['hora_inicio'], $horaFin,
                $data['motivo'] ?? null,
                $data['estado'] ?? 'pendiente',
                $data['veterinario'] ?? 'Dr. General',
                $data['notas'] ?? null,
            ]);
            $newId = (int) $db->lastInsertId();

            // Create appointment notification
            $n = $db->prepare(
                "INSERT INTO notificaciones (titulo, mensaje, tipo, cita_id) VALUES (?,?,'info',?)"
            );
            $n->execute([
                "Cita agendada",
                "Cita el {$data['fecha']} a las {$data['hora_inicio']} – {$data['motivo']}.",
                $newId,
            ]);

            jsonResponse(['id' => $newId, 'hora_fin' => $horaFin, 'message' => 'Cita creada'], 201);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                jsonResponse(['error' => 'El slot ya está ocupado'], 409);
            }
            jsonResponse(['error' => $e->getMessage()], 500);
        }

    // ── PUT ──────────────────────────────────────────────────────────────
    case 'PUT':
        if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
        $data = getBody();

        // If changing date/time, check new slot
        if (isset($data['hora_inicio']) || isset($data['fecha'])) {
            $cur = $db->prepare('SELECT fecha, hora_inicio FROM citas WHERE id=?');
            $cur->execute([$id]);
            $current = $cur->fetch();
            $newFecha = $data['fecha'] ?? $current['fecha'];
            $newHora  = $data['hora_inicio'] ?? $current['hora_inicio'];

            if ($newFecha !== $current['fecha'] || $newHora !== $current['hora_inicio']) {
                $check = $db->prepare(
                    "SELECT id FROM citas WHERE fecha=? AND hora_inicio=? AND id!=? AND estado!='cancelada'"
                );
                $check->execute([$newFecha, $newHora, $id]);
                if ($check->fetch()) {
                    jsonResponse(['error' => 'El nuevo slot ya está ocupado'], 409);
                }
            }
        }

        // If updating estado to 'completada' or 'cancelada', create notification
        if (isset($data['estado'])) {
            $tipo = $data['estado'] === 'completada' ? 'success' : 'warning';
            $n = $db->prepare(
                "INSERT INTO notificaciones (titulo, mensaje, tipo, cita_id) VALUES (?,?,?,?)"
            );
            $msgs = [
                'confirmada'  => 'La cita fue confirmada.',
                'completada'  => 'La cita fue completada exitosamente.',
                'cancelada'   => 'La cita fue cancelada.',
                'pendiente'   => 'La cita volvió a estado pendiente.',
            ];
            $n->execute([
                "Cita actualizada",
                $msgs[$data['estado']] ?? 'Estado de cita actualizado.',
                $tipo,
                $id,
            ]);
        }

        $stmt = $db->prepare(
            'UPDATE citas SET mascota_id=COALESCE(?,mascota_id), cliente_id=COALESCE(?,cliente_id),
             fecha=COALESCE(?,fecha), hora_inicio=COALESCE(?,hora_inicio),
             hora_fin=COALESCE(?,hora_fin), motivo=COALESCE(?,motivo),
             estado=COALESCE(?,estado), veterinario=COALESCE(?,veterinario),
             notas=COALESCE(?,notas) WHERE id=?'
        );
        $horaFin = isset($data['hora_inicio'])
            ? date('H:i', strtotime($data['hora_inicio']) + 1800)
            : ($data['hora_fin'] ?? null);

        $stmt->execute([
            $data['mascota_id'] ?? null, $data['cliente_id'] ?? null,
            $data['fecha'] ?? null, $data['hora_inicio'] ?? null,
            $horaFin, $data['motivo'] ?? null, $data['estado'] ?? null,
            $data['veterinario'] ?? null, $data['notas'] ?? null,
            $id,
        ]);
        jsonResponse(['message' => 'Cita actualizada']);

    // ── DELETE ───────────────────────────────────────────────────────────
    case 'DELETE':
        if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
        $stmt = $db->prepare('DELETE FROM citas WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(['message' => 'Cita eliminada']);

    default:
        jsonResponse(['error' => 'Método no permitido'], 405);
}
