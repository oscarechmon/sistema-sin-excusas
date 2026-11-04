<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import { AxiosError } from 'axios';
import { appointmentsApi, APPOINTMENT_STATUS, isoDate, visitLabel, type Appointment, type AppointmentStatus } from '@/services/agenda';
import { employeesApi, type Employee } from '@/services/staff';
import { customersApi, type Customer } from '@/services/contacts';
import { productsApi, type Product } from '@/services/catalog';
import { useAuthStore } from '@/stores/auth';
import { useTodayAppointmentsStore } from '@/stores/todayAppointments';

const toast = useToast();
const confirm = useConfirm();
const router = useRouter();
const auth = useAuthStore();
const today = useTodayAppointmentsStore();

const day = ref(new Date());
const employeeId = ref<number | null>(null);
const rows = ref<Appointment[]>([]);
const loading = ref(false);

const employees = ref<Employee[]>([]);
const customers = ref<Customer[]>([]);
const services = ref<Product[]>([]);

const title = computed(() => day.value.toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
const isToday = computed(() => isoDate(day.value) === isoDate(new Date()));
const active = computed(() => rows.value.filter((a) => !['cancelled', 'postponed', 'no_show'].includes(a.status)).length);

async function load(): Promise<void> {
    loading.value = true;
    try {
        rows.value = await appointmentsApi.range({ date: isoDate(day.value), employee_id: employeeId.value ?? undefined });
    } finally {
        loading.value = false;
    }
    // La campana se pone al día sin esperar a su ciclo.
    today.refresh();
}
function shift(days: number): void {
    const d = new Date(day.value);
    d.setDate(d.getDate() + days);
    day.value = d;
}
watch([day, employeeId], load);

// --- Alta / edición ------------------------------------------------------
// Al crear, la visita puede llevar varios servicios, cada uno con su
// especialista y su horario: se agenda una cita por cada uno (el mismo día
// cuentan como una sola visita). Al editar se trabaja sobre una sola cita.
interface AppointmentLine {
    key: number;
    service_id: number | null;
    employee_id: number | null;
    start_time: string;
    end_time: string;
    /** Duración en minutos: se conserva al mover el inicio. */
    minutes: number;
    employees: Employee[];
}

const toMinutes = (t: string): number => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
const fromMinutes = (n: number): string => `${String(Math.floor(n / 60) % 24).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`;

const dialog = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});
const editingId = ref<number | null>(null);
/** Cita ya atendida: su horario, servicio y especialista quedaron en la atención; solo se editan título y notas. */
const editingAttended = ref<Appointment | null>(null);
const form = ref({
    customer_id: null as number | null,
    title: '',
    notes: '',
    status: undefined as AppointmentStatus | undefined,
    date: new Date(),
    lines: [] as AppointmentLine[],
});

let lineKey = 0;
const newLine = (start: string, employee: number | null = null): AppointmentLine => ({
    key: ++lineKey, service_id: null, employee_id: employee, start_time: start, end_time: fromMinutes(toMinutes(start) + 60), minutes: 60, employees: [],
});

/** Error de un campo de la línea: al crear llega por servicio (services.N.campo); al editar, directo. */
const lineError = (i: number, field: string): string | undefined =>
    (editingId.value ? errors.value[field] : errors.value[`services.${i}.${field}`])?.[0];

/** Qué visita del cliente será (al crear) o es (al editar). */
const visitNumber = ref<number | null>(null);
watch(
    () => [dialog.value, form.value.customer_id, form.value.date ? isoDate(form.value.date) : ''] as const,
    async ([open, customerId, date]) => {
        if (!open || editingId.value) return;
        visitNumber.value = null;
        if (!customerId || !date) return;
        try {
            const n = await appointmentsApi.visitNumber(customerId, date);
            // Si mientras tanto cambió el cliente o la fecha, esta respuesta ya no vale.
            if (customerId === form.value.customer_id && form.value.date && date === isoDate(form.value.date)) visitNumber.value = n;
        } catch {
            // Es solo informativo: sin el dato, la cita se agenda igual.
        }
    },
);

