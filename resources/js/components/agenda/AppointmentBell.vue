<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import Popover from 'primevue/popover';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import { useAuthStore } from '@/stores/auth';
import { minutesUntil, useTodayAppointmentsStore } from '@/stores/todayAppointments';
import { visitLabel, type Appointment } from '@/services/agenda';
import TodayAppointmentList from './TodayAppointmentList.vue';

/**
 * Campana de la barra superior: las citas de hoy por atender, un aviso con el
 * resumen del día la primera vez que se entra y un recordatorio antes de cada cita.
 */

/** Con cuánta anticipación sale el recordatorio de cada cita. */
const REMINDER_MINUTES = 30;
const REMINDED_KEY = 'sistema.agenda.recordadas';

const auth = useAuthStore();
const store = useTodayAppointmentsStore();
const router = useRouter();
const toast = useToast();

const panel = ref();
const summary = ref(false);

const dayTitle = computed(() => new Date(`${store.date}T00:00:00`).toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' }));
const pendingCount = computed(() => store.open.length);
const badge = computed(() => (pendingCount.value > 9 ? '9+' : String(pendingCount.value)));
const sections = computed(() => [
    { label: 'Próximas', items: store.upcoming },
    { label: 'Pasó la hora y sigue sin atender', items: store.overdue },
].filter((s) => s.items.length));

const startsIn = (a: Appointment): number => minutesUntil(a.appointment_date, a.start_time, store.now);
const dueSoon = computed(() => store.upcoming.filter((a) => startsIn(a) > 0 && startsIn(a) <= REMINDER_MINUTES));

function goToAgenda(): void {
    panel.value?.hide();
    summary.value = false;
    router.push({ name: 'agenda' });
}

// --- Recordatorios -----------------------------------------------------------
const desktopSupported = 'Notification' in window;
const desktop = ref<NotificationPermission | 'unsupported'>(desktopSupported ? Notification.permission : 'unsupported');

async function enableDesktop(): Promise<void> {
    desktop.value = await Notification.requestPermission();
    if (desktop.value === 'granted') {
        toast.add({ severity: 'success', summary: 'Avisos activados', detail: 'También te avisaremos aunque estés en otra pestaña o programa.', life: 4000 });
    }
}

function describe(a: Appointment): string {
    const visit = a.visit_number ? ` (${visitLabel(a.visit_number)})` : '';
    return `${a.title || a.service?.name} — ${a.customer?.name}${visit} · con ${a.employee?.name}`;
}

/** Las ya avisadas hoy, compartidas entre pestañas para no repetir el aviso. */
function readReminded(): Set<string> {
    try {
        const saved = JSON.parse(localStorage.getItem(REMINDED_KEY) ?? '{}') as { date?: string; keys?: string[] };
        return new Set(saved.date === store.date ? saved.keys : []);
    } catch {
        return new Set();
    }
}
function saveReminded(keys: Set<string>): void {
    try {
        localStorage.setItem(REMINDED_KEY, JSON.stringify({ date: store.date, keys: [...keys] }));
    } catch {
        // Sin almacenamiento: a lo sumo se repite el aviso al recargar.
    }
}

function remind(): void {
    if (!dueSoon.value.length) return;
    const reminded = readReminded();
    for (const a of dueSoon.value) {
        // Si la cita se mueve a otra hora, se vuelve a avisar.
        const key = `${a.id}@${a.start_time}`;
        if (reminded.has(key)) continue;
        reminded.add(key);

        const title = `Cita en ${startsIn(a)} min · ${a.start_time}`;
        toast.add({ severity: 'info', summary: title, detail: describe(a), life: 30000 });
        if (desktop.value === 'granted' && document.hidden) {
            try {
                const n = new Notification(title, { body: describe(a), tag: key });
                n.onclick = () => {
                    window.focus();
                    n.close();
                };
            } catch {
                // Algunos navegadores móviles no permiten crearla así; queda el aviso en pantalla.
            }
        }
    }
    saveReminded(reminded);
}
watch(() => store.now, remind);

// --- Resumen del día: una vez por día y por usuario ------------------------------
watch(
    () => [store.loaded, store.date] as const,
    ([loaded, date]) => {
        if (!loaded || !pendingCount.value) return;
        const key = `sistema.agenda.resumen.${auth.user?.id ?? 0}`;
        try {
            if (localStorage.getItem(key) === date) return;
            localStorage.setItem(key, date);
        } catch {
            // Sin almacenamiento: se muestra igual.
        }
        summary.value = true;
    },
);

onMounted(() => store.start());
onBeforeUnmount(() => store.stop());
</script>

<template>
    <button
        type="button"
        class="relative grid h-9 w-9 place-items-center rounded-lg hover:bg-brand-50 dark:hover:bg-white/5"
        v-tooltip.bottom="'Citas de hoy'"
        @click="panel.toggle($event)"
    >
        <i class="pi pi-bell" :class="{ 'bell-ring': dueSoon.length }"></i>
        <span
            v-if="pendingCount"
            class="absolute -right-0.5 -top-0.5 grid h-[18px] min-w-[18px] place-items-center rounded-full px-1 text-[10px] font-bold leading-none text-white"
            :class="dueSoon.length ? 'bg-amber-500' : 'bg-rose-500'"
        >
            {{ badge }}
        </span>
    </button>

    <Popover ref="panel">
        <div class="w-[22rem] max-w-[calc(100vw-3rem)]">
            <div class="flex items-start justify-between gap-3 px-3 pb-1">
                <div>
                    <p class="font-semibold">Citas de hoy</p>
                    <p class="text-xs capitalize text-slate-500">{{ dayTitle }}</p>
                </div>
                <p class="text-right text-xs leading-snug text-slate-500">
                    <span class="font-semibold text-slate-700 dark:text-slate-200">{{ pendingCount }}</span> por atender<br />
                    {{ store.attendedCount }} {{ store.attendedCount === 1 ? 'atendida' : 'atendidas' }}
                </p>
            </div>

            <div class="max-h-[60vh] overflow-y-auto">
                <template v-for="section in sections" :key="section.label">
                    <p class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ section.label }}</p>
                    <TodayAppointmentList :items="section.items" :now="store.now" @select="goToAgenda" />
                </template>
                <div v-if="!sections.length" class="px-3 py-8 text-center text-sm text-slate-400">
                    <i class="pi pi-calendar mb-2 block text-2xl"></i>
                    {{ store.loaded ? 'No quedan citas por atender hoy.' : 'Cargando…' }}
                </div>
            </div>

            <div class="mt-2 space-y-2 border-t border-[var(--surface-border)] px-3 pt-3">
                <Button label="Ver agenda" icon="pi pi-calendar" size="small" class="w-full" @click="goToAgenda" />
                <button
                    v-if="desktop === 'default'"
                    type="button"
                    class="w-full text-center text-xs text-brand-600 hover:underline dark:text-brand-300"
                    @click="enableDesktop"
                >
                    <i class="pi pi-desktop mr-1 text-[11px]"></i>Avisarme también si estoy en otra pestaña
                </button>
                <p class="text-center text-[11px] text-slate-400">Te avisamos {{ REMINDER_MINUTES }} min antes de cada cita.</p>
            </div>
        </div>
    </Popover>

    <Dialog
        v-model:visible="summary"
        modal
        position="top"
        :draggable="false"
        :style="{ width: '32rem' }"
        :breakpoints="{ '640px': '94vw' }"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <span class="grid h-10 w-10 place-items-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300">
                    <i class="pi pi-calendar-clock text-lg"></i>
                </span>
                <div>
                    <p class="text-lg font-bold">Citas de hoy</p>
                    <p class="text-sm text-slate-500">
                        <span class="capitalize">{{ dayTitle }}</span> · {{ pendingCount }} por atender
                    </p>
                </div>
            </div>
        </template>

        <div class="-mx-3 max-h-[60vh] overflow-y-auto">
            <template v-for="section in sections" :key="section.label">
                <p class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ section.label }}</p>
                <TodayAppointmentList :items="section.items" :now="store.now" @select="goToAgenda" />
            </template>
        </div>
        <p class="mt-3 text-xs text-slate-400"><i class="pi pi-bell mr-1 text-[11px]"></i>Te avisaremos {{ REMINDER_MINUTES }} min antes de cada una. Las ves de nuevo en la campana.</p>

        <template #footer>
            <Button label="Entendido" text @click="summary = false" />
            <Button label="Ir a la agenda" icon="pi pi-calendar" @click="goToAgenda" />
        </template>
    </Dialog>
</template>

<style scoped>
/* La campana se mueve cuando hay una cita a punto de empezar. */
.bell-ring {
    animation: bell-ring 2.4s ease-in-out infinite;
    transform-origin: 50% 0;
}
@keyframes bell-ring {
    0%, 60%, 100% { transform: rotate(0); }
    10%, 30%, 50% { transform: rotate(14deg); }
    20%, 40% { transform: rotate(-14deg); }
}
@media (prefers-reduced-motion: reduce) {
    .bell-ring { animation: none; }
}
</style>
