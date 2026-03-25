/**
 * PawCare — Notification Manager
 * Compiled from ts/notifications.ts
 * (Pre-compiled for environments without tsc)
 */
class NotificationManager {
  constructor() {
    this.pollInterval    = 60_000;
    this.reminderOffset  = 15;
    this.timerId         = null;
    this.knownIds        = new Set();
    this.browserGranted  = false;
    this.panelEl  = document.getElementById('notif-panel');
    this.badgeEl  = document.getElementById('notif-badge');
    this.dotEl    = document.getElementById('notif-dot');
  }

  async init() {
    await this.requestBrowserPermission();
    this.bindPanelToggle();
    await this.refresh();
    this.timerId = setInterval(() => this.refresh(), this.pollInterval);
  }

  async requestBrowserPermission() {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'granted') {
      this.browserGranted = true;
    } else if (Notification.permission !== 'denied') {
      const result = await Notification.requestPermission();
      this.browserGranted = (result === 'granted');
    }
  }

  async refresh() {
    try {
      const data = await api('notificaciones.php?no_leidas=1');
      this.updateBadge(data.total_no_leidas);
      this.checkNewNotifications(data.notificaciones);
      await this.checkUpcomingAppointments();
      this.renderPanel(data.notificaciones);
    } catch (err) {
      console.warn('[NotifManager] Refresh error:', err);
    }
  }

  checkNewNotifications(notifs) {
    const isFirstLoad = this.knownIds.size === 0;
    notifs.forEach(n => {
      if (!this.knownIds.has(n.id)) {
        this.knownIds.add(n.id);
        if (!isFirstLoad) {
          this.fireBrowserNotification(n.titulo, n.mensaje);
        }
      }
    });
  }

  async checkUpcomingAppointments() {
    try {
      const today = formatDateInput(new Date());
      const data = await api(`citas.php?fecha=${today}&hoy=1`);
      const now = new Date();
      data.forEach(cita => {
        const [h, m] = cita.hora_inicio.split(':').map(Number);
        const apptTime = new Date();
        apptTime.setHours(h, m, 0, 0);
        const diffMin = (apptTime.getTime() - now.getTime()) / 60_000;
        const remKey = `appt-remind-${cita.id}`;
        if (diffMin > 0 && diffMin <= this.reminderOffset && !sessionStorage.getItem(remKey)) {
          sessionStorage.setItem(remKey, '1');
          this.fireBrowserNotification(
            `🐾 Cita próxima en ${Math.round(diffMin)} min`,
            `${cita.mascota_nombre}: ${cita.motivo || 'Consulta'} a las ${formatTime(cita.hora_inicio)}`
          );
          showToast(
            `Cita en ${Math.round(diffMin)} min: ${cita.mascota_nombre}`,
            'warning',
            8000
          );
        }
      });
    } catch { /* ignore */ }
  }

  fireBrowserNotification(title, body) {
    if (!this.browserGranted) return;
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'granted') return;
    try {
      new Notification(title, { body, icon: '/favicon.ico' });
    } catch { /* ignore */ }
  }

  updateBadge(count) {
    if (this.badgeEl) {
      if (count > 0) {
        this.badgeEl.textContent = count > 99 ? '99+' : String(count);
        this.badgeEl.style.display = 'inline-flex';
      } else {
        this.badgeEl.style.display = 'none';
      }
    }
    if (this.dotEl) {
      this.dotEl.classList.toggle('show', count > 0);
    }
  }

  renderPanel(notifs) {
    const list = document.getElementById('notif-list');
    if (!list) return;
    if (!notifs || notifs.length === 0) {
      list.innerHTML = `
        <div class="notif-empty">
          <div class="notif-empty-icon">🔔</div>
          <p>No hay notificaciones nuevas</p>
        </div>`;
      return;
    }
    list.innerHTML = notifs.map(n => `
      <div class="notif-item ${n.leida ? '' : 'unread'}" data-id="${n.id}"
           onclick="notifManager.markRead(${n.id}, this)">
        <div class="notif-dot-item"></div>
        <div class="notif-item-content">
          <div class="notif-item-title">${n.titulo}</div>
          <div class="notif-item-msg">${n.mensaje}</div>
          <div class="notif-item-time">${timeAgo(n.created_at)}</div>
        </div>
      </div>`).join('');
  }

  async markRead(id, el) {
    try {
      await api(`notificaciones.php?id=${id}`, 'PUT');
      el?.classList.remove('unread');
      await this.refresh();
    } catch { /* ignore */ }
  }

  async markAllRead() {
    try {
      await api('notificaciones.php?id=all', 'PUT');
      await this.refresh();
      showToast('Todas las notificaciones marcadas como leídas', 'success');
    } catch { /* ignore */ }
  }

  bindPanelToggle() {
    const btn      = document.getElementById('notif-toggle-btn');
    const closeBtn = document.getElementById('notif-close-btn');
    btn?.addEventListener('click', () => this.panelEl?.classList.toggle('open'));
    closeBtn?.addEventListener('click', () => this.panelEl?.classList.remove('open'));
  }

  destroy() {
    if (this.timerId !== null) clearInterval(this.timerId);
  }
}

// Instantiate global manager
const notifManager = new NotificationManager();
document.addEventListener('DOMContentLoaded', () => notifManager.init());