async function loadOptions(): Promise<void> {
    if (!customers.value.length) customers.value = await customersApi.options();
    if (!services.value.length) services.value = (await productsApi.list({ type: 'service', is_active: 1, per_page: 100, sort_by: 'name', sort_dir: 'asc' })).data;
}
/** Especialistas que atienden el servicio de la línea (todos, si aún no hay servicio). */
async function refreshLineEmployees(line: AppointmentLine): Promise<void> {
    line.employees = await employeesApi.options(line.service_id);
    if (line.employee_id && !line.employees.some((e) => e.id === line.employee_id)) line.employee_id = null;
}
/** Al elegir el servicio, la cita dura lo que dura el servicio (si lo tiene configurado). */
async function onServiceChange(line: AppointmentLine): Promise<void> {
    const minutes = services.value.find((s) => s.id === line.service_id)?.duration_minutes;
    if (minutes) {
        line.minutes = minutes;
        line.end_time = fromMinutes(toMinutes(line.start_time) + minutes);
    }
    await refreshLineEmployees(line);
}
/** Al cambiar el inicio, la cita mantiene su duración. */
function onStartChange(line: AppointmentLine): void {
    if (line.start_time) line.end_time = fromMinutes(toMinutes(line.start_time) + line.minutes);
}
/** Si se ajusta el fin a mano, esa pasa a ser su duración. */
function onEndChange(line: AppointmentLine): void {
    const minutes = line.start_time && line.end_time ? toMinutes(line.end_time) - toMinutes(line.start_time) : 0;
    if (minutes > 0) line.minutes = minutes;
}

/** El servicio que se agrega empieza cuando termina el anterior. */
async function addLine(): Promise<void> {
    const last = form.value.lines[form.value.lines.length - 1];
    form.value.lines.push(newLine(last?.end_time || '09:00', employeeId.value));
    await refreshLineEmployees(form.value.lines[form.value.lines.length - 1]);
}
const removeLine = (i: number): void => { form.value.lines.splice(i, 1); };

async function openCreate(): Promise<void> {
    await loadOptions();
    editingId.value = null;
    editingAttended.value = null;
    errors.value = {};
    form.value = { customer_id: null, title: '', notes: '', status: undefined, date: new Date(day.value), lines: [newLine('09:00', employeeId.value)] };
    await refreshLineEmployees(form.value.lines[0]);
    dialog.value = true;
}
async function openEdit(a: Appointment): Promise<void> {
    await loadOptions();
    editingId.value = a.id;
    errors.value = {};
    form.value = {
        customer_id: a.customer_id, title: a.title ?? '', notes: a.notes ?? '', status: a.status, date: new Date(`${a.appointment_date}T00:00:00`),
        lines: [{
            key: ++lineKey, service_id: a.service_id, employee_id: a.employee_id, start_time: a.start_time, end_time: a.end_time,
            minutes: Math.max(5, toMinutes(a.end_time) - toMinutes(a.start_time)), employees: [],
        }],
    };
    visitNumber.value = a.visit_number;
    editingAttended.value = a.status === 'attended' ? a : null;
    // En la atendida no se cambia el especialista: no hace falta (ni conviene) refiltrar la lista.
    if (!editingAttended.value) await refreshLineEmployees(form.value.lines[0]);
    dialog.value = true;
}

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};
    try {
        const { date, lines, customer_id, title, notes, status } = form.value;
        const appointment_date = isoDate(date);
        if (editingId.value) {
            const [l] = lines;
            await appointmentsApi.save({
                customer_id, title, notes, status, appointment_date,
                service_id: l.service_id, employee_id: l.employee_id, start_time: l.start_time, end_time: l.end_time,
            }, editingId.value);
            toast.add({ severity: 'success', summary: 'Cita actualizada', life: 2000 });
        } else {
            const created = await appointmentsApi.createMany({
                customer_id, title, notes, appointment_date,
                services: lines.map((l) => ({ service_id: l.service_id, employee_id: l.employee_id, start_time: l.start_time, end_time: l.end_time })),
            });
            toast.add({ severity: 'success', summary: created.length === 1 ? 'Cita registrada' : `${created.length} citas registradas`, life: 2500 });
        }
        dialog.value = false;
        day.value = new Date(date);
        load();
    } catch (e) {
        const ax = e as AxiosError<{ errors?: Record<string, string[]>; message?: string }>;
        if (ax.response?.data?.errors) errors.value = ax.response.data.errors;
        else toast.add({ severity: 'warn', summary: 'No se pudo guardar', detail: ax.response?.data?.message, life: 5000 });
    } finally {
        saving.value = false;
    }
}

