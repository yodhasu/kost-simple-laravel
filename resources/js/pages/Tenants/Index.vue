<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Plus, RotateCcw, Search } from 'lucide-vue-next';
import BaseModal from '@/components/BaseModal.vue';
import TenantDetailModal from '@/components/tenants/TenantDetailModal.vue';
import TenantFormModal, {
    type EditableTenant,
    type TenantFormKostOption,
    type TenantFormPayload,
} from '@/components/tenants/TenantFormModal.vue';
import { Button } from '@/components/ui/button';
import { ApiError, apiRequest } from '@/lib/api';
import type { RegionOption, Viewer } from '@/types/kost';

type Tenant = {
    id: string;
    name: string;
    phone: string;
    kostName: string;
    regionName: string;
    kostId: string;
    startDate: string;
    rentPrice: number;
    trashFee: number;
    securityFee: number;
    adminFee: number;
    status: string;
    dpAmount: number | null;
    dpPaidAmount?: number;
    dpRemainingAmount?: number;
    dpDueDate: string | null;
    isDp: boolean;
    prepaidBalance: number;
    paidUntil: string | null;
    nextBillingDate?: string | null;
    currentDueAmount: number;
    totalOutstandingAmount?: number;
    isActive: boolean;
};

const props = defineProps<{
    viewer: Viewer;
    regions: RegionOption[];
    kostOptions: TenantFormKostOption[];
    filters: {
        search: string;
        status: string;
        regionId: string;
    };
    tenants: Tenant[];
    pagination: {
        total: number;
        currentPage: number;
        pageSize: number;
    };
}>();

const search = ref(props.filters.search);
const status = ref(props.filters.status);
const regionId = ref(props.filters.regionId);
const tenantModalOpen = ref(false);
const editingTenant = ref<EditableTenant | null>(null);
const detailModalOpen = ref(false);
const activeTenant = ref<Tenant | null>(null);
const confirmDeleteOpen = ref(false);
const actionError = ref('');
const mobileFilterOpen = ref(false);
const isApplyingFilters = ref(false);
const isResettingFilters = ref(false);
const isTenantRequestInFlight = ref(false);

const hasActiveFilters = computed(() =>
    Boolean(search.value.trim()) || Boolean(status.value) || (regionId.value && regionId.value !== 'all'),
);

const activeFilterCount = computed(() =>
    [Boolean(search.value.trim()), Boolean(status.value), Boolean(regionId.value && regionId.value !== 'all')]
        .filter(Boolean)
        .length,
);

const totalPages = computed(() => Math.max(1, Math.ceil(props.pagination.total / props.pagination.pageSize)));
const pageStart = computed(() =>
    props.pagination.total === 0
        ? 0
        : ((props.pagination.currentPage - 1) * props.pagination.pageSize) + 1,
);
const pageEnd = computed(() =>
    props.pagination.total === 0
        ? 0
        : pageStart.value + props.tenants.length - 1,
);

const isFilterBusy = computed(() => isTenantRequestInFlight.value || isApplyingFilters.value || isResettingFilters.value);

const visiblePages = computed(() => {
    const current = props.pagination.currentPage;
    const pages = new Set<number>([1, totalPages.value, current - 1, current, current + 1]);

    return [...pages]
        .filter((page) => page >= 1 && page <= totalPages.value)
        .sort((a, b) => a - b);
});

const currency = (value: number) =>
    new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(value);

const formatDate = (value: string) =>
    new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));

const statusTone = (value: string) => {
    switch (value) {
        case 'aktif':
        case 'LUNAS':
            return 'bg-emerald-100 text-emerald-700';
        case 'DP':
            return 'bg-amber-100 text-amber-700';
        case 'BELUM LUNAS':
            return 'bg-rose-100 text-rose-700';
        case 'JATUH TEMPO':
            return 'bg-orange-100 text-orange-800';
        case 'TELAT BAYAR':
            return 'bg-rose-100 text-rose-700';
        case 'ON HOLD':
            return 'bg-sky-100 text-sky-700';
        default:
            return 'bg-slate-100 text-slate-600';
    }
};

