import os
import requests
import json
from fastapi import APIRouter, Request, Depends
from database import get_db
from typing import Any

router = APIRouter()

def get_bot_token():
    return os.environ.get('api_telegram', '')

def send_message(chat_id, text, reply_markup=None):
    token = get_bot_token()
    if not token: return
    data = {"chat_id": chat_id, "text": text, "parse_mode": "HTML"}
    if reply_markup: data["reply_markup"] = reply_markup
    requests.post(f"https://api.telegram.org/bot{token}/sendMessage", json=data)

def answer_callback(cb_id):
    token = get_bot_token()
    if not token: return
    requests.post(f"https://api.telegram.org/bot{token}/answerCallbackQuery", json={"callback_query_id": cb_id})

from datetime import datetime, timedelta

def get_ai_response(text, history=None):
    """Obtain a response from OpenRouter AI."""
    api_key = os.environ.get('OPENROUTER_API_KEY', '')
    if not api_key:
        return "⚠️ Error: OPENROUTER_API_KEY no configurada en el servidor."

    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json",
        "HTTP-Referer": "https://veterinaria.netlify.app",
        "X-Title": "Veterinaria AI Assistant"
    }

    # Prepare messages starting with a system prompt
    messages = [
        {"role": "system", "content": (
            "Eres un asistente virtual experto para la clínica veterinaria 'PawCare'. "
            "Eres extremadamente amable, profesional y empático. Ayudas con dudas sobre mascotas, "
            "explicas los servicios (citas, vacunas, estética) y orientas sobre la salud general. "
            "Si el usuario quiere agendar una cita, explícale que puede hacerlo pulsando el botón '📅 Agendar Cita' del menú. "
            "MUY IMPORTANTE: No diagnostiques enfermedades críticas ni recetes medicamentos. "
            "Ante emergencias o dolores fuertes, siempre indica que deben acudir a urgencias. "
            "Usa emojis para un tono amigable."
        )}
    ]
    
    # Add historical context if provided (keep it limited to last few interactions)
    if history:
        messages.extend(history)

    # Add the newest message
    messages.append({"role": "user", "content": text})

    payload = {
        "model": "google/gemini-2.0-flash-001",
        "messages": messages,
        "temperature": 0.7,
        "max_tokens": 400
    }

    try:
        response = requests.post(
            "https://openrouter.ai/api/v1/chat/completions",
            headers=headers,
            json=payload,
            timeout=20
        )
        response.raise_for_status()
        data = response.json()
        return data['choices'][0]['message']['content']
    except Exception as e:
        print(f"[OpenRouter Error] {e}")
        return "He tenido un pequeño problema técnico al pensar mi respuesta. ¿En qué más puedo ayudarte? 🐾"

class BotSession:
    def __init__(self, db: Any, chat_id: int):
        self.db = db
        self.chat_id = chat_id
        self._ensure_table()
        self.data = self._load()

    def _is_pg(self):
        return hasattr(self.db, 'conn')

    def _ensure_table(self):
        """Ensure necessary tables and columns exist."""
        try:
            if self._is_pg():
                # Table for sessions
                self.db.execute("""
                    CREATE TABLE IF NOT EXISTS bot_sessions (
                        chat_id BIGINT PRIMARY KEY,
                        data TEXT NOT NULL
                    )
                """)
                # Migration: Add column to clientes if missing
                try:
                    self.db.execute("ALTER TABLE clientes ADD COLUMN telegram_chat_id BIGINT")
                    self.db.commit()
                except:
                    pass # Already exists
            else:
                self.db.execute(
                    "CREATE TABLE IF NOT EXISTS bot_sessions (chat_id INTEGER PRIMARY KEY, data TEXT)"
                )
                try:
                    self.db.execute("ALTER TABLE clientes ADD COLUMN telegram_chat_id INTEGER")
                    self.db.commit()
                except:
                    pass
            self.db.commit()
        except Exception as e:
            print(f"[BotSession] _ensure_table error: {e}")

    def _load(self):
        try:
            cur = self.db.execute(
                "SELECT data FROM bot_sessions WHERE chat_id = ?", (self.chat_id,)
            )
            row = cur.fetchone()
            if row:
                raw = row['data'] if hasattr(row, '__getitem__') else row[0]
                return json.loads(raw)
        except Exception as e:
            print(f"[BotSession] _load error: {e}")
        return {'estado': 'idle'}

    def save(self):
        try:
            data_str = json.dumps(self.data)
            if self._is_pg():
                self.db.execute(
                    """INSERT INTO bot_sessions (chat_id, data) VALUES (?, ?)
                       ON CONFLICT (chat_id) DO UPDATE SET data = EXCLUDED.data""",
                    (self.chat_id, data_str)
                )
            else:
                self.db.execute(
                    "INSERT OR REPLACE INTO bot_sessions (chat_id, data) VALUES (?, ?)",
                    (self.chat_id, data_str)
                )
            self.db.commit()
        except Exception as e:
            print(f"[BotSession] save error: {e}")

    def get_estado(self): return self.data.get('estado', 'idle')
    def set_estado(self, e): self.data['estado'] = e
    def get(self, k, default=None): return self.data.get(k, default)
    def set(self, k, v): self.data[k] = v
    def clear(self): self.data = {'estado': 'idle'}


