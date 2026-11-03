<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import DataTable, { type DataTablePageEvent } from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
import Tag from 'primevue/tag';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import Message from 'primevue/message';
import { AxiosError } from 'axios';
import { attendancesApi, appointmentsApi, serviceSuppliesApi, isoDate, visitLabel, type Attendance } from '@/services/agenda';
import { customerPackagesApi, type CustomerPackage } from '@/services/packages';
import { employeesApi, type Employee } from '@/services/staff';
import { customersApi, type Customer } from '@/services/contacts';
import { productsApi, type Product } from '@/services/catalog';
import { useAuthStore } from '@/stores/auth';
import { useTodayAppointmentsStore } from '@/stores/todayAppointments';

const toast = useToast();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const today = useTodayAppointmentsStore();
const money = (n: number): string => new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' }).format(n);
const dayLabel = (s: string): string => new Date(`${s}T00:00:00`).toLocaleDateString('es-PE');

// --- Historial -------------------------------------------------------------
const rows = ref<Attendance[]>([]);
const total = ref(0);
const loading = ref(true);
const range = ref<Date[] | null>(null);
const params = reactive({ page: 1, per_page: 15, search: '', from: '', to: '' });

async function load(): Promise<void> {
    loading.value = true;
    try {
        params.from = range.value?.[0] ? isoDate(range.value[0]) : '';
        params.to = range.value?.[1] ? isoDate(range.value[1]) : params.from;
        const res = await attendancesApi.list(params);
        rows.value = res.data;
        total.value = res.meta.total;
    } finally {
        loading.value = false;
    }
}
let t: number | undefined;
const onSearch = (): void => { window.clearTimeout(t); t = window.setTimeout(() => { params.page = 1; load(); }, 350); };
const onPage = (e: DataTablePageEvent): void => { params.page = e.page + 1; params.per_page = e.rows; load(); };

// --- Detalle -----------------------------------------------------------------
const detail = ref<Attendance | null>(null);
async function openDetail(a: Attendance): Promise<void> {
    detail.value = await attendancesApi.get(a.id);
}

// --- Registro ------------------------------------------------------------------
// Una visita puede incluir varios servicios, cada uno con su especialista,
// paquete e insumos: al registrar se crea una atención (y una comisión) por cada uno.
interface SupplyLine { product_id: number | null; quantity: number }
interface ServiceLine {
    key: number;
    service_id: number | null;
    employee_id: number | null;
    customer_package_id: number | null;
    supplies: SupplyLine[];
    employees: Employee[];
    packages: CustomerPackage[];
}

const dialog = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});
const customers = ref<Customer[]>([]);
const services = ref<Product[]>([]);
const supplies = ref<Product[]>([]);
const allEmployees = ref<Employee[]>([]);
const appointmentLabel = ref<string | null>(null);
const form = ref({
    customer_id: null as number | null,
    appointment_id: null as number | null,
    date: new Date(),
    observations: '',
    measurements: '',
    lines: [] as ServiceLine[],
});

let lineKey = 0;
const newLine = (): ServiceLine => ({ key: ++lineKey, service_id: null, employee_id: null, customer_package_id: null, supplies: [], employees: allEmployees.value, packages: [] });

function packageOptions(i: number): { id: number; label: string }[] {
    return form.value.lines[i].packages.map((p) => {
        // Si un servicio anterior de esta visita usa el mismo paquete, a este le toca la sesión siguiente.
        const before = form.value.lines.slice(0, i).filter((l) => l.customer_package_id === p.id).length;
        return { id: p.id, label: `${p.package_name} · sesión ${p.used_sessions + 1 + before} de ${p.total_sessions}` };
    });
}

/** Primer error de un campo de la línea (incluye los de sus insumos). */
const lineError = (i: number, field: string): string | undefined =>
    Object.entries(errors.value).find(([k]) => k === `services.${i}.${field}` || k.startsWith(`services.${i}.${field}.`))?.[1][0];

