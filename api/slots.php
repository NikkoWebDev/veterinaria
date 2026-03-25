<?php
/**
 * GET /api/slots.php?fecha=YYYY-MM-DD
 * Returns time slots every 30 min from 08:00 to 18:00 with availability status.
 */
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$db   = Database::getConnection();
$fecha = $_GET['fecha'] ?? date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    jsonResponse(['error' => 'Formato de fecha inválido. Use YYYY-MM-DD'], 400);
}

// Generate all 30-min slots: 08:00 → 18:00
$slots      = [];
$startHour  = 8;   // 08:00
$endHour    = 18;  // 18:00
$intervalMin = 30;

for ($minutes = $startHour * 60; $minutes < $endHour * 60; $minutes += $intervalMin) {
    $h_start = intdiv($minutes, 60);
    $m_start = $minutes % 60;
    $m_next  = $minutes + $intervalMin;
    $h_end   = intdiv($m_next, 60);
    $m_end   = $m_next % 60;

    $slots[] = [
        'hora_inicio' => sprintf('%02d:%02d', $h_start, $m_start),
        'hora_fin'    => sprintf('%02d:%02d', $h_end,   $m_end),
        'disponible'  => true,
        'cita_id'     => null,
    ];
}

// Find occupied slots (only non-cancelled appointments)
$stmt = $db->prepare(
    "SELECT hora_inicio, hora_fin, id, motivo, estado
     FROM citas
     WHERE fecha = ? AND estado != 'cancelada'"
);
$stmt->execute([$fecha]);
$ocupados = [];
foreach ($stmt->fetchAll() as $row) {
    $ocupados[$row['hora_inicio']] = $row;
}

foreach ($slots as &$slot) {
    if (isset($ocupados[$slot['hora_inicio']])) {
        $slot['disponible'] = false;
        $slot['cita_id']    = $ocupados[$slot['hora_inicio']]['id'];
        $slot['estado']     = $ocupados[$slot['hora_inicio']]['estado'];
    }
}

jsonResponse([
    'fecha'  => $fecha,
    'total'  => count($slots),
    'libres' => count(array_filter($slots, fn($s) => $s['disponible'])),
    'slots'  => $slots,
]);
