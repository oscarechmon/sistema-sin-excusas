import http from '@/lib/http';
import type { Paginated, TableQuery } from '@/types';

export type AppointmentStatus = 'pending' | 'confirmed' | 'attended' | 'cancelled' | 'postponed' | 'no_show';

export interface Appointment {
    id: number;
    appointment_date: string;
    start_time: string;
    end_time: string;
    title: string | null;
    status: AppointmentStatus;
    notes: string | null;
    /** Qué visita del cliente es (días distintos); null si la cita ya no ocupa el horario. */
    visit_number: number | null;
    customer_id: number;
    service_id: number;
    employee_id: number;
    customer: { id: number; code: string | null; name: string; phone: string | null; whatsapp: string | null } | null;
    service: { id: number; name: string } | null;
    employee: { id: number; name: string } | null;
}

export interface AppointmentPayload {
    customer_id: number | null;
    service_id: number | null;
    employee_id: number | null;
    appointment_date: string;
    start_time: string;
    end_time: string;
    title?: string | null;
    status?: AppointmentStatus;
    notes?: string | null;
}

export const APPOINTMENT_STATUS: Record<AppointmentStatus, { label: string; severity: string }> = {
    pending: { label: 'Pendiente', severity: 'warn' },
    confirmed: { label: 'Confirmada', severity: 'info' },
    attended: { label: 'Atendida', severity: 'success' },
    cancelled: { label: 'Cancelada', severity: 'danger' },
    postponed: { label: 'Pospuesta', severity: 'secondary' },
    no_show: { label: 'No se presentó', severity: 'danger' },
};

/** «Primera visita», «Visita N.º 4»… */
export function visitLabel(n: number | null | undefined): string {
    if (!n) return '';
    return n === 1 ? 'Primera visita' : `Visita N.º ${n}`;
}
export interface Attendance {
    id: number;
    attended_at: string;
    customer_id: number;
    service_id: number;
    employee_id: number;
    appointment_id: number | null;
    customer_package_id: number | null;
    session_number: number | null;
    observations: string | null;
    measurements: string | null;
    customer: { id: number; code: string | null; name: string } | null;
    service: string | null;
    employee: string | null;
    package?: { id: number; name: string; total_sessions: number } | null;
    supplies?: { product_id: number; name: string | null; quantity: number }[];
    commission?: { amount: number; status: string } | null;
}

export interface AttendancePayload {
    customer_id: number | null;
    service_id: number | null;
    employee_id: number | null;
    appointment_id?: number | null;
    customer_package_id?: number | null;
    attended_at: string;
    observations?: string | null;
    measurements?: string | null;
    supplies: { product_id: number; quantity: number }[];
}

export interface ServiceSupply {
    supply_id: number;
    name: string | null;
    default_quantity: number;
}

const unwrap = <T>(res: { data: { data: T } }): T => res.data.data;

export const appointmentsApi = {
    async list(params: TableQuery): Promise<Paginated<Appointment>> {
        return unwrap<Paginated<Appointment>>(await http.get('/appointments', { params }));
    },
    async get(id: number): Promise<Appointment> {
        return unwrap<Appointment>(await http.get(`/appointments/${id}`));
    },
    /** Día o rango completo (sin paginar). */
    async range(params: Record<string, unknown>): Promise<Appointment[]> {
        return unwrap<Appointment[]>(await http.get('/appointments', { params: { ...params, all: 1 } }));
    },
    async save(payload: AppointmentPayload, id?: number): Promise<Appointment> {
        const res = id ? await http.put(`/appointments/${id}`, payload) : await http.post('/appointments', payload);
        return unwrap<Appointment>(res);
    },
    /** Qué número de visita sería una cita nueva del cliente ese día. */
    async visitNumber(customerId: number, date: string): Promise<number> {
        return unwrap<{ visit_number: number }>(await http.get('/appointments/visit-number', { params: { customer_id: customerId, date } })).visit_number;
    },
    async status(id: number, status: AppointmentStatus): Promise<Appointment> {
        return unwrap<Appointment>(await http.post(`/appointments/${id}/status`, { status }));
    },
    async remove(id: number): Promise<void> {
        await http.delete(`/appointments/${id}`);
    },
};

export const attendancesApi = {
    async list(params: TableQuery): Promise<Paginated<Attendance>> {
        return unwrap<Paginated<Attendance>>(await http.get('/attendances', { params }));
    },
    async get(id: number): Promise<Attendance> {
        return unwrap<Attendance>(await http.get(`/attendances/${id}`));
    },
    async create(payload: AttendancePayload): Promise<Attendance> {
        return unwrap<Attendance>(await http.post('/attendances', payload));
    },
};

export const serviceSuppliesApi = {
    async get(serviceId: number): Promise<ServiceSupply[]> {
        return unwrap<ServiceSupply[]>(await http.get(`/services/${serviceId}/supplies`));
    },
    async save(serviceId: number, supplies: { supply_id: number; default_quantity: number }[]): Promise<ServiceSupply[]> {
        return unwrap<ServiceSupply[]>(await http.put(`/services/${serviceId}/supplies`, { supplies }));
    },
};

/** Fecha local YYYY-MM-DD (sin el corrimiento de zona horaria de toISOString). */
export function isoDate(d: Date): string {
    const pad = (n: number): string => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
