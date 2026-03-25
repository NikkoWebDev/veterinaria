-- ============================================================
-- PawCare - Sistema de Veterinaria
-- Schema de base de datos SQLite
-- ============================================================

CREATE TABLE IF NOT EXISTS clientes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre     TEXT NOT NULL,
    apellido   TEXT NOT NULL,
    telefono   TEXT NOT NULL,
    email      TEXT UNIQUE,
    direccion  TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mascotas (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre          TEXT NOT NULL,
    especie         TEXT NOT NULL,
    raza            TEXT,
    fecha_nacimiento DATE,
    peso            REAL,
    color           TEXT,
    cliente_id      INTEGER NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS citas (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    mascota_id  INTEGER NOT NULL,
    cliente_id  INTEGER NOT NULL,
    fecha       DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin    TIME NOT NULL,
    motivo      TEXT,
    estado      TEXT DEFAULT 'pendiente'
                     CHECK(estado IN ('pendiente','confirmada','completada','cancelada')),
    veterinario TEXT DEFAULT 'Dr. General',
    notas       TEXT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mascota_id) REFERENCES mascotas(id),
    FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    UNIQUE(fecha, hora_inicio)
);

CREATE TABLE IF NOT EXISTS notificaciones (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    titulo     TEXT NOT NULL,
    mensaje    TEXT NOT NULL,
    tipo       TEXT DEFAULT 'info'
                    CHECK(tipo IN ('info','warning','success','error')),
    leida      INTEGER DEFAULT 0,
    cita_id    INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cita_id) REFERENCES citas(id) ON DELETE SET NULL
);

-- ── Telegram Bot ────────────────────────────────────────────────
-- Sesiones de conversación por chat_id
CREATE TABLE IF NOT EXISTS telegram_sessions (
    chat_id    INTEGER PRIMARY KEY,
    estado     TEXT NOT NULL DEFAULT 'idle',
    datos      TEXT NOT NULL DEFAULT '{}',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Datos de prueba
INSERT OR IGNORE INTO clientes (nombre, apellido, telefono, email, direccion)
VALUES
  ('María', 'García', '555-1001', 'maria.garcia@email.com', 'Calle 10 #45'),
  ('Carlos', 'López', '555-1002', 'carlos.lopez@email.com', 'Av. Principal #12'),
  ('Ana', 'Martínez', '555-1003', 'ana.martinez@email.com', 'Carrera 5 #30');

INSERT OR IGNORE INTO mascotas (nombre, especie, raza, peso, color, cliente_id)
VALUES
  ('Lola', 'Perro', 'Labrador', 8.5, 'Dorado', 1),
  ('Mittens', 'Gato', 'Siamés', 3.2, 'Blanco/Café', 2),
  ('Rocky', 'Perro', 'Pastor Alemán', 15.0, 'Negro/Café', 3);