async function loadOptions(): Promise<void> {
    if (!customers.value.length) customers.value = await customersApi.options();
    if (!services.value.length) services.value = (await productsApi.list({ type: 'service', is_active: 1, per_page: 100, sort_by: 'name', sort_dir: 'asc' })).data;
    if (!supplies.value.length) supplies.value = (await productsApi.list({ type: 'product', is_active: 1, per_page: 100, sort_by: 'name', sort_dir: 'asc' })).data;
    if (!allEmployees.value.length) allEmployees.value = await employeesApi.options();
}

async function refreshPackages(line: ServiceLine): Promise<void> {
    line.packages = form.value.customer_id && line.service_id
        ? await customerPackagesApi.all({ customer_id: form.value.customer_id, service_id: line.service_id, usable: 1 })
        : [];
    if (!line.packages.some((p) => p.id === line.customer_package_id)) {
        line.customer_package_id = line.packages[0]?.id ?? null;
    }
}
const onCustomerChange = async (): Promise<void> => { await Promise.all(form.value.lines.map(refreshPackages)); };

async function onServiceChange(line: ServiceLine): Promise<void> {
    const serviceId = line.service_id;
    line.employees = await employeesApi.options(serviceId);
    if (line.employee_id && !line.employees.some((e) => e.id === line.employee_id)) line.employee_id = null;
    line.supplies = serviceId
        ? (await serviceSuppliesApi.get(serviceId)).map((s) => ({ product_id: s.supply_id, quantity: s.default_quantity }))
        : [];
    await refreshPackages(line);
}

async function openCreate(appointmentId?: number): Promise<void> {
    await loadOptions();
    errors.value = {};
    appointmentLabel.value = null;
    form.value = { customer_id: null, appointment_id: null, date: new Date(), observations: '', measurements: '', lines: [newLine()] };

    if (appointmentId) {
        const a = await appointmentsApi.get(appointmentId);
        const line = form.value.lines[0];
        form.value.appointment_id = a.id;
        form.value.customer_id = a.customer_id;
        line.service_id = a.service_id;
        appointmentLabel.value = [`Cita del ${dayLabel(a.appointment_date)} a las ${a.start_time}`, a.title, visitLabel(a.visit_number)].filter(Boolean).join(' · ');
        await onServiceChange(line);
        line.employee_id = a.employee_id;
    }
    dialog.value = true;
}

