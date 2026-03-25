<?php
/**
 * PawCare — Bot Session Manager
 * Persiste el estado de conversación por chat_id en SQLite.
 */

class BotSession
{
    private PDO   $db;
    private int   $chatId;
    private string $estado;
    private array  $datos;

    public function __construct(PDO $db, int $chatId)
    {
        $this->db     = $db;
        $this->chatId = $chatId;
        $this->load();
    }

    // ── Load from DB ────────────────────────────────────────────
    private function load(): void
    {
        $stmt = $this->db->prepare(
            'SELECT estado, datos FROM telegram_sessions WHERE chat_id = ?'
        );
        $stmt->execute([$this->chatId]);
        $row = $stmt->fetch();

        if ($row) {
            $this->estado = $row['estado'];
            $this->datos  = json_decode($row['datos'], true) ?? [];
        } else {
            $this->estado = 'idle';
            $this->datos  = [];
        }
    }

    // ── Getters / Setters ───────────────────────────────────────
    public function getEstado(): string
    {
        return $this->estado;
    }

    public function setEstado(string $estado): void
    {
        $this->estado = $estado;
    }

    public function get(string $key): mixed
    {
        return $this->datos[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->datos[$key] = $value;
    }

    public function clear(): void
    {
        $this->estado = 'idle';
        $this->datos  = [];
    }

    // ── Persist ─────────────────────────────────────────────────
    public function save(): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO telegram_sessions (chat_id, estado, datos, updated_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)
             ON CONFLICT(chat_id) DO UPDATE SET
               estado     = excluded.estado,
               datos      = excluded.datos,
               updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $this->chatId,
            $this->estado,
            json_encode($this->datos, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