const visitTenants = (page = 1, onFinish?: () => void) => {
    if (isTenantRequestInFlight.value) {
        return;
    }

    isTenantRequestInFlight.value = true;

    router.get('/tenants', {
        page,
        page_size: props.pagination.pageSize,
        search: search.value.trim() || undefined,
        status: status.value || undefined,
        region_id: regionId.value === 'all' ? undefined : regionId.value,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['filters', 'tenants', 'pagination', 'kostOptions'],
        onFinish: () => {
            isTenantRequestInFlight.value = false;
            onFinish?.();
        },
    });
};

const applyFilters = () => {
    if (isTenantRequestInFlight.value) {
        return;
    }

    isApplyingFilters.value = true;
    visitTenants(1, () => {
        isApplyingFilters.value = false;
    });
};

const resetFilters = () => {
    if (isTenantRequestInFlight.value) {
        return;
    }

    isResettingFilters.value = true;
    search.value = '';
    status.value = '';
    regionId.value = 'all';
    visitTenants(1, () => {
        isResettingFilters.value = false;
    });
};

const openCreateModal = () => {
    actionError.value = '';
    editingTenant.value = null;
    tenantModalOpen.value = true;
};

const openEditModal = (tenant: Tenant) => {
    actionError.value = '';
    editingTenant.value = {
        id: tenant.id,
        kostId: tenant.kostId,
        name: tenant.name,
        phone: tenant.phone,
        startDate: tenant.startDate,
        rentPrice: tenant.rentPrice,
        trashFee: tenant.trashFee,
        securityFee: tenant.securityFee,
        adminFee: tenant.adminFee,
        status: tenant.isDp ? 'DP' : tenant.status === 'ON HOLD' ? 'ON HOLD' : 'LUNAS',
        dpAmount: tenant.dpAmount,
        dpPaidAmount: tenant.dpPaidAmount,
        dpRemainingAmount: tenant.dpRemainingAmount,
        dpDueDate: tenant.dpDueDate,
        isDp: tenant.isDp,
        prepaidBalance: tenant.prepaidBalance,
        paidUntil: tenant.paidUntil,
        nextBillingDate: tenant.nextBillingDate,
        currentDueAmount: tenant.currentDueAmount,
        totalOutstandingAmount: tenant.totalOutstandingAmount,
        isActive: tenant.isActive,
    };
    tenantModalOpen.value = true;
};

const refreshTenants = () =>
    router.visit(`${window.location.pathname}${window.location.search}`, {
        only: ['filters', 'tenants', 'pagination', 'kostOptions'],
        preserveScroll: true,
        preserveState: true,
    });

const handleTenantSave = async (payload: TenantFormPayload) => {
    actionError.value = '';

    try {
        if (editingTenant.value) {
            await apiRequest(`/api/tenants/${editingTenant.value.id}`, {
                method: 'PATCH',
                body: payload,
            });
        } else {
            await apiRequest('/api/tenants', {
                method: 'POST',
                body: payload,
            });
        }

        tenantModalOpen.value = false;
        detailModalOpen.value = false;
        editingTenant.value = null;
        activeTenant.value = null;
        refreshTenants();
    } catch (error) {
        actionError.value = error instanceof ApiError ? error.message : 'Gagal menyimpan data penyewa.';
    }
};

const openDetailModal = (tenant: Tenant) => {
    activeTenant.value = tenant;
    detailModalOpen.value = true;
};

const handleEditFromDetail = () => {
    if (!activeTenant.value) {
        return;
    }

    detailModalOpen.value = false;
    openEditModal(activeTenant.value);
};

const confirmDelete = (tenant: Tenant) => {
    actionError.value = '';
    activeTenant.value = tenant;
    confirmDeleteOpen.value = true;
};

const confirmInactiveFromDetail = () => {
    detailModalOpen.value = false;
    confirmDeleteOpen.value = true;
};

const handleTenantPayment = async (payload: { kost_id: string; tenant_id: string; amount: number; transaction_date: string }) => {
    actionError.value = '';

    try {
        await apiRequest('/api/payments', {
            method: 'POST',
            body: payload,
        });

        detailModalOpen.value = false;
        activeTenant.value = null;
        refreshTenants();
    } catch (error) {
        actionError.value = error instanceof ApiError ? error.message : 'Gagal mencatat pembayaran.';
    }
};

