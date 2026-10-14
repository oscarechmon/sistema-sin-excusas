<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import ToggleSwitch from 'primevue/toggleswitch';
import Button from 'primevue/button';
import Message from 'primevue/message';
import { AxiosError } from 'axios';
import {
    brandsApi,
    categoriesApi,
    GALLERY_MAX,
    productsApi,
    unitsApi,
    type Option,
    type Product,
    type ProductPhoto,
    type ProductType,
} from '@/services/catalog';
import { useAuthStore } from '@/stores/auth';

/**
 * Alta y edición de un producto o servicio. Arriba lo que hace falta (nombre,
 * categoría, precio, fotos, descripción y si se publica en la web); los datos
 * de inventario y códigos quedan en «Más datos», que casi nunca se tocan. A la
 * derecha, si se publica y si está en promoción (con su precio), y cómo quedará
 * en la web mientras se escribe.
 *
 * La foto principal es la de los listados y el POS; las adicionales solo se
 * ven en la ficha de la web, debajo de la principal.
 */
const props = withDefaults(defineProps<{ visible: boolean; product: Product | null; kind?: 'product' | 'service' }>(), { kind: 'product' });
const emit = defineEmits<{ 'update:visible': [boolean]; saved: [] }>();

const auth = useAuthStore();
const categories = ref<Option[]>([]);
const brands = ref<Option[]>([]);
const units = ref<Option[]>([]);
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});
const imageFile = ref<File | null>(null);
const currentImage = ref<string | null>(null);
const showMore = ref(false);
// Lo que se ve: la imagen recién elegida o la que ya tiene.
const preview = computed(() => (imageFile.value ? URL.createObjectURL(imageFile.value) : currentImage.value));

// --- Fotos adicionales (ficha de la web) ---------------------------------
const savedPhotos = ref<ProductPhoto[]>([]);
const newPhotos = ref<{ file: File; url: string }[]>([]);
const removedIds = ref<number[]>([]);
const galleryLoading = ref(false);
const galleryNotice = ref<string | null>(null);

/** Las que quedarán al guardar: las que ya tiene (menos las quitadas) y las nuevas. */
const photos = computed(() => [
    ...savedPhotos.value.filter((p) => !removedIds.value.includes(p.id)).map((p) => ({ key: `id-${p.id}`, url: p.url, id: p.id })),
    ...newPhotos.value.map((p) => ({ key: p.url, url: p.url, id: null as number | null })),
]);
/** Miniaturas de la ficha web: la principal primero y luego las adicionales. */
const fichaThumbs = computed(() => {
    const all = [preview.value, ...photos.value.map((p) => p.url)].filter((url): url is string => !!url);
    return { shown: all.slice(0, 5), more: Math.max(0, all.length - 5) };
});
const galleryError = computed(() => errors.value.gallery?.[0] ?? Object.entries(errors.value).find(([k]) => k.startsWith('gallery.'))?.[1]?.[0]);

const blank = (type: ProductType) => ({
    type,
    name: '',
    code: '',
    barcode: '',
    sku: '',
    description: '',
    category_id: null as number | null,
    brand_id: null as number | null,
    unit_id: null as number | null,
    cost: 0,
    price: 0,
    wholesale_price: null as number | null,
    offer_price: null as number | null,
    stock_min: 0,
    stock_max: null as number | null,
    is_active: true,
    // null en lo que llegó de la web y nadie tocó aquí: no se cambia al guardar.
    // Lo nuevo nace sin publicar: un insumo no debe aparecer en la tienda.
    web_published: false as boolean | null,
    on_promotion: false,
    duration_minutes: (type === 'service' ? 60 : null) as number | null,
});

const form = ref(blank(props.kind));
const isEdit = computed(() => props.product !== null);
const isService = computed(() => form.value.type === 'service');
const noun = computed(() => (isService.value ? 'servicio' : 'producto'));
const title = computed(() => (isEdit.value ? `Editar ${noun.value}` : `Nuevo ${noun.value}`));
const categoryName = computed(() => categories.value.find((c) => c.id === form.value.category_id)?.name ?? null);
const money = (n: number | null): string => new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' }).format(n ?? 0);

/** Lo que se cobrará en promoción (como en el servidor): solo si rebaja el precio. */
const promoPrice = computed(() => {
    const offer = form.value.offer_price ?? 0;
    return form.value.on_promotion && offer > 0 && offer < (form.value.price ?? 0) ? offer : null;
});
const promoDiscount = computed(() => (promoPrice.value === null ? 0 : Math.round((1 - promoPrice.value / form.value.price) * 100)));

