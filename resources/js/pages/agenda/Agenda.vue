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
import { appointmentsApi, APPOINTMENT_STATUS, isoDate, visitLabel, type Appointment, type AppointmentPayload, type AppointmentStatus } from '@/services/agenda';
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
const dialog = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});
const editingId = ref<number | null>(null);
const form = ref<AppointmentPayload & { date: Date }>({ customer_id: null, service_id: null, employee_id: null, appointment_date: '', start_time: '09:00', end_time: '10:00', title: '', notes: '', date: new Date() });
const formEmployees = ref<Employee[]>([]);

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
async function refreshFormEmployees(): Promise<void> {
    formEmployees.value = await employeesApi.options(form.value.service_id);
    if (form.value.employee_id && !formEmployees.value.some((e) => e.id === form.value.employee_id)) form.value.employee_id = null;
}

async function openCreate(): Promise<void> {
    await loadOptions();
    editingId.value = null;
    errors.value = {};
    form.value = { customer_id: null, service_id: null, employee_id: employeeId.value, appointment_date: '', start_time: '09:00', end_time: '10:00', title: '', notes: '', date: new Date(day.value) };
    await refreshFormEmployees();
    dialog.value = true;
}
async function openEdit(a: Appointment): Promise<void> {
    await loadOptions();
    editingId.value = a.id;
    errors.value = {};
    form.value = {
        customer_id: a.customer_id, service_id: a.service_id, employee_id: a.employee_id, appointment_date: a.appointment_date,
        start_time: a.start_time, end_time: a.end_time, title: a.title ?? '', notes: a.notes ?? '', status: a.status, date: new Date(`${a.appointment_date}T00:00:00`),
    };
    visitNumber.value = a.visit_number;
    await refreshFormEmployees();
    dialog.value = true;
}

/** Al cambiar el inicio, la cita mantiene su duración. */
function onStartChange(): void {
    const [h, m] = form.value.start_time.split(':').map(Number);
    const end = new Date(2000, 0, 1, h, m + 60);
    form.value.end_time = `${String(end.getHours()).padStart(2, '0')}:${String(end.getMinutes()).padStart(2, '0')}`;
}

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};
    try {
        const { date, ...payload } = form.value;
        await appointmentsApi.save({ ...payload, appointment_date: isoDate(date) }, editingId.value ?? undefined);
        dialog.value = false;
        toast.add({ severity: 'success', summary: editingId.value ? 'Cita actualizada' : 'Cita registrada', life: 2000 });
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
                        <p v-if="data.title" class="text-xs text-slate-400">{{ data.service?.name }}</p>
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
                    <template #body="{ data }"><Tag :value="APPOINTMENT_STATUS[data.status as AppointmentStatus].label" :severity="APPOINTMENT_STATUS[data.status as AppointmentStatus].severity" /></template>
                </Column>
                <Column header="" header-style="width:15rem">
                    <template #body="{ data }">
                        <div class="flex justify-end gap-1">
                            <template v-if="['pending', 'confirmed'].includes(data.status)">
                                <Button v-if="auth.can('attendances.create')" label="Atender" icon="pi pi-check-circle" size="small" @click="attend(data)" />
                                <Button v-if="data.status === 'pending' && auth.can('appointments.edit')" icon="pi pi-thumbs-up" text rounded size="small" v-tooltip.top="'Confirmar'" @click="setStatus(data, 'confirmed')" />
                                <Button v-if="auth.can('appointments.edit')" icon="pi pi-user-minus" text rounded size="small" severity="warn" v-tooltip.top="'No se presentó'" @click="setStatus(data, 'no_show')" />
                                <Button v-if="auth.can('appointments.edit')" icon="pi pi-ban" text rounded size="small" severity="danger" v-tooltip.top="'Cancelar cita'" @click="setStatus(data, 'cancelled')" />
                            </template>
                            <Button v-if="auth.can('appointments.edit') && data.status !== 'attended'" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
                            <Button v-if="auth.can('appointments.delete') && data.status !== 'attended'" icon="pi pi-trash" text rounded size="small" severity="danger" @click="remove(data)" />
                        </div>
                    </template>
                </Column>
            </DataTable>
        </div>

        <Dialog v-model:visible="dialog" modal :header="editingId ? 'Editar cita' : 'Nueva cita'" :style="{ width: '560px' }">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Cliente *</label>
                    <Select v-model="form.customer_id" :options="customers" option-label="name" option-value="id" filter class="w-full" placeholder="Buscar cliente" :invalid="!!errors.customer_id" />
                    <Message v-if="errors.customer_id" severity="error" size="small" variant="simple">{{ errors.customer_id[0] }}</Message>
                    <p v-else-if="visitNumber" class="mt-1 text-xs" :class="visitNumber === 1 ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-600 dark:text-brand-300'">
                        <i class="pi pi-history mr-1 text-[11px]"></i>{{ editingId ? visitLabel(visitNumber) : visitNumber === 1 ? 'Será su primera visita' : `Será su visita N.º ${visitNumber}` }}
                    </p>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Título</label>
                    <InputText v-model="form.title" maxlength="120" class="w-full" placeholder="Ej.: Evaluación inicial, Control, Retoque…" :invalid="!!errors.title" />
                    <Message v-if="errors.title" severity="error" size="small" variant="simple">{{ errors.title[0] }}</Message>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Servicio *</label>
                    <Select v-model="form.service_id" :options="services" option-label="name" option-value="id" filter class="w-full" :invalid="!!errors.service_id" @change="refreshFormEmployees" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Especialista *</label>
                    <Select v-model="form.employee_id" :options="formEmployees" option-label="name" option-value="id" class="w-full" :invalid="!!errors.employee_id" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Fecha *</label>
                    <DatePicker v-model="form.date" date-format="dd/mm/yy" show-icon class="w-full" :invalid="!!errors.appointment_date" />
                    <Message v-if="errors.appointment_date" severity="error" size="small" variant="simple">{{ errors.appointment_date[0] }}</Message>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Inicio</label>
                        <InputText v-model="form.start_time" type="time" class="w-full" @change="onStartChange" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Fin</label>
                        <InputText v-model="form.end_time" type="time" class="w-full" :invalid="!!errors.end_time" />
                    </div>
                </div>
                <Message v-if="errors.end_time" class="md:col-span-2" severity="error" size="small" variant="simple">{{ errors.end_time[0] }}</Message>
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
    </div>
</template>