class BotHandler:
    def __init__(self, db: Any, chat_id: int, first_name: str = ""):
        self.db = db
        self.chat_id = chat_id
        self.first_name = first_name
        self.session = BotSession(db, chat_id)

    def handle(self, text: str, cb_data=None, cb_id=None):
        if cb_id:
            answer_callback(cb_id)
        
        input_txt = (cb_data or text or "").strip()
        state = self.session.get_estado()
        
        print(f"[BotHandler] Chat:{self.chat_id} State:{state} Input:{input_txt}")

        # Comandos globales
        if input_txt in ['/start', '/menu', '🔙 Cancelar', 'cancelar_cita']:
            self.session.clear()
            self._menu(f"¡Hola {self.first_name}! 🐶" if self.first_name else "¡Hola! 🐶")
            self.session.save()
            return

        if state == 'idle':
            if input_txt == '📅 Agendar Cita' or input_txt == 'agendar':
                self._flow_start_booking()
            elif input_txt == '📋 Mis Citas':
                self._show_citas()
            elif input_txt == '❓ Ayuda':
                send_message(self.chat_id, "Soy tu asistente de PawCare. Puedes preguntarme sobre cuidados, servicios o agendar una cita directamente aquí.")
            elif input_txt:
                history = self.session.get('history', [])
                ai_resp = get_ai_response(input_txt, history)
                send_message(self.chat_id, ai_resp)
                history.append({"role": "user", "content": input_txt})
                history.append({"role": "assistant", "content": ai_resp})
                self.session.set('history', history[-10:])
            else:
                self._menu("Elige una opción:")

        elif state == 'await_phone':
            self._handle_phone(input_txt)
        elif state == 'select_pet':
            self._handle_pet_selection(input_txt)
        elif state == 'select_date':
            self._handle_date_selection(input_txt)
        elif state == 'select_time':
            self._handle_time_selection(input_txt)

        self.session.save()

    def _flow_start_booking(self):
        print(f"[BotHandler] _flow_start_booking for {self.chat_id}")
        # 1. Buscar si el cliente ya está vinculado
        try:
            cur = self.db.execute("SELECT id FROM clientes WHERE telegram_chat_id = ?", (self.chat_id,))
            client = cur.fetchone()
            
            if not client:
                self.session.set_estado('await_phone')
                send_message(self.chat_id, "Para agendar tu cita, primero necesito identificarte. \n\nPor favor, **escribe tu número de teléfono** registrado en la clínica:")
            else:
                cid = client['id'] if isinstance(client, dict) else client[0]
                self.session.set('cliente_id', cid)
                self._show_pet_selection()
        except Exception as e:
            print(f"[BotHandler] _flow_start_booking DB Error: {e}")
            send_message(self.chat_id, "Ocurrió un error al acceder a la base de datos. Por favor intenta más tarde.")

    def _handle_phone(self, phone):
        # Limpiar teléfono de espacios/guiones
        clean_phone = ''.join(filter(str.isdigit, phone))
        if not clean_phone:
            send_message(self.chat_id, "Por favor, escribe un número de teléfono válido.")
            return

        cur = self.db.execute("SELECT id, nombre FROM clientes WHERE telefono LIKE ?", (f"%{clean_phone}",))
        client = cur.fetchone()
        
        if client:
            cid = client['id'] if isinstance(client, dict) else client[0]
            cname = client['nombre'] if isinstance(client, dict) else client[1]
            # Vincular
            self.db.execute("UPDATE clientes SET telegram_chat_id = ? WHERE id = ?", (self.chat_id, cid))
            self.db.commit()
            
            self.session.set('cliente_id', cid)
            send_message(self.chat_id, f"¡Gracias {cname}! He vinculado tu cuenta.")
            self._show_pet_selection()
        else:
            send_message(self.chat_id, "No encontré ningún cliente con ese teléfono. Por favor, asegúrate de escribirlo correctamente o regístrate en nuestra web. \n\n¿Quieres intentarlo de nuevo? Escribe tu teléfono o pulsa /menu para salir.")

    def _show_pet_selection(self):
        cid = self.session.get('cliente_id')
        cur = self.db.execute("SELECT id, nombre FROM mascotas WHERE cliente_id = ?", (cid,))
        pets = cur.fetchall()
        
        if not pets:
            send_message(self.chat_id, "No tienes mascotas registradas. Por favor regístralas en la web primero. 🐾")
            self.session.clear()
            return

        buttons = []
        for p in pets:
            pid = p['id'] if isinstance(p, dict) else p[0]
            pname = p['nombre'] if isinstance(p, dict) else p[1]
            buttons.append([{"text": f"🐾 {pname}", "callback_data": f"pet_{pid}"}])
        
        buttons.append([{"text": "❌ Cancelar", "callback_data": "cancelar_cita"}])
        
        self.session.set_estado('select_pet')
        send_message(self.chat_id, "¿Para qué mascota es la cita?", reply_markup={"inline_keyboard": buttons})

    def _handle_pet_selection(self, data):
        if not data.startswith('pet_'): 
            send_message(self.chat_id, "Por favor selecciona una mascota válida usando los botones.")
            return
        
        pid = int(data.split('_')[1])
        self.session.set('mascota_id', pid)
        
        # Mostrar fechas (Hoy y próximos 3 días)
        buttons = []
        today = datetime.now()
        for i in range(4):
            date_obj = today + timedelta(days=i)
            date_str = date_obj.strftime('%Y-%m-%d')
            label = "Hoy" if i == 0 else "Mañana" if i == 1 else date_obj.strftime('%d/%m')
            buttons.append([{"text": f"📅 {label} ({date_str})", "callback_data": f"date_{date_str}"}])
            
        buttons.append([{"text": "🔙 Atrás", "callback_data": "agendar"}])
        
        self.session.set_estado('select_date')
        send_message(self.chat_id, "Selecciona una fecha:", reply_markup={"inline_keyboard": buttons})

    def _handle_date_selection(self, data):
        if not data.startswith('date_'): return
        date_str = data.split('_')[1]
        self.session.set('fecha', date_str)
        
        # Obtener slots disponibles
        slots = self._get_available_slots(date_str)
        if not slots:
            send_message(self.chat_id, "Lo siento, no hay turnos disponibles para esa fecha. Por favor elige otro día.")
            return

        buttons = []
        row = []
        for s in slots:
            row.append({"text": s, "callback_data": f"time_{s}"})
            if len(row) == 3:
                buttons.append(row)
                row = []
        if row: buttons.append(row)
        
        buttons.append([{"text": "🔙 Cambiar fecha", "callback_data": "select_date_back"}]) # Simplificado
        
        self.session.set_estado('select_time')
        send_message(self.chat_id, f"Turnos disponibles para el {date_str}:", reply_markup={"inline_keyboard": buttons})

    def _get_available_slots(self, fecha):
        # Lógica similar a routes_api.py
        all_slots = []
        for h in range(8, 18):
            for m in (0, 30):
                all_slots.append(f"{h:02d}:{m:02d}")
        
        cur = self.db.execute("SELECT hora_inicio FROM citas WHERE fecha=? AND estado!='cancelada'", (fecha,))
        ocupados = [r['hora_inicio'] if isinstance(r, dict) else r[0] for r in cur.fetchall()]
        # Quitar segundos si vienen de PG
        ocupados = [t.strftime('%H:%M') if hasattr(t, 'strftime') else t[:5] for t in ocupados]
        
        return [s for s in all_slots if s not in ocupados]

    def _handle_time_selection(self, data):
        if data == "select_date_back":
            self._handle_pet_selection(f"pet_{self.session.get('mascota_id')}")
            return
            
        if not data.startswith('time_'): return
        hora = data.split('_')[1]
        
        # Crear la cita!
        cid = self.session.get('cliente_id')
        pid = self.session.get('mascota_id')
        fecha = self.session.get('fecha')
        hora_fin = (datetime.strptime(hora, '%H:%M') + timedelta(minutes=30)).strftime('%H:%M')
        
        try:
            self.db.execute(
                "INSERT INTO citas (cliente_id, mascota_id, fecha, hora_inicio, hora_fin, motivo, estado) VALUES (?,?,?,?,?,?,?)",
                (cid, pid, fecha, hora, hora_fin, "Cita desde Telegram", "pendiente")
            )
            self.db.commit()
            
            # Notificación
            self.db.execute("INSERT INTO notificaciones (titulo, mensaje, tipo) VALUES (?,?,?)",
                           ("Nueva Cita Telegram", f"Cita para el {fecha} a las {hora}", "info"))
            self.db.commit()
            
            send_message(self.chat_id, f"✅ ¡Cita confirmada!\n\n📅 Fecha: {fecha}\n⏰ Hora: {hora}\n🐾 Mascota: Al día\n\nTe esperamos en PawCare.")
            self.session.clear()
            self._menu("¿Algo más en lo que pueda ayudarte?")
        except Exception as e:
            print(f"Error booking: {e}")
            send_message(self.chat_id, "Ocurrió un error al guardar tu cita. Por favor intenta más tarde.")
            self.session.clear()

    def _show_citas(self):
        cur = self.db.execute("SELECT id FROM clientes WHERE telegram_chat_id = ?", (self.chat_id,))
        client = cur.fetchone()
        if not client:
            send_message(self.chat_id, "Aún no has vinculado tu cuenta. Usa '📅 Agendar Cita' para hacerlo.")
            return
            
        cid = client['id'] if isinstance(client, dict) else client[0]
        cur = self.db.execute("""
            SELECT c.fecha, c.hora_inicio, m.nombre as mascota, c.estado 
            FROM citas c 
            JOIN mascotas m ON m.id = c.mascota_id 
            WHERE c.cliente_id = ? AND c.fecha >= date('now')
            ORDER BY c.fecha, c.hora_inicio LIMIT 5
        """, (cid,))
        rows = cur.fetchall()
        
        if not rows:
            send_message(self.chat_id, "No tienes citas próximas. 📅")
        else:
            txt = "<b>Tus próximas citas:</b>\n\n"
            for r in rows:
                fecha = r['fecha'] if isinstance(r, dict) else r[0]
                hora = r['hora_inicio'] if isinstance(r, dict) else r[1]
                mascota = r['mascota'] if isinstance(r, dict) else r[2]
                estado = r['estado'] if isinstance(r, dict) else r[3]
                txt += f"• {fecha} {hora} - {mascota} ({estado})\n"
            send_message(self.chat_id, txt)

    def _menu(self, prefix: str):
        send_message(
            self.chat_id,
            f"{prefix}\n\nSoy el asistente virtual de PawCare. ¿En qué puedo ayudarte hoy?",
            reply_markup={"keyboard": [
                [{"text": "📅 Agendar Cita"}, {"text": "📋 Mis Citas"}],
                [{"text": "❓ Ayuda"}]
            ], "resize_keyboard": True}
        )