async function setStatus(a: Appointment, status: AppointmentStatus): Promise<void> {
    try {
        await appointmentsApi.status(a.id, status);
        load();
    } catch (e) {
        const ax = e as AxiosError<{ message?: string }>;
        toast.add({ severity: 'warn', summary: 'No se pudo cambiar', detail: ax.response?.data?.message, life: 4000 });
    }
}
function remove(a: Appointment): void {
    confirm.require({
        message: `¿Eliminar la cita de ${a.customer?.name} a las ${a.start_time}?`,
        header: 'Confirmar', icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Eliminar', rejectLabel: 'Cancelar', acceptClass: 'p-button-danger',
        accept: async () => { await appointmentsApi.remove(a.id); load(); },
    });
}
const attend = (a: Appointment): void => { router.push({ name: 'attendances', query: { appointment: String(a.id) } }); };

// --- Reprogramar ---------------------------------------------------------
// El cliente avisó que no puede venir: la cita pasa a otra fecha y la original
// queda como «Reprogramó» (a diferencia de «No se presentó», que no avisó).
const rescheduleDialog = ref(false);
const rescheduling = ref(false);
const rescheduleErrors = ref<Record<string, string[]>>({});
const rescheduleOf = ref<Appointment | null>(null);
const rescheduleForm = ref({ date: new Date(), start_time: '09:00', end_time: '10:00', employee_id: null as number | null });
const rescheduleEmployees = ref<Employee[]>([]);
const todayStart = (): Date => new Date(new Date().setHours(0, 0, 0, 0));

const shortDay = (s: string): string => new Date(`${s}T00:00:00`).toLocaleDateString('es-PE', { weekday: 'short', day: '2-digit', month: '2-digit' });
const goToDay = (s: string): void => { day.value = new Date(`${s}T00:00:00`); };

async function openReschedule(a: Appointment): Promise<void> {
    rescheduleOf.value = a;
    rescheduleErrors.value = {};
    // Por defecto el día siguiente al de la cita, nunca antes de hoy.
    const next = new Date(`${a.appointment_date}T00:00:00`);
    next.setDate(next.getDate() + 1);
    rescheduleForm.value = { date: next < todayStart() ? todayStart() : next, start_time: a.start_time, end_time: a.end_time, employee_id: a.employee_id };
    rescheduleEmployees.value = await employeesApi.options(a.service_id);
    if (!rescheduleEmployees.value.some((e) => e.id === a.employee_id)) rescheduleForm.value.employee_id = null;
    rescheduleDialog.value = true;
}
/** Al cambiar el inicio, la cita mantiene la duración que tenía. */
function onRescheduleStartChange(): void {
    const a = rescheduleOf.value;
    if (!a) return;
    rescheduleForm.value.end_time = fromMinutes(toMinutes(rescheduleForm.value.start_time) + toMinutes(a.end_time) - toMinutes(a.start_time));
}
async function submitReschedule(): Promise<void> {
    const a = rescheduleOf.value;
    if (!a) return;
    rescheduling.value = true;
    rescheduleErrors.value = {};
    try {
        const { date, ...payload } = rescheduleForm.value;
        const created = await appointmentsApi.reschedule(a.id, { ...payload, appointment_date: isoDate(date) });
        rescheduleDialog.value = false;
        toast.add({ severity: 'success', summary: 'Cita reprogramada', detail: `Nueva cita: ${shortDay(created.appointment_date)} a las ${created.start_time}`, life: 3000 });
        load();
    } catch (e) {
        const ax = e as AxiosError<{ errors?: Record<string, string[]>; message?: string }>;
        if (ax.response?.data?.errors) rescheduleErrors.value = ax.response.data.errors;
        else toast.add({ severity: 'warn', summary: 'No se pudo reprogramar', detail: ax.response?.data?.message, life: 5000 });
    } finally {
        rescheduling.value = false;
    }
}
/** Avisó pero aún no tiene fecha: se marca y luego se le da una desde la misma fila. */
async function markPostponed(): Promise<void> {
    if (!rescheduleOf.value) return;
    rescheduleDialog.value = false;
    await setStatus(rescheduleOf.value, 'postponed');
}

