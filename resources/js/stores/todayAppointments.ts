import { defineStore } from 'pinia';
import { appointmentsApi, isoDate, type Appointment } from '@/services/agenda';

/** Cada cuánto se vuelve a pedir la agenda del día (la campana y los avisos). */
const POLL_MS = 60_000;

/** Minutos desde `now` hasta la hora `HH:MM` del día `YYYY-MM-DD` (negativo si ya pasó). */
export function minutesUntil(date: string, time: string, now: number): number {
    return Math.round((new Date(`${date}T${time}:00`).getTime() - now) / 60_000);
}

interface State {
    /** Día (hora local del navegador) al que corresponden las citas. */
    date: string;
    items: Appointment[];
    now: number;
    loaded: boolean;
}

let timer: number | undefined;
let onVisible: (() => void) | undefined;

/**
 * Citas de hoy para la campana, el resumen del día y los recordatorios.
 * La agenda llama a `refresh()` cuando cambia algo para no esperar al ciclo.
 */
export const useTodayAppointmentsStore = defineStore('todayAppointments', {
    state: (): State => ({ date: isoDate(new Date()), items: [], now: Date.now(), loaded: false }),

    getters: {
        /** Las que aún hay que atender (pendientes o confirmadas), por hora. */
        open: (state): Appointment[] => state.items.filter((a) => a.status === 'pending' || a.status === 'confirmed'),
        attendedCount: (state): number => state.items.filter((a) => a.status === 'attended').length,
        /** Por atender que todavía no terminan (próximas o en curso). */
        upcoming(): Appointment[] {
            return this.open.filter((a) => minutesUntil(a.appointment_date, a.end_time, this.now) > 0);
        },
        /** Ya pasó su hora de fin y nadie la marcó como atendida o no asistió. */
        overdue(): Appointment[] {
            return this.open.filter((a) => minutesUntil(a.appointment_date, a.end_time, this.now) <= 0);
        },
    },

    actions: {
        async refresh(): Promise<void> {
            const date = isoDate(new Date());
            try {
                this.items = await appointmentsApi.range({ date });
                this.date = date;
                this.loaded = true;
            } catch {
                // Sin conexión o sin permiso: se reintenta en el próximo ciclo.
            }
            this.now = Date.now();
        },

        start(): void {
            if (timer) return;
            this.refresh();
            timer = window.setInterval(() => this.refresh(), POLL_MS);
            onVisible = () => {
                if (document.visibilityState === 'visible') this.refresh();
            };
            document.addEventListener('visibilitychange', onVisible);
        },

        stop(): void {
            window.clearInterval(timer);
            timer = undefined;
            if (onVisible) document.removeEventListener('visibilitychange', onVisible);
            onVisible = undefined;
        },
    },
});
