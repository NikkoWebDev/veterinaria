import os
import requests
from fastapi import APIRouter, Depends, HTTPException, Request
from database import get_db
from typing import Any
from datetime import datetime, timedelta

router = APIRouter()

def get_json(req: Request):
    return req.json()

@router.get("/telegram_link")
async def telegram_link():
    token = os.environ.get('api_telegram', '')
    if not token:
        raise HTTPException(status_code=500, detail="Token no config")
    
    res = requests.post(f"https://api.telegram.org/bot{token}/getMe")
    data = res.json()
    if data.get('ok'):
        return {"url": f"https://t.me/{data['result']['username']}"}
    raise HTTPException(status_code=500, detail="Bot not found")

@router.api_route("/citas", methods=["GET", "POST", "PUT", "DELETE"])
async def citas_endpoint(request: Request, db: Any = Depends(get_db)):
    method = request.method
    id = request.query_params.get('id')

    if method == "GET":
        if id:
            cur = db.execute('''SELECT c.*, m.nombre AS mascota_nombre, m.especie,
                        cl.nombre AS cliente_nombre, cl.apellido AS cliente_apellido,
                        cl.telefono
                 FROM citas c
                 JOIN mascotas m  ON m.id  = c.mascota_id
                 JOIN clientes cl ON cl.id = c.cliente_id
                 WHERE c.id = ?''', (id,))
            row = cur.fetchone()
            if not row: raise HTTPException(404, "Not found")
            return dict(row)

        params = []
        where = []
        fecha = request.query_params.get('fecha')
        estado = request.query_params.get('estado')
        hoy = request.query_params.get('hoy')
        
        if fecha: where.append('c.fecha = ?'); params.append(fecha)
        if estado: where.append('c.estado = ?'); params.append(estado)
        if hoy == '1':
            where.append("c.fecha = date('now','localtime')")
            where.append("c.estado != 'cancelada'")
            where.append("c.estado != 'completada'")
            
        sql = '''SELECT c.*, m.nombre AS mascota_nombre, m.especie,
                       cl.nombre AS cliente_nombre, cl.apellido AS cliente_apellido, cl.telefono
                FROM citas c
                JOIN mascotas m  ON m.id  = c.mascota_id
                JOIN clientes cl ON cl.id = c.cliente_id'''
        if where: sql += ' WHERE ' + ' AND '.join(where)
        sql += ' ORDER BY c.fecha, c.hora_inicio'
        return [dict(r) for r in db.execute(sql, params).fetchall()]

    elif method == "POST":
        data = await request.json()
        hora_fin = (datetime.strptime(data['hora_inicio'], '%H:%M') + timedelta(minutes=30)).strftime('%H:%M')
        cur = db.execute("SELECT id FROM citas WHERE fecha=? AND hora_inicio=? AND estado!='cancelada'", (data['fecha'], data['hora_inicio']))
        if cur.fetchone(): raise HTTPException(409, "Slot ocupado")
        
        if hasattr(db, 'conn'): # PostgreSQL wrapper
            cur = db.execute('''INSERT INTO citas (mascota_id, cliente_id, fecha, hora_inicio, hora_fin, motivo, estado, veterinario, notas)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id''', (
                data.get('mascota_id'), data.get('cliente_id'), data.get('fecha'), data.get('hora_inicio'), hora_fin,
                data.get('motivo'), data.get('estado', 'pendiente'), data.get('veterinario', 'Dr. General'), data.get('notas')
            ))
            new_id = cur.fetchone()['id']
        else: # SQLite
            cur = db.execute('''INSERT INTO citas (mascota_id, cliente_id, fecha, hora_inicio, hora_fin, motivo, estado, veterinario, notas)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)''', (
                data.get('mascota_id'), data.get('cliente_id'), data.get('fecha'), data.get('hora_inicio'), hora_fin,
                data.get('motivo'), data.get('estado', 'pendiente'), data.get('veterinario', 'Dr. General'), data.get('notas')
            ))
            new_id = cur.lastrowid
            
        db.execute("INSERT INTO notificaciones (titulo, mensaje, tipo, cita_id) VALUES (?,?,'info',?)", 
                   ("Cita agendada", f"Cita el {data['fecha']} a las {data['hora_inicio']}", new_id))
        db.commit()
        return {"id": new_id, "hora_fin": hora_fin}

    elif method == "PUT":
        data = await request.json()
        if 'estado' in data:
            tipo = 'success' if data['estado'] == 'completada' else 'warning'
            db.execute("INSERT INTO notificaciones (titulo, mensaje, tipo, cita_id) VALUES (?,?,?,?)",
                       ("Cita actualizada", "Nuevo estado: " + data['estado'], tipo, id))
            
        query = "UPDATE citas SET "
        updates = []
        params = []
        for k in ['mascota_id', 'cliente_id', 'fecha', 'hora_inicio', 'motivo', 'estado', 'veterinario', 'notas']:
            if k in data:
                updates.append(f"{k}=?")
                params.append(data[k])
                
        if 'hora_inicio' in data:
            hora_fin = (datetime.strptime(data['hora_inicio'], '%H:%M') + timedelta(minutes=30)).strftime('%H:%M')
            updates.append("hora_fin=?")
            params.append(hora_fin)
            
        if updates and id:
            params.append(id)
            db.execute(query + ", ".join(updates) + " WHERE id=?", params)
            db.commit()
        return {"message": "updated"}

