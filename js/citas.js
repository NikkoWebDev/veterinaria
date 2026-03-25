/**
 * PawCare — Citas Page Logic
 * js/citas.js
 */

// -- State -------------------------------------------------------
let selectedDate  = formatDateInput(new Date());
let selectedSlot  = null;
let clientes      = [];
let mascotas      = [];
let allCitas      = [];
let editingId     = null;
let weekOffset    = 0;

// -- Init --------------------------------------------------------
document.addEventListener('DOMContentLoaded', async () => {
  await loadClientes();
  renderWeek();
  await loadSlots(selectedDate);
  await loadCitas();
  bindEvents();
});

// -- Load data ---------------------------------------------------
async function loadClientes() {
  clientes = await api('clientes.php');
  const sel = document.getElementById('f-cliente');
  if (!sel) return;
  sel.innerHTML = '<option value="">Selecciona un cliente...</option>' +
    clientes.map(c => `<option value="${c.id}">${c.nombre} ${c.apellido}</option>`).join('');
}

async function loadMascotasByCliente(clienteId) {
  if (!clienteId) {
    mascotas = [];
    const sel = document.getElementById('f-mascota');
    if (sel) sel.innerHTML = '<option value="">Primero selecciona un cliente</option>';
    return;
  }
  mascotas = await api(`mascotas.php?cliente_id=${clienteId}`);
  const sel = document.getElementById('f-mascota');
  if (!sel) return;
  sel.innerHTML = mascotas.length
    ? '<option value="">Selecciona una mascota...</option>' +
      mascotas.map(m => `<option value="${m.id}">${m.nombre} (${m.especie})</option>`).join('')
    : '<option value="">Sin mascotas registradas</option>';
}

async function loadSlots(fecha) {
  const grid = document.getElementById('slots-grid');
  if (!grid) return;
  grid.innerHTML = '<div style="grid-column:1/-1;color:var(--text-muted);font-size:13px;">Cargando horarios...</div>';
  selectedSlot = null;
  updateSlotInput();
  try {
    const data = await api(`slots.php?fecha=${fecha}`);
    renderSlots(data.slots, grid);
    const info = document.getElementById('slots-info');
    if (info) info.textContent = `${data.libres} de ${data.total} horarios disponibles`;
  } catch {
    grid.innerHTML = '<div class="alert alert-error">Error cargando horarios</div>';
  }
}

async function loadCitas(filters = {}) {
  const params = new URLSearchParams(filters).toString();
  allCitas = await api(`citas.php${params ? '?' + params : ''}`);
  renderCitasTable(allCitas);
}

// -- Render Slots ------------------------------------------------
function renderSlots(slots, container) {
  if (!slots.length) {
    container.innerHTML = '<p style="color:var(--text-muted)">No hay horarios disponibles</p>';
    return;
  }
  container.innerHTML = slots.map(s => `
    <button type="button"
      class="slot-btn ${s.disponible ? 'available' : 'occupied'}"
      ${!s.disponible ? 'disabled' : ''}
      data-start="${s.hora_inicio}" data-end="${s.hora_fin}"
      onclick="${s.disponible ? `selectSlot('${s.hora_inicio}','${s.hora_fin}',this)` : ''}">
      <span class="slot-time">${formatTime(s.hora_inicio)}</span>
      <span class="slot-status">${s.disponible ? '✓ Disponible' : '✗ Ocupado'}</span>
    </button>`).join('');
}

function selectSlot(start, end, el) {
  document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
  el.classList.add('selected');
  selectedSlot = { start, end };
  updateSlotInput();
}

function updateSlotInput() {
  const el = document.getElementById('f-slot-display');
  if (el) {
    el.textContent = selectedSlot
      ? `${formatTime(selectedSlot.start)} – ${formatTime(selectedSlot.end)}`
      : 'Ninguno seleccionado';
    el.style.color = selectedSlot ? 'var(--accent)' : 'var(--text-muted)';
  }
}

