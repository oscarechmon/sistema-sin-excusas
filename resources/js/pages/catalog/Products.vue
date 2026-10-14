<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import DataTable, { type DataTablePageEvent, type DataTableSortEvent } from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import Tag from 'primevue/tag';
import Skeleton from 'primevue/skeleton';
import Select from 'primevue/select';
import ToggleSwitch from 'primevue/toggleswitch';
import { AxiosError } from 'axios';
import ProductFormDialog from '@/components/catalog/ProductFormDialog.vue';
import LabelPrintDialog from '@/components/catalog/LabelPrintDialog.vue';
import ServiceSuppliesDialog from '@/components/catalog/ServiceSuppliesDialog.vue';
import WebOrderDialog from '@/components/catalog/WebOrderDialog.vue';
import { categoriesApi, productsApi, type Option, type Product } from '@/services/catalog';
import { useAuthStore } from '@/stores/auth';

/**
 * Productos (se venden en tienda, llevan stock) y servicios (se atienden en el
 * centro) en pantallas separadas: la misma página con su tipo. Desde aquí se
 * publica cada uno en la web con un clic.
 */
const props = withDefaults(defineProps<{ kind?: 'product' | 'service' }>(), { kind: 'product' });

const toast = useToast();
const confirm = useConfirm();
const auth = useAuthStore();

const isService = computed(() => props.kind === 'service');
const words = computed(() =>
    isService.value
        ? { one: 'servicio', many: 'servicios', title: 'Servicios', icon: 'pi-sparkles' }
        : { one: 'producto', many: 'productos', title: 'Productos', icon: 'pi-box' },
);

const WEB_FILTER = [
    { label: 'Publicados en la web', value: 'published' },
    { label: 'No publicados', value: 'hidden' },
    { label: 'En promoción', value: 'promotion' },
];

const rows = ref<Product[]>([]);
const total = ref(0);
const publishedTotal = ref(0);
const promotionTotal = ref(0);
const loading = ref(true);
const selection = ref<Product[]>([]);
const categories = ref<Option[]>([]);

const params = reactive({
    page: 1,
    per_page: 10,
    search: '',
    category_id: null as number | null,
    web: null as 'published' | 'hidden' | 'promotion' | null,
    sort_by: 'created_at',
    sort_dir: 'desc' as 'asc' | 'desc',
});

const dialogVisible = ref(false);
const editing = ref<Product | null>(null);

const labelDialogVisible = ref(false);
const labelIds = ref<number[]>([]);

const orderVisible = ref(false);

const suppliesVisible = ref(false);
const suppliesService = ref<Product | null>(null);

const money = (n: number): string => new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' }).format(n);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const res = await productsApi.list({ ...params, type: props.kind });
        rows.value = res.data;
        total.value = res.meta.total;
        publishedTotal.value = res.web_published_total ?? 0;
        promotionTotal.value = res.promotion_total ?? 0;
    } catch {
        toast.add({ severity: 'error', summary: 'Error', detail: `No se pudieron cargar los ${words.value.many}`, life: 3000 });
    } finally {
        loading.value = false;
    }
}

async function loadCategories(): Promise<void> {
    categories.value = await categoriesApi.options({ for: props.kind });
}

/** Al pasar de Productos a Servicios la pantalla es la misma: se reinicia. */
watch(
    () => props.kind,
    () => {
        Object.assign(params, { page: 1, search: '', category_id: null, web: null });
        selection.value = [];
        load();
        loadCategories();
    },
);

let searchTimer: number | undefined;
function onSearch(): void {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        params.page = 1;
        load();
    }, 350);
}

function reload(): void {
    params.page = 1;
    load();
}

function onPage(e: DataTablePageEvent): void {
    params.page = e.page + 1;
    params.per_page = e.rows;
    load();
}

function onSort(e: DataTableSortEvent): void {
    params.sort_by = (e.sortField as string) || 'created_at';
    params.sort_dir = e.sortOrder === 1 ? 'asc' : 'desc';
    load();
}

function openCreate(): void {
    editing.value = null;
    dialogVisible.value = true;
}

function openEdit(product: Product): void {
    editing.value = product;
    dialogVisible.value = true;
}

function onSaved(): void {
    dialogVisible.value = false;
    toast.add({ severity: 'success', summary: 'Guardado', detail: `${words.value.one[0].toUpperCase()}${words.value.one.slice(1)} guardado`, life: 2500 });
    load();
    loadCategories();
}

// --- Publicar en la web con un clic --------------------------------------
const publishing = ref<number | null>(null);

