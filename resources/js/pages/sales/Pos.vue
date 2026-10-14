<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import Button from 'primevue/button';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import Textarea from 'primevue/textarea';
import ToggleSwitch from 'primevue/toggleswitch';
import Tag from 'primevue/tag';
import { AxiosError } from 'axios';
import { productsApi, type Product, type ProductType } from '@/services/catalog';
import { customersApi, type Customer } from '@/services/contacts';
import { inventoryApi, type Warehouse } from '@/services/inventory';
import { employeesApi, type Employee } from '@/services/staff';
import { salesApi, DOC_TYPES, PAYMENT_METHODS, type PaymentInput } from '@/services/sales';

interface CartLine {
    product_id: number;
    type: ProductType;
    code: string;
    name: string;
    price: number;
    quantity: number;
    stock: number;
    /** Quién atendió (solo servicios). */
    employee_id: number | null;
}

const toast = useToast();

const query = ref('');
const results = ref<Product[]>([]);
const searching = ref(false);
const cart = ref<CartLine[]>([]);
const scanRef = ref<HTMLInputElement>();

const warehouses = ref<Warehouse[]>([]);
const customers = ref<Customer[]>([]);
const warehouseId = ref<number | null>(null);
const customerId = ref<number | null>(null);
const docType = ref('ticket');
const notes = ref('');
const processing = ref(false);
const employees = ref<Employee[]>([]);
/** Venta con saldo pendiente: se cobra lo que se paga ahora y el resto después. */
const allowBalance = ref(false);

const payments = ref<PaymentInput[]>([{ method: 'efectivo', amount: 0 }]);

const money = (n: number): string => new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' }).format(n);

// --- Totales (el precio incluye IGV; se muestra desglosado) ---
const total = computed(() => cart.value.reduce((s, l) => s + l.price * l.quantity, 0));
const igv = computed(() => total.value - total.value / 1.18);
const base = computed(() => total.value - igv.value);
const paid = computed(() => payments.value.reduce((s, p) => s + (p.amount || 0), 0));
const change = computed(() => Math.max(paid.value - total.value, 0));
const balance = computed(() => Math.max(total.value - paid.value, 0));
const hasPackage = computed(() => cart.value.some((l) => l.type === 'package'));
const isWalkIn = computed(() => !customerId.value || customers.value.find((c) => c.id === customerId.value)?.name === 'Público general');
const canCheckout = computed(() => cart.value.length > 0
    && (docType.value === 'cotizacion' || paid.value + 0.001 >= total.value || (allowBalance.value && !isWalkIn.value))
    && !(hasPackage.value && isWalkIn.value && docType.value !== 'cotizacion'));

// --- Búsqueda / escaneo ---
let t: number | undefined;
function onSearch(): void {
    window.clearTimeout(t);
    t = window.setTimeout(async () => {
        if (!query.value.trim()) {
            results.value = [];
            return;
        }
        searching.value = true;
        try {
            const res = await productsApi.list({ search: query.value, per_page: 12, is_active: 1 });
            results.value = res.data;
        } finally {
            searching.value = false;
        }
    }, 250);
}

/** Enter = intento de escaneo exacto por código de barras (pistola USB). */
async function onScan(): Promise<void> {
    const code = query.value.trim();
    if (!code) return;
    try {
        const product = await productsApi.scan(code);
        addToCart(product);
        query.value = '';
        results.value = [];
    } catch {
        // Si no es un código exacto, se mantiene la búsqueda por texto.
    }
    scanRef.value?.focus();
}

function addToCart(p: Product): void {
    const existing = cart.value.find((l) => l.product_id === p.id);
    if (existing) {
        existing.quantity += 1;
    } else {
        // En promoción se cobra su precio de promoción (se puede editar igual).
        cart.value.push({ product_id: p.id, type: p.type, code: p.code, name: p.name, price: p.promotion_price ?? p.price, quantity: 1, stock: p.current_stock, employee_id: null });
    }
    query.value = '';
    results.value = [];
    scanRef.value?.focus();
}

function removeLine(i: number): void {
    cart.value.splice(i, 1);
}

function payExact(): void {
    payments.value = [{ method: payments.value[0]?.method ?? 'efectivo', amount: Number(total.value.toFixed(2)) }];
}

function addPayment(): void {
    payments.value.push({ method: 'efectivo', amount: 0 });
}

function clearCart(): void {
    cart.value = [];
    notes.value = '';
    allowBalance.value = false;
    payments.value = [{ method: 'efectivo', amount: 0 }];
}

const typeLabel = (p: Product): string => (p.type === 'service' ? 'Servicio' : p.type === 'package' ? 'Paquete de sesiones' : `Stock: ${p.current_stock}`);

