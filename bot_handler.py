import os
import requests
import json
import sqlite3
from fastapi import APIRouter, Request, Depends, HTTPException
from database import get_db

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
    def __init__(self, db: sqlite3.Connection, chat_id: int):
        self.db = db
        self.chat_id = chat_id
        self.data = self._load()

    def _load(self):
        self.db.execute("CREATE TABLE IF NOT EXISTS bot_sessions (chat_id INTEGER PRIMARY KEY, data TEXT)")
        cur = self.db.execute("SELECT data FROM bot_sessions WHERE chat_id=?", (self.chat_id,))
        row = cur.fetchone()
        return json.loads(row['data']) if row else {'estado': 'idle'}

    def save(self):
        self.db.execute("INSERT OR REPLACE INTO bot_sessions (chat_id, data) VALUES (?, ?)", 
                        (self.chat_id, json.dumps(self.data)))
        self.db.commit()

    def get_estado(self): return self.data.get('estado', 'idle')
    def set_estado(self, e): self.data['estado'] = e
    def get(self, k, default=None): return self.data.get(k, default)
    def set(self, k, v): self.data[k] = v
    def clear(self): self.data = {'estado': 'idle'}

class BotHandler:
    def __init__(self, db, chat_id, first_name=""):
        self.db = db
        self.chat_id = chat_id
        self.first_name = first_name
        self.session = BotSession(db, chat_id)

    def handle(self, text, cb_data=None, cb_id=None):
        if cb_id: answer_callback(cb_id)
        input_txt = cb_data or text.strip()
        
        if input_txt in ['/start', '/menu', '🔙 Cancelar', '/cancelar_flujo']:
            self.session.clear()
            self._menu("Menu Principal")
        else:
            send_message(self.chat_id, "Funcionalidad nativa en Python en construcción. Usa tu panel web para agendar citas o vuelve al menú.", 
                         reply_markup={"keyboard": [
                             [{"text": "/menu"}]
                         ], "resize_keyboard": True})
        self.session.save()

    def _menu(self, prefix):
        send_message(self.chat_id, prefix + "\n\nPawCare bot ahora corriendo en **Python/FastAPI** 🎉\n(En proceso de migración total)",
                     reply_markup={"keyboard": [
                         [{"text": "📅 Agendar Cita"}, {"text": "📋 Mis Citas"}],
                         [{"text": "❌ Cancelar Cita"}, {"text": "❓ Ayuda"}]
                     ], "resize_keyboard": True})

@router.post("/webhook")
async def telegram_webhook(request: Request, db: sqlite3.Connection = Depends(get_db)):
    data = await request.json()
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
        handler = BotHandler(db, chat_id, first_name)
        handler.handle(text, cb_data, cb_id)

    return {"ok": True}
