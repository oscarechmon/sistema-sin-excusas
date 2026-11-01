<script setup lang="ts">
import { computed } from 'vue';
import { visitLabel, type Appointment } from '@/services/agenda';
import { minutesUntil } from '@/stores/todayAppointments';

const props = defineProps<{ items: Appointment[]; now: number }>();
const emit = defineEmits<{ select: [appointment: Appointment] }>();

/** Etiqueta de cuánto falta: solo para lo que está encima (≤ 1 h), en curso o atrasado. */
function chipFor(a: Appointment): { label: string; class: string } | null {
    if (minutesUntil(a.appointment_date, a.end_time, props.now) <= 0) {
        return { label: 'Pasó la hora', class: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' };
    }
    const toStart = minutesUntil(a.appointment_date, a.start_time, props.now);
    if (toStart <= 0) return { label: 'Ahora', class: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' };
    if (toStart <= 30) return { label: `en ${toStart} min`, class: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' };
    if (toStart <= 60) return { label: `en ${toStart} min`, class: 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' };
    return null;
}

const rows = computed(() => props.items.map((a) => ({ a, chip: chipFor(a) })));
</script>

<template>
    <ul class="space-y-0.5">
        <li v-for="{ a, chip } in rows" :key="a.id">
            <button
                type="button"
                class="flex w-full items-start gap-3 rounded-xl px-3 py-2.5 text-left hover:bg-brand-50 dark:hover:bg-white/5"
                @click="emit('select', a)"
            >
                <div class="w-11 shrink-0 pt-0.5 text-center leading-tight">
                    <p class="text-sm font-bold">{{ a.start_time }}</p>
                    <p class="text-[11px] text-slate-400">{{ a.end_time }}</p>
                </div>
                <div class="min-w-0 flex-1 leading-snug">
                    <p class="truncate text-sm font-semibold">{{ a.title || a.service?.name }}</p>
                    <p class="truncate text-xs text-slate-600 dark:text-slate-300">
                        {{ a.customer?.name }}
                        <span v-if="a.visit_number" :class="a.visit_number === 1 ? 'font-semibold text-emerald-600 dark:text-emerald-400' : 'text-brand-600 dark:text-brand-300'">
                            · {{ visitLabel(a.visit_number) }}
                        </span>
                    </p>
                    <p class="truncate text-[11px] text-slate-400">
                        <template v-if="a.title">{{ a.service?.name }} · </template>{{ a.employee?.name }}<template v-if="a.status === 'pending'"> · sin confirmar</template>
                    </p>
                </div>
                <span v-if="chip" class="mt-0.5 shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="chip.class">
                    {{ chip.label }}
                </span>
            </button>
        </li>
    </ul>
</template>
