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
            return json.loads(raw)
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
            self._menu("Menu Principal")
        else:
            send_message(
                self.chat_id,
                "Funcionalidad nativa en Python en construcción. Usa tu panel web para agendar citas o vuelve al menú.",
                reply_markup={"keyboard": [[{"text": "/menu"}]], "resize_keyboard": True}
            )
        self.session.save()

    def _menu(self, prefix: str):
        send_message(
            self.chat_id,
            f"{prefix}\n\nPawCare bot corriendo en Python/FastAPI 🎉",
            reply_markup={"keyboard": [
                [{"text": "📅 Agendar Cita"}, {"text": "📋 Mis Citas"}],
                [{"text": "❌ Cancelar Cita"}, {"text": "❓ Ayuda"}]
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
