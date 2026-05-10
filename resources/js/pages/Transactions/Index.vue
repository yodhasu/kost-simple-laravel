<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, ChevronRight, Eye, Home, Pencil, Search, SlidersHorizontal, Trash2, User, X, XCircle } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { ApiError, apiRequest } from '@/lib/api';
import type { RegionOption, Viewer } from '@/types/kost';

type KostOption = {
    id: string;
    name: string;
    regionId: string;
    regionName?: string | null;
};

type TenantOption = {
    id: string;
    name: string;
    kostId: string;
    kostName?: string | null;
};

type TransactionRow = {
    id: string;
    date: string | null;
    category: string | null;
    financialClass: 'REVENUE' | 'EXPENSE' | 'LIABILITY' | string | null;
    amount: number;
    signedAmount: number;
    description: string | null;
    tenantId: string | null;
    tenantName: string | null;
    kostId: string | null;
    kostName: string | null;
    regionId: string | null;
    regionName: string | null;
    referenceId: string | null;
    isFrozen: boolean;
    createdAt: string | null;
};

type Filters = {
    search: string;
    regionId: string;
    kostId: string;
    financialClass: string;
    dateFrom: string;
    dateTo: string;
    pageSize: number;
};

type Summary = {
    count: number;
    revenue: number;
    expense: number;
    net: number;
};

type Pagination = {
    total: number;
    currentPage: number;
    lastPage: number;
    pageSize: number;
    from: number | null;
    to: number | null;
};

const props = defineProps<{
    viewer: Viewer;
    regions: RegionOption[];
    kostOptions: KostOption[];
    tenantOptions: TenantOption[];
    filters: Filters;
    summary: Summary;
    transactions: TransactionRow[];
    pagination: Pagination;
}>();

const filterForm = reactive({ ...props.filters });
const mobileFilterOpen = ref(false);
const selectedTransaction = ref<TransactionRow | null>(null);
const detailOpen = ref(false);
const editOpen = ref(false);
const deleteOpen = ref(false);
const editConfirmOpen = ref(false);
const deleteConfirmation = ref('');
const actionError = ref('');
const saving = ref(false);
const deleting = ref(false);
const statusOpen = ref(false);
const statusType = ref<'success' | 'error'>('success');
const statusTitle = ref('');
const statusMessage = ref('');
const pendingRefreshAfterStatus = ref(false);

const editForm = reactive({
    transaction_date: '',
    financial_class: 'REVENUE',
    category: '',
    amount: 0,
    description: '',
    kost_id: '',
    tenant_id: '',
});

const formatCurrency = (value: number) =>
    new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(value);

const formatDate = (value: string | null) => {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(`${value}T00:00:00`));
};

const financialClassLabel = (value: string | null) => {
    if (value === 'REVENUE') {
        return 'Pemasukan';
    }

    if (value === 'EXPENSE') {
        return 'Pengeluaran';
    }

    if (value === 'LIABILITY') {
        return 'Titipan / Liability';
    }

    return value ?? '-';
};

const financialClassTone = (value: string | null) => {
    if (value === 'REVENUE') {
        return 'bg-emerald-100 text-emerald-700 ring-emerald-200';
    }

    if (value === 'EXPENSE') {
        return 'bg-amber-100 text-amber-700 ring-amber-200';
    }

    return 'bg-slate-100 text-slate-700 ring-slate-200';
};

const filteredKostOptions = computed(() => {
    if (filterForm.regionId === 'all') {
        return props.kostOptions;
    }

    return props.kostOptions.filter((kost) => kost.regionId === filterForm.regionId);
});

const editableTenantOptions = computed(() => {
    if (!editForm.kost_id) {
        return props.tenantOptions;
    }

    return props.tenantOptions.filter((tenant) => tenant.kostId === editForm.kost_id);
});

const hasActiveFilters = computed(() =>
    filterForm.search.trim()
    || filterForm.regionId !== 'all'
    || filterForm.kostId !== 'all'
    || filterForm.financialClass !== 'all'
    || filterForm.dateFrom
    || filterForm.dateTo,
);

const activeFilterCount = computed(() =>
    [
        Boolean(filterForm.search.trim()),
        Boolean(filterForm.regionId !== 'all'),
        Boolean(filterForm.kostId !== 'all'),
        Boolean(filterForm.financialClass !== 'all'),
        Boolean(filterForm.dateFrom),
        Boolean(filterForm.dateTo),
    ].filter(Boolean).length,
);

