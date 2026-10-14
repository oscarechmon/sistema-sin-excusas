<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import DataTable, { type DataTableRowReorderEvent } from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import { AxiosError } from 'axios';
import { productsApi, type WebOrder, type WebOrderItem } from '@/services/catalog';

/**
 * «Orden en la web»: qué va primero en sinexcusas.org.pe. La web agrupa por
 * categoría, así que aquí se ordenan las categorías (Faciales antes que
 * Corporales…) y, dentro de cada una, sus productos o servicios. Se arrastra
 * por el asa o se usan las flechas; nada cambia hasta «Guardar orden».
 */
const props = withDefaults(defineProps<{ kind?: 'product' | 'service' }>(), { kind: 'product' });
const visible = defineModel<boolean>('visible', { required: true });

const toast = useToast();
const order = ref<WebOrder>({ categories: [], uncategorized: [] });
const loading = ref(false);
const saving = ref(false);
const changed = ref(false);

const words = computed(() => (props.kind === 'service' ? { many: 'servicios', page: 'Servicios', other: 'Otros' } : { many: 'productos', page: 'Productos', other: 'Otros productos' }));

/** Cada categoría en su orden y, al final, lo que no tiene (en la web va siempre último). */
const groups = computed(() => [
    ...order.value.categories.map((category, position) => ({ key: `c-${category.id}`, name: category.name, position, items: category.items })),
    ...(order.value.uncategorized.length ? [{ key: 'none', name: null, position: -1, items: order.value.uncategorized }] : []),
]);

const money = (n: number): string => new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' }).format(n);

watch(visible, async (open) => {
    if (!open) return;
    loading.value = true;
    changed.value = false;
    try {
        order.value = await productsApi.webOrder(props.kind);
    } catch {
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo cargar el orden de la web', life: 3000 });
        visible.value = false;
    } finally {
        loading.value = false;
    }
});

/** Mueve un elemento una posición arriba (-1) o abajo (+1) en su lista. */
function move<T>(list: T[], index: number, step: -1 | 1): void {
    const target = index + step;
    if (target < 0 || target >= list.length) return;
    [list[index], list[target]] = [list[target], list[index]];
    changed.value = true;
}

function onReorder(list: WebOrderItem[], e: DataTableRowReorderEvent): void {
    list.splice(0, list.length, ...(e.value as WebOrderItem[]));
    changed.value = true;
}

async function save(): Promise<void> {
    saving.value = true;
    try {
        const categories = order.value.categories.map((c) => c.id);
        const items = groups.value.flatMap((g) => g.items).map((i) => i.id);
        const { message } = await productsApi.saveWebOrder(props.kind, categories, items);
        toast.add({ severity: 'success', summary: 'Orden guardado', detail: message, life: 3000 });
        visible.value = false;
    } catch (e) {
        const ax = e as AxiosError<{ message?: string }>;
        toast.add({ severity: 'error', summary: 'No se guardó', detail: ax.response?.data?.message, life: 4000 });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:visible="visible" modal :header="`Orden en la web · ${words.page}`" :style="{ width: '640px' }" :breakpoints="{ '700px': '96vw' }">
        <p class="mb-4 text-sm text-slate-500">
            Así se ven en sinexcusas.org.pe › {{ words.page }}, de arriba hacia abajo. Arrastra por
            <i class="pi pi-bars text-xs"></i> o usa las flechas para decidir qué va primero. Lo que publiques después aparece al final de su categoría.
        </p>

        <div v-if="loading" class="py-10 text-center text-slate-400"><i class="pi pi-spin pi-spinner text-2xl"></i></div>

        <div v-else-if="!groups.length" class="py-10 text-center text-sm text-slate-400">
            <i class="pi pi-globe mb-2 block text-3xl"></i>
            Todavía no hay {{ words.many }} publicados en la web.
        </div>

        <div v-else class="space-y-4">
            <section
                v-for="group in groups"
                :key="group.key"
                class="rounded-xl border border-[var(--surface-border)]"
                :class="{ 'border-dashed': group.name === null }"
            >
                <header class="flex items-center gap-2 border-b border-[var(--surface-border)] px-3 py-2">
                    <template v-if="group.name !== null">
                        <span class="grid h-6 w-6 place-items-center rounded-full bg-slate-100 text-xs font-semibold dark:bg-white/10">{{ group.position + 1 }}</span>
                        <span class="flex-1 text-sm font-semibold uppercase tracking-wider">{{ group.name }}</span>
                        <template v-if="order.categories.length > 1">
                            <Button
                                icon="pi pi-arrow-up"
                                text
                                rounded
                                size="small"
                                :disabled="group.position === 0"
                                :aria-label="`Subir la categoría ${group.name}`"
                                v-tooltip.top="'Subir categoría'"
                                @click="move(order.categories, group.position, -1)"
                            />
                            <Button
                                icon="pi pi-arrow-down"
                                text
                                rounded
                                size="small"
                                :disabled="group.position === order.categories.length - 1"
                                :aria-label="`Bajar la categoría ${group.name}`"
                                v-tooltip.top="'Bajar categoría'"
                                @click="move(order.categories, group.position, 1)"
                            />
                        </template>
                    </template>
                    <span v-else class="flex-1 text-sm">
                        <span class="font-semibold uppercase tracking-wider">Sin categoría</span>
                        <span class="ml-2 text-xs text-slate-400">siempre al final, en «{{ words.other }}»</span>
                    </span>
                </header>

                <DataTable :value="group.items" data-key="id" :show-headers="false" size="small" class="text-sm" @row-reorder="(e) => onReorder(group.items, e)">
                    <Column row-reorder header-style="width:2.5rem" />
                    <Column>
                        <template #body="{ data, index }">
                            <div class="flex items-center gap-3">
                                <span class="w-5 text-right text-xs text-slate-400">{{ index + 1 }}</span>
                                <span class="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-md bg-slate-100 dark:bg-white/5">
                                    <img v-if="data.image_url" :src="data.image_url" class="h-full w-full object-cover" alt="" loading="lazy" />
                                    <i v-else class="pi pi-image text-slate-400"></i>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium">{{ data.name }}</span>
                                    <span class="text-xs text-slate-400">
                                        {{ money(data.promotion_price ?? data.price) }}
                                        <Tag v-if="data.on_promotion" value="Promoción" severity="danger" class="ml-1 !px-1.5 !py-0 !text-[10px]" />
                                    </span>
                                </span>
                            </div>
                        </template>
                    </Column>
                    <Column header-style="width:5.5rem">
                        <template #body="{ index }">
                            <div class="flex justify-end">
                                <Button icon="pi pi-chevron-up" text rounded size="small" :disabled="index === 0" aria-label="Subir" @click="move(group.items, index, -1)" />
                                <Button
                                    icon="pi pi-chevron-down"
                                    text
                                    rounded
                                    size="small"
                                    :disabled="index === group.items.length - 1"
                                    aria-label="Bajar"
                                    @click="move(group.items, index, 1)"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </section>
        </div>

        <template #footer>
            <Button label="Cancelar" text @click="visible = false" />
            <Button label="Guardar orden" icon="pi pi-check" :loading="saving" :disabled="loading || !groups.length || !changed" @click="save" />
        </template>
    </Dialog>
</template>