/** Campos que viven en «Más datos»: si alguno trae error, se abre solo. */
const MORE_FIELDS = ['code', 'barcode', 'sku', 'brand_id', 'unit_id', 'wholesale_price', 'stock_max', 'is_active'];

/** Lo que conviene completar antes de publicar (no impide guardar). */
const checklist = computed(() => [
    { label: 'Foto', ok: !!preview.value },
    { label: 'Descripción', ok: !!form.value.description?.trim() },
    { label: 'Precio', ok: (form.value.price ?? 0) > 0 },
    { label: 'Categoría', ok: !!form.value.category_id },
]);

async function loadOptions(type: ProductType): Promise<void> {
    [categories.value, brands.value, units.value] = await Promise.all([
        categoriesApi.options({ for: type }),
        brands.value.length ? Promise.resolve(brands.value) : brandsApi.options(),
        units.value.length ? Promise.resolve(units.value) : unitsApi.options(),
    ]);
}

watch(
    () => props.visible,
    async (open) => {
        if (!open) {
            clearNewPhotos();
            return;
        }
        errors.value = {};
        imageFile.value = null;
        showMore.value = false;
        newCategory.value = null;
        currentImage.value = props.product?.image_url ?? null;
        clearNewPhotos();
        savedPhotos.value = [];
        removedIds.value = [];
        galleryNotice.value = null;
        galleryLoading.value = false;
        const p = props.product;
        if (p) void loadGallery(p.id);
        form.value = p
            ? {
                  type: p.type,
                  name: p.name,
                  code: p.code,
                  barcode: p.barcode ?? '',
                  sku: p.sku ?? '',
                  description: p.description ?? '',
                  category_id: p.category_id,
                  brand_id: p.brand_id,
                  unit_id: p.unit_id,
                  cost: p.cost,
                  price: p.price,
                  wholesale_price: p.wholesale_price,
                  offer_price: p.offer_price,
                  stock_min: p.stock_min,
                  stock_max: p.stock_max,
                  is_active: p.is_active,
                  web_published: p.web_published,
                  on_promotion: p.on_promotion,
                  duration_minutes: p.duration_minutes,
              }
            : blank(props.kind);
        await loadOptions(form.value.type);
    },
);

function onFile(e: Event): void {
    const target = e.target as HTMLInputElement;
    imageFile.value = target.files?.[0] ?? null;
}

function onDrop(e: DragEvent): void {
    const file = e.dataTransfer?.files?.[0];
    if (file && file.type.startsWith('image/')) imageFile.value = file;
}

/** El listado no trae las fotos adicionales: se piden al abrir la edición. */
async function loadGallery(id: number): Promise<void> {
    galleryLoading.value = true;
    try {
        const full = await productsApi.get(id);
        // Si mientras tanto se abrió otro ítem, esta respuesta ya no sirve.
        if (props.product?.id === id) savedPhotos.value = full.gallery ?? [];
    } finally {
        if (props.product?.id === id) galleryLoading.value = false;
    }
}

function addPhotos(files: Iterable<File>): void {
    const images = [...files].filter((f) => f.type.startsWith('image/'));
    const room = GALLERY_MAX - photos.value.length;
    images.slice(0, room).forEach((file) => newPhotos.value.push({ file, url: URL.createObjectURL(file) }));
    galleryNotice.value = images.length > room ? `Se agregaron ${Math.max(room, 0)} de ${images.length}: el máximo es ${GALLERY_MAX} fotos adicionales.` : null;
}

function onGalleryFiles(e: Event): void {
    const target = e.target as HTMLInputElement;
    addPhotos(target.files ?? []);
    target.value = ''; // permite volver a elegir la misma foto si se quitó
}

function onGalleryDrop(e: DragEvent): void {
    addPhotos(e.dataTransfer?.files ?? []);
}

function removePhoto(photo: { key: string; id: number | null }): void {
    galleryNotice.value = null;
    if (photo.id !== null) {
        removedIds.value.push(photo.id);
        return;
    }
    URL.revokeObjectURL(photo.key);
    newPhotos.value = newPhotos.value.filter((p) => p.url !== photo.key);
}