// -- Week navigator ----------------------------------------------
function renderWeek() {
  const today = new Date();
  today.setDate(today.getDate() + weekOffset * 7);
  const startOfWeek = new Date(today);
  startOfWeek.setDate(today.getDate() - today.getDay() + 1); // Monday

  const container = document.getElementById('week-days');
  if (!container) return;

  const dayNames = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
  const todayStr = formatDateInput(new Date());

  container.innerHTML = '';
  for (let i = 0; i < 7; i++) {
    const d = new Date(startOfWeek);
    d.setDate(startOfWeek.getDate() + i);
    const dateStr = formatDateInput(d);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `week-day${dateStr === selectedDate ? ' selected' : ''}${dateStr === todayStr ? ' today' : ''}`;
    btn.innerHTML = `<div class="day-name">${dayNames[i]}</div><div class="day-num">${d.getDate()}</div>`;
    btn.addEventListener('click', () => {
      selectedDate = dateStr;
      document.getElementById('f-fecha').value = dateStr;
      renderWeek();
      loadSlots(dateStr);
    });
    container.appendChild(btn);
  }

  const label = document.getElementById('week-label');
  if (label) {
    const end = new Date(startOfWeek);
    end.setDate(startOfWeek.getDate() + 6);
    label.textContent = `${formatDateDisplay(formatDateInput(startOfWeek))} – ${formatDateDisplay(formatDateInput(end))}`;
  }
}

// -- Render table ------------------------------------------------
function renderCitasTable(citas) {
  const tbody = document.getElementById('citas-tbody');
  if (!tbody) return;
  if (!citas.length) {
    tbody.innerHTML = `<tr><td colspan="8">
      <div class="table-empty">
        <div class="empty-icon">📅</div>
        <p>No hay citas registradas</p>
      </div></td></tr>`;
    return;
  }
  tbody.innerHTML = citas.map(c => `
    <tr class="fade-in">
      <td><strong>${formatDateDisplay(c.fecha)}</strong></td>
      <td><strong>${formatTime(c.hora_inicio)}</strong><br>
          <small style="color:var(--text-muted)">${formatTime(c.hora_fin)}</small></td>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <div class="avatar avatar-accent">${c.mascota_nombre?.[0] ?? '?'}</div>
          <div><strong>${c.mascota_nombre}</strong><br>
          <small style="color:var(--text-muted)">${c.especie}</small></div>
        </div>
      </td>
      <td>${c.cliente_nombre} ${c.cliente_apellido}</td>
      <td>${c.motivo || '<span style="color:var(--text-muted)">—</span>'}</td>
      <td>${c.veterinario || '—'}</td>
      <td>${estadoBadge(c.estado)}</td>
      <td>
        <div style="display:flex;gap:6px">
          <button class="btn btn-sm btn-secondary btn-icon" title="Editar" onclick="editCita(${c.id})">✏️</button>
          <button class="btn btn-sm btn-danger btn-icon" title="Cancelar" onclick="cancelarCita(${c.id})">✕</button>
        </div>
      </td>
    </tr>`).join('');
}

