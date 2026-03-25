<?php
/**
 * PawCare — Telegram Bot Handler
 * Maneja el estado de conversación y la lógica de agendamiento.
 *
 * Estados (session->estado):
 *   idle              → menú principal
 *   esperando_nombre  → registro: nombre
 *   esperando_telefono→ registro: teléfono
 *   agendar_mascota   → flujo cita: selección mascota
 *   agendar_fecha     → flujo cita: selección fecha
 *   agendar_slot      → flujo cita: selección horario
 *   agendar_motivo    → flujo cita: selección motivo
 *   agendar_motivo_custom → motivo libre escrito
 *   agendar_confirm   → confirmación final
 *   cancelar_select   → seleccionar cita a cancelar
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../api/db.php';

class BotHandler
{
    private PDO        $db;
    private BotSession $session;
    private int        $chatId;
    private string     $firstName;

    public function __construct(int $chatId, string $firstName = '')
    {
        $this->chatId    = $chatId;
        $this->firstName = $firstName;
        $this->db        = Database::getConnection();
        $this->session   = new BotSession($this->db, $chatId);
    }

    // ── Entry point ─────────────────────────────────────────────
    public function handle(string $text, ?string $cbData = null, ?string $cbId = null): void
    {
        // Acknowledge callback immediately
        if ($cbId !== null) answerCallback($cbId);

        $input  = $cbData ?? trim($text);
        $estado = $this->session->getEstado();

        // ── Comandos globales (siempre disponibles) ──
        if (in_array($input, ['/start', '/menu'])) {
            $this->handleStart();
            return;
        }
        if ($input === '/cancelar_flujo' || $input === '/cancel') {
            $this->session->clear();
            $this->session->save();
            $this->showMenu("⚠️ Operación cancelada.");
            return;
        }
        if ($input === '/mis_citas') {
            $this->showMisCitas();
            $this->session->save();
            return;
        }

        // ── Flujo de registro ──
        $cliente = $this->getClienteByChatId();
        if (!$cliente && $estado === 'idle') {
            $this->handleRegistrationStart();
            return;
        }
        if ($estado === 'esperando_nombre') {
            $this->handleNombre($input);
            return;
        }
        if ($estado === 'esperando_telefono') {
            $this->handleTelefono($input);
            return;
        }

        // ── Comandos de menú (texto del keyboard) ──
        switch ($input) {
            case '📅 Agendar Cita': case 'agendar':
                $this->startAgendar(); break;
            case '📋 Mis Citas':    case 'mis_citas':
                $this->showMisCitas(); break;
            case '❌ Cancelar Cita': case 'cancelar_cita':
                $this->startCancelar(); break;
            case '❓ Ayuda':        case 'ayuda':
                $this->showAyuda(); break;
            default:
                $this->handleState($input, $estado);
        }

        $this->session->save();
    }

    // ════════════════════════════════════════════════════════════
    // REGISTRO
    // ════════════════════════════════════════════════════════════

    private function handleRegistrationStart(): void
    {
        $this->session->setEstado('esperando_nombre');
        $this->session->save();
        sendMessage($this->chatId,
            "👋 ¡Bienvenido a <b>PawCare Veterinaria</b>! 🐾\n\n" .
            "Para comenzar necesito registrarte.\n\n" .
            "¿Cuál es tu <b>nombre completo</b>?"
        );
    }

    private function handleNombre(string $input): void
    {
        if (mb_strlen(trim($input)) < 2) {
            sendMessage($this->chatId, "⚠️ Por favor ingresa un nombre válido (mínimo 2 caracteres).");
            return;
        }
        $this->session->set('reg_nombre', trim($input));
        $this->session->setEstado('esperando_telefono');
        sendMessage($this->chatId,
            "¡Hola, <b>{$input}</b>! 😊\n\n" .
            "¿Cuál es tu <b>número de teléfono</b>?\n<i>Ej: 555-1234</i>"
        );
    }

    private function handleTelefono(string $input): void
    {
        $telefono = preg_replace('/[^\d\-\+\s]/', '', trim($input));
        if (strlen($telefono) < 6) {
            sendMessage($this->chatId, "⚠️ Ingresa un teléfono válido.");
            return;
        }
        $nombre = $this->session->get('reg_nombre');
        if (!$nombre) { $this->handleRegistrationStart(); return; }

        // Buscar cliente existente por teléfono
        $stmt = $this->db->prepare('SELECT id FROM clientes WHERE telefono = ?');
        $stmt->execute([$telefono]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Vincular chat_id al cliente existente
            $this->db->prepare('UPDATE clientes SET telegram_chat_id = ? WHERE id = ?')
                     ->execute([$this->chatId, $existing['id']]);
            $msg = "✅ ¡Te reconocemos! Tu cuenta ha sido vinculada con éxito.";
        } else {
            // Crear nuevo cliente
            $parts    = explode(' ', $nombre, 2);
            $apellido = $parts[1] ?? '';
            $this->db->prepare(
                'INSERT INTO clientes (nombre, apellido, telefono, telegram_chat_id) VALUES (?,?,?,?)'
            )->execute([$parts[0], $apellido, $telefono, $this->chatId]);

            $this->db->prepare(
                "INSERT INTO notificaciones (titulo, mensaje, tipo) VALUES (?,?,'success')"
            )->execute(["Nuevo cliente (Telegram)", "Se registró a $nombre vía Telegram."]);

            $msg = "✅ ¡Registro exitoso, <b>$nombre</b>! Bienvenido a PawCare.";
        }

        $this->session->clear();
        $this->session->save();
        sendMessage($this->chatId, $msg);
        $this->showMenu();
    }

    // ════════════════════════════════════════════════════════════
    // MENÚ PRINCIPAL
    // ════════════════════════════════════════════════════════════

    private function showMenu(string $prefix = ''): void
    {
        $text = ($prefix ? "$prefix\n\n" : '') . "🐾 <b>PawCare Veterinaria</b>\n¿Qué deseas hacer?";
        sendMessage($this->chatId, $text, [
            'reply_markup' => replyKeyboard([
                [['text' => '📅 Agendar Cita'], ['text' => '📋 Mis Citas']],
                [['text' => '❌ Cancelar Cita'], ['text' => '❓ Ayuda']],
            ]),
        ]);
    }

    private function handleStart(): void
    {
        $this->session->clear();
        $this->session->save();
        $cliente = $this->getClienteByChatId();
        if (!$cliente) {
            $this->handleRegistrationStart();
            return;
        }
        $this->showMenu("👋 ¡Hola de nuevo, <b>{$cliente['nombre']}</b>!");
    }

    // ════════════════════════════════════════════════════════════
    // FLUJO AGENDAR CITA
    // ════════════════════════════════════════════════════════════

    private function startAgendar(): void
    {
        $cliente = $this->getClienteByChatId();
        if (!$cliente) { $this->handleRegistrationStart(); return; }

        $stmt = $this->db->prepare(
            'SELECT id, nombre, especie FROM mascotas WHERE cliente_id = ? ORDER BY nombre'
        );
        $stmt->execute([$cliente['id']]);
        $mascotas = $stmt->fetchAll();

        if (!$mascotas) {
            sendMessage($this->chatId,
                "🐾 Aún no tienes mascotas registradas en el sistema.\n\n" .
                "Visita nuestra clínica o escríbenos para registrar a tu mascota."
            );
            return;
        }

        $this->session->set('cliente_id', $cliente['id']);
        $this->session->set('mascotas',   $mascotas);
        $this->session->setEstado('agendar_mascota');

        $rows = array_map(
            fn($m) => [['text' => "🐾 {$m['nombre']} ({$m['especie']})", 'callback_data' => "mascota:{$m['id']}"]],
            $mascotas
        );
        $rows[] = [['text' => '🔙 Cancelar', 'callback_data' => '/cancelar_flujo']];

        sendMessage($this->chatId, "📅 <b>Nueva Cita</b>\n\n🐾 ¿Para cuál de tus mascotas es la cita?", [
            'reply_markup' => inlineKeyboard($rows),
        ]);
    }

    private function askFecha(): void
    {
        $this->session->setEstado('agendar_fecha');
        $hoy    = date('Y-m-d');
        $d1     = date('Y-m-d', strtotime('+1 day'));
        $d2     = date('Y-m-d', strtotime('+2 days'));
        $d7     = date('Y-m-d', strtotime('+7 days'));

        sendMessage($this->chatId,
            "📅 ¿Para qué <b>fecha</b> deseas la cita?\n\n" .
            "Selecciona una opción o escribe la fecha: <code>YYYY-MM-DD</code>",
            ['reply_markup' => inlineKeyboard([
                [
                    ['text' => "📅 Hoy ($hoy)",        'callback_data' => "fecha:$hoy"],
                    ['text' => "📅 Mañana ($d1)",       'callback_data' => "fecha:$d1"],
                ],
                [
                    ['text' => "📅 Pasado ($d2)",       'callback_data' => "fecha:$d2"],
                    ['text' => "📅 En 1 semana ($d7)",  'callback_data' => "fecha:$d7"],
                ],
                [['text' => '🔙 Cancelar', 'callback_data' => '/cancelar_flujo']],
            ])]
        );
    }

    private function askSlot(string $fecha): void
    {
        $this->session->set('fecha', $fecha);
        $this->session->setEstado('agendar_slot');

        // Slots ocupados en esa fecha
        $stmt = $this->db->prepare(
            "SELECT hora_inicio FROM citas WHERE fecha = ? AND estado != 'cancelada'"
        );
        $stmt->execute([$fecha]);
        $ocupados = array_column($stmt->fetchAll(), 'hora_inicio');

        // Generar todos los slots de 30 min (08:00 – 18:00)
        $libres = [];
        for ($min = 8 * 60; $min < 18 * 60; $min += 30) {
            $hi = sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
            if (!in_array($hi, $ocupados)) $libres[] = $hi;
        }

        if (!$libres) {
            sendMessage($this->chatId,
                "😔 No hay horarios disponibles para el <b>$fecha</b>.\n" .
                "Elige otra fecha:"
            );
            $this->askFecha();
            return;
        }

        // Teclado en filas de 3
        $rows = [];
        foreach (array_chunk($libres, 3) as $chunk) {
            $rows[] = array_map(
                fn($s) => ['text' => "⏰ $s", 'callback_data' => "slot:$s"],
                $chunk
            );
        }
        $rows[] = [
            ['text' => '📅 Otra fecha', 'callback_data' => 'back_fecha'],
            ['text' => '🔙 Cancelar',   'callback_data' => '/cancelar_flujo'],
        ];

        $total = count($libres);
        sendMessage($this->chatId,
            "⏰ <b>$fecha</b> — $total horario" . ($total !== 1 ? 's' : '') . " disponible" . ($total !== 1 ? 's' : '') . "\n" .
            "(Cada cita dura 30 minutos)",
            ['reply_markup' => inlineKeyboard($rows)]
        );
    }

    private function askMotivo(string $slot): void
    {
        $this->session->set('hora_inicio', $slot);
        $this->session->setEstado('agendar_motivo');

        sendMessage($this->chatId, "📋 ¿Cuál es el <b>motivo</b> de la consulta?", [
            'reply_markup' => inlineKeyboard([
                [
                    ['text' => '💉 Vacunación',      'callback_data' => 'motivo:Vacunación'],
                    ['text' => '🔍 Revisión general', 'callback_data' => 'motivo:Revisión general'],
                ],
                [
                    ['text' => '🚨 Urgencia',         'callback_data' => 'motivo:Urgencia'],
                    ['text' => '✂️ Cirugía',          'callback_data' => 'motivo:Cirugía'],
                ],
                [
                    ['text' => '🪮 Desparasitación',  'callback_data' => 'motivo:Desparasitación'],
                    ['text' => '✍️ Otro motivo...',   'callback_data' => 'motivo:_custom'],
                ],
                [['text' => '🔙 Cancelar', 'callback_data' => '/cancelar_flujo']],
            ]),
        ]);
    }

    private function confirmCita(string $motivo): void
    {
        if ($motivo === '_custom') {
            $this->session->setEstado('agendar_motivo_custom');
            sendMessage($this->chatId, "✍️ Escribe el motivo de tu consulta:");
            return;
        }
        $this->session->set('motivo', $motivo);
        $this->showConfirmacion();
    }

    private function showConfirmacion(): void
    {
        $fecha     = $this->session->get('fecha');
        $hora      = $this->session->get('hora_inicio');
        $horaFin   = date('H:i', strtotime($hora) + 1800);
        $motivo    = $this->session->get('motivo') ?? 'Sin especificar';
        $mascotaId = $this->session->get('mascota_id');

        // Obtener nombre mascota
        $mascotaNombre = '—';
        $mascotas = $this->session->get('mascotas') ?? [];
        foreach ($mascotas as $m) {
            if ($m['id'] == $mascotaId) { $mascotaNombre = "{$m['nombre']} ({$m['especie']})"; break; }
        }
        if ($mascotaNombre === '—') {
            $row = $this->db->prepare('SELECT nombre, especie FROM mascotas WHERE id=?');
            $row->execute([$mascotaId]);
            $m = $row->fetch();
            if ($m) $mascotaNombre = "{$m['nombre']} ({$m['especie']})";
        }

        $this->session->setEstado('agendar_confirm');
        sendMessage($this->chatId,
            "📋 <b>Confirma tu cita:</b>\n\n" .
            "🐾 Mascota:  <b>$mascotaNombre</b>\n" .
            "📅 Fecha:    <b>$fecha</b>\n" .
            "⏰ Horario:  <b>$hora – $horaFin</b>\n" .
            "📋 Motivo:   <b>$motivo</b>\n\n" .
            "¿Confirmas?",
            ['reply_markup' => inlineKeyboard([
                [
                    ['text' => '✅ Confirmar cita', 'callback_data' => 'confirm_cita'],
                    ['text' => '❌ Cancelar',       'callback_data' => '/cancelar_flujo'],
                ],
            ])]
        );
    }

    private function crearCita(): void
    {
        $clienteId = $this->session->get('cliente_id');
        $mascotaId = $this->session->get('mascota_id');
        $fecha     = $this->session->get('fecha');
        $hora      = $this->session->get('hora_inicio');
        $motivo    = $this->session->get('motivo') ?? 'Consulta general';
        $horaFin   = date('H:i', strtotime($hora) + 1800);

        // Verificar slot aún libre
        $check = $this->db->prepare(
            "SELECT id FROM citas WHERE fecha=? AND hora_inicio=? AND estado!='cancelada'"
        );
        $check->execute([$fecha, $hora]);
        if ($check->fetch()) {
            $this->session->clear();
            sendMessage($this->chatId,
                "😔 <b>El horario $hora del $fecha ya se ocupó.</b>\n\n" .
                "Por favor agenda con otro horario."
            );
            $this->session->save();
            $this->showMenu();
            return;
        }

        try {
            $this->db->prepare(
                "INSERT INTO citas
                 (mascota_id, cliente_id, fecha, hora_inicio, hora_fin, motivo, estado)
                 VALUES (?, ?, ?, ?, ?, ?, 'confirmada')"
            )->execute([$mascotaId, $clienteId, $fecha, $hora, $horaFin, $motivo]);

            $newId = (int) $this->db->lastInsertId();

            // Notificación en el dashboard web
            $this->db->prepare(
                "INSERT INTO notificaciones (titulo, mensaje, tipo, cita_id) VALUES (?,?,'success',?)"
            )->execute([
                "📲 Cita vía Telegram",
                "Agendada por Telegram: $fecha a las $hora — $motivo.",
                $newId,
            ]);

            $this->session->clear();
            sendMessage($this->chatId,
                "🎉 <b>¡Cita confirmada!</b>\n\n" .
                "📅 <b>$fecha</b>  ⏰ <b>$hora – $horaFin</b>\n" .
                "📋 $motivo\n" .
                "🆔 Cita #$newId\n\n" .
                "¡Te esperamos! 🐾 Si necesitas cancelar, usa el botón del menú."
            );
            $this->showMenu();
        } catch (\Throwable $e) {
            error_log('[PawCare Bot] crearCita error: ' . $e->getMessage());
            sendMessage($this->chatId, "❌ Ocurrió un error. Intenta de nuevo.");
            $this->session->clear();
        }
    }

    // ════════════════════════════════════════════════════════════
    // MIS CITAS
    // ════════════════════════════════════════════════════════════

    private function showMisCitas(): void
    {
        $cliente = $this->getClienteByChatId();
        if (!$cliente) { $this->handleRegistrationStart(); return; }

        $stmt = $this->db->prepare(
            "SELECT c.id, c.fecha, c.hora_inicio, c.hora_fin, c.motivo, c.estado,
                    m.nombre AS mascota_nombre, m.especie
             FROM citas c
             JOIN mascotas m ON m.id = c.mascota_id
             WHERE c.cliente_id = ?
               AND c.estado NOT IN ('cancelada','completada')
               AND c.fecha >= date('now','localtime')
             ORDER BY c.fecha, c.hora_inicio
             LIMIT 5"
        );
        $stmt->execute([$cliente['id']]);
        $citas = $stmt->fetchAll();

        if (!$citas) {
            sendMessage($this->chatId,
                "📋 No tienes citas próximas agendadas.",
                ['reply_markup' => inlineKeyboard([[
                    ['text' => '📅 Agendar Cita', 'callback_data' => 'agendar'],
                ]])]
            );
            return;
        }

        $text = "📋 <b>Tus próximas citas:</b>\n\n";
        foreach ($citas as $c) {
            $icon  = match($c['estado']) { 'confirmada' => '✅', 'pendiente' => '⏳', default => '📌' };
            $text .= "$icon <b>#{$c['id']}</b> — {$c['mascota_nombre']} ({$c['especie']})\n";
            $text .= "   📅 {$c['fecha']}  ⏰ {$c['hora_inicio']}–{$c['hora_fin']}\n";
            if ($c['motivo']) $text .= "   📋 {$c['motivo']}\n";
            $text .= "\n";
        }
        sendMessage($this->chatId, $text);
    }

    // ════════════════════════════════════════════════════════════
    // CANCELAR CITA
    // ════════════════════════════════════════════════════════════

    private function startCancelar(): void
    {
        $cliente = $this->getClienteByChatId();
        if (!$cliente) { $this->handleRegistrationStart(); return; }

        $stmt = $this->db->prepare(
            "SELECT c.id, c.fecha, c.hora_inicio, m.nombre AS mascota_nombre
             FROM citas c JOIN mascotas m ON m.id = c.mascota_id
             WHERE c.cliente_id = ?
               AND c.estado NOT IN ('cancelada','completada')
               AND c.fecha >= date('now','localtime')
             ORDER BY c.fecha, c.hora_inicio LIMIT 5"
        );
        $stmt->execute([$cliente['id']]);
        $citas = $stmt->fetchAll();

        if (!$citas) {
            sendMessage($this->chatId, "✅ No tienes citas activas para cancelar.");
            return;
        }

        $rows = array_map(fn($c) => [[
            'text'          => "#{$c['id']} — {$c['mascota_nombre']} · {$c['fecha']} {$c['hora_inicio']}",
            'callback_data' => "cancel_cita:{$c['id']}",
        ]], $citas);
        $rows[] = [['text' => '🔙 Volver', 'callback_data' => '/cancelar_flujo']];

        $this->session->setEstado('cancelar_select');
        sendMessage($this->chatId, "❌ ¿Cuál cita deseas <b>cancelar</b>?", [
            'reply_markup' => inlineKeyboard($rows),
        ]);
    }

    private function ejecutarCancelar(int $citaId): void
    {
        $cliente = $this->getClienteByChatId();
        if (!$cliente) return;

        $check = $this->db->prepare(
            "SELECT id FROM citas WHERE id=? AND cliente_id=? AND estado!='cancelada'"
        );
        $check->execute([$citaId, $cliente['id']]);
        if (!$check->fetch()) {
            sendMessage($this->chatId, "❌ Cita no encontrada o ya cancelada.");
            $this->session->clear();
            return;
        }

        $this->db->prepare("UPDATE citas SET estado='cancelada' WHERE id=?")
                 ->execute([$citaId]);

        $this->db->prepare(
            "INSERT INTO notificaciones (titulo, mensaje, tipo, cita_id) VALUES (?,?,'warning',?)"
        )->execute(["Cita cancelada (Telegram)", "El cliente canceló la cita #$citaId desde Telegram.", $citaId]);

        $this->session->clear();
        sendMessage($this->chatId,
            "✅ Cita <b>#$citaId</b> cancelada exitosamente.\n\n¿Necesitas algo más?"
        );
        $this->showMenu();
    }

    // ════════════════════════════════════════════════════════════
    // AYUDA
    // ════════════════════════════════════════════════════════════

    private function showAyuda(): void
    {
        sendMessage($this->chatId,
            "❓ <b>Ayuda — PawCare Bot</b>\n\n" .
            "📅 <b>Agendar Cita</b>\nReserva un horario para tu mascota en horarios " .
            "de 30 min (08:00–18:00)\n\n" .
            "📋 <b>Mis Citas</b>\nVer tus próximas citas activas\n\n" .
            "❌ <b>Cancelar Cita</b>\nCancela una cita activa\n\n" .
            "💬 Comandos:\n" .
            "/start — Reiniciar\n" .
            "/mis_citas — Ver citas\n" .
            "/cancel — Cancelar acción actual\n\n" .
            "📞 ¿Más dudas? Llámanos al 555-PAWCARE"
        );
    }

    // ════════════════════════════════════════════════════════════
    // MÁQUINA DE ESTADOS
    // ════════════════════════════════════════════════════════════

    private function handleState(string $input, string $estado): void
    {
        // mascota seleccionada
        if (str_starts_with($input, 'mascota:') && $estado === 'agendar_mascota') {
            $this->session->set('mascota_id', (int) substr($input, 8));
            $this->askFecha();
            return;
        }

        // fecha: callback o texto libre
        if (str_starts_with($input, 'fecha:') || $estado === 'agendar_fecha') {
            $fecha = str_starts_with($input, 'fecha:') ? substr($input, 6) : $input;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) && $fecha >= date('Y-m-d')) {
                $this->askSlot($fecha);
            } else {
                sendMessage($this->chatId,
                    "⚠️ Fecha inválida o anterior a hoy.\nUsa formato <code>YYYY-MM-DD</code>.\n\n" .
                    "Ej: <code>" . date('Y-m-d', strtotime('+1 day')) . "</code>"
                );
            }
            return;
        }

        if ($input === 'back_fecha') { $this->askFecha(); return; }

        // slot seleccionado
        if (str_starts_with($input, 'slot:') && $estado === 'agendar_slot') {
            $this->askMotivo(substr($input, 5));
            return;
        }

        // motivo
        if (str_starts_with($input, 'motivo:') && in_array($estado, ['agendar_motivo', 'agendar_slot'])) {
            $this->confirmCita(substr($input, 7));
            return;
        }

        // motivo personalizado (texto libre)
        if ($estado === 'agendar_motivo_custom') {
            $this->session->set('motivo', $input);
            $this->showConfirmacion();
            return;
        }

        // confirmar cita
        if ($input === 'confirm_cita' && $estado === 'agendar_confirm') {
            $this->crearCita();
            return;
        }

        // cancelar cita seleccionada
        if (str_starts_with($input, 'cancel_cita:') && $estado === 'cancelar_select') {
            $this->ejecutarCancelar((int) substr($input, 12));
            return;
        }

        // callback 'agendar' desde inline button (ej. en mis_citas vacías)
        if ($input === 'agendar') { $this->startAgendar(); return; }

        // fallback
        if ($estado === 'idle') {
            $this->showMenu("❓ No entendí ese mensaje. Usa el menú:");
        }
    }

    // ════════════════════════════════════════════════════════════
    // HELPERS INTERNOS
    // ════════════════════════════════════════════════════════════

    private function getClienteByChatId(): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM clientes WHERE telegram_chat_id = ?');
        $stmt->execute([$this->chatId]);
        return $stmt->fetch() ?: null;
    }
}
