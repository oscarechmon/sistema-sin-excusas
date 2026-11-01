<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
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
interface SupplyLine { product_id: number | null; quantity: number }

const dialog = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});
const customers = ref<Customer[]>([]);
const services = ref<Product[]>([]);
const supplies = ref<Product[]>([]);
const employees = ref<Employee[]>([]);
const packages = ref<CustomerPackage[]>([]);
const appointmentLabel = ref<string | null>(null);
const form = ref({
    customer_id: null as number | null,
    service_id: null as number | null,
    employee_id: null as number | null,
    appointment_id: null as number | null,
    customer_package_id: null as number | null,
    date: new Date(),
    observations: '',
    measurements: '',
    supplies: [] as SupplyLine[],
});

const packageOptions = computed(() => packages.value.map((p) => ({
    id: p.id,
    label: `${p.package_name} · sesión ${p.used_sessions + 1} de ${p.total_sessions}`,
})));

async function loadOptions(): Promise<void> {
    if (!customers.value.length) customers.value = await customersApi.options();
    if (!services.value.length) services.value = (await productsApi.list({ type: 'service', is_active: 1, per_page: 100, sort_by: 'name', sort_dir: 'asc' })).data;
    if (!supplies.value.length) supplies.value = (await productsApi.list({ type: 'product', is_active: 1, per_page: 100, sort_by: 'name', sort_dir: 'asc' })).data;
}

async function refreshPackages(): Promise<void> {
    packages.value = form.value.customer_id && form.value.service_id
        ? await customerPackagesApi.all({ customer_id: form.value.customer_id, service_id: form.value.service_id, usable: 1 })
        : [];
    if (!packages.value.some((p) => p.id === form.value.customer_package_id)) {
        form.value.customer_package_id = packages.value[0]?.id ?? null;
    }
}

async function onServiceChange(): Promise<void> {
    const serviceId = form.value.service_id;
    employees.value = await employeesApi.options(serviceId);
    if (form.value.employee_id && !employees.value.some((e) => e.id === form.value.employee_id)) form.value.employee_id = null;
    form.value.supplies = serviceId
        ? (await serviceSuppliesApi.get(serviceId)).map((s) => ({ product_id: s.supply_id, quantity: s.default_quantity }))
        : [];
    await refreshPackages();
}

async function openCreate(appointmentId?: number): Promise<void> {
    await loadOptions();
    errors.value = {};
    appointmentLabel.value = null;
    form.value = { customer_id: null, service_id: null, employee_id: null, appointment_id: null, customer_package_id: null, date: new Date(), observations: '', measurements: '', supplies: [] };

    if (appointmentId) {
        const a = await appointmentsApi.get(appointmentId);
        form.value.appointment_id = a.id;
        form.value.customer_id = a.customer_id;
        form.value.service_id = a.service_id;
        appointmentLabel.value = [`Cita del ${dayLabel(a.appointment_date)} a las ${a.start_time}`, a.title, visitLabel(a.visit_number)].filter(Boolean).join(' · ');
        await onServiceChange();
        form.value.employee_id = a.employee_id;
    } else {
        employees.value = await employeesApi.options();
    }
    dialog.value = true;
}

const addSupply = (): void => { form.value.supplies.push({ product_id: null, quantity: 1 }); };
const removeSupply = (i: number): void => { form.value.supplies.splice(i, 1); };

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};
    try {
        const created = await attendancesApi.create({
            customer_id: form.value.customer_id,
            service_id: form.value.service_id,
            employee_id: form.value.employee_id,
            appointment_id: form.value.appointment_id,
            customer_package_id: form.value.customer_package_id,
            attended_at: isoDate(form.value.date),
            observations: form.value.observations || null,
            measurements: form.value.measurements || null,
            supplies: form.value.supplies.filter((s) => s.product_id && s.quantity > 0) as { product_id: number; quantity: number }[],
        });
        dialog.value = false;
        const extra = created.session_number ? ` · sesión ${created.session_number} del paquete` : '';
        toast.add({ severity: 'success', summary: 'Atención registrada', detail: `${created.service}${extra}`, life: 3500 });
        if (route.query.appointment) router.replace({ name: 'attendances' });
        // La cita quedó atendida: la campana deja de contarla.
        if (created.appointment_id && auth.can('appointments.view')) today.refresh();
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
        <Dialog v-model:visible="dialog" modal header="Registrar atención" :style="{ width: '680px' }">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <Message v-if="appointmentLabel" class="md:col-span-2" severity="info" size="small">{{ appointmentLabel }}: al guardar queda como atendida.</Message>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Cliente *</label>
                    <Select v-model="form.customer_id" :options="customers" option-label="name" option-value="id" filter class="w-full" placeholder="Buscar cliente" :invalid="!!errors.customer_id" @change="refreshPackages" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Servicio *</label>
                    <Select v-model="form.service_id" :options="services" option-label="name" option-value="id" filter class="w-full" :invalid="!!errors.service_id" @change="onServiceChange" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Especialista *</label>
                    <Select v-model="form.employee_id" :options="employees" option-label="name" option-value="id" class="w-full" :invalid="!!errors.employee_id" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Paquete del cliente</label>
                    <Select v-model="form.customer_package_id" :options="packageOptions" option-label="label" option-value="id" show-clear class="w-full" :placeholder="packages.length ? 'Sin paquete (servicio suelto)' : 'No tiene paquetes para este servicio'" :disabled="!packages.length" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Fecha *</label>
                    <DatePicker v-model="form.date" date-format="dd/mm/yy" show-icon class="w-full" :max-date="new Date()" />
                </div>

                <div class="md:col-span-2">
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-sm font-medium">Insumos usados</label>
                        <button type="button" class="text-sm text-brand-600 hover:underline" @click="addSupply"><i class="pi pi-plus text-xs"></i> Agregar insumo</button>
                    </div>
                    <p v-if="!form.supplies.length" class="text-xs text-slate-400">Sin insumos. Los que el servicio usa normalmente se proponen solos (se configuran en Centro › Servicios, botón de insumos).</p>
                    <div v-for="(s, i) in form.supplies" :key="i" class="mb-2 flex items-center gap-2">
                        <Select v-model="s.product_id" :options="supplies" option-label="name" option-value="id" filter class="flex-1" placeholder="Insumo" />
                        <InputNumber v-model="s.quantity" :min="0.01" :max-fraction-digits="2" class="w-28" input-class="text-right" />
                        <Button icon="pi pi-times" text rounded size="small" severity="danger" @click="removeSupply(i)" />
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