@router.post("/webhook")
async def telegram_webhook(request: Request, db: Any = Depends(get_db)):
    try:
        data = await request.json()
    except Exception:
        return {"ok": False, "error": "Invalid JSON"}

    chat_id = None
    text = ""
    first_name = ""
    cb_data = None
    cb_id = None

    if "message" in data:
        msg = data["message"]
        chat_id = msg.get("chat", {}).get("id")
        text = msg.get("text", "")
        first_name = msg.get("from", {}).get("first_name", "")
    elif "callback_query" in data:
        cb = data["callback_query"]
        chat_id = cb.get("message", {}).get("chat", {}).get("id")
        cb_data = cb.get("data", "")
        cb_id = cb.get("id")
        first_name = cb.get("from", {}).get("first_name", "")

    if chat_id:
        try:
            handler = BotHandler(db, chat_id, first_name)
            handler.handle(text, cb_data, cb_id)
        except Exception as e:
            print(f"[BotHandler] Error: {e}")

    return {"ok": True}


@router.get("/setup_webhook")
async def setup_webhook(request: Request):
    """Register the Telegram webhook pointing to this server."""
    token = get_bot_token()
    if not token:
        return {"ok": False, "error": "Token not configured"}

    # Build webhook URL from current request host
    base_url = str(request.base_url).rstrip("/")
    webhook_url = f"{base_url}/api/bot/webhook"

    res = requests.post(
        f"https://api.telegram.org/bot{token}/setWebhook",
        json={"url": webhook_url}
    )
    result = res.json()
    return {"webhook_url": webhook_url, "telegram_response": result}
