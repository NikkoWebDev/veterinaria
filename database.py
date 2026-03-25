import sqlite3
import os
import psycopg2
from psycopg2.extras import RealDictCursor

DB_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'db', 'veterinaria.db')
ENV_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'api.env')

def load_env():
    if os.path.exists(ENV_PATH):
        with open(ENV_PATH, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#'):
                    if '=' in line:
                        key, val = line.split('=', 1)
                        os.environ[key.strip()] = val.strip()

load_env()

def get_db():
    db_url = os.environ.get("DATABASE_URL")
    if db_url:
        # Usar PostgreSQL
        conn = psycopg2.connect(db_url)
        # Adaptador para que funcione similar a sqlite3 (dict access)
        class PGConnWrapper:
            def __init__(self, conn):
                self.conn = conn
            def execute(self, sql, params=None):
                # Convertir placeholders ? a %s para PostgreSQL
                sql = sql.replace('?', '%s')
                # Manejar date('now')
                sql = sql.replace("date('now','localtime')", "CURRENT_DATE")
                
                cur = self.conn.cursor(cursor_factory=RealDictCursor)
                cur.execute(sql, params)
                return cur
            def commit(self): self.conn.commit()
            def close(self): self.conn.close()
            def fetchone(self, cur): return cur.fetchone()
            def fetchall(self, cur): return cur.fetchall()
        
        wrapped = PGConnWrapper(conn)
        try:
            yield wrapped
        finally:
            wrapped.close()
    else:
        # Usar SQLite
        conn = sqlite3.connect(DB_PATH, check_same_thread=False)
        conn.row_factory = sqlite3.Row
        try:
            yield conn
        finally:
            conn.close()
