/**
 * PawCare — Notification Manager
 * TypeScript source file
 * Compiled output: js/notifications.js
 */

interface Notification {
  id: number;
  titulo: string;
  mensaje: string;
  tipo: 'info' | 'warning' | 'success' | 'error';
  leida: number;
  cita_id: number | null;
  created_at: string;
}

interface AppointmentCheck {
  id: number;
  fecha: string;
  hora_inicio: string;
  motivo: string;
  mascota_nombre: string;
}

interface UnreadResponse {
  total_no_leidas: number;
  notificaciones: Notification[];
}

class NotificationManager {
  private pollInterval: number = 60_000;   // 60 s polling
  private reminderOffset: number = 15;     // minutes before appointment
  private timerId: ReturnType<typeof setInterval> | null = null;
  private knownIds: Set<number> = new Set();
  private browserGranted: boolean = false;
  private panelEl: HTMLElement | null = null;
  private badgeEl: HTMLElement | null = null;
  private dotEl: HTMLElement | null    = null;

  constructor() {
    this.panelEl  = document.getElementById('notif-panel');
    this.badgeEl  = document.getElementById('notif-badge');
    this.dotEl    = document.getElementById('notif-dot');
  }

  async init(): Promise<void> {
    await this.requestBrowserPermission();
    this.bindPanelToggle();
    await this.refresh();
    this.timerId = setInterval(() => this.refresh(), this.pollInterval);
  }

  private async requestBrowserPermission(): Promise<void> {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'granted') {
      this.browserGranted = true;
    } else if (Notification.permission !== 'denied') {
      const result = await Notification.requestPermission();
      this.browserGranted = result === 'granted';
    }
  }

  async refresh(): Promise<void> {
    try {
      const data: UnreadResponse = await (window as any).api(
        'notificaciones.php?no_leidas=1'
      );
      this.updateBadge(data.total_no_leidas);
      this.checkNewNotifications(data.notificaciones);
      await this.checkUpcomingAppointments();
      this.renderPanel(data.notificaciones);
    } catch (err) {
      console.warn('[NotifManager] Refresh error:', err);
    }
  }

  private checkNewNotifications(notifs: Notification[]): void {
    notifs.forEach(n => {
      if (!this.knownIds.has(n.id)) {
        this.knownIds.add(n.id);
        if (this.knownIds.size > 1) {
          // Only fire for truly new items (not first load)
          this.fireBrowserNotification(n.titulo, n.mensaje);
        }
      }
    });
  }

  private async checkUpcomingAppointments(): Promise<void> {
    try {
      const today = (window as any).formatDateInput(new Date());
      const data = await (window as any).api(
        `citas.php?fecha=${today}&hoy=1`
      );
      const now = new Date();
      (data as AppointmentCheck[]).forEach((cita: AppointmentCheck) => {
        const [h, m] = cita.hora_inicio.split(':').map(Number);
        const apptTime = new Date();
        apptTime.setHours(h, m, 0, 0);
        const diffMin = (apptTime.getTime() - now.getTime()) / 60_000;
        if (diffMin > 0 && diffMin <= this.reminderOffset) {
          const key = `reminder-${cita.id}`;
          if (!this.knownIds.has(Number(key.replace('reminder-', '99000')))) {
            this.fireBrowserNotification(
              `🐾 Cita en ${Math.round(diffMin)} min`,
              `${cita.mascota_nombre} — ${cita.motivo || 'Consulta'} a las ${(window as any).formatTime(cita.hora_inicio)}`
            );
          }
        }
      });
    } catch { /* ignore */ }
  }

  private fireBrowserNotification(title: string, body: string): void {
    if (!this.browserGranted || !('Notification' in window)) return;
    if (Notification.permission !== 'granted') return;
    new Notification(title, {
      body,
      icon: '/favicon.ico',
      badge: '/favicon.ico',
    });
  }

  private updateBadge(count: number): void {
    if (this.badgeEl) {
      this.badgeEl.textContent = count > 0 ? String(count > 99 ? '99+' : count) : '';
      this.badgeEl.style.display = count > 0 ? 'inline-flex' : 'none';
    }
    if (this.dotEl) {
      this.dotEl.classList.toggle('show', count > 0);
    }
  }

  private renderPanel(notifs: Notification[]): void {
    const list = document.getElementById('notif-list');
    if (!list) return;
    if (notifs.length === 0) {
      list.innerHTML = `
        <div class="notif-empty">
          <div class="notif-empty-icon">🔔</div>
          <p>No hay notificaciones nuevas</p>
        </div>`;
      return;
    }
    list.innerHTML = notifs.map(n => `
      <div class="notif-item ${n.leida ? '' : 'unread'}" data-id="${n.id}" onclick="notifManager.markRead(${n.id}, this)">
        <div class="notif-dot-item"></div>
        <div class="notif-item-content">
          <div class="notif-item-title">${n.titulo}</div>
          <div class="notif-item-msg">${n.mensaje}</div>
          <div class="notif-item-time">${(window as any).timeAgo(n.created_at)}</div>
        </div>
      </div>`).join('');
  }

  async markRead(id: number, el?: HTMLElement): Promise<void> {
    try {
      await (window as any).api(`notificaciones.php?id=${id}`, 'PUT');
      el?.classList.remove('unread');
      await this.refresh();
    } catch { /* ignore */ }
  }

  async markAllRead(): Promise<void> {
    try {
      await (window as any).api('notificaciones.php?id=all', 'PUT');
      await this.refresh();
      (window as any).showToast('Todas las notificaciones marcadas como leídas', 'success');
    } catch { /* ignore */ }
  }

  private bindPanelToggle(): void {
    const btn = document.getElementById('notif-toggle-btn');
    const closeBtn = document.getElementById('notif-close-btn');
    btn?.addEventListener('click', () => this.panelEl?.classList.toggle('open'));
    closeBtn?.addEventListener('click', () => this.panelEl?.classList.remove('open'));
  }

  destroy(): void {
    if (this.timerId !== null) clearInterval(this.timerId);
  }
}

declare const notifManager: NotificationManager;