// -- Form submit -------------------------------------------------
async function saveCita(e) {
  e.preventDefault();
  const form = e.target;

  const clienteId  = document.getElementById('f-cliente').value;
  const mascotaId  = document.getElementById('f-mascota').value;
  const fecha      = document.getElementById('f-fecha').value;
  const motivo     = document.getElementById('f-motivo').value;
  const veterinario = document.getElementById('f-veterinario').value;
  const notas      = document.getElementById('f-notas').value;

  if (!clienteId)        return showToast('Selecciona un cliente', 'warning');
  if (!mascotaId)        return showToast('Selecciona una mascota', 'warning');
  if (!selectedSlot && !editingId)
                         return showToast('Selecciona un horario', 'warning');
  if (!fecha)            return showToast('Selecciona una fecha', 'warning');

  const payload = {
    mascota_id: mascotaId, cliente_id: clienteId,
    fecha, hora_inicio: selectedSlot?.start,
    motivo, veterinario, notas,
  };

  try {
    if (editingId) {
      await api(`citas.php?id=${editingId}`, 'PUT', payload);
      showToast('Cita actualizada correctamente', 'success');
    } else {
      await api('citas.php', 'POST', payload);
      showToast('Cita agendada correctamente 🐾', 'success');
    }
    closeModal('cita-modal');
    form.reset();
    selectedSlot = null; editingId = null;
    await loadCitas();
    await loadSlots(selectedDate);
    notifManager.refresh();
  } catch (err) {
    showToast(err.message, 'error');
  }
}

async function editCita(id) {
  try {
    const c = await api(`citas.php?id=${id}`);
    editingId = id;
    document.getElementById('modal-title').textContent = 'Editar Cita';
    document.getElementById('f-cliente').value    = c.cliente_id;
    await loadMascotasByCliente(c.cliente_id);
    document.getElementById('f-mascota').value    = c.mascota_id;
    document.getElementById('f-fecha').value      = c.fecha;
    document.getElementById('f-motivo').value     = c.motivo || '';
    document.getElementById('f-veterinario').value= c.veterinario || '';
    document.getElementById('f-notas').value      = c.notas || '';
    selectedDate = c.fecha;
    renderWeek();
    await loadSlots(c.fecha);
    setTimeout(() => {
      const slot = document.querySelector(`.slot-btn[data-start="${c.hora_inicio}"]`);
      if (slot) { slot.disabled = false; selectSlot(c.hora_inicio, c.hora_fin, slot); }
    }, 100);
    openModal('cita-modal');
  } catch (err) {
    showToast(err.message, 'error');
  }
}

async function cancelarCita(id) {
  if (!confirm('¿Seguro que deseas cancelar esta cita?')) return;
  try {
    await api(`citas.php?id=${id}`, 'PUT', { estado: 'cancelada' });
    showToast('Cita cancelada', 'warning');
    await loadCitas();
    await loadSlots(selectedDate);
    notifManager.refresh();
  } catch (err) {
    showToast(err.message, 'error');
  }
}

// -- Filter -------------------------------------------------------
async function filterCitas() {
  const fecha  = document.getElementById('filter-fecha').value;
  const estado = document.getElementById('filter-estado').value;
  const filters = {};
  if (fecha)  filters.fecha  = fecha;
  if (estado) filters.estado = estado;
  await loadCitas(filters);
}

// -- Bind events -------------------------------------------------
function bindEvents() {
  document.getElementById('cita-form')?.addEventListener('submit', saveCita);

  document.getElementById('f-cliente')?.addEventListener('change', e => {
    loadMascotasByCliente(e.target.value);
  });

  document.getElementById('btn-nueva-cita')?.addEventListener('click', () => {
    editingId = null;
    document.getElementById('modal-title').textContent = 'Nueva Cita';
    document.getElementById('cita-form').reset();
    selectedSlot = null; updateSlotInput();
    openModal('cita-modal');
  });

  document.getElementById('btn-prev-week')?.addEventListener('click', () => { weekOffset--; renderWeek(); });
  document.getElementById('btn-next-week')?.addEventListener('click', () => { weekOffset++; renderWeek(); });
  document.getElementById('btn-hoy')?.addEventListener('click', () => { weekOffset=0; selectedDate=formatDateInput(new Date()); renderWeek(); loadSlots(selectedDate); });

  document.getElementById('btn-filter')?.addEventListener('click', filterCitas);
  document.getElementById('btn-reset-filter')?.addEventListener('click', () => {
    document.getElementById('filter-fecha').value  = '';
    document.getElementById('filter-estado').value = '';
    loadCitas();
  });
}