const totalPages = computed(() => Math.max(1, props.pagination.lastPage));
const pageStart = computed(() => props.pagination.from ?? 0);
const pageEnd = computed(() => props.pagination.to ?? 0);

const visiblePages = computed(() => {
    const current = props.pagination.currentPage;
    const pages = new Set<number>([1, totalPages.value, current - 1, current, current + 1]);

    return [...pages]
        .filter((page) => page >= 1 && page <= totalPages.value)
        .sort((a, b) => a - b);
});

const showStatus = (type: 'success' | 'error', title: string, message: string) => {
    statusType.value = type;
    statusTitle.value = title;
    statusMessage.value = message;
    statusOpen.value = true;
};

const closeStatus = () => {
    statusOpen.value = false;

    if (pendingRefreshAfterStatus.value) {
        pendingRefreshAfterStatus.value = false;
        refreshPage();
    }
};

watch(
    () => filterForm.regionId,
    () => {
        if (filterForm.kostId !== 'all' && !filteredKostOptions.value.some((kost) => kost.id === filterForm.kostId)) {
            filterForm.kostId = 'all';
        }
    },
);

watch(
    () => editForm.kost_id,
    () => {
        if (editForm.tenant_id && !editableTenantOptions.value.some((tenant) => tenant.id === editForm.tenant_id)) {
            editForm.tenant_id = '';
        }
    },
);

const routeData = (page = 1) => ({
    search: filterForm.search || undefined,
    region_id: filterForm.regionId === 'all' ? undefined : filterForm.regionId,
    kost_id: filterForm.kostId === 'all' ? undefined : filterForm.kostId,
    financial_class: filterForm.financialClass === 'all' ? undefined : filterForm.financialClass,
    date_from: filterForm.dateFrom || undefined,
    date_to: filterForm.dateTo || undefined,
    page_size: filterForm.pageSize,
    page,
});

const applyFilters = () => {
    if (window.innerWidth < 1024) {
        mobileFilterOpen.value = false;
    }

    router.visit('/transactions', {
        data: routeData(),
        preserveScroll: true,
        replace: true,
    });
};

const resetFilters = () => {
    filterForm.search = '';
    filterForm.regionId = 'all';
    filterForm.kostId = 'all';
    filterForm.financialClass = 'all';
    filterForm.dateFrom = '';
    filterForm.dateTo = '';
    filterForm.pageSize = 10;

    if (window.innerWidth < 1024) {
        mobileFilterOpen.value = false;
    }

    applyFilters();
};

const refreshPage = () => {
    router.visit('/transactions', {
        data: routeData(props.pagination.currentPage),
        only: ['transactions', 'summary', 'pagination'],
        preserveScroll: true,
        replace: true,
    });
};

const goToPage = (page: number) => {
    if (page < 1 || page > props.pagination.lastPage || page === props.pagination.currentPage) {
        return;
    }

    router.visit('/transactions', {
        data: routeData(page),
        preserveScroll: true,
        replace: true,
    });
};

const applyTransactionTab = (financialClass: string) => {
    filterForm.financialClass = financialClass;
    applyFilters();
};

const openDetail = (transaction: TransactionRow) => {
    selectedTransaction.value = transaction;
    detailOpen.value = true;
};

const openEdit = (transaction: TransactionRow) => {
    selectedTransaction.value = transaction;
    actionError.value = '';
    editForm.transaction_date = transaction.date ?? '';
    editForm.financial_class = transaction.financialClass ?? 'REVENUE';
    editForm.category = transaction.category ?? '';
    editForm.amount = transaction.amount;
    editForm.description = transaction.description ?? '';
    editForm.kost_id = transaction.kostId ?? '';
    editForm.tenant_id = transaction.tenantId ?? '';
    editOpen.value = true;
};

const requestSaveTransaction = () => {
    actionError.value = '';
    editConfirmOpen.value = true;
};

const openDelete = (transaction: TransactionRow) => {
    selectedTransaction.value = transaction;
    actionError.value = '';
    deleteConfirmation.value = '';
    deleteOpen.value = true;
};

