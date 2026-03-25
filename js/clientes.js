/**
 * PawCare — Clientes Page Logic
 * js/clientes.js
 */

let clientes = [];
let editingId = null;

document.addEventListener('DOMContentLoaded', async () => {
  await loadClientes();
  bindEvents();
});

async function loadClientes(search = '') {
  const endpoint = search ? `clientes.php?search=${encodeURIComponent(search)}` : 'clientes.php';
  clientes = await api(endpoint);
  renderTable(clientes);
}

function renderTable(data) {
  const tbody = document.getElementById('clientes-tbody');
  if (!tbody) return;
  if (!data.length) {
    tbody.innerHTML = `<tr><td colspan="6">
      <div class="table-empty">
        <div class="empty-icon">👤</div>
        <p>No se encontraron clientes</p>
      </div></td></tr>`;
    return;
  }
  tbody.innerHTML = data.map(c => `
    <tr class="fade-in">
      <td>
        <div style="display:flex;align-items:center;gap:10px">
          <div class="avatar avatar-purple">${c.nombre[0]}${c.apellido[0]}</div>
          <div>
            <strong>${c.nombre} ${c.apellido}</strong>
            <div style="font-size:11px;color:var(--text-muted);margin-top:1px">#${c.id}</div>
          </div>
        </div>
      </td>
      <td>${c.telefono}</td>
      <td>${c.email ? `<a href="mailto:${c.email}" style="color:var(--accent)">${c.email}</a>` : '<span style="color:var(--text-muted)">—</span>'}</td>
      <td>${c.direccion || '<span style="color:var(--text-muted)">—</span>'}</td>
      <td><span style="font-size:11px;color:var(--text-muted)">${formatDateDisplay(c.created_at?.split(' ')[0])}</span></td>
      <td>
        <div style="display:flex;gap:6px">
          <button class="btn btn-sm btn-secondary btn-icon" title="Editar" onclick="editCliente(${c.id})">✏️</button>
          <button class="btn btn-sm btn-danger btn-icon" title="Eliminar" onclick="deleteCliente(${c.id},'${c.nombre} ${c.apellido}')">🗑️</button>
        </div>
      </td>
    </tr>`).join('');
}

async function saveCliente(e) {
  e.preventDefault();
  const payload = {
    nombre:    document.getElementById('f-nombre').value.trim(),
    apellido:  document.getElementById('f-apellido').value.trim(),
    telefono:  document.getElementById('f-telefono').value.trim(),
    email:     document.getElementById('f-email').value.trim() || null,
    direccion: document.getElementById('f-direccion').value.trim() || null,
  };

  if (!payload.nombre || !payload.apellido || !payload.telefono) {
    return showToast('Nombre, apellido y teléfono son requeridos', 'warning');
  }

  try {
    if (editingId) {
      await api(`clientes.php?id=${editingId}`, 'PUT', payload);
      showToast('Cliente actualizado', 'success');
    } else {
      await api('clientes.php', 'POST', payload);
      showToast('Cliente registrado exitosamente', 'success');
      notifManager.refresh();
    }
    closeModal('cliente-modal');
    e.target.reset();
    editingId = null;
    await loadClientes();
  } catch (err) {
    showToast(err.message, 'error');
  }
}

async function editCliente(id) {
  const c = clientes.find(x => x.id === id);
  if (!c) return;
  editingId = id;
  document.getElementById('modal-title').textContent = 'Editar Cliente';
  document.getElementById('f-nombre').value    = c.nombre;
  document.getElementById('f-apellido').value  = c.apellido;
  document.getElementById('f-telefono').value  = c.telefono;
  document.getElementById('f-email').value     = c.email || '';
  document.getElementById('f-direccion').value = c.direccion || '';
  openModal('cliente-modal');
}

async function deleteCliente(id, nombre) {
  if (!confirm(`¿Eliminar al cliente "${nombre}"? Se eliminarán también sus mascotas.`)) return;
  try {
    await api(`clientes.php?id=${id}`, 'DELETE');
    showToast(`Cliente "${nombre}" eliminado`, 'warning');
    await loadClientes();
  } catch (err) {
    showToast(err.message, 'error');
  }
}

function bindEvents() {
  document.getElementById('cliente-form')?.addEventListener('submit', saveCliente);

  document.getElementById('btn-nuevo-cliente')?.addEventListener('click', () => {
    editingId = null;
    document.getElementById('modal-title').textContent = 'Nuevo Cliente';
    document.getElementById('cliente-form').reset();
    openModal('cliente-modal');
  });

  let searchTimer;
  document.getElementById('search-input')?.addEventListener('input', e => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadClientes(e.target.value), 350);
  });
}