const deactivateTenant = async () => {
    if (!activeTenant.value) {
        return;
    }

    actionError.value = '';

    try {
        await apiRequest(`/api/tenants/${activeTenant.value.id}`, {
            method: 'DELETE',
        });

        confirmDeleteOpen.value = false;
        detailModalOpen.value = false;
        activeTenant.value = null;
        refreshTenants();
    } catch (error) {
        actionError.value = error instanceof ApiError ? error.message : 'Gagal menonaktifkan penyewa.';
    }
};
</script>

<template>
    <Head title="Daftar Penyewa" />

    <section class="space-y-2 md:space-y-5">
        <!-- Desktop hero (hidden on mobile) -->
        <div class="hidden flex-col gap-4 rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/70 lg:flex xl:flex-row xl:items-center xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-teal-700">Tenant Management</p>
                <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950">Daftar penyewa aktif dan pipeline masuk</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    Pantau penyewa aktif, status DP, dan riwayat masuk dengan filter yang bisa langsung dipakai untuk pencarian cepat.
                    Tabel ini juga jadi titik awal untuk edit, pembayaran, dan penonaktifan penyewa.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-2xl bg-teal-600 px-4 py-3 text-sm font-semibold text-white shadow-sm"
                    @click="openCreateModal"
                >
                    <Plus class="size-4" />
                    Tambah Penyewa
                </button>
            </div>
        </div>

        <!-- Mobile compact header -->
        <div class="sticky top-2 z-10 flex items-center justify-between rounded-xl bg-white/90 px-2 py-1.5 shadow-sm backdrop-blur lg:hidden">
            <div>
                <h2 class="text-sm font-bold text-slate-950 md:text-xl">Penyewa <span class="font-normal text-slate-500">({{ pagination.total }})</span></h2>
            </div>
            <div class="flex items-center gap-2 md:gap-4">
                <button
                    type="button"
                    class="inline-flex min-h-10 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-600 md:rounded-xl md:px-4 md:py-2.5 md:text-base"
                    @click="mobileFilterOpen = !mobileFilterOpen"
                >
                    <Search class="size-3 md:size-4" />
                    Filter
                    <span v-if="activeFilterCount" class="rounded-full bg-teal-600 px-1.5 py-0.5 text-[9px] font-bold text-white md:text-[11px]">{{ activeFilterCount }}</span>
                </button>
                <button
                    type="button"
                    class="inline-flex min-h-10 items-center gap-1 rounded-lg bg-teal-600 px-3 py-2 text-xs font-semibold text-white md:rounded-xl md:px-4 md:py-2.5 md:text-base"
                    @click="openCreateModal"
                >
                    <Plus class="size-3 md:size-4" />
                    Baru
                </button>
            </div>
        </div>

        <!-- Mobile collapsible filter -->
        <div v-if="mobileFilterOpen" class="space-y-2 rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200/70 lg:hidden md:space-y-4 md:rounded-2xl md:p-5">
            <div class="relative">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-slate-400 md:left-4 md:size-4" />
                <input
                    v-model="search"
                    type="text"
                    placeholder="Cari nama / HP..."
                    class="w-full rounded-lg border border-slate-200 bg-slate-50 py-3 pl-8 pr-3 text-xs text-slate-700 focus:border-teal-500 focus:outline-none md:rounded-xl md:py-2.5 md:pl-10 md:text-base"
                    @keydown.enter.prevent="applyFilters"
                />
            </div>
            <div class="grid grid-cols-2 gap-2 md:gap-4">
                <select
                    v-model="regionId"
                    class="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-3 text-xs text-slate-700 focus:border-teal-500 focus:outline-none md:rounded-xl md:px-4 md:py-2.5 md:text-base"
                >
                    <option v-for="region in regions" :key="region.id" :value="region.id">{{ region.name }}</option>
                </select>
                <select
                    v-model="status"
                    class="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-3 text-xs text-slate-700 focus:border-teal-500 focus:outline-none md:rounded-xl md:px-4 md:py-2.5 md:text-base"
                >
                    <option value="">Semua Status</option>
                    <option value="LUNAS">LUNAS</option>
                    <option value="DP">DP</option>
                    <option value="BELUM LUNAS">BELUM LUNAS</option>
                    <option value="JATUH TEMPO">JATUH TEMPO</option>
                    <option value="TELAT BAYAR">TELAT BAYAR</option>
                    <option value="ON HOLD">ON HOLD</option>
                </select>
            </div>
            <div class="flex gap-2.5 md:mt-2 md:gap-4">
                <button type="button" class="flex-1 rounded-lg border border-slate-200 py-3 text-xs font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60 md:rounded-xl md:py-2.5 md:text-base" :disabled="isFilterBusy" @click="resetFilters">{{ isResettingFilters ? 'Mereset...' : 'Reset' }}</button>
                <button type="button" class="flex-1 rounded-lg bg-teal-600 py-3 text-xs font-semibold text-white transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:bg-teal-400 md:rounded-xl md:py-2.5 md:text-base" :disabled="isFilterBusy" @click="applyFilters">{{ isApplyingFilters ? 'Menerapkan...' : 'Terapkan' }}</button>
            </div>
        </div>

        <!-- Desktop filter (hidden on mobile) -->
        <div class="hidden rounded-[2rem] bg-white p-5 shadow-sm ring-1 ring-slate-200/70 sm:p-5 lg:block">
            <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-[minmax(0,1.6fr)_minmax(220px,0.8fr)_minmax(220px,0.8fr)_auto]">
                <label class="relative block">
                    <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Pencarian</span>
                    <Search class="pointer-events-none absolute left-4 top-[calc(50%+0.75rem)] size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Cari nama atau nomor HP..."
                        class="w-full rounded-2xl border py-3 pl-11 pr-4 text-sm transition focus:border-teal-500 focus:outline-none"
                        :class="search
                            ? 'border-teal-200 bg-teal-50 text-teal-700 placeholder:text-teal-400'
                            : 'border-slate-200 bg-slate-50 text-slate-700'"
                        @keydown.enter.prevent="applyFilters"
                    />
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Region</span>
                    <select
                        v-model="regionId"
                        class="w-full rounded-2xl border px-4 py-3 text-sm transition focus:border-teal-500 focus:outline-none"
                        :class="regionId !== 'all'
                            ? 'border-teal-200 bg-teal-50 text-teal-700'
                            : 'border-slate-200 bg-slate-50 text-slate-700'"
                    >
                        <option v-for="region in regions" :key="region.id" :value="region.id">
                            {{ region.name }}
                        </option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</span>
                    <select
                        v-model="status"
                        class="w-full rounded-2xl border px-4 py-3 text-sm transition focus:border-teal-500 focus:outline-none"
                        :class="status
                            ? 'border-teal-200 bg-teal-50 text-teal-700'
                            : 'border-slate-200 bg-slate-50 text-slate-700'"
                    >
                        <option value="">Semua</option>
                        <option value="LUNAS">LUNAS</option>
                        <option value="DP">DP</option>
                        <option value="BELUM LUNAS">BELUM LUNAS</option>
                        <option value="JATUH TEMPO">JATUH TEMPO</option>
                        <option value="TELAT BAYAR">TELAT BAYAR</option>
                        <option value="ON HOLD">ON HOLD</option>
                    </select>
                </label>

                <div class="flex flex-wrap items-end gap-3 lg:col-span-2 xl:col-span-1 xl:justify-end">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="isFilterBusy"
                        @click="resetFilters"
                    >
                        <RotateCcw class="size-4" />
                        {{ isResettingFilters ? 'Mereset...' : 'Reset' }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-2xl bg-teal-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:bg-teal-400"
                        :disabled="isFilterBusy"
                        @click="applyFilters"
                    >
                        {{ isApplyingFilters ? 'Menerapkan...' : 'Terapkan' }}
                    </button>
                </div>
            </div>

            <div v-if="hasActiveFilters" class="mt-4 flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Filter Aktif</span>
                <span
                    v-if="search.trim()"
                    class="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-700 ring-1 ring-teal-200"
                >
                    Cari: {{ search.trim() }}
                </span>
                <span
                    v-if="regionId !== 'all'"
                    class="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-700 ring-1 ring-teal-200"
                >
                    Region: {{ regions.find((item) => item.id === regionId)?.name }}
                </span>
                <span
                    v-if="status"
                    class="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold capitalize text-teal-700 ring-1 ring-teal-200"
                >
                    Status: {{ status }}
                </span>
            </div>
        </div>

        <!-- Mobile card list -->
        <div class="space-y-1.5 lg:hidden md:space-y-2.5">
            <div
                v-for="tenant in props.tenants"
                :key="'m-' + tenant.id"
                class="flex items-center gap-2.5 rounded-xl bg-white px-3 py-2.5 shadow-sm ring-1 ring-slate-200/70 transition hover:bg-slate-50 active:scale-[0.995] md:gap-4 md:rounded-2xl md:px-5 md:py-4"
                @click="openDetailModal(tenant)"
            >
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-teal-100 text-xs font-semibold text-teal-700 md:size-10 md:rounded-xl md:text-base">
                    {{ tenant.name.split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase() }}
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <p class="truncate text-xs font-semibold text-slate-900 md:text-base">{{ tenant.name }}</p>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize md:px-2.5 md:py-1.5 md:text-xs" :class="statusTone(tenant.status)">{{ tenant.status }}</span>
                    </div>
                    <div class="mt-0.5 flex items-center justify-between gap-2 md:mt-1.5">
                        <p class="truncate text-[10px] text-slate-500 md:text-base">{{ tenant.kostName }}</p>
                        <p class="shrink-0 text-[10px] font-semibold text-slate-800 md:text-base">{{ currency(tenant.rentPrice) }}</p>
                    </div>
                </div>
            </div>
            <div v-if="props.tenants.length === 0" class="rounded-xl bg-white px-4 py-6 text-center text-xs text-slate-500 ring-1 ring-slate-200/70 md:rounded-2xl md:py-10 md:text-base">
                <p class="font-semibold text-slate-700">Tidak ada data penyewa.</p>
                <p class="mt-1 text-[11px] text-slate-500 md:text-sm">Coba ubah filter atau tambahkan penyewa baru.</p>
            </div>
            <!-- Mobile pagination -->
            <div class="flex items-center justify-between pt-1 text-[10px] text-slate-500 md:pt-2.5 md:text-base">
                <span>{{ pageStart }}-{{ pageEnd }} / {{ pagination.total }}</span>
                <div class="flex gap-1 md:gap-2">
                    <button type="button" class="min-h-10 min-w-10 rounded-md border border-slate-200 bg-white px-2.5 py-2 text-xs font-semibold text-slate-600 disabled:opacity-40 md:rounded-lg md:px-4 md:py-2 md:text-base" :disabled="pagination.currentPage <= 1" @click="visitTenants(pagination.currentPage - 1)">
                        <ChevronLeft class="size-3 md:size-4" />
                    </button>
                    <span class="inline-flex items-center px-2 text-[10px] font-semibold text-slate-700 md:px-2.5 md:text-base">{{ pagination.currentPage }}/{{ totalPages }}</span>
                    <button type="button" class="min-h-10 min-w-10 rounded-md border border-slate-200 bg-white px-2.5 py-2 text-xs font-semibold text-slate-600 disabled:opacity-40 md:rounded-lg md:px-4 md:py-2 md:text-base" :disabled="pagination.currentPage >= totalPages" @click="visitTenants(pagination.currentPage + 1)">
                        <ChevronRight class="size-3 md:size-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Desktop table (hidden on mobile) -->
        <div class="hidden overflow-hidden rounded-[2rem] bg-white shadow-sm ring-1 ring-slate-200/70 lg:block">
            <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                <div>
                    <h3 class="text-lg font-bold text-slate-950">Data Penyewa</h3>
                    <p class="text-sm text-slate-500">{{ props.tenants.length }} data tampil dari total {{ pagination.total }}</p>
                </div>
                <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-600">
                    Page {{ pagination.currentPage }} / {{ totalPages }}
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-left">
                    <thead class="bg-slate-50 text-xs uppercase tracking-[0.16em] text-slate-500">
                        <tr>
                            <th class="px-6 py-4">Nama Penyewa</th>
                            <th class="px-6 py-4">Nomor HP</th>
                            <th class="px-6 py-4">Kost</th>
                            <th class="px-6 py-4">Region</th>
                            <th class="px-6 py-4">Tanggal Masuk</th>
                            <th class="px-6 py-4">Biaya Sewa</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="tenant in props.tenants"
                            :key="tenant.id"
                            class="border-t border-slate-100 align-top text-sm text-slate-600 transition hover:bg-slate-50"
                            @click="openDetailModal(tenant)"
                        >
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-11 items-center justify-center rounded-2xl bg-teal-100 font-semibold text-teal-700">
                                        {{ tenant.name.split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase() }}
                                    </div>
                                    <div>
                                        <p class="font-semibold text-slate-900">{{ tenant.name }}</p>
                                        <p class="text-xs text-slate-500">{{ tenant.id }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 font-medium text-slate-700">{{ tenant.phone }}</td>
                            <td class="px-6 py-4 font-medium text-slate-800">{{ tenant.kostName }}</td>
                            <td class="px-6 py-4 text-slate-700">{{ tenant.regionName }}</td>
                            <td class="px-6 py-4">{{ formatDate(tenant.startDate) }}</td>
                            <td class="px-6 py-4 font-medium text-slate-900">{{ currency(tenant.rentPrice) }}</td>
                            <td class="px-6 py-4">
                                <span class="rounded-full px-3 py-1 text-xs font-semibold capitalize" :class="statusTone(tenant.status)">
                                    {{ tenant.status }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="rounded-xl bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-700 ring-1 ring-sky-100 transition hover:bg-sky-100"
                                        @click.stop="openEditModal(tenant)"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-xl bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 ring-1 ring-rose-100 transition hover:bg-rose-100"
                                        @click.stop="confirmDelete(tenant)"
                                    >
                                        Hapus
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="props.tenants.length === 0">
                            <td colspan="8" class="px-6 py-10 text-center text-sm text-slate-500">
                                Tidak ada data penyewa untuk filter yang dipilih.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-3 border-t border-slate-200 px-6 py-4 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                <p>
                    Menampilkan {{ pageStart }}-{{ pageEnd }} dari {{ pagination.total }} data penyewa.
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-slate-300 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="pagination.currentPage <= 1"
                        @click="visitTenants(pagination.currentPage - 1)"
                    >
                        <ChevronLeft class="size-3.5" />
                        Sebelumnya
                    </button>

                    <button
                        v-for="page in visiblePages"
                        :key="page"
                        type="button"
                        class="inline-flex min-w-10 items-center justify-center rounded-xl px-3 py-2 text-xs font-semibold transition"
                        :class="page === pagination.currentPage
                            ? 'bg-teal-600 text-white shadow-sm'
                            : 'border border-slate-200 bg-white text-slate-700 hover:border-slate-300'"
                        @click="visitTenants(page)"
                    >
                        {{ page }}
                    </button>

                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-slate-300 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="pagination.currentPage >= totalPages"
                        @click="visitTenants(pagination.currentPage + 1)"
                    >
                        Berikutnya
                        <ChevronRight class="size-3.5" />
                    </button>
                </div>
            </div>
        </div>

        <div v-if="actionError" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {{ actionError }}
        </div>

        <TenantFormModal
            :open="tenantModalOpen"
            :tenant="editingTenant"
            :kost-options="kostOptions"
            @update:open="tenantModalOpen = $event"
            @save="handleTenantSave"
        />

        <TenantDetailModal
            :open="detailModalOpen"
            :tenant="activeTenant"
            :kost-options="kostOptions"
            @update:open="detailModalOpen = $event"
            @edit="handleEditFromDetail"
            @pay="handleTenantPayment"
            @set-inactive="confirmInactiveFromDetail"
        />

        <BaseModal
            :open="confirmDeleteOpen"
            title="Nonaktifkan Penyewa"
            description=""
            max-width-class="sm:max-w-md"
            @update:open="confirmDeleteOpen = $event"
        >
            <div class="space-y-4">
                <p class="text-sm leading-6 text-slate-600">
                    Apakah Anda yakin ingin menonaktifkan
                    <span class="font-semibold text-slate-950">{{ activeTenant?.name }}</span>?
                </p>
            </div>
            <template #footer>
                <Button type="button" variant="outline" @click="confirmDeleteOpen = false">Batal</Button>
                <Button type="button" class="bg-rose-600 text-white hover:bg-rose-700" @click="deactivateTenant">
                    Nonaktifkan
                </Button>
            </template>
        </BaseModal>
    </section>
</template>