const saveTransaction = async () => {
    if (!selectedTransaction.value) {
        return;
    }

    actionError.value = '';
    saving.value = true;

    try {
        await apiRequest(`/api/transactions/${selectedTransaction.value.id}`, {
            method: 'PATCH',
            body: {
                transaction_date: editForm.transaction_date,
                financial_class: editForm.financial_class,
                category: editForm.category,
                amount: editForm.amount,
                description: editForm.description || null,
                kost_id: editForm.kost_id || null,
                tenant_id: editForm.tenant_id || null,
            },
        });
        editOpen.value = false;
        editConfirmOpen.value = false;
        pendingRefreshAfterStatus.value = true;
        showStatus('success', 'Transaksi berhasil diperbarui', 'Koreksi transaksi sudah tersimpan di buku transaksi.');
    } catch (error) {
        actionError.value = error instanceof ApiError ? error.message : 'Gagal memperbarui transaksi.';
        editConfirmOpen.value = false;
        showStatus('error', 'Gagal memperbarui transaksi', actionError.value);
    } finally {
        saving.value = false;
    }
};

const deleteTransaction = async () => {
    if (!selectedTransaction.value) {
        return;
    }

    actionError.value = '';
    deleting.value = true;

    try {
        await apiRequest(`/api/transactions/${selectedTransaction.value.id}`, {
            method: 'DELETE',
            body: {
                confirmation: deleteConfirmation.value,
            },
        });
        deleteOpen.value = false;
        detailOpen.value = false;
        pendingRefreshAfterStatus.value = true;
        showStatus('success', 'Transaksi berhasil dihapus', 'Transaksi sudah dihapus permanen dari database lokal.');
    } catch (error) {
        actionError.value = error instanceof ApiError ? error.message : 'Gagal menghapus transaksi.';
        showStatus('error', 'Gagal menghapus transaksi', actionError.value);
    } finally {
        deleting.value = false;
    }
};
</script>