@router.api_route("/clientes", methods=["GET", "POST", "PUT", "DELETE"])
async def clientes_endp(request: Request, db: Any = Depends(get_db)):
    method = request.method
    id = request.query_params.get('id')
    if method == "GET":
        if id:
             return dict(db.execute("SELECT * FROM clientes WHERE id=?", (id,)).fetchone() or {})
        search = request.query_params.get('search', '').strip()
        if search:
            return [dict(r) for r in db.execute("SELECT * FROM clientes WHERE nombre LIKE ? OR apellido LIKE ? ORDER BY nombre", (f"%{search}%", f"%{search}%")).fetchall()]
        return [dict(r) for r in db.execute("SELECT * FROM clientes ORDER BY nombre").fetchall()]
    elif method == "POST":
        data = await request.json()
        db.execute("INSERT INTO clientes (nombre, apellido, telefono, email, direccion) VALUES (?,?,?,?,?)",
                   (data['nombre'], data['apellido'], data['telefono'], data.get('email'), data.get('direccion')))
        db.commit()
        return {"message": "created"}

@router.api_route("/mascotas", methods=["GET", "POST", "PUT", "DELETE"])
async def mascotas_endp(request: Request, db: Any = Depends(get_db)):
    method = request.method
    id = request.query_params.get('id')
    if method == "GET":
        if id:
             return dict(db.execute("SELECT m.*, c.nombre AS cliente_nombre FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.id=?", (id,)).fetchone() or {})
        return [dict(r) for r in db.execute("SELECT m.*, c.nombre AS cliente_nombre FROM mascotas m JOIN clientes c ON c.id=m.cliente_id ORDER BY m.nombre").fetchall()]
    elif method == "POST":
        data = await request.json()
        db.execute("INSERT INTO mascotas (nombre, especie, raza, fecha_nacimiento, peso, color, cliente_id) VALUES (?,?,?,?,?,?,?)",
                   (data['nombre'], data['especie'], data.get('raza'), data.get('fecha_nacimiento'), data.get('peso'), data.get('color'), data['cliente_id']))
        db.commit()
        return {"message": "created"}

@router.api_route("/notificaciones", methods=["GET", "POST", "PUT", "DELETE"])
async def notif_endp(request: Request, db: Any = Depends(get_db)):
    method = request.method
    id = request.query_params.get('id')
    if method == "GET":
        if request.query_params.get('no_leidas'):
            count = db.execute("SELECT COUNT(*) as c FROM notificaciones WHERE leida=0").fetchone()['c']
            rows = db.execute("SELECT * FROM notificaciones WHERE leida=0 ORDER BY created_at DESC LIMIT 5").fetchall()
            return {"total_no_leidas": count, "notificaciones": [dict(r) for r in rows]}
        return []
    elif method == "PUT":
        if id == 'all':
            db.execute("UPDATE notificaciones SET leida=1 WHERE leida=0")
        elif id:
            db.execute("UPDATE notificaciones SET leida=1 WHERE id=?", (id,))
        db.commit()
        return {"message": "updated"}

@router.get("/slots")
async def slots_endp(fecha: str, db: Any = Depends(get_db)):
    slots = []
    for h in range(8, 18):
        for m in (0, 30):
            start = f"{h:02d}:{m:02d}"
            end_m = m + 30
            end = f"{h + end_m//60:02d}:{end_m%60:02d}"
            slots.append({"hora_inicio": start, "hora_fin": end, "disponible": True})
            
    ocupados = {r['hora_inicio']: dict(r) for r in db.execute("SELECT hora_inicio, id, estado FROM citas WHERE fecha=? AND estado!='cancelada'", (fecha,)).fetchall()}
    for s in slots:
        if s['hora_inicio'] in ocupados:
            s['disponible'] = False
            s['estado'] = ocupados[s['hora_inicio']]['estado']
    return slots
