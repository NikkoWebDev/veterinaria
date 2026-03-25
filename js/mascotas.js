/**
 * PawCare — Mascotas Page Logic
 * js/mascotas.js
 */

let mascotas = [];
let clientes = [];
let editingId = null;

document.addEventListener('DOMContentLoaded', async () => {
  await loadClientes();
  await loadMascotas();
  bindEvents();
});

async function loadClientes() {
  clientes = await api('clientes.php');
  const sel = document.getElementById('f-cliente');
  if (!sel) return;
  sel.innerHTML = '<option value="">Selecciona un cliente...</option>' +
    clientes.map(c => `<option value="${c.id}">${c.nombre} ${c.apellido}</option>`).join('');

  // Filter dropdown
  const filterSel = document.getElementById('filter-cliente');
  if (filterSel) {
    filterSel.innerHTML = '<option value="">Todos los clientes</option>' +
      clientes.map(c => `<option value="${c.id}">${c.nombre} ${c.apellido}</option>`).join('');
  }
}

async function loadMascotas(clienteId = '') {
  const endpoint = clienteId
    ? `mascotas.php?cliente_id=${clienteId}`
    : 'mascotas.php';
  mascotas = await api(endpoint);
  renderTable(mascotas);
}

const ESPECIE_ICON = { Perro:'🐶', Gato:'🐱', Conejo:'🐰', Ave:'🐦', Reptil:'🦎', Pez:'🐟' };

function renderTable(data) {
  const tbody = document.getElementById('mascotas-tbody');
  if (!tbody) return;
  if (!data.length) {
    tbody.innerHTML = `<tr><td colspan="7">
      <div class="table-empty">
        <div class="empty-icon">🐾</div>
        <p>No se encontraron mascotas</p>
      </div></td></tr>`;
    return;
  }
  tbody.innerHTML = data.map(m => {
    const icon = ESPECIE_ICON[m.especie] ?? '🐾';
    const age  = m.fecha_nacimiento ? calcAge(m.fecha_nacimiento) : null;
    return `
    <tr class="fade-in">
      <td>
        <div style="display:flex;align-items:center;gap:10px">
          <div class="avatar avatar-orange" style="font-size:20px">${icon}</div>
          <div>
            <strong>${m.nombre}</strong>
            <div style="font-size:11px;color:var(--text-muted)">${m.raza || m.especie}</div>
          </div>
        </div>
      </td>
      <td><span class="badge badge-accent">${m.especie}</span></td>
      <td>${m.color || '<span style="color:var(--text-muted)">—</span>'}</td>
      <td>${age ? `<strong>${age}</strong><br><small style="color:var(--text-muted)">${formatDateDisplay(m.fecha_nacimiento)}</small>` : '<span style="color:var(--text-muted)">—</span>'}</td>
      <td>${m.peso ? `<strong>${m.peso} kg</strong>` : '<span style="color:var(--text-muted)">—</span>'}</td>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <div class="avatar avatar-purple" style="width:28px;height:28px;font-size:11px">${m.cliente_nombre?.[0]}${m.cliente_apellido?.[0]}</div>
          <span>${m.cliente_nombre} ${m.cliente_apellido}</span>
        </div>
      </td>
      <td>
        <div style="display:flex;gap:6px">
          <button class="btn btn-sm btn-secondary btn-icon" title="Editar" onclick="editMascota(${m.id})">✏️</button>
          <button class="btn btn-sm btn-danger btn-icon" title="Eliminar" onclick="deleteMascota(${m.id},'${m.nombre}')">🗑️</button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

function calcAge(dateStr) {
  const dob = new Date(dateStr);
  const diff = Date.now() - dob.getTime();
  const ageDate = new Date(diff);
  const years = Math.abs(ageDate.getUTCFullYear() - 1970);
  const months = ageDate.getUTCMonth();
  if (years === 0) return `${months} meses`;
  return `${years} año${years !== 1 ? 's' : ''}`;
}

async function saveMascota(e) {
  e.preventDefault();
  const payload = {
    nombre:          document.getElementById('f-nombre').value.trim(),
    especie:         document.getElementById('f-especie').value,
    raza:            document.getElementById('f-raza').value.trim() || null,
    fecha_nacimiento:document.getElementById('f-nacimiento').value || null,
    peso:            parseFloat(document.getElementById('f-peso').value) || null,
    color:           document.getElementById('f-color').value.trim() || null,
    cliente_id:      document.getElementById('f-cliente').value,
  };

  if (!payload.nombre || !payload.especie || !payload.cliente_id) {
    return showToast('Nombre, especie y cliente son requeridos', 'warning');
  }

  try {
    if (editingId) {
      await api(`mascotas.php?id=${editingId}`, 'PUT', payload);
      showToast('Mascota actualizada', 'success');
    } else {
      await api('mascotas.php', 'POST', payload);
      showToast('Mascota registrada exitosamente 🐾', 'success');
      notifManager.refresh();
    }
    closeModal('mascota-modal');
    e.target.reset();
    editingId = null;
    await loadMascotas(document.getElementById('filter-cliente')?.value || '');
  } catch (err) {
    showToast(err.message, 'error');
  }
}

async function editMascota(id) {
  const m = mascotas.find(x => x.id === id);
  if (!m) return;
  editingId = id;
  document.getElementById('modal-title').textContent = 'Editar Mascota';
  document.getElementById('f-nombre').value       = m.nombre;
  document.getElementById('f-especie').value      = m.especie;
  document.getElementById('f-raza').value         = m.raza || '';
  document.getElementById('f-nacimiento').value   = m.fecha_nacimiento || '';
  document.getElementById('f-peso').value         = m.peso || '';
  document.getElementById('f-color').value        = m.color || '';
  document.getElementById('f-cliente').value      = m.cliente_id;
  openModal('mascota-modal');
}

async function deleteMascota(id, nombre) {
  if (!confirm(`¿Eliminar a "${nombre}"?`)) return;
  try {
    await api(`mascotas.php?id=${id}`, 'DELETE');
    showToast(`"${nombre}" eliminada`, 'warning');
    await loadMascotas();
  } catch (err) {
    showToast(err.message, 'error');
  }
}

function bindEvents() {
  document.getElementById('mascota-form')?.addEventListener('submit', saveMascota);

  document.getElementById('btn-nueva-mascota')?.addEventListener('click', () => {
    editingId = null;
    document.getElementById('modal-title').textContent = 'Nueva Mascota';
    document.getElementById('mascota-form').reset();
    openModal('mascota-modal');
  });

  document.getElementById('filter-cliente')?.addEventListener('change', e => {
    loadMascotas(e.target.value);
  });
}
