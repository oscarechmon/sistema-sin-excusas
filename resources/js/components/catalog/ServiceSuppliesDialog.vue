<script setup lang="ts">
import { ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import Select from 'primevue/select';
import InputNumber from 'primevue/inputnumber';
import { AxiosError } from 'axios';
import { productsApi, type Product } from '@/services/catalog';
import { serviceSuppliesApi } from '@/services/agenda';

/**
 * Insumos que un servicio usa normalmente. Al registrar una atención de ese
 * servicio se proponen con estas cantidades (se pueden ajustar).
 */
const props = defineProps<{ service: Product | null }>();
const visible = defineModel<boolean>('visible', { required: true });

const toast = useToast();
const supplies = ref<Product[]>([]);
const lines = ref<{ supply_id: number | null; default_quantity: number }[]>([]);
const loading = ref(false);
const saving = ref(false);

watch(visible, async (open) => {
    if (!open || !props.service) return;
    loading.value = true;
    try {
        const [catalog, current] = await Promise.all([
            supplies.value.length ? Promise.resolve(null) : productsApi.list({ type: 'product', is_active: 1, per_page: 100, sort_by: 'name', sort_dir: 'asc' }),
            serviceSuppliesApi.get(props.service.id),
        ]);
        if (catalog) supplies.value = catalog.data;
        lines.value = current.map((s) => ({ supply_id: s.supply_id, default_quantity: s.default_quantity }));
    } finally {
        loading.value = false;
    }
});

async function save(): Promise<void> {
    if (!props.service) return;
    saving.value = true;
    try {
        await serviceSuppliesApi.save(
            props.service.id,
            lines.value.filter((l) => l.supply_id && l.default_quantity > 0) as { supply_id: number; default_quantity: number }[],
        );
        toast.add({ severity: 'success', summary: 'Insumos guardados', life: 2000 });
        visible.value = false;
    } catch (e) {
        const ax = e as AxiosError<{ message?: string }>;
        toast.add({ severity: 'warn', summary: 'No se guardó', detail: ax.response?.data?.message, life: 4000 });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:visible="visible" modal :header="`Insumos de «${service?.name ?? ''}»`" :style="{ width: '520px' }" :breakpoints="{ '560px': '96vw' }">
        <p class="mb-3 text-sm text-slate-500">Se proponen al registrar cada atención de este servicio y salen del stock del almacén por defecto.</p>
        <div v-if="loading" class="py-6 text-center text-slate-400"><i class="pi pi-spin pi-spinner"></i></div>
        <template v-else>
            <div v-for="(l, i) in lines" :key="i" class="mb-2 flex items-center gap-2">
                <Select v-model="l.supply_id" :options="supplies" option-label="name" option-value="id" filter class="min-w-0 flex-1" placeholder="Insumo" />
                <div class="w-24 shrink-0">
                    <InputNumber v-model="l.default_quantity" :min="0.01" :max-fraction-digits="2" fluid input-class="text-right" placeholder="Cant." />
                </div>
                <Button icon="pi pi-times" text rounded size="small" severity="danger" class="shrink-0" @click="lines.splice(i, 1)" />
            </div>
            <button type="button" class="text-sm text-brand-600 hover:underline" @click="lines.push({ supply_id: null, default_quantity: 1 })">
                <i class="pi pi-plus text-xs"></i> Agregar insumo
            </button>
        </template>
        <template #footer>
            <Button label="Cancelar" text @click="visible = false" />
            <Button label="Guardar" icon="pi pi-check" :loading="saving" @click="save" />
        </template>
    </Dialog>
</template>