<template>
    <Head title="Kontrol Transaksi" />

    <section class="space-y-3 md:space-y-5">
        <div class="space-y-3 lg:hidden">
            <header class="px-1 pt-1">
                <div>
                    <h1 class="text-xl font-bold tracking-tight text-slate-950">Kontrol Transaksi</h1>
                    <p class="mt-1 max-w-[18rem] text-sm leading-5 text-slate-500">Kelola dan koreksi transaksi yang dibuat admin.</p>
                </div>
            </header>

            <section class="grid grid-cols-2 gap-2.5">
                <div class="rounded-2xl border border-slate-100 bg-white p-3 shadow-sm">
                    <p class="text-[11px] font-medium text-slate-500">Total Data</p>
                    <p class="mt-1 text-base font-bold text-slate-950">{{ summary.count }}</p>
                </div>
                <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-3 shadow-sm">
                    <p class="text-[11px] font-medium text-emerald-700">Pemasukan</p>
                    <p class="mt-1 text-sm font-bold text-emerald-800">{{ formatCurrency(summary.revenue) }}</p>
                </div>
                <div class="rounded-2xl border border-orange-100 bg-orange-50 p-3 shadow-sm">
                    <p class="text-[11px] font-medium text-orange-700">Pengeluaran</p>
                    <p class="mt-1 text-sm font-bold text-orange-800">{{ formatCurrency(summary.expense) }}</p>
                </div>
                <div class="rounded-2xl border border-teal-100 bg-teal-50 p-3 shadow-sm">
                    <p class="text-[11px] font-medium text-teal-700">Net</p>
                    <p class="mt-1 text-sm font-bold text-teal-800">{{ formatCurrency(summary.net) }}</p>
                </div>
            </section>

            <div class="flex gap-2">
                <div class="relative flex-1">
                    <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="filterForm.search"
                        type="text"
                        class="w-full rounded-xl border border-slate-200 bg-white py-3 pl-9 pr-3 text-sm text-slate-700 shadow-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100"
                        placeholder="Cari transaksi"
                        @keyup.enter="applyFilters"
                    />
                </div>
                <button
                    type="button"
                    class="relative flex size-12 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm"
                    @click="mobileFilterOpen = true"
                >
                    <SlidersHorizontal class="size-5" />
                    <span v-if="activeFilterCount" class="absolute -right-1 -top-1 rounded-full bg-teal-600 px-1.5 py-0.5 text-[9px] font-bold text-white">{{ activeFilterCount }}</span>
                    <span class="sr-only">Filter transaksi</span>
                </button>
            </div>

            <div class="flex gap-2 overflow-x-auto pb-0.5">
                <button type="button" class="shrink-0 rounded-full px-4 py-2 text-xs font-semibold" :class="filterForm.financialClass === 'all' ? 'bg-teal-600 text-white shadow-sm' : 'bg-white text-slate-600 ring-1 ring-slate-200'" @click="applyTransactionTab('all')">Semua</button>
                <button type="button" class="shrink-0 rounded-full px-4 py-2 text-xs font-semibold" :class="filterForm.financialClass === 'REVENUE' ? 'bg-teal-600 text-white shadow-sm' : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100'" @click="applyTransactionTab('REVENUE')">Pemasukan</button>
                <button type="button" class="shrink-0 rounded-full px-4 py-2 text-xs font-semibold" :class="filterForm.financialClass === 'EXPENSE' ? 'bg-teal-600 text-white shadow-sm' : 'bg-orange-50 text-orange-700 ring-1 ring-orange-100'" @click="applyTransactionTab('EXPENSE')">Pengeluaran</button>
                <button type="button" class="shrink-0 rounded-full px-4 py-2 text-xs font-semibold" :class="filterForm.financialClass === 'LIABILITY' ? 'bg-teal-600 text-white shadow-sm' : 'bg-slate-50 text-slate-700 ring-1 ring-slate-100'" @click="applyTransactionTab('LIABILITY')">Liability</button>
            </div>
        </div>

        <div class="hidden rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 md:rounded-4xl md:p-6 md:shadow-[0_18px_40px_rgba(15,23,42,0.08)] lg:block">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-teal-700">Owner Control</p>
                    <h1 class="mt-1 text-xl font-extrabold tracking-tight text-slate-950 md:text-3xl">Kontrol Transaksi</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Area khusus owner untuk mengecek, mengoreksi, atau menghapus transaksi yang salah input. Gunakan hati-hati karena perubahan di sini langsung menyentuh buku transaksi.
                    </p>
                </div>
                <div class="rounded-2xl bg-teal-50 px-4 py-3 text-sm text-teal-800 ring-1 ring-teal-100">
                    Login sebagai <span class="font-bold">{{ viewer.name }}</span>
                </div>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-4">
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200/80">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Data tampil</p>
                    <p class="mt-2 text-2xl font-extrabold text-slate-950">{{ summary.count }}</p>
                </div>
                <div class="rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-100">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Pemasukan</p>
                    <p class="mt-2 text-lg font-extrabold text-emerald-800">{{ formatCurrency(summary.revenue) }}</p>
                </div>
                <div class="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-100">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Pengeluaran</p>
                    <p class="mt-2 text-lg font-extrabold text-amber-800">{{ formatCurrency(summary.expense) }}</p>
                </div>
                <div class="rounded-2xl bg-teal-50 p-4 ring-1 ring-teal-100">
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Net</p>
                    <p class="mt-2 text-lg font-extrabold text-teal-800">{{ formatCurrency(summary.net) }}</p>
                </div>
            </div>
        </div>

        <div v-if="mobileFilterOpen" class="fixed inset-0 z-[9999] bg-slate-950/40 lg:hidden" @click="mobileFilterOpen = false">
            <article class="absolute bottom-0 left-1/2 max-h-[88dvh] w-full max-w-md -translate-x-1/2 overflow-y-auto overscroll-contain rounded-t-3xl bg-white px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-5 shadow-2xl sm:px-5" @click.stop>
                <div class="mx-auto mb-4 h-1 w-12 rounded-full bg-slate-300"></div>
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-slate-950">Filter Transaksi</h2>
                        <p class="text-xs text-slate-500">Saring data tanpa memenuhi layar utama.</p>
                    </div>
                    <button type="button" class="flex size-8 items-center justify-center rounded-full bg-slate-100 text-slate-600" @click="mobileFilterOpen = false">
                        <X class="size-4" />
                        <span class="sr-only">Tutup filter</span>
                    </button>
                </div>

                <div class="grid gap-3">
                <label class="grid gap-1.5">
                    <span class="text-xs font-medium text-slate-600">Cari</span>
                    <span class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-3 focus-within:border-teal-500 focus-within:ring-2 focus-within:ring-teal-100">
                        <Search class="size-4 text-slate-400" />
                        <input v-model="filterForm.search" type="text" class="w-full bg-transparent text-sm outline-none" placeholder="Tenant, kost, kategori" @keyup.enter="applyFilters" />
                    </span>
                </label>

                <div class="grid grid-cols-2 gap-3">
                    <label class="grid gap-1.5">
                        <span class="text-xs font-medium text-slate-600">Region</span>
                        <select v-model="filterForm.regionId" class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                            <option v-for="region in regions" :key="region.id" :value="region.id">{{ region.name }}</option>
                        </select>
                    </label>

                    <label class="grid gap-1.5">
                        <span class="text-xs font-medium text-slate-600">Kost</span>
                        <select v-model="filterForm.kostId" class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                            <option value="all">Semua Kost</option>
                            <option v-for="kost in filteredKostOptions" :key="kost.id" :value="kost.id">{{ kost.name }}</option>
                        </select>
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <label class="grid gap-1.5">
                        <span class="text-xs font-medium text-slate-600">Jenis</span>
                        <select v-model="filterForm.financialClass" class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                            <option value="all">Semua</option>
                            <option value="REVENUE">Pemasukan</option>
                            <option value="EXPENSE">Pengeluaran</option>
                            <option value="LIABILITY">Liability</option>
                        </select>
                    </label>

                    <label class="grid gap-1.5">
                        <span class="text-xs font-medium text-slate-600">Per halaman</span>
                        <select v-model.number="filterForm.pageSize" class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                            <option :value="5">5</option>
                            <option :value="10">10</option>
                            <option :value="20">20</option>
                            <option :value="50">50</option>
                        </select>
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <label class="grid gap-1.5">
                        <span class="text-xs font-medium text-slate-600">Dari</span>
                        <input v-model="filterForm.dateFrom" type="date" class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100" />
                    </label>
                    <label class="grid gap-1.5">
                        <span class="text-xs font-medium text-slate-600">Sampai</span>
                        <input v-model="filterForm.dateTo" type="date" class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100" />
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3 pt-2">
                    <Button type="button" variant="outline" class="h-12 rounded-xl text-sm font-semibold" :disabled="!hasActiveFilters" @click="resetFilters">Reset</Button>
                    <Button type="button" class="h-12 rounded-xl bg-teal-600 text-sm font-semibold text-white hover:bg-teal-700" @click="applyFilters">Terapkan Filter</Button>
                </div>
                </div>
            </article>
        </div>

        <article class="hidden rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 md:rounded-4xl md:p-5 lg:block">
            <div class="flex items-center gap-2 text-slate-950">
                <SlidersHorizontal class="size-5 text-teal-700" />
                <h2 class="font-bold">Filter transaksi</h2>
            </div>

            <div class="mt-4 grid gap-3 lg:grid-cols-7">
                <label class="grid gap-2 lg:col-span-2">
                    <span class="text-xs font-semibold text-slate-600">Cari</span>
                    <span class="flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2.5 focus-within:border-teal-500 focus-within:ring-2 focus-within:ring-teal-100">
                        <Search class="size-4 text-slate-400" />
                        <input v-model="filterForm.search" type="text" class="w-full bg-transparent text-sm outline-none" placeholder="Tenant, kost, kategori, deskripsi" @keyup.enter="applyFilters" />
                    </span>
                </label>

                <label class="grid gap-2">
                    <span class="text-xs font-semibold text-slate-600">Region</span>
                    <select v-model="filterForm.regionId" class="rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                        <option v-for="region in regions" :key="region.id" :value="region.id">{{ region.name }}</option>
                    </select>
                </label>

                <label class="grid gap-2">
                    <span class="text-xs font-semibold text-slate-600">Kost</span>
                    <select v-model="filterForm.kostId" class="rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                        <option value="all">Semua Kost</option>
                        <option v-for="kost in filteredKostOptions" :key="kost.id" :value="kost.id">{{ kost.name }}</option>
                    </select>
                </label>

                <label class="grid gap-2">
                    <span class="text-xs font-semibold text-slate-600">Jenis</span>
                    <select v-model="filterForm.financialClass" class="rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                        <option value="all">Semua</option>
                        <option value="REVENUE">Pemasukan</option>
                        <option value="EXPENSE">Pengeluaran</option>
                        <option value="LIABILITY">Liability</option>
                    </select>
                </label>

                <label class="grid gap-2">
                    <span class="text-xs font-semibold text-slate-600">Per halaman</span>
                    <select v-model.number="filterForm.pageSize" class="rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                        <option :value="5">5</option>
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                    </select>
                </label>

                <div class="grid grid-cols-2 gap-2 lg:col-span-2">
                    <label class="grid gap-2">
                        <span class="text-xs font-semibold text-slate-600">Dari</span>
                        <input v-model="filterForm.dateFrom" type="date" class="rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100" />
                    </label>
                    <label class="grid gap-2">
                        <span class="text-xs font-semibold text-slate-600">Sampai</span>
                        <input v-model="filterForm.dateTo" type="date" class="rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100" />
                    </label>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <Button type="button" class="bg-teal-600 text-white hover:bg-teal-700" @click="applyFilters">Terapkan Filter</Button>
                <Button type="button" variant="outline" :disabled="!hasActiveFilters" @click="resetFilters">Reset</Button>
            </div>
        </article>

        <article class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200/70 md:rounded-4xl">
            <div class="hidden overflow-x-auto lg:block">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-4">Tanggal</th>
                            <th class="px-5 py-4">Transaksi</th>
                            <th class="px-5 py-4">Kost / Tenant</th>
                            <th class="px-5 py-4">Jenis</th>
                            <th class="px-5 py-4 text-right">Nominal</th>
                            <th class="px-5 py-4 text-right">Kontrol</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="transaction in transactions" :key="transaction.id" class="hover:bg-teal-50/30">
                            <td class="px-5 py-4 font-medium text-slate-700">{{ formatDate(transaction.date) }}</td>
                            <td class="px-5 py-4">
                                <p class="font-semibold text-slate-950">{{ transaction.description || 'Tanpa deskripsi' }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ transaction.category || '-' }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="font-medium text-slate-800">{{ transaction.kostName || '-' }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ transaction.tenantName || transaction.regionName || 'Tidak terkait tenant' }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1" :class="financialClassTone(transaction.financialClass)">
                                    {{ financialClassLabel(transaction.financialClass) }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-right font-extrabold" :class="transaction.financialClass === 'EXPENSE' ? 'text-amber-700' : 'text-emerald-700'">
                                {{ formatCurrency(transaction.signedAmount) }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end gap-2">
                                    <button type="button" class="rounded-xl p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" @click="openDetail(transaction)">
                                        <Eye class="size-4" />
                                        <span class="sr-only">Detail</span>
                                    </button>
                                    <button type="button" class="rounded-xl p-2 text-teal-600 transition hover:bg-teal-50 hover:text-teal-800" @click="openEdit(transaction)">
                                        <Pencil class="size-4" />
                                        <span class="sr-only">Edit</span>
                                    </button>
                                    <button type="button" class="rounded-xl p-2 text-rose-600 transition hover:bg-rose-50 hover:text-rose-800" @click="openDelete(transaction)">
                                        <Trash2 class="size-4" />
                                        <span class="sr-only">Hapus</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-3 bg-slate-50 p-1 lg:hidden">
                <article v-for="transaction in transactions" :key="'m-' + transaction.id" class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm active:scale-[0.99]">
                    <button type="button" class="w-full text-left" @click="openDetail(transaction)">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs text-slate-400">{{ formatDate(transaction.date) }}</p>
                                <h3 class="mt-1 line-clamp-2 text-sm font-semibold leading-snug text-slate-950">{{ transaction.description || 'Tanpa deskripsi' }}</h3>
                            </div>
                            <div class="shrink-0 text-right">
                                <span class="inline-flex rounded-full px-2 py-1 text-[11px] font-semibold ring-1" :class="financialClassTone(transaction.financialClass)">
                                    {{ financialClassLabel(transaction.financialClass) }}
                                </span>
                                <p class="mt-2 text-sm font-bold" :class="transaction.financialClass === 'EXPENSE' ? 'text-orange-600' : 'text-emerald-600'">
                                    {{ formatCurrency(transaction.signedAmount) }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-500">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <Home class="size-3 shrink-0" />
                                <span class="truncate">{{ transaction.kostName || transaction.regionName || '-' }}</span>
                            </div>
                            <div class="flex min-w-0 items-center gap-1.5">
                                <User class="size-3 shrink-0" />
                                <span class="truncate">{{ transaction.tenantName || transaction.category || 'Tidak terkait tenant' }}</span>
                            </div>
                        </div>
                    </button>

                    <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
                        <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-teal-700" @click="openDetail(transaction)">
                            Detail
                            <ChevronRight class="size-3" />
                        </button>
                        <div class="flex items-center gap-2">
                            <button type="button" class="inline-flex size-8 items-center justify-center rounded-xl bg-teal-50 text-teal-700 ring-1 ring-teal-100" @click="openEdit(transaction)">
                                <Pencil class="size-3.5" />
                                <span class="sr-only">Edit</span>
                            </button>
                            <button type="button" class="inline-flex size-8 items-center justify-center rounded-xl bg-rose-50 text-rose-600 ring-1 ring-rose-100" @click="openDelete(transaction)">
                                <Trash2 class="size-3.5" />
                                <span class="sr-only">Hapus</span>
                            </button>
                        </div>
                    </div>
                </article>
            </div>

            <div v-if="transactions.length === 0" class="px-4 py-14 text-center">
                <p class="font-semibold text-slate-800">Tidak ada transaksi yang cocok.</p>
                <p class="mt-1 text-sm text-slate-500">Coba longgarkan filter atau tanggalnya.</p>
            </div>

            <div v-if="pagination.total > 0" class="border-t border-slate-100 px-3 py-3 lg:hidden">
                <div class="flex items-center justify-between text-[11px] text-slate-600">
                    <span>{{ pageStart }}-{{ pageEnd }} / {{ pagination.total }}</span>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="min-h-8 min-w-8 rounded-md border border-slate-200 bg-white px-2 py-1 text-[10px] font-semibold text-slate-600 disabled:opacity-40" :disabled="pagination.currentPage <= 1" @click="goToPage(pagination.currentPage - 1)">
                            ←
                        </button>
                        <span class="inline-flex items-center px-1 text-[10px] font-semibold text-slate-700">{{ pagination.currentPage }}/{{ totalPages }}</span>
                        <button type="button" class="min-h-8 min-w-8 rounded-md border border-slate-200 bg-white px-2 py-1 text-[10px] font-semibold text-slate-600 disabled:opacity-40" :disabled="pagination.currentPage >= totalPages" @click="goToPage(pagination.currentPage + 1)">
                            →
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="pagination.total > 0" class="hidden border-t border-slate-100 px-6 py-5 lg:flex lg:items-center lg:justify-between">
                <p class="text-sm text-slate-600">
                    Menampilkan {{ pageStart }}-{{ pageEnd }} dari {{ pagination.total }} transaksi.
                </p>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition hover:border-teal-300 hover:text-teal-700 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="pagination.currentPage <= 1"
                        @click="goToPage(pagination.currentPage - 1)"
                    >
                        Sebelumnya
                    </button>

                    <div class="flex items-center gap-1">
                        <button
                            v-for="page in visiblePages"
                            :key="`tx-page-${page}`"
                            type="button"
                            class="inline-flex min-w-9 items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold transition"
                            :class="page === pagination.currentPage
                                ? 'bg-teal-600 text-white shadow-sm'
                                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'"
                            @click="goToPage(page)"
                        >
                            {{ page }}
                        </button>
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition hover:border-teal-300 hover:text-teal-700 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="pagination.currentPage >= totalPages"
                        @click="goToPage(pagination.currentPage + 1)"
                    >
                        Berikutnya
                    </button>
                </div>
            </div>
        </article>

        <BaseModal :open="detailOpen" title="Detail Transaksi" description="Cek data sebelum mengedit atau menghapus." max-width-class="sm:max-w-2xl" @update:open="detailOpen = $event">
            <div v-if="selectedTransaction" class="grid gap-3 text-sm sm:grid-cols-2">
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Deskripsi</p>
                    <p class="mt-2 font-bold text-slate-950">{{ selectedTransaction.description || 'Tanpa deskripsi' }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Nominal</p>
                    <p class="mt-2 font-bold text-slate-950">{{ formatCurrency(selectedTransaction.signedAmount) }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">Tanggal: <strong>{{ formatDate(selectedTransaction.date) }}</strong></div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">Kategori: <strong>{{ selectedTransaction.category || '-' }}</strong></div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">Kost: <strong>{{ selectedTransaction.kostName || '-' }}</strong></div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">Tenant: <strong>{{ selectedTransaction.tenantName || '-' }}</strong></div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">Region: <strong>{{ selectedTransaction.regionName || '-' }}</strong></div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">Reference: <strong>{{ selectedTransaction.referenceId || '-' }}</strong></div>
            </div>
            <template #footer>
                <Button type="button" variant="outline" @click="detailOpen = false">Tutup</Button>
                <Button v-if="selectedTransaction" type="button" class="bg-teal-600 text-white hover:bg-teal-700" @click="openEdit(selectedTransaction)">Edit</Button>
                <Button v-if="selectedTransaction" type="button" class="bg-rose-600 text-white hover:bg-rose-700" @click="openDelete(selectedTransaction)">Hapus</Button>
            </template>
        </BaseModal>

        <BaseModal :open="editOpen" title="Edit Transaksi" description="Koreksi ledger transaksi. Ini tidak otomatis menghitung ulang status billing tenant." max-width-class="sm:max-w-2xl" @update:open="editOpen = $event">
            <div v-if="actionError" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ actionError }}</div>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="grid gap-2">
                    <Label>Tanggal</Label>
                    <input v-model="editForm.transaction_date" type="date" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100" />
                </label>
                <label class="grid gap-2">
                    <Label>Jenis</Label>
                    <select v-model="editForm.financial_class" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                        <option value="REVENUE">Pemasukan</option>
                        <option value="EXPENSE">Pengeluaran</option>
                        <option value="LIABILITY">Liability</option>
                    </select>
                </label>
                <label class="grid gap-2">
                    <Label>Kategori</Label>
                    <input v-model="editForm.category" type="text" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100" placeholder="rent, extra_fee, maintenance..." />
                </label>
                <label class="grid gap-2">
                    <Label>Nominal</Label>
                    <input v-model.number="editForm.amount" type="number" min="1" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100" />
                </label>
                <label class="grid gap-2">
                    <Label>Kost</Label>
                    <select v-model="editForm.kost_id" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                        <option value="">Tidak terkait kost</option>
                        <option v-for="kost in kostOptions" :key="kost.id" :value="kost.id">{{ kost.name }}</option>
                    </select>
                </label>
                <label class="grid gap-2">
                    <Label>Tenant</Label>
                    <select v-model="editForm.tenant_id" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                        <option value="">Tidak terkait tenant</option>
                        <option v-for="tenant in editableTenantOptions" :key="tenant.id" :value="tenant.id">{{ tenant.name }} — {{ tenant.kostName || 'Kost' }}</option>
                    </select>
                </label>
                <label class="grid gap-2 sm:col-span-2">
                    <Label>Deskripsi</Label>
                    <textarea v-model="editForm.description" rows="3" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100" />
                </label>
            </div>
            <template #footer>
                <Button type="button" variant="outline" :disabled="saving" @click="editOpen = false">Batal</Button>
                <Button type="button" class="bg-teal-600 text-white hover:bg-teal-700" :disabled="saving" @click="requestSaveTransaction">Simpan Koreksi</Button>
            </template>
        </BaseModal>

        <ConfirmModal
            :open="editConfirmOpen"
            title="Simpan koreksi transaksi?"
            description="Pastikan nominal, tanggal, kategori, kost, dan tenant sudah benar. Koreksi ini langsung mengubah buku transaksi."
            confirm-label="Ya, Simpan Koreksi"
            variant="info"
            :loading="saving"
            @update:open="editConfirmOpen = $event"
            @confirm="saveTransaction"
        />

        <ConfirmModal
            :open="deleteOpen"
            title="Hapus transaksi permanen?"
            description="Aksi ini menghapus transaksi dari database. Ketik HAPUS untuk membuka tombol konfirmasi."
            confirm-label="Hapus Permanen"
            variant="danger"
            :loading="deleting"
            :confirm-disabled="deleteConfirmation !== 'HAPUS'"
            @update:open="deleteOpen = $event"
            @confirm="deleteTransaction"
        >
            <div v-if="actionError" class="mb-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ actionError }}</div>
            <label class="grid gap-2">
                <Label>Ketik HAPUS</Label>
                <input v-model="deleteConfirmation" type="text" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" placeholder="HAPUS" />
            </label>
        </ConfirmModal>

        <BaseModal
            :open="statusOpen"
            :title="statusTitle"
            :description="statusMessage"
            max-width-class="sm:max-w-md"
            @update:open="$event ? statusOpen = true : closeStatus()"
        >
            <div class="flex items-center gap-3 rounded-2xl p-4" :class="statusType === 'success' ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-100' : 'bg-rose-50 text-rose-800 ring-1 ring-rose-100'">
                <CheckCircle2 v-if="statusType === 'success'" class="size-8 shrink-0 text-emerald-600" />
                <XCircle v-else class="size-8 shrink-0 text-rose-600" />
                <p class="text-sm leading-6">
                    {{ statusType === 'success' ? 'Perubahan sudah dikonfirmasi oleh server.' : 'Server menolak perubahan. Data belum berubah.' }}
                </p>
            </div>
            <template #footer>
                <Button type="button" class="bg-teal-600 text-white hover:bg-teal-700" @click="closeStatus">Mengerti</Button>
            </template>
        </BaseModal>
    </section>
</template>