onMounted(async () => {
    employees.value = await employeesApi.options();
    load();
});
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Agenda</h1>
                <p class="text-sm capitalize text-slate-500">{{ title }} · {{ active }} citas</p>
            </div>
            <Button v-if="auth.can('appointments.create')" label="Nueva cita" icon="pi pi-plus" @click="openCreate" />
        </div>

        <div class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface-card)] p-4 shadow-sm">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <Button icon="pi pi-chevron-left" text rounded @click="shift(-1)" />
                <DatePicker v-model="day" date-format="dd/mm/yy" show-icon class="w-44" />
                <Button icon="pi pi-chevron-right" text rounded @click="shift(1)" />
                <Button label="Hoy" size="small" outlined :disabled="isToday" @click="day = new Date()" />
                <Select v-model="employeeId" :options="employees" option-label="name" option-value="id" show-clear placeholder="Todo el personal" class="ml-auto w-56" />
            </div>

            <DataTable :value="rows" :loading="loading" class="text-sm" data-key="id">
                <template #empty><div class="py-8 text-center text-slate-400">No hay citas este día.</div></template>
                <Column header="Hora" header-style="width:8rem">
                    <template #body="{ data }"><span class="font-semibold">{{ data.start_time }}</span><span class="text-slate-400"> – {{ data.end_time }}</span></template>
                </Column>
                <Column header="Cita">
                    <template #body="{ data }">
                        <p class="font-semibold">
                            {{ data.title || data.service?.name }}
                            <i v-if="data.notes" class="pi pi-comment ml-1 text-xs text-slate-400" v-tooltip.top="data.notes"></i>
                        </p>
                        <span v-if="data.title" class="mt-1 inline-block rounded-md bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-700 dark:bg-white/10 dark:text-slate-200">{{ data.service?.name }}</span>
                    </template>
                </Column>
                <Column header="Cliente">
                    <template #body="{ data }">
                        <p class="font-medium">{{ data.customer?.name }}</p>
                        <p class="text-xs text-slate-400">
                            <span
                                v-if="data.visit_number"
                                class="font-medium"
                                :class="data.visit_number === 1 ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-600 dark:text-brand-300'"
                            >{{ visitLabel(data.visit_number) }}</span>
                            <template v-if="data.visit_number && (data.customer?.whatsapp ?? data.customer?.phone)"> · </template>
                            {{ data.customer?.whatsapp ?? data.customer?.phone ?? '' }}
                        </p>
                    </template>
                </Column>
                <Column header="Especialista"><template #body="{ data }">{{ data.employee?.name }}</template></Column>
                <Column header="Estado">
                    <template #body="{ data }">
                        <Tag :value="APPOINTMENT_STATUS[data.status as AppointmentStatus].label" :severity="APPOINTMENT_STATUS[data.status as AppointmentStatus].severity" />
                        <button
                            v-if="data.rescheduled_to"
                            type="button"
                            class="mt-1 block text-xs text-slate-500 hover:text-brand-600 hover:underline"
                            v-tooltip.top="'Ver la nueva cita'"
                            @click="goToDay(data.rescheduled_to.appointment_date)"
                        ><i class="pi pi-arrow-right mr-1 text-[10px]"></i>{{ shortDay(data.rescheduled_to.appointment_date) }} · {{ data.rescheduled_to.start_time }}</button>
                        <button
                            v-else-if="data.rescheduled_from"
                            type="button"
                            class="mt-1 block text-xs text-slate-500 hover:text-brand-600 hover:underline"
                            v-tooltip.top="'Ver la cita original'"
                            @click="goToDay(data.rescheduled_from.appointment_date)"
                        ><i class="pi pi-replay mr-1 text-[10px]"></i>Reprogramada del {{ shortDay(data.rescheduled_from.appointment_date) }}</button>
                    </template>
                </Column>
                <Column header="" header-style="width:17rem">
                    <template #body="{ data }">
                        <div class="flex justify-end gap-1">
                            <template v-if="['pending', 'confirmed'].includes(data.status)">
                                <Button v-if="auth.can('attendances.create')" label="Atender" icon="pi pi-check-circle" size="small" @click="attend(data)" />
                                <Button v-if="data.status === 'pending' && auth.can('appointments.edit')" icon="pi pi-thumbs-up" text rounded size="small" v-tooltip.top="'Confirmar'" @click="setStatus(data, 'confirmed')" />
                                <Button v-if="auth.can('appointments.edit')" icon="pi pi-calendar-clock" text rounded size="small" severity="help" v-tooltip.top="'Reprogramó'" @click="openReschedule(data)" />
                                <Button v-if="auth.can('appointments.edit')" icon="pi pi-user-minus" text rounded size="small" severity="warn" v-tooltip.top="'No se presentó'" @click="setStatus(data, 'no_show')" />
                                <Button v-if="auth.can('appointments.edit')" icon="pi pi-ban" text rounded size="small" severity="danger" v-tooltip.top="'Cancelar cita'" @click="setStatus(data, 'cancelled')" />
                            </template>
                            <Button
                                v-else-if="data.status === 'postponed' && !data.rescheduled_to && auth.can('appointments.edit')"
                                icon="pi pi-calendar-clock" text rounded size="small" severity="help" v-tooltip.top="'Darle nueva fecha'" @click="openReschedule(data)"
                            />
                            <Button
                                v-if="auth.can('appointments.edit')" icon="pi pi-pencil" text rounded size="small"
                                v-tooltip.top="data.status === 'attended' ? 'Editar título y notas' : 'Editar'" @click="openEdit(data)"
                            />
                            <Button v-if="auth.can('appointments.delete') && data.status !== 'attended'" icon="pi pi-trash" text rounded size="small" severity="danger" @click="remove(data)" />
                        </div>
                    </template>
                </Column>
            </DataTable>
        </div>

        <Dialog v-model:visible="dialog" modal :header="editingId ? 'Editar cita' : 'Nueva cita'" :style="{ width: '640px' }" :breakpoints="{ '680px': '96vw' }">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div v-if="editingAttended" class="rounded-xl bg-slate-50 p-3 text-sm md:col-span-2 dark:bg-white/5">
                    <p class="font-semibold">{{ editingAttended.customer?.name }}</p>
                    <p class="text-slate-500">
                        {{ editingAttended.service?.name }} · {{ editingAttended.employee?.name }} ·
                        {{ shortDay(editingAttended.appointment_date) }}, {{ editingAttended.start_time }} – {{ editingAttended.end_time }}
                    </p>
                    <p class="mt-2 text-xs text-slate-500"><i class="pi pi-lock mr-1 text-[10px]"></i>Ya fue atendida: solo se cambian el título y las notas.</p>
                </div>
                <div v-else class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Cliente *</label>
                    <Select v-model="form.customer_id" :options="customers" option-label="name" option-value="id" filter class="w-full" placeholder="Buscar cliente" :invalid="!!errors.customer_id" />
                    <Message v-if="errors.customer_id" severity="error" size="small" variant="simple">{{ errors.customer_id[0] }}</Message>
                    <p v-else-if="visitNumber" class="mt-1 text-xs" :class="visitNumber === 1 ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-600 dark:text-brand-300'">
                        <i class="pi pi-history mr-1 text-[11px]"></i>{{ editingId ? visitLabel(visitNumber) : visitNumber === 1 ? 'Será su primera visita' : `Será su visita N.º ${visitNumber}` }}
                    </p>
                </div>
                <div :class="{ 'md:col-span-2': editingAttended }">
                    <label class="mb-1 block text-sm font-medium">Título</label>
                    <InputText v-model="form.title" maxlength="120" class="w-full" placeholder="Ej.: Evaluación inicial, Control, Retoque…" :invalid="!!errors.title" />
                    <Message v-if="errors.title" severity="error" size="small" variant="simple">{{ errors.title[0] }}</Message>
                </div>
                <div v-if="!editingAttended">
                    <label class="mb-1 block text-sm font-medium">Fecha *</label>
                    <DatePicker v-model="form.date" date-format="dd/mm/yy" show-icon class="w-full" :invalid="!!errors.appointment_date" />
                    <Message v-if="errors.appointment_date" severity="error" size="small" variant="simple">{{ errors.appointment_date[0] }}</Message>
                </div>

                <div v-if="!editingAttended" class="space-y-3 md:col-span-2">
                    <div v-if="!editingId" class="flex items-center justify-between">
                        <label class="text-sm font-medium">Servicios</label>
                        <button type="button" class="text-sm text-brand-600 hover:underline" @click="addLine"><i class="pi pi-plus text-xs"></i> Agregar otro servicio</button>
                    </div>
                    <div v-for="(line, i) in form.lines" :key="line.key" :class="{ 'rounded-xl border border-[var(--surface-border)] p-3': !editingId }">
                        <div v-if="form.lines.length > 1" class="-mt-1 mb-2 flex items-center justify-between">
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Servicio {{ i + 1 }}</span>
                            <Button icon="pi pi-trash" text rounded size="small" severity="danger" v-tooltip.top="'Quitar este servicio'" @click="removeLine(i)" />
                        </div>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium">Servicio *</label>
                                <Select v-model="line.service_id" :options="services" option-label="name" option-value="id" filter class="w-full" :invalid="!!lineError(i, 'service_id')" @change="onServiceChange(line)" />
                                <Message v-if="lineError(i, 'service_id')" severity="error" size="small" variant="simple">{{ lineError(i, 'service_id') }}</Message>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Especialista *</label>
                                <Select v-model="line.employee_id" :options="line.employees" option-label="name" option-value="id" class="w-full" :invalid="!!lineError(i, 'employee_id')" />
                                <Message v-if="lineError(i, 'employee_id')" severity="error" size="small" variant="simple">{{ lineError(i, 'employee_id') }}</Message>
                            </div>
                            <div class="grid grid-cols-2 gap-3 md:col-span-2">
                                <div>
                                    <label class="mb-1 block text-sm font-medium">Inicio</label>
                                    <InputText v-model="line.start_time" type="time" class="w-full" :invalid="!!lineError(i, 'start_time')" @change="onStartChange(line)" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium">Fin</label>
                                    <InputText v-model="line.end_time" type="time" class="w-full" :invalid="!!lineError(i, 'end_time')" @change="onEndChange(line)" />
                                </div>
                                <Message v-if="lineError(i, 'start_time') || lineError(i, 'end_time')" class="col-span-2" severity="error" size="small" variant="simple">
                                    {{ lineError(i, 'start_time') || lineError(i, 'end_time') }}
                                </Message>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Notas</label>
                    <Textarea v-model="form.notes" rows="2" auto-resize class="w-full" />
                </div>
            </div>
            <template #footer>
                <Button label="Cancelar" text @click="dialog = false" />
                <Button label="Guardar" icon="pi pi-check" :loading="saving" @click="submit" />
            </template>
        </Dialog>

        <Dialog v-model:visible="rescheduleDialog" modal header="Reprogramar cita" :style="{ width: '480px' }">
            <div v-if="rescheduleOf" class="space-y-4">
                <div class="rounded-xl bg-slate-50 p-3 text-sm dark:bg-white/5">
                    <p class="font-semibold">{{ rescheduleOf.customer?.name }}</p>
                    <p class="text-slate-500">{{ rescheduleOf.title || rescheduleOf.service?.name }} · {{ shortDay(rescheduleOf.appointment_date) }}, {{ rescheduleOf.start_time }}</p>
                </div>
                <p class="text-xs text-slate-500">El cliente avisó: esta cita quedará como «Reprogramó» y se creará la nueva con el mismo servicio, título y notas.</p>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-medium">Nueva fecha *</label>
                        <DatePicker v-model="rescheduleForm.date" date-format="dd/mm/yy" show-icon :min-date="todayStart()" class="w-full" :invalid="!!rescheduleErrors.appointment_date" />
                        <Message v-if="rescheduleErrors.appointment_date" severity="error" size="small" variant="simple">{{ rescheduleErrors.appointment_date[0] }}</Message>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Inicio</label>
                        <InputText v-model="rescheduleForm.start_time" type="time" class="w-full" @change="onRescheduleStartChange" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Fin</label>
                        <InputText v-model="rescheduleForm.end_time" type="time" class="w-full" :invalid="!!rescheduleErrors.end_time" />
                    </div>
                    <Message v-if="rescheduleErrors.end_time" class="md:col-span-2" severity="error" size="small" variant="simple">{{ rescheduleErrors.end_time[0] }}</Message>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-medium">Especialista *</label>
                        <Select v-model="rescheduleForm.employee_id" :options="rescheduleEmployees" option-label="name" option-value="id" class="w-full" :invalid="!!rescheduleErrors.employee_id" />
                    </div>
                </div>
            </div>
            <template #footer>
                <Button label="Cancelar" text @click="rescheduleDialog = false" />
                <Button
                    v-if="rescheduleOf?.status !== 'postponed'"
                    label="Aún sin fecha" text severity="secondary"
                    v-tooltip.top="'Solo marcarla como «Reprogramó»; la nueva fecha se le da después desde la misma fila'"
                    @click="markPostponed"
                />
                <Button label="Reprogramar" icon="pi pi-calendar-clock" :loading="rescheduling" :disabled="!rescheduleForm.employee_id" @click="submitReschedule" />
            </template>
        </Dialog>
    </div>
</template>