async function checkout(): Promise<void> {
    processing.value = true;
    try {
        const sale = await salesApi.checkout({
            doc_type: docType.value,
            customer_id: customerId.value,
            warehouse_id: warehouseId.value,
            notes: notes.value || undefined,
            allow_balance: docType.value !== 'cotizacion' && allowBalance.value,
            items: cart.value.map((l) => ({ product_id: l.product_id, quantity: l.quantity, price: l.price, employee_id: l.employee_id })),
            payments: docType.value === 'cotizacion' ? [] : payments.value.filter((p) => p.amount > 0),
        });
        const detail = (sale.balance ?? 0) > 0
            ? `Total ${money(sale.total)} · Saldo pendiente ${money(sale.balance ?? 0)}`
            : `Total ${money(sale.total)} · Vuelto ${money(sale.change)}`;
        toast.add({ severity: 'success', summary: `Venta ${sale.full_number}`, detail, life: 4000 });
        if (docType.value !== 'cotizacion') window.open(salesApi.ticketUrl(sale.id), '_blank');
        clearCart();
    } catch (e) {
        const ax = e as AxiosError<{ message?: string }>;
        toast.add({ severity: 'error', summary: 'No se pudo cobrar', detail: ax.response?.data?.message ?? 'Error al procesar la venta', life: 5000 });
    } finally {
        processing.value = false;
    }
}

onMounted(async () => {
    [warehouses.value, customers.value] = await Promise.all([inventoryApi.warehouses(), customersApi.options()]);
    employeesApi.options().then((list) => { employees.value = list; }).catch(() => { employees.value = []; });
    warehouseId.value = warehouses.value.find((w) => w.is_default)?.id ?? warehouses.value[0]?.id ?? null;
    customerId.value = customers.value.find((c) => c.name === 'Público general')?.id ?? null;
    scanRef.value?.focus();
});
</script>

