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
        "model": "google/gemini-2.0-flash-001", # High quality and very fast
        "messages": messages,
        "temperature": 0.7,
        "max_tokens": 500
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
        """Detect if using PostgreSQL wrapper."""
        return hasattr(self.db, 'conn')

    def _ensure_table(self):
        if self._is_pg():
            self.db.execute("""
                CREATE TABLE IF NOT EXISTS bot_sessions (
                    chat_id BIGINT PRIMARY KEY,
                    data TEXT NOT NULL
                )
            """)
        else:
            self.db.execute(
                "CREATE TABLE IF NOT EXISTS bot_sessions (chat_id INTEGER PRIMARY KEY, data TEXT)"
            )
        self.db.commit()

    def _load(self):
        cur = self.db.execute(
            "SELECT data FROM bot_sessions WHERE chat_id = ?", (self.chat_id,)
        )
        row = cur.fetchone()
        if row:
            raw = row['data'] if hasattr(row, '__getitem__') else row[0]
            try:
                return json.loads(raw)
            except:
                return {'estado': 'idle'}
        return {'estado': 'idle'}

    def save(self):
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
        input_txt = cb_data or text.strip()

        if input_txt in ['/start', '/menu', '🔙 Cancelar', '/cancelar_flujo']:
            self.session.clear()
            self._menu(f"¡Hola {self.first_name}! 🐶" if self.first_name else "¡Hola! 🐶")
        elif input_txt == '📅 Agendar Cita':
            send_message(self.chat_id, "Puedes agendar tus citas desde nuestro panel web pulsando el enlace superior. Pronto podré agendarlas directamente por aquí. 🐾")
        elif input_txt == '📋 Mis Citas':
            send_message(self.chat_id, "Estamos sincronizando tus datos. Por favor revisa el panel de Clientes en la web por ahora. 💻")
        elif input_txt == '❓ Ayuda':
            send_message(self.chat_id, "Soy tu asistente de PawCare. Puedes preguntarme sobre cuidados para tu perro, servicios que ofrecemos o simplemente charlar. ¿En qué te ayudo?")
        else:
            # AI Inference
            history = self.session.get('history', [])
            ai_resp = get_ai_response(input_txt, history)
            send_message(self.chat_id, ai_resp)
            
            # Simple context management (last 10 messages)
            history.append({"role": "user", "content": input_txt})
            history.append({"role": "assistant", "content": ai_resp})
            self.session.set('history', history[-10:])
            
        self.session.save()

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