function clearNewPhotos(): void {
    newPhotos.value.forEach((p) => URL.revokeObjectURL(p.url));
    newPhotos.value = [];
}

// --- Categoría nueva sin salir del formulario ----------------------------
const newCategory = ref<string | null>(null);
const creatingCategory = ref(false);

async function createCategory(): Promise<void> {
    const name = newCategory.value?.trim();
    if (!name) return;
    creatingCategory.value = true;
    try {
        const created = await categoriesApi.save({ name, is_active: true });
        categories.value = [...categories.value, { id: created.id, name: created.name }];
        form.value.category_id = created.id;
        newCategory.value = null;
    } catch (e) {
        const ax = e as AxiosError<{ errors?: Record<string, string[]>; message?: string }>;
        errors.value = { ...errors.value, category_id: ax.response?.data?.errors?.name ?? [ax.response?.data?.message ?? 'No se pudo crear la categoría.'] };
    } finally {
        creatingCategory.value = false;
    }
}

const err = (field: string): string | undefined => errors.value[field]?.[0];

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};
    try {
        const payload = {
            ...form.value,
            image: imageFile.value,
            gallery: newPhotos.value.map((p) => p.file),
            remove_images: removedIds.value,
        };
        await productsApi.save(payload, props.product?.id);
        emit('saved');
    } catch (e) {
        const ax = e as AxiosError<{ errors?: Record<string, string[]>; message?: string }>;
        if (ax.response?.status === 422) {
            errors.value = ax.response.data.errors ?? {};
            if (MORE_FIELDS.some((f) => errors.value[f])) showMore.value = true;
        }
    } finally {
        saving.value = false;
    }
}