/** Lo que le falta para verse bien en la web (no impide publicar). */
function missing(product: Product): string[] {
    const gaps: string[] = [];
    if (!product.image_url) gaps.push('foto');
    if (!product.description?.trim()) gaps.push('descripción');
    if (!(product.price > 0)) gaps.push('precio');
    return gaps;
}

async function togglePublish(product: Product, value: boolean): Promise<void> {
    publishing.value = product.id;
    const before = product.web_published;
    product.web_published = value;
    try {
        const { message } = await productsApi.publish(product.id, value);
        publishedTotal.value += value === !!before ? 0 : value ? 1 : -1;
        const gaps = missing(product);
        toast.add({
            severity: value && gaps.length ? 'warn' : 'success',
            summary: value ? 'Publicado en la web' : 'Oculto en la web',
            detail: value && gaps.length ? `${message} Le falta ${gaps.join(' y ')}: complétalo para que se vea mejor.` : message,
            life: 4000,
        });
    } catch (e) {
        product.web_published = before;
        const ax = e as AxiosError<{ message?: string }>;
        toast.add({ severity: 'error', summary: 'No se pudo cambiar', detail: ax.response?.data?.message, life: 4000 });
    } finally {
        publishing.value = null;
    }
}

// --- Promoción con un clic ------------------------------------------------
const promoting = ref<number | null>(null);

/** Por qué una promoción todavía no se ve como debería (no impide activarla). */
function promotionNote(product: Product): string | null {
    if (!product.on_promotion) return null;
    if (!product.web_published || !product.is_active) return 'No está publicado en la web (o está inactivo): no se verá en Promociones hasta publicarlo.';
    if (product.promotion_price === null) return 'Sin precio de promoción: se vende al precio normal. Ponlo al editarlo para mostrarlo rebajado.';
    return null;
}

async function togglePromotion(product: Product, value: boolean): Promise<void> {
    promoting.value = product.id;
    const before = product.on_promotion;
    product.on_promotion = value;
    try {
        const { product: saved, message } = await productsApi.promote(product.id, value);
        product.promotion_price = saved.promotion_price;
        promotionTotal.value += value === before ? 0 : value ? 1 : -1;
        const note = promotionNote(product);
        toast.add({
            severity: note ? 'warn' : 'success',
            summary: value ? 'En promoción' : 'Sin promoción',
            detail: note ? `${message} ${note}` : message,
            life: note ? 6000 : 4000,
        });
    } catch (e) {
        product.on_promotion = before;
        const ax = e as AxiosError<{ message?: string }>;
        toast.add({ severity: 'error', summary: 'No se pudo cambiar', detail: ax.response?.data?.message, life: 4000 });
    } finally {
        promoting.value = null;
    }
}

// --- Otras acciones -------------------------------------------------------
function openLabels(ids: number[]): void {
    if (!ids.length) return;
    labelIds.value = ids;
    labelDialogVisible.value = true;
}

function openSupplies(service: Product): void {
    suppliesService.value = service;
    suppliesVisible.value = true;
}

/** Exporta el catálogo (respeta el buscador actual) a Excel o CSV. */
function exportProducts(format: 'xlsx' | 'csv'): void {
    window.open(productsApi.exportUrl(format, { search: params.search }), '_blank');
}

function confirmDelete(product: Product): void {
    confirm.require({
        message: `¿Eliminar el ${words.value.one} «${product.name}»? También deja de verse en la web.`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Cancelar',
        acceptLabel: 'Eliminar',
        acceptClass: 'p-button-danger',
        accept: async () => {
            await productsApi.remove(product.id);
            toast.add({ severity: 'success', summary: 'Eliminado', detail: `«${product.name}» eliminado`, life: 2500 });
            load();
        },
    });
}

function confirmBulkDelete(): void {
    const ids = selection.value.map((p) => p.id);
    confirm.require({
        message: `¿Eliminar ${ids.length} ${words.value.many} seleccionado(s)?`,
        header: 'Eliminación masiva',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Eliminar',
        rejectLabel: 'Cancelar',
        acceptClass: 'p-button-danger',
        accept: async () => {
            await productsApi.bulkRemove(ids);
            selection.value = [];
            toast.add({ severity: 'success', summary: 'Listo', detail: `${ids.length} eliminados`, life: 2500 });
            load();
        },
    });
}

