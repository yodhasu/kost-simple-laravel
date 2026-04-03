<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Download,
    LayoutDashboard,
    LogOut,
    ReceiptText,
    Settings,
    Users,
} from 'lucide-vue-next';
import ConfirmModal from '@/components/ConfirmModal.vue';

type SharedPageProps = {
    component?: string;
    regions?: Array<{
        id: string;
        name: string;
    }>;
    selectedRegionId?: string;
    auth: {
        user?: {
            name?: string;
            email?: string;
        } | null;
    };
    appContext?: {
        viewerRole?: string;
    };
};

const page = usePage<SharedPageProps>();
const mobileOpen = ref(false);
const logoutConfirmOpen = ref(false);

const handleLogout = () => {
    logoutConfirmOpen.value = false;
    router.post('/logout');
};

const allDesktopNavItems = [
    { label: 'Beranda', href: '/dashboard', icon: LayoutDashboard },
    { label: 'Daftar Penyewa', href: '/tenants', icon: Users },
    { label: 'Aksi & Pembayaran', href: '/payments', icon: ReceiptText },
    { label: 'Ekspor Data', href: '/export', icon: Download },
    { label: 'Pengaturan', href: '/settings', icon: Settings },
];

const allMobileNavItems = [
    { label: 'Daftar Penyewa', href: '/tenants', icon: Users },
    { label: 'Aksi & Pembayaran', href: '/payments', icon: ReceiptText },
    { label: 'Beranda', href: '/dashboard', icon: LayoutDashboard },
    { label: 'Ekspor Data', href: '/export', icon: Download },
    { label: 'Pengaturan', href: '/settings', icon: Settings },
];

const isAdminRole = computed(() => viewerRole.value === 'admin');
const desktopNavItems = computed(() =>
    isAdminRole.value
        ? allDesktopNavItems.filter((item) => item.href !== '/settings')
        : allDesktopNavItems,
);
const mobileNavItems = computed(() => {
    if (isAdminRole.value) {
        // Admin has 4 items — use desktop order (Beranda first) for cleaner mobile layout
        return allDesktopNavItems.filter((item) => item.href !== '/settings');
    }

    return allMobileNavItems;
});

