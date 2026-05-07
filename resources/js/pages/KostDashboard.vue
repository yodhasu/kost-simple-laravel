<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { BedDouble, CalendarDays, CircleDollarSign, DoorOpen, TrendingUp, Users } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import type { RegionOption, Viewer } from '@/types/kost';

type TrendBar = {
    label: string;
    income: number;
    expense: number;
};

type BreakdownItem = {
    label: string;
    value: number;
};

const props = defineProps<{
    viewer: Viewer;
    regions: RegionOption[];
    selectedRegionId: string;
    stats: {
        todayNetRevenue: number;
        tenantChangePercent: number;
        dpTotal: number;
        dpCount: number;
        activeTenants: number;
        emptyRooms: number;
        totalRooms: number;
        overdueTenants: number;
        overdueByKost: { kost_name: string; count: number }[];
    };
    trendBars: TrendBar[];
    financeOverview: {
        period: string;
        income_total: number;
        expense_total: number;
        income_by_kost: BreakdownItem[];
        expense_by_category: BreakdownItem[];
    };
}>();

const selectedRegion = ref(props.selectedRegionId);
const selectedRegionName = computed(() => {
    if (!selectedRegion.value || selectedRegion.value === 'all') {
        return 'Semua Region';
    }

    const found = props.regions.find((r: RegionOption) => r.id === selectedRegion.value);

    return found ? found.name : 'Semua Region';
});
const financeTab = ref<'income' | 'expense'>('income');
const showOverdueTooltip = ref(false);

const dismissOverdue = () => {
    showOverdueTooltip.value = false;
};

onMounted(() => document.addEventListener('click', dismissOverdue));
onUnmounted(() => document.removeEventListener('click', dismissOverdue));
const maxBarValue = computed(() =>
    Math.max(...props.trendBars.flatMap((item) => [item.income, item.expense]), 1),
);

const chartScale = computed(() => {
    const maxRb = Math.ceil(maxBarValue.value / 1000 / 100) * 100 || 400;
    const step = Math.ceil(maxRb / 4);

    return [0, step, step * 2, step * 3, step * 4];
});
const chartMax = computed(() => Math.max(maxBarValue.value, chartScale.value[chartScale.value.length - 1] * 1000));
const chartLinePositions = computed(() => chartScale.value.map((value: number) => (value / chartScale.value[chartScale.value.length - 1]) * 100));
const occupancyRate = computed(() => {
    if (props.stats.totalRooms <= 0) {
        return 0;
    }

    return Math.round((props.stats.activeTenants / props.stats.totalRooms) * 100);
});
const piePalette = ['#22c55e', '#06b6d4', '#3b82f6', '#8b5cf6', '#f59e0b', '#f97316'];

const incomeBreakdown = computed(() =>
    props.financeOverview.income_by_kost.map((item, index) => ({
        ...item,
        color: piePalette[index % piePalette.length],
    })),
);

const expenseBreakdown = computed(() =>
    props.financeOverview.expense_by_category.map((item, index) => ({
        ...item,
        color: piePalette[index % piePalette.length],
    })),
);

const activeBreakdown = computed(() =>
    financeTab.value === 'income' ? incomeBreakdown.value : expenseBreakdown.value,
);

const activeBreakdownTotal = computed(() =>
    activeBreakdown.value.reduce((sum, item) => sum + item.value, 0),
);

const pieStyle = computed(() => {
    const total = activeBreakdownTotal.value;

    if (total <= 0 || activeBreakdown.value.length === 0) {
        return {
            background: 'conic-gradient(#233256 0deg 360deg)',
        };
    }

    let current = 0;
    const segments = activeBreakdown.value.map((item) => {
        const start = current;
        const angle = (item.value / total) * 360;
        current += angle;

        return `${item.color} ${start}deg ${current}deg`;
    });

    return {
        background: `conic-gradient(${segments.join(', ')})`,
    };
});

const currency = (value: number) =>
    new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(value);

