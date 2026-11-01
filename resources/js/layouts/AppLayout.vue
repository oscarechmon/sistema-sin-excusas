<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Menu from 'primevue/menu';
import AppSidebar from './AppSidebar.vue';
import AppointmentBell from '@/components/agenda/AppointmentBell.vue';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';

const auth = useAuthStore();
const ui = useUiStore();
const route = useRoute();
const router = useRouter();

// Breadcrumbs derivados del título de la ruta actual.
const breadcrumb = computed(() => (route.meta.title as string) ?? 'Inicio');

const userMenu = ref();
const userMenuItems = [
    { label: 'Mi perfil', icon: 'pi pi-user', command: () => router.push('/profile') },
    { separator: true },
    {
        label: 'Cerrar sesión',
        icon: 'pi pi-sign-out',
        command: async () => {
            await auth.logout();
            router.push('/login');
        },
    },
];

const initials = computed(() =>
    (auth.user?.name ?? '?')
        .split(' ')
        .map((p) => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);
</script>

<template>
    <div class="flex h-screen overflow-hidden bg-[var(--surface-ground)] text-slate-800 dark:text-slate-100">
        <!-- Sidebar de escritorio -->
        <div class="hidden lg:block">
            <AppSidebar />
        </div>

        <!-- Sidebar móvil (drawer) -->
        <transition name="fade">
            <div v-if="ui.sidebarMobileOpen" class="fixed inset-0 z-40 lg:hidden">
                <div class="absolute inset-0 bg-black/40" @click="ui.toggleMobileSidebar(false)"></div>
                <div class="absolute left-0 top-0 h-full"><AppSidebar /></div>
            </div>
        </transition>

        <div class="flex flex-1 flex-col overflow-hidden">
            <!-- Topbar -->
            <header
                class="flex h-16 shrink-0 items-center gap-3 border-b border-[var(--surface-border)] bg-[var(--surface-card)] px-4"
            >
                <button
                    type="button"
                    class="grid h-9 w-9 place-items-center rounded-lg hover:bg-brand-50 lg:hidden dark:hover:bg-white/5"
                    @click="ui.toggleMobileSidebar()"
                >
                    <i class="pi pi-bars"></i>
                </button>

                <!-- Breadcrumbs -->
                <div class="flex items-center gap-2 text-sm">
                    <i class="pi pi-home text-slate-400"></i>
                    <i class="pi pi-angle-right text-xs text-slate-400"></i>
                    <span class="font-semibold">{{ breadcrumb }}</span>
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <!-- Buscador global (placeholder) -->
                    <div class="hidden items-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm text-slate-400 md:flex">
                        <i class="pi pi-search text-xs"></i>
                        <span>Buscar…</span>
                    </div>

                    <!-- Toggle tema -->
                    <button
                        type="button"
                        class="grid h-9 w-9 place-items-center rounded-lg hover:bg-brand-50 dark:hover:bg-white/5"
                        @click="ui.toggleDark()"
                        v-tooltip.bottom="ui.dark ? 'Modo claro' : 'Modo oscuro'"
                    >
                        <i class="pi" :class="ui.dark ? 'pi-sun' : 'pi-moon'"></i>
                    </button>

                    <!-- Citas de hoy y recordatorios -->
                    <AppointmentBell v-if="auth.can('appointments.view')" />

                    <!-- Usuario -->
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg py-1 pl-1 pr-2 hover:bg-brand-50 dark:hover:bg-white/5"
                        @click="userMenu.toggle($event)"
                    >
                        <span class="grid h-8 w-8 place-items-center rounded-full bg-brand-500 text-xs font-semibold text-white">
                            {{ initials }}
                        </span>
                        <span class="hidden text-sm font-medium md:block">{{ auth.user?.name }}</span>
                        <i class="pi pi-angle-down text-xs text-slate-400"></i>
                    </button>
                    <Menu ref="userMenu" :model="userMenuItems" :popup="true" />
                </div>
            </header>

            <!-- Contenido -->
            <main class="flex-1 overflow-y-auto p-4 md:p-6">
                <router-view v-slot="{ Component }">
                    <transition name="fade" mode="out-in">
                        <component :is="Component" />
                    </transition>
                </router-view>
            </main>
        </div>
    </div>
</template>
