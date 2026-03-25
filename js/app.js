/**
 * PawCare — Shared App Utilities
 * app.js
 */

// ── API helper ─────────────────────────────────────────────────
// ── API helper ─────────────────────────────────────────────────
const API_BASE_URL = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1' 
  ? '' 
  : 'https://veterinaria-api-nt7o.onrender.com/'; // <--- CAMBIAR POR TU URL DE RENDER

async function api(endpoint, method = 'GET', body = null) {
  const opts = { method, headers: { 'Content-Type': 'application/json' } };
  if (body) opts.body = JSON.stringify(body);
  const cleanEndpoint = endpoint.replace('.php', '');
  
  // Si API_BASE_URL está presente, lo usamos; si no, usamos la ruta relativa
  const url = API_BASE_URL ? `${API_BASE_URL}/api/${cleanEndpoint}` : `/api/${cleanEndpoint}`;
  
  const res = await fetch(url, opts);
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || `Error ${res.status}`);
  return data;
}

// ── Toast Notifications ────────────────────────────────────────
const TOAST_ICONS = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };

function showToast(message, type = 'success', duration = 4000) {
  const container = document.getElementById('toast-container');
  if (!container) return;
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `
    <span class="toast-icon">${TOAST_ICONS[type] || '🔔'}</span>
    <span class="toast-msg">${message}</span>
    <span class="toast-close" onclick="removeToast(this.parentElement)">✕</span>`;
  container.appendChild(toast);
  if (duration > 0) {
    setTimeout(() => removeToast(toast), duration);
  }
}

function removeToast(toast) {
  toast.classList.add('removing');
  setTimeout(() => toast.remove(), 300);
}

// ── Date / Time Helpers ────────────────────────────────────────
function formatDateDisplay(dateStr) {
  if (!dateStr) return '—';
  const [y, m, d] = dateStr.split('-');
  const months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
  return `${d} ${months[parseInt(m)-1]} ${y}`;
}

function formatDateInput(date = new Date()) {
  const y = date.getFullYear();
  const m = String(date.getMonth()+1).padStart(2,'0');
  const d = String(date.getDate()).padStart(2,'0');
  return `${y}-${m}-${d}`;
}

function formatTime(timeStr) {
  if (!timeStr) return '—';
  const [h, m] = timeStr.split(':');
  const hour = parseInt(h);
  const ampm = hour >= 12 ? 'PM' : 'AM';
  const displayHour = hour % 12 || 12;
  return `${displayHour}:${m} ${ampm}`;
}

function timeAgo(isoString) {
  const date = new Date(isoString);
  if (isNaN(date)) return '';
  const diff = Math.floor((Date.now() - date.getTime()) / 1000);
  if (diff < 60)  return 'Ahora mismo';
  if (diff < 3600) return `Hace ${Math.floor(diff/60)} min`;
  if (diff < 86400) return `Hace ${Math.floor(diff/3600)} h`;
  return `Hace ${Math.floor(diff/86400)} d`;
}

// ── Estado badge helpers ───────────────────────────────────────
const ESTADO_CONFIG = {
  pendiente:   { cls: 'badge-warning', label: 'Pendiente'   },
  confirmada:  { cls: 'badge-info',    label: 'Confirmada'  },
  completada:  { cls: 'badge-success', label: 'Completada'  },
  cancelada:   { cls: 'badge-danger',  label: 'Cancelada'   },
};

function estadoBadge(estado) {
  const cfg = ESTADO_CONFIG[estado] || { cls:'badge-muted', label: estado };
  return `<span class="badge ${cfg.cls}">${cfg.label}</span>`;
}

const TIPO_NOTIF_CONFIG = {
  info:    { cls: 'badge-info',    icon: 'ℹ️' },
  success: { cls: 'badge-success', icon: '✅' },
  warning: { cls: 'badge-warning', icon: '⚠️' },
  error:   { cls: 'badge-danger',  icon: '❌' },
};

// ── Sidebar active link ────────────────────────────────────────
function setActiveNav() {
  const page = location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-item').forEach(link => {
    const href = link.getAttribute('href') || '';
    if (href === page || (page === '' && href === 'index.html')) {
      link.classList.add('active');
    }
  });
}

// ── Modal helpers ──────────────────────────────────────────────
function openModal(id) {
  document.getElementById(id)?.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeModal(id) {
  document.getElementById(id)?.classList.remove('open');
  document.body.style.overflow = '';
}

// Close modal when clicking overlay background
document.addEventListener('click', (e) => {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
    document.body.style.overflow = '';
  }
});

// ── Topbar date ────────────────────────────────────────────────
function initTopbarDate() {
  const el = document.getElementById('topbar-date');
  if (!el) return;
  const now = new Date();
  const opts = { weekday:'long', year:'numeric', month:'long', day:'numeric' };
  el.textContent = now.toLocaleDateString('es-ES', opts);
}

// ── Init on load ───────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  setActiveNav();
  initTopbarDate();

  // Telegram Web Widget
  try {
    const tgData = await api('telegram_link.php');
    if (tgData.url) {
      const btn = document.createElement('a');
      btn.href = tgData.url;
      btn.target = '_blank';
      btn.className = 'telegram-float-btn';
      btn.innerHTML = `<span class="tg-icon">✈️</span> Agendar por Telegram`;
      document.body.appendChild(btn);
    }
  } catch (err) {
    console.log('Bot de Telegram no configurado o no disponible.');
  }
});