const addLine = (): void => { form.value.lines.push(newLine()); };
const removeLine = (i: number): void => { form.value.lines.splice(i, 1); };
const addSupply = (line: ServiceLine): void => { line.supplies.push({ product_id: null, quantity: 1 }); };
const removeSupply = (line: ServiceLine, i: number): void => { line.supplies.splice(i, 1); };

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};
    try {
        const created = await attendancesApi.createMany({
            customer_id: form.value.customer_id,
            appointment_id: form.value.appointment_id,
            attended_at: isoDate(form.value.date),
            observations: form.value.observations || null,
            measurements: form.value.measurements || null,
            services: form.value.lines.map((l) => ({
                service_id: l.service_id,
                employee_id: l.employee_id,
                customer_package_id: l.customer_package_id,
                supplies: l.supplies.filter((s) => s.product_id && s.quantity > 0) as { product_id: number; quantity: number }[],
            })),
        });
        dialog.value = false;
        const detail = created.map((a) => `${a.service}${a.session_number ? ` (sesión ${a.session_number} del paquete)` : ''}`).join(' · ');
        toast.add({ severity: 'success', summary: created.length === 1 ? 'Atención registrada' : `${created.length} atenciones registradas`, detail, life: 3500 });
        if (route.query.appointment) router.replace({ name: 'attendances' });
        // La cita quedó atendida: la campana deja de contarla.
        if (created.some((a) => a.appointment_id) && auth.can('appointments.view')) today.refresh();
        load();
    } catch (e) {
        const ax = e as AxiosError<{ errors?: Record<string, string[]>; message?: string }>;
        if (ax.response?.data?.errors) errors.value = ax.response.data.errors;
        else toast.add({ severity: 'warn', summary: 'No se registró', detail: ax.response?.data?.message, life: 5000 });
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    load();
    const fromAppointment = Number(route.query.appointment);
    if (fromAppointment && auth.can('attendances.create')) openCreate(fromAppointment);
});
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Atenciones</h1>
                <p class="text-sm text-slate-500">Servicios realizados: descuentan insumos y sesiones de paquete, y generan la comisión</p>
            </div>
            <Button v-if="auth.can('attendances.create')" label="Registrar atención" icon="pi pi-plus" @click="openCreate()" />
        </div>

        <div class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface-card)] p-4 shadow-sm">
            <div class="mb-3 flex flex-wrap gap-3">
                <IconField>
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="params.search" placeholder="Cliente u observación…" class="w-72" @input="onSearch" />
                </IconField>
                <DatePicker v-model="range" selection-mode="range" date-format="dd/mm/yy" show-icon show-button-bar placeholder="Rango de fechas" class="w-64" @update:model-value="params.page = 1; load()" />
            </div>
            <DataTable
                :value="rows" :loading="loading" lazy paginator :rows="params.per_page" :total-records="total"
                :first="(params.page - 1) * params.per_page" :rows-per-page-options="[15, 30, 50]" class="text-sm" @page="onPage"
            >
                <template #empty><div class="py-8 text-center text-slate-400">Sin atenciones registradas.</div></template>
                <Column header="Fecha"><template #body="{ data }">{{ dayLabel(data.attended_at) }}</template></Column>
                <Column header="Cliente">
                    <template #body="{ data }">
                        <p class="font-medium">{{ data.customer?.name }}</p>
                        <p class="text-xs text-slate-400">{{ data.customer?.code }}</p>
                    </template>
                </Column>
                <Column header="Servicio">
                    <template #body="{ data }">
                        {{ data.service }}
                        <Tag v-if="data.session_number" class="ml-1" :value="`Sesión ${data.session_number}`" severity="info" />
                    </template>
                </Column>
                <Column header="Especialista"><template #body="{ data }">{{ data.employee }}</template></Column>
                <Column header="Comisión">
                    <template #body="{ data }">{{ data.commission ? money(data.commission.amount) : '—' }}</template>
                </Column>
                <Column header="" header-style="width:4rem">
                    <template #body="{ data }"><Button icon="pi pi-eye" text rounded size="small" @click="openDetail(data)" /></template>
                </Column>
            </DataTable>
        </div>

        <!-- Registro -->
        <Dialog v-model:visible="dialog" modal header="Registrar atención" :style="{ width: '720px' }">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <Message v-if="appointmentLabel" class="md:col-span-2" severity="info" size="small">{{ appointmentLabel }}: al guardar queda como atendida.</Message>
                <div>
                    <label class="mb-1 block text-sm font-medium">Cliente *</label>
                    <Select v-model="form.customer_id" :options="customers" option-label="name" option-value="id" filter class="w-full" placeholder="Buscar cliente" :invalid="!!errors.customer_id" @change="onCustomerChange" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Fecha *</label>
                    <DatePicker v-model="form.date" date-format="dd/mm/yy" show-icon class="w-full" :max-date="new Date()" :invalid="!!errors.attended_at" />
                    <Message v-if="errors.attended_at" severity="error" size="small" variant="simple">{{ errors.attended_at[0] }}</Message>
                </div>

                <div class="space-y-3 md:col-span-2">
                    <div class="flex items-center justify-between">
                        <label class="text-sm font-medium">Servicios realizados</label>
                        <button type="button" class="text-sm text-brand-600 hover:underline" @click="addLine"><i class="pi pi-plus text-xs"></i> Agregar otro servicio</button>
                    </div>
                    <div v-for="(line, i) in form.lines" :key="line.key" class="rounded-xl border border-[var(--surface-border)] p-3">
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
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-sm font-medium">Paquete del cliente</label>
                                <Select v-model="line.customer_package_id" :options="packageOptions(i)" option-label="label" option-value="id" show-clear class="w-full" :placeholder="line.packages.length ? 'Sin paquete (servicio suelto)' : 'No tiene paquetes para este servicio'" :disabled="!line.packages.length" />
                            </div>
                            <div class="md:col-span-2">
                                <div class="mb-1 flex items-center justify-between">
                                    <label class="text-sm font-medium">Insumos usados</label>
                                    <button type="button" class="text-sm text-brand-600 hover:underline" @click="addSupply(line)"><i class="pi pi-plus text-xs"></i> Agregar insumo</button>
                                </div>
                                <p v-if="!line.supplies.length" class="text-xs text-slate-400">Sin insumos. Los que el servicio usa normalmente se proponen solos (se configuran en Centro › Servicios, botón de insumos).</p>
                                <div v-for="(s, j) in line.supplies" :key="j" class="mb-2 flex items-center gap-2">
                                    <Select v-model="s.product_id" :options="supplies" option-label="name" option-value="id" filter class="flex-1" placeholder="Insumo" />
                                    <InputNumber v-model="s.quantity" :min="0.01" :max-fraction-digits="2" class="w-28" input-class="text-right" />
                                    <Button icon="pi pi-times" text rounded size="small" severity="danger" @click="removeSupply(line, j)" />
                                </div>
                                <Message v-if="lineError(i, 'supplies')" severity="error" size="small" variant="simple">{{ lineError(i, 'supplies') }}</Message>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Observaciones</label>
                    <Textarea v-model="form.observations" rows="3" auto-resize class="w-full" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Medidas / evolución</label>
                    <Textarea v-model="form.measurements" rows="3" auto-resize class="w-full" placeholder="Cintura, peso, zona tratada…" />
                </div>
            </div>
            <template #footer>
                <Button label="Cancelar" text @click="dialog = false" />
                <Button label="Registrar" icon="pi pi-check" :loading="saving" @click="submit" />
            </template>
        </Dialog>

        <!-- Detalle -->
        <Dialog :visible="!!detail" modal header="Atención" :style="{ width: '520px' }" @update:visible="detail = null">
            <div v-if="detail" class="space-y-3 text-sm">
                <div class="grid grid-cols-2 gap-2">
                    <div><p class="text-xs text-slate-400">Cliente</p><p class="font-medium">{{ detail.customer?.name }}</p></div>
                    <div><p class="text-xs text-slate-400">Fecha</p><p>{{ dayLabel(detail.attended_at) }}</p></div>
                    <div><p class="text-xs text-slate-400">Servicio</p><p>{{ detail.service }}</p></div>
                    <div><p class="text-xs text-slate-400">Especialista</p><p>{{ detail.employee }}</p></div>
                </div>
                <p v-if="detail.package">Paquete <span class="font-medium">{{ detail.package.name }}</span> · sesión {{ detail.session_number }} de {{ detail.package.total_sessions }}</p>
                <div v-if="detail.supplies?.length">
                    <p class="text-xs text-slate-400">Insumos</p>
                    <p v-for="s in detail.supplies" :key="s.product_id">{{ s.quantity }} × {{ s.name }}</p>
                </div>
                <div v-if="detail.observations"><p class="text-xs text-slate-400">Observaciones</p><p class="whitespace-pre-line">{{ detail.observations }}</p></div>
                <div v-if="detail.measurements"><p class="text-xs text-slate-400">Medidas</p><p class="whitespace-pre-line">{{ detail.measurements }}</p></div>
                <p v-if="detail.commission">Comisión: <span class="font-semibold">{{ money(detail.commission.amount) }}</span> ({{ detail.commission.status === 'paid' ? 'pagada' : 'pendiente' }})</p>
            </div>
        </Dialog>
    </div>
</template>