const close = () => emit('update:visible', false);
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :header="title"
        :style="{ width: '920px' }"
        :breakpoints="{ '960px': '96vw' }"
        :dismissable-mask="true"
        @update:visible="close"
    >
        <div class="grid grid-cols-1 gap-6 md:grid-cols-[minmax(0,1fr)_280px]">
            <!-- Datos -->
            <div class="space-y-5">
                <section class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-medium" for="pf-name">Nombre *</label>
                        <InputText
                            id="pf-name"
                            v-model="form.name"
                            class="w-full"
                            :placeholder="isService ? 'Ej.: Limpieza facial profunda' : 'Ej.: Colágeno hidrolizado 300 g'"
                            :invalid="!!err('name')"
                        />
                        <Message v-if="err('name')" severity="error" size="small" variant="simple">{{ err('name') }}</Message>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Categoría</label>
                        <div v-if="newCategory === null" class="flex gap-2">
                            <Select
                                v-model="form.category_id"
                                :options="categories"
                                option-label="name"
                                option-value="id"
                                class="min-w-0 flex-1"
                                filter
                                show-clear
                                :placeholder="isService ? 'Ej.: Faciales' : 'Ej.: Suplementos'"
                                :invalid="!!err('category_id')"
                            />
                            <Button
                                v-if="auth.can('categories.create')"
                                icon="pi pi-plus"
                                severity="secondary"
                                outlined
                                v-tooltip.top="'Nueva categoría'"
                                @click="newCategory = ''"
                            />
                        </div>
                        <div v-else class="flex gap-2">
                            <InputText v-model="newCategory" class="min-w-0 flex-1" placeholder="Nombre de la categoría" autofocus @keydown.enter.prevent="createCategory" />
                            <Button icon="pi pi-check" :loading="creatingCategory" v-tooltip.top="'Crear'" @click="createCategory" />
                            <Button icon="pi pi-times" severity="secondary" text @click="newCategory = null" />
                        </div>
                        <Message v-if="err('category_id')" severity="error" size="small" variant="simple">{{ err('category_id') }}</Message>
                        <p v-else class="mt-1 text-xs text-slate-400">En la web se agrupa por categoría.</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="pf-price">Precio *</label>
                        <InputNumber input-id="pf-price" v-model="form.price" mode="currency" currency="PEN" locale="es-PE" class="w-full" :min="0" :invalid="!!err('price')" />
                        <Message v-if="err('price')" severity="error" size="small" variant="simple">{{ err('price') }}</Message>
                    </div>

                    <template v-if="isService">
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="pf-duration">Duración (minutos)</label>
                            <InputNumber input-id="pf-duration" v-model="form.duration_minutes" class="w-full" :min="5" :max="600" :step="5" show-buttons placeholder="Ej. 60" :invalid="!!err('duration_minutes')" />
                            <Message v-if="err('duration_minutes')" severity="error" size="small" variant="simple">{{ err('duration_minutes') }}</Message>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="pf-cost">Costo (opcional)</label>
                            <InputNumber input-id="pf-cost" v-model="form.cost" mode="currency" currency="PEN" locale="es-PE" class="w-full" :min="0" :invalid="!!err('cost')" />
                            <Message v-if="err('cost')" severity="error" size="small" variant="simple">{{ err('cost') }}</Message>
                            <p v-else class="mt-1 text-xs text-slate-400">Para calcular la ganancia; no se muestra.</p>
                        </div>
                    </template>
                    <template v-else>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="pf-cost">Costo *</label>
                            <InputNumber input-id="pf-cost" v-model="form.cost" mode="currency" currency="PEN" locale="es-PE" class="w-full" :min="0" :invalid="!!err('cost')" />
                            <Message v-if="err('cost')" severity="error" size="small" variant="simple">{{ err('cost') }}</Message>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="pf-stock-min">Avisar cuando queden</label>
                            <InputNumber input-id="pf-stock-min" v-model="form.stock_min" class="w-full" :min="0" suffix=" u." />
                            <p class="mt-1 text-xs text-slate-400">El stock se ingresa con Compras.</p>
                        </div>
                    </template>
                </section>

                <section class="space-y-4 rounded-xl border border-[var(--surface-border)] p-4">
                    <p class="flex items-center gap-2 text-sm font-semibold"><i class="pi pi-globe text-emerald-500"></i> Lo que ve el cliente en la web</p>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Foto principal</label>
                        <label
                            class="flex cursor-pointer items-center gap-4 rounded-lg border-2 border-dashed border-[var(--surface-border)] p-3 transition hover:border-emerald-400"
                            @dragover.prevent
                            @drop.prevent="onDrop"
                        >
                            <span class="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-lg bg-slate-100 dark:bg-white/5">
                                <img v-if="preview" :src="preview" class="h-full w-full object-cover" alt="" />
                                <i v-else class="pi pi-image text-2xl text-slate-400"></i>
                            </span>
                            <span class="text-sm">
                                <span class="font-medium text-emerald-600 dark:text-emerald-400">{{ preview ? 'Cambiar foto' : 'Subir foto' }}</span>
                                <span class="block text-xs text-slate-400">Arrástrala aquí o haz clic. JPG, PNG o WEBP, hasta 4 MB.</span>
                            </span>
                            <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFile" />
                        </label>
                        <Message v-if="err('image')" severity="error" size="small" variant="simple">{{ err('image') }}</Message>
                    </div>

                    <div>
                        <p class="mb-1 flex items-baseline justify-between gap-2">
                            <span class="text-sm font-medium">Más fotos <span class="font-normal text-slate-400">(opcional)</span></span>
                            <span class="text-xs text-slate-400">{{ photos.length }}/{{ GALLERY_MAX }}</span>
                        </p>
                        <div class="grid grid-cols-4 gap-2 sm:grid-cols-6">
                            <div
                                v-for="photo in photos"
                                :key="photo.key"
                                class="relative aspect-square overflow-hidden rounded-lg border border-[var(--surface-border)] bg-slate-100 dark:bg-white/5"
                            >
                                <img :src="photo.url" class="h-full w-full object-cover" alt="" />
                                <button
                                    type="button"
                                    class="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-black/60 text-white transition hover:bg-red-600"
                                    aria-label="Quitar foto"
                                    v-tooltip.top="'Quitar'"
                                    @click="removePhoto(photo)"
                                >
                                    <i class="pi pi-times text-[10px]"></i>
                                </button>
                            </div>
                            <span v-if="galleryLoading" class="grid aspect-square place-items-center rounded-lg bg-slate-100 dark:bg-white/5">
                                <i class="pi pi-spin pi-spinner text-slate-400"></i>
                            </span>
                            <!-- Hasta saber cuántas tiene, no se agregan (el máximo cuenta todas). -->
                            <label
                                v-if="!galleryLoading && photos.length < GALLERY_MAX"
                                class="flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-[var(--surface-border)] text-slate-400 transition hover:border-emerald-400 hover:text-emerald-500"
                                @dragover.prevent
                                @drop.prevent="onGalleryDrop"
                            >
                                <i class="pi pi-plus"></i>
                                <span class="text-[11px]">Agregar</span>
                                <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="onGalleryFiles" />
                            </label>
                        </div>
                        <Message v-if="galleryError" severity="error" size="small" variant="simple">{{ galleryError }}</Message>
                        <Message v-else-if="galleryNotice" severity="warn" size="small" variant="simple">{{ galleryNotice }}</Message>
                        <p v-else class="mt-1 text-xs text-slate-400">
                            Se ven en la ficha de la web, debajo de la principal: al hacer clic en una, pasa a verse en grande.
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="pf-description">Descripción</label>
                        <Textarea
                            id="pf-description"
                            v-model="form.description"
                            class="w-full"
                            rows="3"
                            auto-resize
                            maxlength="600"
                            :placeholder="isService ? 'Qué es, para qué sirve y qué resultados esperar.' : 'Qué es, para qué sirve y cómo se usa.'"
                        />
                        <p class="mt-1 flex justify-between text-xs text-slate-400">
                            <span>Dos o tres líneas bastan.</span>
                            <span>{{ form.description?.length ?? 0 }}/600</span>
                        </p>
                    </div>
                </section>

                <section>
                    <Button
                        :label="showMore ? 'Ocultar más datos' : `Más datos (códigos${isService ? '' : ', marca, unidad, precio mayorista'})`"
                        :icon="showMore ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                        text
                        size="small"
                        @click="showMore = !showMore"
                    />
                    <div v-if="showMore" class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Código interno</label>
                            <InputText v-model="form.code" class="w-full" placeholder="Automático (PRD-000001)" :invalid="!!err('code')" />
                            <Message v-if="err('code')" severity="error" size="small" variant="simple">{{ err('code') }}</Message>
                        </div>
                        <template v-if="!isService">
                            <div>
                                <label class="mb-1 block text-sm font-medium">Código de barras</label>
                                <InputText v-model="form.barcode" class="w-full" placeholder="Automático (EAN-13)" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">SKU</label>
                                <InputText v-model="form.sku" class="w-full" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Marca</label>
                                <Select v-model="form.brand_id" :options="brands" option-label="name" option-value="id" class="w-full" filter show-clear placeholder="Seleccionar" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Unidad</label>
                                <Select v-model="form.unit_id" :options="units" option-label="name" option-value="id" class="w-full" show-clear placeholder="Seleccionar" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Precio mayorista</label>
                                <InputNumber v-model="form.wholesale_price" mode="currency" currency="PEN" locale="es-PE" class="w-full" :min="0" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Stock máximo</label>
                                <InputNumber v-model="form.stock_max" class="w-full" :min="0" />
                            </div>
                        </template>
                        <div class="flex items-center gap-2 sm:col-span-2">
                            <ToggleSwitch v-model="form.is_active" input-id="pf-active" />
                            <label for="pf-active" class="text-sm font-medium">Activo</label>
                            <span class="text-xs text-slate-400">— inactivo no se vende en el POS ni se ve en la web.</span>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Así se verá en la web -->
            <aside class="space-y-3 md:sticky md:top-0 md:self-start">
                <div
                    class="rounded-xl border p-4 transition"
                    :class="form.web_published ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-500/40 dark:bg-emerald-500/10' : 'border-[var(--surface-border)]'"
                >
                    <div class="flex items-center justify-between gap-3">
                        <label for="pf-web" class="font-semibold">Publicar en la web</label>
                        <ToggleSwitch
                            :model-value="form.web_published ?? false"
                            input-id="pf-web"
                            @update:model-value="(value: boolean) => (form.web_published = value)"
                        />
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        <template v-if="form.web_published">
                            Se verá en sinexcusas.org.pe › {{ isService ? 'Servicios' : 'Productos' }}{{ categoryName ? ` › ${categoryName}` : '' }}.
                        </template>
                        <template v-else>Solo se usa en el sistema ({{ isService ? 'agenda, atenciones y POS' : 'POS e inventario' }}).</template>
                    </p>
                </div>

                <div
                    class="rounded-xl border p-4 transition"
                    :class="form.on_promotion ? 'border-rose-300 bg-rose-50 dark:border-rose-500/40 dark:bg-rose-500/10' : 'border-[var(--surface-border)]'"
                >
                    <div class="flex items-center justify-between gap-3">
                        <label for="pf-promo" class="font-semibold">En promoción</label>
                        <ToggleSwitch v-model="form.on_promotion" input-id="pf-promo" />
                    </div>
                    <template v-if="form.on_promotion">
                        <label class="mb-1 mt-3 block text-sm font-medium" for="pf-offer">Precio de promoción <span class="font-normal text-slate-400">(opcional)</span></label>
                        <InputNumber
                            input-id="pf-offer"
                            v-model="form.offer_price"
                            mode="currency"
                            currency="PEN"
                            locale="es-PE"
                            class="w-full"
                            :min="0"
                            :invalid="!!err('offer_price')"
                        />
                        <Message v-if="err('offer_price')" severity="error" size="small" variant="simple">{{ err('offer_price') }}</Message>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            <template v-if="promoPrice !== null">
                                {{ promoDiscount }}% menos. La web y el POS cobran {{ money(promoPrice) }} mientras esté en promoción.
                            </template>
                            <template v-else>Sin precio de promoción se vende al precio normal, con la etiqueta «Promoción».</template>
                            <template v-if="!form.web_published"> Publícalo en la web para que salga en Promociones.</template>
                        </p>
                    </template>
                    <p v-else class="mt-1 text-xs text-slate-500 dark:text-slate-400">Actívalo para que salga en sinexcusas.org.pe › Promociones.</p>
                </div>

                <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Así se verá</p>
                <article class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-white text-slate-800 shadow-sm" :class="{ 'opacity-50': !form.web_published }">
                    <div class="relative grid aspect-[4/3] place-items-center bg-[#FAF6EE]">
                        <img v-if="preview" :src="preview" class="h-full w-full object-cover" alt="" />
                        <span v-else class="px-4 text-center text-sm text-[#9A8B6C]">{{ form.name || 'Sin foto' }}</span>
                        <span v-if="form.on_promotion" class="absolute left-2 top-2 bg-[#14110C] px-2 py-1 text-[10px] uppercase leading-none tracking-[.16em] text-[#EBD3A2]">
                            {{ promoDiscount ? `−${promoDiscount} %` : 'Promoción' }}
                        </span>
                    </div>
                    <div class="space-y-1 p-3">
                        <p class="text-[10px] uppercase tracking-[.18em] text-[#B08D4B]">{{ categoryName ?? (isService ? 'Otros' : 'Productos') }}</p>
                        <p class="text-lg leading-tight">{{ form.name || `Nombre del ${noun}` }}</p>
                        <p class="line-clamp-3 text-xs text-[#7C7263]">{{ form.description || 'Aquí va la descripción.' }}</p>
                        <div class="flex items-center justify-between pt-1">
                            <span>
                                <span v-if="promoPrice !== null" class="mr-1.5 text-xs text-[#9A8B6C] line-through">{{ money(form.price) }}</span>
                                <span class="font-semibold">{{ money(promoPrice ?? form.price) }}</span>
                            </span>
                            <span v-if="isService && form.duration_minutes" class="text-xs text-[#7C7263]">{{ form.duration_minutes }} min</span>
                        </div>
                    </div>
                </article>

                <div v-if="photos.length" class="space-y-1.5" :class="{ 'opacity-50': !form.web_published }">
                    <p class="text-xs text-slate-400">En la ficha, debajo de la foto:</p>
                    <div class="flex gap-1.5">
                        <img
                            v-for="url in fichaThumbs.shown"
                            :key="url"
                            :src="url"
                            class="h-11 w-11 rounded border border-[var(--surface-border)] bg-white object-contain"
                            alt=""
                        />
                        <span v-if="fichaThumbs.more" class="grid h-11 w-11 place-items-center rounded border border-[var(--surface-border)] text-xs text-slate-500">
                            +{{ fichaThumbs.more }}
                        </span>
                    </div>
                </div>

                <ul class="space-y-1 text-xs">
                    <li v-for="item in checklist" :key="item.label" class="flex items-center gap-2" :class="item.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600'">
                        <i :class="['pi', item.ok ? 'pi-check-circle' : 'pi-circle']"></i>
                        {{ item.label }}{{ item.ok ? '' : ': falta' }}
                    </li>
                </ul>
            </aside>
        </div>

        <template #footer>
            <Button label="Cancelar" text @click="close" />
            <Button :label="isEdit ? 'Guardar cambios' : `Crear ${noun}`" icon="pi pi-check" :loading="saving" @click="submit" />
        </template>
    </Dialog>
</template>