<template>
    <div class="grid h-[calc(100vh-8rem)] grid-cols-1 gap-4 lg:grid-cols-3">
        <!-- Panel de productos -->
        <div class="flex flex-col lg:col-span-2">
            <div class="mb-3 flex items-center gap-2">
                <IconField class="flex-1">
                    <InputIcon class="pi pi-barcode" />
                    <InputText
                        ref="scanRef"
                        v-model="query"
                        placeholder="Escanea un código o busca por nombre… (Enter para escanear)"
                        class="w-full"
                        @input="onSearch"
                        @keyup.enter="onScan"
                    />
                </IconField>
                <Select v-model="warehouseId" :options="warehouses" option-label="name" option-value="id" class="w-48" placeholder="Almacén" />
            </div>

            <div class="grid flex-1 auto-rows-min grid-cols-2 gap-3 overflow-y-auto rounded-2xl border border-[var(--surface-border)] bg-[var(--surface-card)] p-3 sm:grid-cols-3 xl:grid-cols-4">
                <button
                    v-for="p in results"
                    :key="p.id"
                    type="button"
                    class="flex flex-col rounded-xl border border-[var(--surface-border)] p-3 text-left transition hover:border-brand-400 hover:shadow-sm"
                    @click="addToCart(p)"
                >
                    <span class="mb-2 grid h-16 w-full place-items-center overflow-hidden rounded-lg bg-slate-100 dark:bg-white/5">
                        <img v-if="p.image_url" :src="p.image_url" class="h-full w-full object-cover" alt="" loading="lazy" decoding="async" />
                        <i v-else class="pi pi-box text-2xl text-slate-300"></i>
                    </span>
                    <span class="line-clamp-2 text-sm font-medium">{{ p.name }}</span>
                    <span class="mt-1 text-xs text-slate-400">{{ typeLabel(p) }}</span>
                    <span v-if="p.promotion_price !== null" class="mt-1">
                        <span class="mr-1 text-xs text-slate-400 line-through">{{ money(p.price) }}</span>
                        <span class="font-bold text-rose-600 dark:text-rose-400">{{ money(p.promotion_price) }}</span>
                        <Tag value="Promo" severity="danger" class="ml-1 !px-1.5 !py-0 !text-[10px]" />
                    </span>
                    <span v-else class="mt-1 font-bold text-brand-600">{{ money(p.price) }}</span>
                </button>
                <div v-if="!results.length" class="col-span-full grid place-items-center py-16 text-center text-sm text-slate-400">
                    <div>
                        <i class="pi pi-search mb-2 block text-3xl"></i>
                        Busca o escanea productos para agregarlos a la venta.
                    </div>
                </div>
            </div>
        </div>

        <!-- Carrito / Cobro -->
        <div class="flex flex-col rounded-2xl border border-[var(--surface-border)] bg-[var(--surface-card)] shadow-sm">
            <div class="border-b border-[var(--surface-border)] p-4">
                <div class="grid grid-cols-2 gap-2">
                    <Select v-model="docType" :options="DOC_TYPES" option-label="label" option-value="value" class="w-full" />
                    <Select v-model="customerId" :options="customers" option-label="name" option-value="id" class="w-full" filter placeholder="Cliente" />
                </div>
            </div>

            <!-- Líneas -->
            <div class="flex-1 space-y-2 overflow-y-auto p-4">
                <div v-if="!cart.length" class="grid h-full place-items-center text-sm text-slate-400">
                    <div><i class="pi pi-shopping-cart mb-2 block text-3xl"></i>Carrito vacío</div>
                </div>
                <div v-for="(line, i) in cart" :key="line.product_id" class="rounded-xl border border-[var(--surface-border)] p-2">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ line.name }}
                                <Tag v-if="line.type === 'package'" value="Paquete" severity="info" class="ml-1" />
                            </p>
                            <p class="text-xs text-slate-400">{{ line.code }}</p>
                        </div>
                        <Button icon="pi pi-times" text rounded size="small" severity="danger" @click="removeLine(i)" />
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <InputNumber v-model="line.quantity" :min="1" show-buttons button-layout="horizontal" class="w-28" input-class="w-10 text-center" :allow-empty="false">
                            <template #incrementbuttonicon><i class="pi pi-plus" /></template>
                            <template #decrementbuttonicon><i class="pi pi-minus" /></template>
                        </InputNumber>
                        <InputNumber v-model="line.price" mode="currency" currency="PEN" locale="es-PE" class="flex-1" input-class="text-right" />
                        <span class="w-24 text-right text-sm font-semibold">{{ money(line.price * line.quantity) }}</span>
                    </div>
                    <Select
                        v-if="line.type === 'service' && employees.length" v-model="line.employee_id" :options="employees" option-label="name" option-value="id"
                        show-clear placeholder="¿Quién atendió? (opcional)" class="mt-2 w-full" size="small"
                    />
                </div>
                <p v-if="hasPackage && isWalkIn" class="rounded-lg bg-amber-50 p-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                    <i class="pi pi-info-circle mr-1"></i>Selecciona el cliente: el paquete queda a su nombre con su saldo de sesiones.
                </p>
            </div>

            <!-- Totales y pago -->
            <div class="space-y-3 border-t border-[var(--surface-border)] p-4">
                <div class="space-y-1 text-sm">
                    <div class="flex justify-between text-slate-500"><span>Op. gravada</span><span>{{ money(base) }}</span></div>
                    <div class="flex justify-between text-slate-500"><span>IGV (18%)</span><span>{{ money(igv) }}</span></div>
                    <div class="flex justify-between text-lg font-bold"><span>Total</span><span>{{ money(total) }}</span></div>
                </div>

                <template v-if="docType !== 'cotizacion'">
                    <div v-for="(p, i) in payments" :key="i" class="flex items-center gap-2">
                        <Select v-model="p.method" :options="PAYMENT_METHODS" option-label="label" option-value="value" class="w-36" />
                        <InputNumber v-model="p.amount" mode="currency" currency="PEN" locale="es-PE" class="flex-1" input-class="text-right" />
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <button class="text-brand-600 hover:underline" @click="addPayment"><i class="pi pi-plus text-xs"></i> Pago mixto</button>
                        <button class="text-brand-600 hover:underline" @click="payExact">Importe exacto</button>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Pagado {{ money(paid) }}</span>
                        <span v-if="allowBalance && balance > 0" class="font-semibold text-amber-600">Saldo {{ money(balance) }}</span>
                        <span v-else class="font-semibold">Vuelto {{ money(change) }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <ToggleSwitch v-model="allowBalance" input-id="pos-balance" :disabled="isWalkIn" />
                        <label for="pos-balance" :class="isWalkIn ? 'text-slate-400' : ''">
                            Dejar saldo pendiente<span v-if="isWalkIn" class="text-xs"> (elige un cliente)</span>
                        </label>
                    </div>
                </template>

                <Textarea v-model="notes" placeholder="Observaciones (opcional)" rows="1" auto-resize class="w-full text-sm" />

                <Button
                    :label="docType === 'cotizacion' ? 'Generar cotización' : `Cobrar ${money(total)}`"
                    icon="pi pi-check"
                    class="w-full"
                    size="large"
                    :disabled="!canCheckout"
                    :loading="processing"
                    @click="checkout"
                />
            </div>
        </div>
    </div>
</template>