const compactCurrency = (value: number) => {
    if (value >= 1_000_000_000) {
        return `Rp ${(value / 1_000_000_000).toFixed(1)} M`;
    }

    if (value >= 1_000_000) {
        return `Rp ${(value / 1_000_000).toFixed(1)} Jt`;
    }

    if (value >= 1_000) {
        return `Rp ${(value / 1_000).toFixed(0)} Rb`;
    }

    return currency(value);
};

watch(
    () => props.selectedRegionId,
    (value) => {
        if (value) {
            selectedRegion.value = value;
        }
    },
);

watch(selectedRegion, (regionId, previousRegionId) => {
    if (regionId === previousRegionId) {
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
    <Head title="Beranda" />

    <section class="space-y-4">
        <div class="space-y-3 lg:hidden md:space-y-3.5">
            <!-- Compact mobile control: region only (no mode toggle) -->
            <div class="flex items-center gap-2 px-0.5 md:px-1">
                <select
                    v-model="selectedRegion"
                    class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 focus:border-teal-500 focus:outline-none md:rounded-md md:px-2.5 md:py-1.5 md:text-sm"
                >
                    <option v-for="region in regions" :key="region.id" :value="region.id">
                        {{ region.name }}
                    </option>
                </select>
            </div>

            <!-- Revenue + DP: side-by-side compact -->
            <div class="grid grid-cols-1 gap-2 md:gap-3.5 sm:grid-cols-2">
                <article class="rounded-xl bg-white p-2.5 shadow-[0_8px_20px_rgba(15,23,42,0.06)] ring-1 ring-slate-200/80 md:rounded-2xl md:p-3.5">
                    <div class="flex items-center justify-between">
                        <p class="text-[10px] font-semibold text-slate-500 md:text-[9px] md:tracking-wide">Total Pendapatan Bersih</p>
                        <div class="rounded-lg bg-emerald-100 p-1 md:rounded-md md:p-1.5">
                            <CircleDollarSign class="size-3 text-emerald-700 md:size-3.5 lg:size-4" />
                        </div>
                    </div>
                    <p class="mt-1 text-lg font-extrabold leading-tight text-slate-950 md:mt-1.5 md:text-[1.44rem]">{{ compactCurrency(stats.todayNetRevenue) }}</p>
                    <p class="mt-0.5 text-[10px] font-medium text-slate-500 md:mt-1 md:text-sm">untuk {{ selectedRegionName }}</p>
                </article>
                <article class="rounded-xl bg-white p-2.5 shadow-[0_8px_20px_rgba(15,23,42,0.06)] ring-1 ring-slate-200/80 md:rounded-2xl md:p-3.5">
                    <div class="flex items-center justify-between">
                        <p class="text-[10px] font-semibold text-slate-500 md:text-[9px] md:tracking-wide">Total DP</p>
                        <div class="rounded-lg bg-amber-100 p-1 md:rounded-md md:p-1.5">
                            <CircleDollarSign class="size-3 text-amber-700 md:size-3.5 lg:size-4" />
                        </div>
                    </div>
                    <p class="mt-1 text-lg font-extrabold leading-tight text-slate-950 md:mt-1.5 md:text-[1.44rem]">{{ compactCurrency(stats.dpTotal) }}</p>
                    <p class="mt-0.5 text-[10px] font-medium text-amber-700 md:mt-1 md:text-sm">{{ stats.dpCount }} tenant DP</p>
                </article>
            </div>

            <!-- Status Hunian -->
            <article class="rounded-xl bg-white p-2.5 shadow-[0_8px_20px_rgba(15,23,42,0.06)] ring-1 ring-slate-200/80 md:rounded-2xl md:p-3.5 md:pb-4">
                <p class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500 md:text-[9px] md:tracking-[0.2em]">Status Hunian</p>
                <div class="mt-1.5 grid grid-cols-3 gap-1.5 md:mt-2.5 md:gap-3.5">
                    <div class="group relative rounded-lg bg-linear-to-br from-emerald-50 to-emerald-100 p-2 ring-1 ring-emerald-100 md:rounded-lg md:p-2.5">
                        <div class="flex items-center gap-1.5 md:gap-1.5">
                            <Users class="size-3 text-emerald-600 md:size-3.5" />
                            <p class="text-[10px] font-medium text-emerald-700 md:text-sm">Aktif</p>
                        </div>
                        <p class="mt-1 text-base font-extrabold leading-none text-emerald-600 md:mt-1.5 md:text-xl">{{ stats.activeTenants }}</p>
                        <!-- Overdue badge -->
                        <div v-if="stats.overdueTenants > 0" class="absolute -right-1 -top-1 flex size-4 items-center justify-center rounded-full bg-red-500 shadow-sm md:size-5" @click.stop="showOverdueTooltip = !showOverdueTooltip">
                            <span class="text-[8px] font-bold text-white md:text-[10px]">!</span>
                        </div>
                        <div v-if="stats.overdueTenants > 0 && showOverdueTooltip" class="absolute -top-1 left-full z-50 ml-1 rounded-md bg-slate-900 px-2.5 py-1.5 text-[10px] font-medium text-white shadow-lg md:text-xs">
                            <p v-for="item in stats.overdueByKost" :key="'m-ov-' + item.kost_name" class="whitespace-nowrap leading-relaxed">
                                {{ item.count }} penyewa telat di {{ item.kost_name }}
                            </p>
                        </div>
                    </div>
                    <div class="rounded-lg bg-linear-to-br from-amber-50 to-amber-100 p-2 ring-1 ring-amber-100 md:rounded-lg md:p-2.5">
                        <div class="flex items-center gap-1.5 md:gap-1.5">
                            <DoorOpen class="size-3 text-amber-600 md:size-3.5" />
                            <p class="text-[10px] font-medium text-amber-700 md:text-sm">Kosong</p>
                        </div>
                        <p class="mt-1 text-base font-extrabold leading-none text-amber-600 md:mt-1.5 md:text-xl">{{ stats.emptyRooms }}</p>
                    </div>
                    <div class="rounded-lg bg-linear-to-br from-sky-50 to-sky-100 p-2 ring-1 ring-sky-100 md:rounded-lg md:p-2.5">
                        <div class="flex items-center gap-1.5 md:gap-1.5">
                            <BedDouble class="size-3 text-sky-600 md:size-3.5" />
                            <p class="text-[10px] font-medium text-sky-700 md:text-sm">Kamar</p>
                        </div>
                        <p class="mt-1 text-base font-extrabold leading-none text-sky-600 md:mt-1.5 md:text-xl">{{ stats.totalRooms }}</p>
                    </div>
                </div>
            </article>

            <!-- Finance summary: compact 2-col -->
            <div class="grid gap-2 rounded-xl bg-white p-2.5 shadow-[0_8px_20px_rgba(15,23,42,0.06)] ring-1 ring-slate-200/80 md:gap-3.5 md:rounded-2xl md:p-3.5 sm:grid-cols-2">
                <div class="rounded-lg bg-linear-to-r from-emerald-50 to-emerald-100 p-2 md:rounded-lg md:p-3.5">
                    <p class="text-[10px] font-medium text-emerald-800 md:text-[9px] md:font-semibold">Penghasilan</p>
                    <p class="mt-0.5 text-sm font-extrabold text-emerald-700 md:mt-1 md:text-lg">{{ compactCurrency(props.financeOverview.income_total) }}</p>
                </div>
                <div class="rounded-lg bg-linear-to-r from-amber-50 to-amber-100 p-2 md:rounded-lg md:p-3.5">
                    <p class="text-[10px] font-medium text-amber-800 md:text-[9px] md:font-semibold">Pengeluaran</p>
                    <p class="mt-0.5 text-sm font-extrabold text-amber-700 md:mt-1 md:text-lg">{{ compactCurrency(props.financeOverview.expense_total) }}</p>
                </div>
            </div>

            <!-- Compact cashflow chart -->
            <section class="rounded-xl bg-white p-2.5 shadow-[0_8px_20px_rgba(15,23,42,0.06)] ring-1 ring-slate-200/80 md:rounded-2xl md:p-3.5 md:pb-4">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-bold text-slate-950 md:text-base">Cashflow Mingguan</h4>
                    <div class="flex items-center gap-2 text-[10px] md:gap-2.5 md:text-xs">
                        <div class="flex items-center gap-1 text-slate-500 md:gap-1">
                            <span class="size-2 rounded bg-amber-400 md:size-1.5 md:rounded-sm" />
                            <span class="font-medium">Out</span>
                        </div>
                        <div class="flex items-center gap-1 text-slate-500 md:gap-1">
                            <span class="size-2 rounded bg-emerald-400 md:size-1.5 md:rounded-sm" />
                            <span class="font-medium">In</span>
                        </div>
                    </div>
                </div>

                <div class="mt-2 rounded-lg border border-slate-200 bg-slate-50/90 p-2 md:mt-3.5 md:rounded-lg md:border-2 md:p-3.5">
                    <div class="relative h-20 md:h-36">
                        <div
                            v-for="position in chartLinePositions"
                            :key="'m-line-' + position"
                            class="absolute inset-x-0 border-t border-dashed border-slate-200"
                            :style="{ bottom: `${position}%` }"
                        />
                        <div class="absolute inset-0 flex items-end justify-between gap-1 pt-2 md:gap-1.5 md:pt-2.5">
                            <div
                                v-for="item in trendBars"
                                :key="`mobile-${item.label}`"
                                class="flex min-w-0 flex-1 flex-col items-center justify-end gap-1 md:gap-1.5"
                            >
                                <div class="flex h-14 w-full items-end justify-center gap-0.5 md:h-full md:gap-1">
                                    <div class="flex h-full w-3 flex-col justify-end md:w-3.5">
                                        <div
                                            class="w-full rounded-t bg-amber-400"
                                            :style="{ height: `${Math.max((item.expense / chartMax) * 100, 5)}%` }"
                                        />
                                    </div>
                                    <div class="flex h-full w-3 flex-col justify-end md:w-3.5">
                                        <div
                                            class="w-full rounded-t bg-emerald-400"
                                            :style="{ height: `${Math.max((item.income / chartMax) * 100, 5)}%` }"
                                        />
                                    </div>
                                </div>
                                <p class="text-[9px] font-semibold text-slate-600 md:mt-1 md:text-sm">{{ item.label }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Compact breakdown -->
            <section class="rounded-xl bg-white p-2.5 shadow-[0_8px_20px_rgba(15,23,42,0.06)] ring-1 ring-slate-200/80 md:rounded-2xl md:p-3.5 md:pb-4">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-bold text-slate-950 md:text-base">
                        {{ financeTab === 'income' ? 'Penghasilan' : 'Pengeluaran' }}
                    </h4>
                    <div class="inline-flex rounded-md bg-slate-100 p-0.5 md:rounded-sm md:p-1">
                        <button
                            type="button"
                            class="rounded px-2 py-1 text-[10px] font-semibold transition md:rounded-sm md:px-2.5 md:py-1 md:text-xs"
                            :class="financeTab === 'income' ? 'bg-slate-900 text-white' : 'text-slate-500 hover:bg-slate-200/50'"
                            @click="financeTab = 'income'"
                        >
                            Income
                        </button>
                        <button
                            type="button"
                            class="rounded px-2 py-1 text-[10px] font-semibold transition md:rounded-sm md:px-2.5 md:py-1 md:text-xs"
                            :class="financeTab === 'expense' ? 'bg-slate-900 text-white' : 'text-slate-500 hover:bg-slate-200/50'"
                            @click="financeTab = 'expense'"
                        >
                            Expense
                        </button>
                    </div>
                </div>

                <div class="mt-2 flex flex-col gap-3 md:mt-3.5 md:gap-5 lg:flex-row">
                    <!-- Mini pie -->
                    <div class="flex shrink-0 flex-col items-center md:justify-center">
                        <div class="relative flex h-24 w-24 shrink-0 items-center justify-center rounded-full md:h-40 md:w-40" :style="pieStyle">
                            <div class="absolute inset-[22%] rounded-full bg-white shadow-inner" />
                            <div class="relative z-10 text-center">
                                <p class="text-[9px] font-bold text-slate-900 md:text-sm">{{ compactCurrency(activeBreakdownTotal) }}</p>
                            </div>
                        </div>
                    </div>
                    <!-- Legend list -->
                    <div class="min-w-0 flex-1 space-y-1 md:flex md:flex-col md:justify-center md:space-y-1.5">
                        <div
                            v-for="item in activeBreakdown"
                            :key="`m-bd-${financeTab}-${item.label}`"
                            class="flex items-center justify-between gap-1 rounded-md bg-slate-50 px-2 py-1 md:rounded-md md:px-2.5 md:py-1.5"
                        >
                            <div class="flex min-w-0 items-center gap-1.5 md:gap-1.5">
                                <span class="size-2 shrink-0 rounded-full md:size-3.5" :style="{ backgroundColor: item.color }" />
                                <p class="truncate text-[10px] font-medium text-slate-700 md:text-sm">{{ item.label }}</p>
                            </div>
                            <p class="shrink-0 text-[10px] font-bold text-slate-900 md:text-sm">{{ compactCurrency(item.value) }}</p>
                        </div>
                        <div
                            v-if="activeBreakdown.length === 0"
                            class="rounded-md border border-dashed border-slate-200 px-2 py-3 text-center text-[10px] text-slate-500 md:rounded-md md:py-3.5 md:text-sm"
                        >
                            Belum ada data.
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="hidden space-y-6 lg:block">
            <!-- Row 1: Top Stats -->
            <div class="grid grid-cols-12 gap-6">
                <!-- Pendapatan -->
                <article class="col-span-4 rounded-[1.5rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-slate-900">Total Seluruh Pendapatan Bersih</h3>
                            <p class="mt-4 text-4xl font-extrabold text-slate-950">{{ currency(stats.todayNetRevenue) }}</p>
                            <p class="mt-2 text-sm font-medium text-slate-500">untuk {{ selectedRegionName }}</p>
                        </div>
                        <div class="rounded-full bg-emerald-100 p-3">
                            <CircleDollarSign class="size-6 text-emerald-700" />
                        </div>
                    </div>
                </article>

                <!-- Total DP -->
                <article class="col-span-3 rounded-[1.5rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-slate-900">Total DP</h3>
                            <p class="mt-4 text-4xl font-extrabold text-slate-950">{{ currency(stats.dpTotal) }}</p>
                            <p class="mt-2 text-sm font-medium text-amber-700">{{ stats.dpCount }} tenant masih DP</p>
                        </div>
                        <div class="rounded-full bg-amber-100 p-3">
                            <CircleDollarSign class="size-6 text-amber-700" />
                        </div>
                    </div>
                </article>

                <!-- Status Hunian -->
                <article class="col-span-5 rounded-[1.5rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Status Hunian</p>
                    <div class="mt-4 grid grid-cols-3 gap-4">
                        <div class="group relative rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-100/50">
                            <div class="flex items-center gap-2">
                                <Users class="size-4 text-emerald-600" />
                                <p class="text-xs font-medium text-emerald-700">Penyewa Aktif</p>
                            </div>
                            <p class="mt-2 text-3xl font-extrabold text-emerald-600">{{ stats.activeTenants }}</p>
                            <!-- Overdue badge -->
                            <div v-if="stats.overdueTenants > 0" class="absolute -right-1.5 -top-1.5 flex size-6 items-center justify-center rounded-full bg-red-500 shadow-sm">
                                <span class="text-xs font-bold text-white">!</span>
                            </div>
                            <div v-if="stats.overdueTenants > 0" class="pointer-events-none absolute -top-10 left-1/2 z-50 hidden -translate-x-1/2 rounded-lg bg-slate-900 px-3 py-2 text-xs font-medium text-white shadow-lg group-hover:block">
                                <p v-for="item in stats.overdueByKost" :key="'d-ov-' + item.kost_name" class="whitespace-nowrap leading-relaxed">
                                    {{ item.count }} penyewa telat di {{ item.kost_name }}
                                </p>
                            </div>
                        </div>
                        <div class="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-100/50">
                            <div class="flex items-center gap-2">
                                <DoorOpen class="size-4 text-amber-600" />
                                <p class="text-xs font-medium text-amber-700">Kamar Kosong</p>
                            </div>
                            <p class="mt-2 text-3xl font-extrabold text-amber-600">{{ stats.emptyRooms }}</p>
                        </div>
                        <div class="rounded-2xl bg-sky-50 p-4 ring-1 ring-sky-100/50">
                            <div class="flex items-center gap-2">
                                <BedDouble class="size-4 text-sky-600" />
                                <p class="text-xs font-medium text-sky-700">Total Kamar</p>
                            </div>
                            <p class="mt-2 text-3xl font-extrabold text-sky-600">{{ stats.totalRooms }}</p>
                        </div>
                    </div>
                </article>
            </div>

            <!-- Lower Section: Finance Overview -->
            <article class="rounded-[1.5rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-teal-700">Tren Pendapatan</p>
                        <h3 class="mt-1 text-2xl font-bold text-slate-950">Tracker keuangan bulan ini</h3>
                        <div class="mt-3 inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-100">
                            <TrendingUp class="size-3.5" />
                            Hunian {{ occupancyRate }}%
                        </div>
                    </div>
                    <div class="inline-flex items-center gap-2 rounded-xl bg-slate-50 px-4 py-2 ring-1 ring-slate-200">
                        <CalendarDays class="size-5 text-slate-500" />
                        <span class="text-sm font-medium text-slate-700">{{ props.financeOverview.period }}</span>
                    </div>
                </div>

                <!-- Green / Yellow Bars -->
                <div class="mt-6 grid grid-cols-2 gap-6">
                    <div class="rounded-2xl bg-emerald-50 p-6 ring-1 ring-emerald-100">
                        <p class="text-sm font-medium text-emerald-800">Penghasilan</p>
                        <p class="mt-2 text-3xl font-extrabold text-emerald-700">{{ currency(props.financeOverview.income_total) }}</p>
                    </div>
                    <div class="rounded-2xl bg-amber-50 p-6 ring-1 ring-amber-100">
                        <p class="text-sm font-medium text-amber-800">Pengeluaran</p>
                        <p class="mt-2 text-3xl font-extrabold text-amber-700">{{ currency(props.financeOverview.expense_total) }}</p>
                    </div>
                </div>

                <!-- Charts layout -->
                <div class="mt-6 grid grid-cols-12 items-stretch gap-6">
                    <!-- Bar Chart -->
                    <section class="col-span-7 flex flex-col rounded-[1.5rem] bg-white p-6 shadow-sm ring-1 ring-slate-200">
                        <div class="flex items-center justify-between">
                            <h4 class="text-lg font-bold text-slate-950">Cashflow mingguan</h4>
                            <div class="flex items-center gap-4 text-sm font-medium text-slate-600">
                                <span>Legend</span>
                                <div class="flex items-center gap-1.5 font-normal text-slate-500">
                                    <span class="size-3 rounded bg-amber-400"></span> Out
                                </div>
                                <div class="flex items-center gap-1.5 font-normal text-slate-500">
                                    <span class="size-3 rounded bg-emerald-400"></span> In
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 flex-1 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                            <div class="flex h-full min-h-[300px] gap-4">
                                <!-- Y Axis -->
                                <div class="flex flex-col justify-between py-2 text-xs font-medium text-slate-400">
                                    <span v-for="tick in [...chartScale].reverse()" :key="tick">{{ tick }} rb</span>
                                </div>
                                <!-- Chart Area -->
                                <div class="relative flex-1">
                                    <!-- Grid lines -->
                                    <div v-for="position in chartLinePositions" :key="position" class="absolute inset-x-0 border-t border-dashed border-slate-200" :style="{ bottom: `${position}%` }"></div>
                                    
                                    <div class="absolute inset-0 flex items-end justify-around pt-6">
                                        <div v-for="item in trendBars" :key="item.label" class="group flex h-full w-16 flex-col justify-end">
                                            <div class="flex h-full items-end justify-center gap-2">
                                                <div class="w-6 rounded-t-lg bg-amber-400 transition-all hover:bg-amber-500" :style="{ height: `${Math.max((item.expense / chartMax) * 100, 5)}%` }"></div>
                                                <div class="w-6 rounded-t-lg bg-emerald-400 transition-all hover:bg-emerald-500" :style="{ height: `${Math.max((item.income / chartMax) * 100, 5)}%` }"></div>
                                            </div>
                                            <div class="mt-4 text-center">
                                                <span class="text-sm font-semibold text-slate-700">{{ item.label }}</span>
                                                <div class="mt-1 flex justify-center gap-4 text-[10px] font-medium text-slate-400">
                                                    <span>Out</span><span>In</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Pie Chart / Detail -->
                    <section class="col-span-5 flex flex-col rounded-[1.5rem] bg-white shadow-sm ring-1 ring-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-100 p-6">
                            <h4 class="text-lg font-bold text-slate-950">Detail {{ financeTab === 'income' ? 'Penghasilan' : 'Pengeluaran' }}</h4>
                            <div class="flex rounded-full bg-slate-100 p-1">
                                <button class="rounded-full px-4 py-1.5 text-sm font-medium transition-all" :class="financeTab === 'income' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'" @click="financeTab = 'income'">Penghasilan</button>
                                <button class="rounded-full px-4 py-1.5 text-sm font-medium transition-all" :class="financeTab === 'expense' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'" @click="financeTab = 'expense'">Pengeluaran</button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-6 p-6">
                            <!-- Composition Graphic -->
                            <div class="flex flex-col rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                                <p class="text-sm font-medium text-slate-500">Komposisi</p>
                                <p class="text-xl font-bold text-slate-900">{{ compactCurrency(activeBreakdownTotal) }}</p>
                                
                                <div class="mt-6 flex flex-1 items-center justify-center px-2">
                                    <div class="relative flex aspect-square w-full max-w-44 items-center justify-center rounded-full" :style="pieStyle">
                                        <div class="absolute inset-[20%] rounded-full bg-white shadow-sm"></div>
                                        <div class="relative z-10 text-center">
                                            <p class="text-[10px] uppercase font-bold tracking-widest text-slate-400">Total</p>
                                            <p class="mt-0.5 text-sm font-bold text-slate-900">{{ compactCurrency(activeBreakdownTotal) }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Breakdown List -->
                            <div class="flex max-h-[300px] flex-col gap-3 overflow-y-auto pr-2">
                                <div v-for="item in activeBreakdown" :key="`${financeTab}-${item.label}`" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <span class="size-3 rounded-full" :style="{ backgroundColor: item.color }"></span>
                                            <div>
                                                <p class="line-clamp-1 text-sm font-bold text-slate-900" :title="item.label">{{ item.label }}</p>
                                                <p class="text-xs text-slate-500">{{ activeBreakdownTotal > 0 ? Math.round((item.value / activeBreakdownTotal) * 100) : 0 }}%</p>
                                            </div>
                                        </div>
                                        <p class="text-sm font-bold text-slate-950">{{ compactCurrency(item.value) }}</p>
                                    </div>
                                </div>
                                <div v-if="activeBreakdown.length === 0" class="flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">
                                    <p class="font-semibold text-slate-700">Belum ada data untuk periode ini.</p>
                                    <p class="text-xs text-slate-500">Data akan muncul setelah transaksi masuk atau pengeluaran dicatat.</p>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </article>
        </div>
    </section>
</template>