const currentPath = computed(() => page.url);
const currentComponent = computed(() => page.component);
const viewerName = computed(() => page.props.auth.user?.name ?? 'Owner Kost');
const viewerEmail = computed(() => page.props.auth.user?.email ?? 'owner@kost.local');
const viewerRole = computed(() => page.props.appContext?.viewerRole ?? 'owner');
const isDashboardPage = computed(() => currentComponent.value === 'KostDashboard' || currentPath.value.startsWith('/dashboard'));
const dashboardRegions = computed(() => page.props.regions ?? []);
const dashboardSelectedRegion = ref(page.props.selectedRegionId ?? 'all');
const viewerInitials = computed(() =>
    viewerName.value
        .split(' ')
        .filter(Boolean)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('')
        .slice(0, 2),
);
const currentDate = computed(() =>
    new Intl.DateTimeFormat('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date()),
);

watch(
    () => page.props.selectedRegionId,
    (value) => {
        if (value) {
            dashboardSelectedRegion.value = value;
        }
    },
);

watch(dashboardSelectedRegion, (regionId, previousRegionId) => {
    if (!isDashboardPage.value || regionId === previousRegionId) {
        return;
    }

    router.visit('/dashboard', {
        method: 'get',
        data: {
            region_id: regionId,
        },
        only: ['selectedRegionId', 'stats', 'trendBars', 'financeOverview'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
});
</script>

<template>
    <Head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="anonymous" />
        <link
            href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
            rel="stylesheet"
        />
    </Head>

    <div class="min-h-screen bg-[linear-gradient(180deg,#edf1f4_0%,#e6ebef_100%)] text-slate-900 [font-family:'Plus_Jakarta_Sans',ui-sans-serif,system-ui,sans-serif]">
        <!-- Desktop sidebar overlay (hidden on mobile now) -->
        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-30 hidden bg-slate-950/40 lg:block"
            @click="mobileOpen = false"
        />

        <!-- Sidebar: hidden on mobile, visible on lg+ -->
        <aside
            class="fixed inset-y-0 left-0 z-40 hidden w-[18.5rem] flex-col border-r border-slate-200 bg-white text-slate-900 shadow-[0_18px_40px_rgba(15,23,42,0.08)] lg:flex xl:w-80"
        >
            <div class="border-b border-slate-200 px-6 py-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-[0.3em] text-teal-700">Kost App</p>
                        <h1 class="mt-2 text-2xl font-extrabold tracking-tight">Kost Simple</h1>
                    </div>
                </div>

                <div class="mt-6 rounded-3xl border border-slate-200 bg-slate-50 p-4">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-12 items-center justify-center rounded-2xl bg-teal-400 font-bold text-slate-950"
                        >
                            {{ viewerInitials }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ viewerName }}</p>
                            <p class="truncate text-sm text-slate-500">{{ viewerEmail }}</p>
                        </div>
                    </div>
                    <p class="mt-4 inline-flex rounded-full bg-teal-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-teal-700">
                        {{ viewerRole }}
                    </p>
                </div>
            </div>

            <nav class="flex-1 space-y-2 px-4 py-6">
                <Link
                    v-for="item in desktopNavItems"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium transition"
                    :class="
                        currentPath === item.href
                            ? 'bg-[#dce9e8] text-teal-800 shadow-sm ring-1 ring-[#cfe0de]'
                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'
                    "
                >
                    <component :is="item.icon" class="size-5" />
                    <span>{{ item.label }}</span>
                </Link>
            </nav>

            <div class="border-t border-slate-200 px-4 py-4">
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-left text-sm font-medium text-rose-600 transition hover:bg-rose-50 hover:text-rose-700"
                >
                    <LogOut class="size-5" />
                    <span>Keluar</span>
                </Link>
            </div>
        </aside>

        <div class="lg:pl-74 xl:pl-80">
            <!-- Header: compact on mobile, full on desktop -->
            <header class="sticky top-0 z-20 border-b border-slate-200/90 bg-white/80 shadow-[0_10px_30px_rgba(15,23,42,0.04)] backdrop-blur">
                <div class="flex items-center justify-between px-3 py-2 lg:px-5 lg:py-4 xl:px-8">
                    <div class="flex items-center gap-2.5 lg:gap-2.5">
                        <div class="leading-tight">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-teal-700 lg:text-xs">Operasional</p>
                            <p class="hidden text-sm text-slate-500 lg:block">Monitor tenant, pembayaran, dan setup properti.</p>
                        </div>
                    </div>
                    <!-- Mobile logout button -->
                    <button
                        type="button"
                        class="flex size-8 items-center justify-center rounded-xl bg-rose-50 text-rose-500 transition active:scale-95 lg:hidden"
                        @click="logoutConfirmOpen = true"
                    >
                        <LogOut class="size-4" />
                        <span class="sr-only">Keluar</span>
                    </button>
                    <div class="hidden items-center gap-3 xl:flex">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                            {{ currentDate }}
                        </div>
                        <label
                            v-if="isDashboardPage"
                            class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5"
                        >
                            <span class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Region</span>
                            <select
                                v-model="dashboardSelectedRegion"
                                class="min-w-0 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm text-slate-700 focus:border-teal-500 focus:outline-none sm:min-w-48"
                            >
                                <option v-for="region in dashboardRegions" :key="region.id" :value="region.id">
                                    {{ region.name }}
                                </option>
                            </select>
                        </label>
                    </div>
                </div>
            </header>

            <main class="px-2.5 py-3 pb-[4.5rem] lg:px-5 lg:py-5 lg:pb-5 xl:px-8">
                <slot />
            </main>
        </div>

        <!-- Mobile bottom tab bar -->
        <nav class="fixed inset-x-0 bottom-0 z-50 flex items-stretch border-t border-slate-200/80 bg-white/95 backdrop-blur-md lg:hidden" style="padding-bottom: env(safe-area-inset-bottom, 0px)">
            <Link
                v-for="item in mobileNavItems"
                :key="'btab-' + item.href"
                :href="item.href"
                class="flex flex-1 flex-col items-center justify-center gap-0.5 py-1.5 text-center transition-colors"
                :class="
                    currentPath === item.href || currentPath.startsWith(item.href + '/')
                        ? 'text-teal-700'
                        : 'text-slate-400'
                "
            >
                <component :is="item.icon" class="size-5" />
                <span class="text-[10px] font-semibold leading-tight">{{ item.label.split(' ').pop() }}</span>
            </Link>
        </nav>

        <!-- Mobile logout confirmation modal -->
        <ConfirmModal
            :open="logoutConfirmOpen"
            title="Keluar dari Akun"
            description="Apakah Anda yakin ingin keluar dari akun ini?"
            confirm-label="Ya, Keluar"
            variant="danger"
            @update:open="logoutConfirmOpen = $event"
            @confirm="handleLogout"
        />
    </div>
</template>