onMounted(() => {
    load();
    loadCategories();
});
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">{{ words.title }}</h1>
                <p class="text-sm text-slate-500">
                    {{ total }} {{ words.many }} ·
                    <span class="font-medium text-emerald-600 dark:text-emerald-400"><i class="pi pi-globe text-xs"></i> {{ publishedTotal }} en la web</span>
                    <template v-if="promotionTotal">
                        ·
                        <span class="font-medium text-rose-600 dark:text-rose-400"><i class="pi pi-percentage text-xs"></i> {{ promotionTotal }} en promoción</span>
                    </template>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="!isService && selection.length && auth.can('products.print')"
                    :label="`Etiquetas (${selection.length})`"
                    icon="pi pi-tags"
                    severity="secondary"
                    outlined
                    @click="openLabels(selection.map((p) => p.id))"
                />
                <Button
                    v-if="selection.length && auth.can('products.delete')"
                    :label="`Eliminar (${selection.length})`"
                    icon="pi pi-trash"
                    severity="danger"
                    outlined
                    @click="confirmBulkDelete"
                />
                <Button
                    v-if="!isService && auth.can('products.export')"
                    label="Excel"
                    icon="pi pi-file-excel"
                    severity="success"
                    outlined
                    @click="exportProducts('xlsx')"
                />
                <Button
                    v-if="auth.can('products.edit')"
                    label="Orden en la web"
                    icon="pi pi-sort-alt"
                    severity="secondary"
                    outlined
                    @click="orderVisible = true"
                />
                <Button
                    v-if="auth.can('products.create')"
                    :label="`Nuevo ${words.one}`"
                    icon="pi pi-plus"
                    @click="openCreate"
                />
            </div>
        </div>

        <div class="flex items-start gap-3 rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
            <i class="pi pi-info-circle mt-0.5"></i>
            <p>
                <strong>Para mostrar un {{ words.one }} en la web</strong> ponle foto, descripción y precio, y activa
                <strong>«En la web»</strong> en la lista. Se ve al instante en sinexcusas.org.pe{{ isService ? ', agrupado por su categoría (Faciales, Corporales…)' : '' }}.
                Activa <strong>«Promoción»</strong> para que además salga en <strong>Promociones</strong>; si al editarlo le pones precio de promoción, la web y el POS cobran ese precio.
                Con <strong>«Orden en la web»</strong> decides qué va primero.
            </p>
        </div>

        <div class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface-card)] p-4 shadow-sm">
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <IconField>
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="params.search" :placeholder="`Buscar ${words.one}…`" class="w-72" @input="onSearch" />
                </IconField>
                <Select
                    v-model="params.category_id"
                    :options="categories"
                    option-label="name"
                    option-value="id"
                    show-clear
                    placeholder="Todas las categorías"
                    class="w-56"
                    @change="reload"
                />
                <Select
                    v-model="params.web"
                    :options="WEB_FILTER"
                    option-label="label"
                    option-value="value"
                    show-clear
                    placeholder="Web: todos"
                    class="w-56"
                    @change="reload"
                />
            </div>

            <DataTable
                :value="rows"
                :loading="loading"
                lazy
                paginator
                :rows="params.per_page"
                :total-records="total"
                :rows-per-page-options="[10, 25, 50, 100]"
                :first="(params.page - 1) * params.per_page"
                v-model:selection="selection"
                data-key="id"
                removable-sort
                paginator-template="FirstPageLink PrevPageLink CurrentPageReport NextPageLink LastPageLink RowsPerPageDropdown"
                current-page-report-template="{first}–{last} de {totalRecords}"
                class="text-sm"
                @page="onPage"
                @sort="onSort"
            >
                <template #empty>
                    <div class="py-10 text-center text-slate-400">
                        <i :class="['pi text-3xl', words.icon]"></i>
                        <p class="mt-2">No hay {{ words.many }} con ese filtro.</p>
                        <Button v-if="auth.can('products.create')" :label="`Crear ${words.one}`" icon="pi pi-plus" text class="mt-2" @click="openCreate" />
                    </div>
                </template>
                <template #loading>
                    <div class="space-y-2 py-4">
                        <Skeleton v-for="i in 6" :key="i" height="2.6rem" />
                    </div>
                </template>

                <Column selection-mode="multiple" header-style="width:3rem" />
                <Column :header="isService ? 'Servicio' : 'Producto'" field="name" sortable>
                    <template #body="{ data }">
                        <div class="flex items-center gap-3">
                            <span class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-lg bg-slate-100 dark:bg-white/5">
                                <img v-if="data.image_url" :src="data.image_url" class="h-full w-full object-cover" alt="" loading="lazy" decoding="async" />
                                <i v-else :class="['pi text-slate-400', words.icon]"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="font-medium">{{ data.name }}</p>
                                <p class="text-xs text-slate-400">
                                    {{ data.category?.name ?? 'Sin categoría' }}
                                    <template v-if="isService && data.duration_minutes"> · {{ data.duration_minutes }} min</template>
                                    · {{ data.code }}
                                </p>
                            </div>
                        </div>
                    </template>
                </Column>
                <Column header="Precio" field="price" sortable>
                    <template #body="{ data }">
                        <template v-if="data.promotion_price !== null">
                            <span class="block text-xs text-slate-400 line-through">{{ money(data.price) }}</span>
                            <span class="font-semibold text-rose-600 dark:text-rose-400">{{ money(data.promotion_price) }}</span>
                        </template>
                        <span v-else class="font-semibold">{{ money(data.price) }}</span>
                    </template>
                </Column>
                <Column v-if="!isService" header="Stock">
                    <template #body="{ data }">
                        <Tag
                            :value="String(data.current_stock)"
                            :severity="data.current_stock <= 0 ? 'danger' : data.current_stock <= data.stock_min ? 'warn' : 'secondary'"
                            v-tooltip.top="data.current_stock <= 0 ? 'Agotado: en la web se ve «Agotado»' : undefined"
                        />
                    </template>
                </Column>
                <Column header="En la web" header-style="width:9rem">
                    <template #body="{ data }">
                        <div class="flex items-center gap-2">
                            <ToggleSwitch
                                :model-value="!!data.web_published"
                                :disabled="!auth.can('products.edit') || publishing === data.id"
                                :aria-label="`Publicar ${data.name} en la web`"
                                @update:model-value="(value: boolean) => togglePublish(data, value)"
                            />
                            <i
                                v-if="data.web_published && !data.is_active"
                                class="pi pi-eye-slash text-amber-500"
                                v-tooltip.top="'Está inactivo: no se verá en la web hasta activarlo'"
                            ></i>
                            <i
                                v-else-if="data.web_published && missing(data).length"
                                class="pi pi-exclamation-circle text-amber-500"
                                v-tooltip.top="`Le falta ${missing(data).join(' y ')}`"
                            ></i>
                        </div>
                    </template>
                </Column>
                <Column header="Promoción" header-style="width:9rem">
                    <template #body="{ data }">
                        <div class="flex items-center gap-2">
                            <ToggleSwitch
                                :model-value="data.on_promotion"
                                :disabled="!auth.can('products.edit') || promoting === data.id"
                                :aria-label="`Poner ${data.name} en promoción`"
                                @update:model-value="(value: boolean) => togglePromotion(data, value)"
                            />
                            <i v-if="promotionNote(data)" class="pi pi-exclamation-circle text-amber-500" v-tooltip.top="promotionNote(data)"></i>
                        </div>
                    </template>
                </Column>
                <Column header="Estado">
                    <template #body="{ data }">
                        <Tag :value="data.is_active ? 'Activo' : 'Inactivo'" :severity="data.is_active ? 'success' : 'danger'" />
                    </template>
                </Column>
                <Column header="" header-style="width:8rem">
                    <template #body="{ data }">
                        <div class="flex justify-end gap-1">
                            <Button
                                v-if="isService && auth.can('products.edit')"
                                icon="pi pi-flask"
                                text
                                rounded
                                size="small"
                                @click="openSupplies(data)"
                                v-tooltip.top="'Insumos que usa'"
                            />
                            <Button
                                v-if="!isService && auth.can('products.print')"
                                icon="pi pi-tag"
                                text
                                rounded
                                size="small"
                                @click="openLabels([data.id])"
                                v-tooltip.top="'Imprimir etiqueta'"
                            />
                            <Button
                                v-if="auth.can('products.edit')"
                                icon="pi pi-pencil"
                                text
                                rounded
                                size="small"
                                @click="openEdit(data)"
                                v-tooltip.top="'Editar'"
                            />
                            <Button
                                v-if="auth.can('products.delete')"
                                icon="pi pi-trash"
                                text
                                rounded
                                size="small"
                                severity="danger"
                                @click="confirmDelete(data)"
                                v-tooltip.top="'Eliminar'"
                            />
                        </div>
                    </template>
                </Column>
            </DataTable>
        </div>

        <ProductFormDialog v-model:visible="dialogVisible" :product="editing" :kind="kind" @saved="onSaved" />
        <LabelPrintDialog v-model:visible="labelDialogVisible" :ids="labelIds" />
        <ServiceSuppliesDialog v-model:visible="suppliesVisible" :service="suppliesService" />
        <WebOrderDialog v-model:visible="orderVisible" :kind="kind" />
    </div>
</template>
