<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Download,
    HousePlus,
    Receipt,
    Search,
    SquarePen,
    TriangleAlert,
    UserPlus,
    Wallet,
} from 'lucide-vue-next';
import BaseModal from '@/components/BaseModal.vue';
import ExpenseFormModal from '@/components/payments/ExpenseFormModal.vue';
import KostFormModal from '@/components/kosts/KostFormModal.vue';
import PaymentUpdateModal, { type PaymentTenantOption } from '@/components/payments/PaymentUpdateModal.vue';
import TenantFormModal, { type TenantFormKostOption } from '@/components/tenants/TenantFormModal.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { ApiError, apiRequest } from '@/lib/api';
import type { RegionOption, Viewer } from '@/types/kost';

type QuickAction = {
    title: string;
    description: string;
    icon: string;
};

type RecentActivity = {
    title: string;
    meta: string;
    amount: number;
    tone: string;
};

const props = defineProps<{
    viewer: Viewer;
    regions: RegionOption[];
    kostOptions: TenantFormKostOption[];
    paymentTenants: PaymentTenantOption[];
    quickActions: QuickAction[];
}>();

const iconMap = {
    'user-plus': UserPlus,
    wallet: Wallet,
    receipt: Receipt,
    'house-plus': HousePlus,
    'square-pen': SquarePen,
    download: Download,
};

const actionToneMap = {
    'user-plus': {
        chip: 'bg-sky-100 text-sky-700 ring-sky-200',
        button: 'bg-sky-600 text-white hover:bg-sky-700',
    },
    wallet: {
        chip: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        button: 'bg-emerald-600 text-white hover:bg-emerald-700',
    },
    receipt: {
        chip: 'bg-amber-100 text-amber-700 ring-amber-200',
        button: 'bg-amber-500 text-white hover:bg-amber-600',
    },
    'house-plus': {
        chip: 'bg-violet-100 text-violet-700 ring-violet-200',
        button: 'bg-violet-600 text-white hover:bg-violet-700',
    },
    'square-pen': {
        chip: 'bg-cyan-100 text-cyan-700 ring-cyan-200',
        button: 'bg-cyan-600 text-white hover:bg-cyan-700',
    },
    download: {
        chip: 'bg-rose-100 text-rose-700 ring-rose-200',
        button: 'bg-rose-600 text-white hover:bg-rose-700',
    },
} as const;

const actionModalOpen = ref(false);
const selectedAction = ref<QuickAction | null>(null);
const selectedKost = ref<TenantFormKostOption | null>(null);
const selectedRegionId = ref('all');
const kostSearch = ref('');
const actionError = ref('');
const showAccessDenied = ref(false);

const isAdmin = computed(() => props.viewer.role === 'admin');

const modalTitleMap = {
    'Tambah Penyewa': 'Tambah penyewa baru',
    'Update Pembayaran': 'Catat pembayaran',
    'Tambah Pengeluaran': 'Tambah pengeluaran operasional',
    'Tambah Kost': 'Tambah properti kost',
    'Daftar Kost': 'Daftar properti kost',
    'Ekspor Data': 'Siapkan ekspor data',
} as const;

const modalDescriptionMap = {
    'Tambah Penyewa': 'Frontend modal dulu untuk alur tambah penyewa. Nanti field ini tinggal disambungkan ke endpoint Laravel.',
    'Update Pembayaran': 'Form pembayaran dibuat dulu di sisi UI agar alurnya siap saat backend transaksi masuk.',
    'Tambah Pengeluaran': 'Catat biaya operasional, utilitas, atau pengeluaran lain dari satu modal yang ringkas.',
    'Tambah Kost': 'Gunakan modal ini untuk input region, nama kost, alamat, dan jumlah unit.',
    'Daftar Kost': 'Pilih kost untuk melihat atau mengedit detail properti.',
    'Ekspor Data': 'Atur jenis data dan periode ekspor dari modal ringan sebelum backend download dihubungkan.',
} as const;

const openActionModal = (action: QuickAction) => {
    if (action.title === 'Ekspor Data') {
        router.visit('/export');
        return;
    }

    if (isAdmin.value && action.title === 'Tambah Kost') {
        showAccessDenied.value = true;

        setTimeout(() => {
            showAccessDenied.value = false;
        }, 1000);

        return;
    }

    actionError.value = '';
    selectedKost.value = null;
    kostSearch.value = '';
    selectedAction.value = action;
    actionModalOpen.value = true;
};

const closeActionModal = () => {
    actionModalOpen.value = false;
    selectedAction.value = null;
    selectedKost.value = null;
};

const actionTone = (icon: string) => actionToneMap[icon as keyof typeof actionToneMap] ?? actionToneMap.wallet;

const modalTitle = computed(() => {
    if (!selectedAction.value) {
        return 'Aksi operasional';
    }

    return modalTitleMap[selectedAction.value.title as keyof typeof modalTitleMap] ?? selectedAction.value.title;
});

const modalDescription = computed(() => {
    if (!selectedAction.value) {
        return '';
    }

    return modalDescriptionMap[selectedAction.value.title as keyof typeof modalDescriptionMap] ?? selectedAction.value.description;
});

const isTenantAction = computed(() => selectedAction.value?.title === 'Tambah Penyewa');
const isPaymentAction = computed(() => selectedAction.value?.title === 'Update Pembayaran');
const isExpenseAction = computed(() => selectedAction.value?.title === 'Tambah Pengeluaran');
const isAddKostAction = computed(() => selectedAction.value?.title === 'Tambah Kost');
const isListKostAction = computed(() => selectedAction.value?.title === 'Daftar Kost');
const filteredKostOptions = computed(() =>
    (selectedRegionId.value === 'all'
        ? props.kostOptions
        : props.kostOptions.filter((kost) => kost.regionId === selectedRegionId.value))
        .filter((kost) =>
            !kostSearch.value.trim()
            || kost.name.toLowerCase().includes(kostSearch.value.trim().toLowerCase())
            || (kost.address ?? '').toLowerCase().includes(kostSearch.value.trim().toLowerCase()),
        ),
);

const refreshPaymentsPage = () =>
    router.visit(window.location.pathname, {
        only: ['kostOptions', 'paymentTenants'],
        preserveScroll: true,
        preserveState: true,
    });

const handleTenantSave = async (payload: {
    kost_id: string;
    name: string;
    phone: string;
    start_date: string;
    rent_price: number;
    trash_fee: number;
    security_fee: number;
    admin_fee: number;
    status: 'LUNAS' | 'ON HOLD' | 'DP';
    dp_amount: number;
    dp_due_date: string;
}) => {
    actionError.value = '';

    try {
        await apiRequest('/api/tenants', {
            method: 'POST',
            body: payload,
        });
        closeActionModal();
        refreshPaymentsPage();
    } catch (error) {
        actionError.value = error instanceof ApiError ? error.message : 'Gagal menambahkan penyewa.';
    }
};

const handlePaymentSave = async (payload: { kost_id: string; tenant_id: string; amount: number; transaction_date: string; allow_carryover?: boolean }) => {
    actionError.value = '';

    try {
        await apiRequest('/api/payments', {
            method: 'POST',
            body: payload,
        });
        closeActionModal();
        refreshPaymentsPage();
    } catch (error) {
        actionError.value = error instanceof ApiError ? error.message : 'Gagal mencatat pembayaran.';
    }
};

const handleExpenseSave = async (payload: {
    tingkat: 'region' | 'kost';
    region_id: string;
    kost_id: string;
    category: string;
    description: string;
    amount: number;
    transaction_date: string;
}) => {
    actionError.value = '';

    try {
        await apiRequest('/api/expenses', {
            method: 'POST',
            body: {
                region_id: payload.region_id || null,
                kost_id: payload.tingkat === 'kost' ? payload.kost_id : null,
                category: payload.category,
                description: payload.description || null,
                amount: payload.amount,
                transaction_date: payload.transaction_date,
            },
        });
        closeActionModal();
        refreshPaymentsPage();
    } catch (error) {
        actionError.value = error instanceof ApiError ? error.message : 'Gagal menyimpan pengeluaran.';
    }
};

const handleKostSave = async (payload: {
    region_id: string;
    name: string;
    address: string;
    total_units: number;
    notes: string;
}) => {
    actionError.value = '';

    try {
        if (selectedKost.value) {
            await apiRequest(`/api/kosts/${selectedKost.value.id}`, {
                method: 'PATCH',
                body: {
                    name: payload.name,
                    address: payload.address || null,
                    total_units: payload.total_units,
                    notes: payload.notes || null,
                },
            });
        } else {
            await apiRequest('/api/kosts', {
                method: 'POST',
                body: {
                    region_id: payload.region_id,
                    name: payload.name,
                    address: payload.address || null,
                    total_units: payload.total_units,
                    notes: payload.notes || null,
                },
            });
        }

        closeActionModal();
        refreshPaymentsPage();
    } catch (error) {
        actionError.value = error instanceof ApiError ? error.message : 'Gagal menyimpan data kost.';
    }
};

const handleKostDelete = async () => {
    if (!selectedKost.value) {
        return;
    }

    actionError.value = '';

    try {
        await apiRequest(`/api/kosts/${selectedKost.value.id}`, {
            method: 'DELETE',
        });
        closeActionModal();
        refreshPaymentsPage();
    } catch (error) {
        actionError.value = error instanceof ApiError ? error.message : 'Gagal menghapus kost.';
    }
};

</script>

<template>
    <Head title="Aksi & Pembayaran" />

    <section class="space-y-2 md:space-y-5">
        <!-- Desktop hero (hidden on mobile) -->
        <div class="hidden rounded-4xl bg-white p-6 shadow-[0_18px_40px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/80 lg:block">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-teal-700">Quick Actions</p>
            <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950">Aksi operasional yang paling sering dipakai</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                Gunakan halaman ini untuk menambah penyewa, mencatat pembayaran, memasukkan pengeluaran, dan mengelola data kost.
                Semua aksi penting operasional harian dikumpulkan di satu tempat agar proses input lebih cepat.
            </p>
        </div>


        <div
            v-if="showAccessDenied"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm transition-opacity"
        >
            <div class="flex flex-col items-center gap-3 rounded-2xl bg-slate-900/90 px-10 py-8 text-center shadow-2xl">
                <TriangleAlert class="size-10 text-amber-400" />
                <p class="text-sm font-semibold text-white">Akses fitur ini hanya untuk Owner</p>
            </div>
        </div>

        <article class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 md:rounded-4xl md:p-6 md:shadow-[0_18px_40px_rgba(15,23,42,0.08)] md:ring-slate-200/80">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 md:text-sm">Action Hub</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950 md:text-2xl">Pilih aksi operasional</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Semua form utama untuk input harian tersedia di sini. Pilih aksi sesuai kebutuhan lalu lanjutkan dari modal.
                    </p>
                </div>
            </div>

            <div v-if="actionError" class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ actionError }}
            </div>

            <div class="mt-5 grid grid-cols-3 gap-2 lg:hidden md:gap-4">
                <button
                    v-for="action in quickActions"
                    :key="'m-' + action.title"
                    type="button"
                    class="flex flex-col items-center gap-1.5 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200/70 transition active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-2 md:gap-2.5 md:rounded-2xl md:p-5"
                    @click="openActionModal(action)"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-xl ring-1 md:size-14 md:rounded-2xl"
                        :class="actionTone(action.icon).chip"
                    >
                        <component :is="iconMap[action.icon as keyof typeof iconMap]" class="size-5 md:size-6" />
                    </div>
                    <span class="text-center text-[10px] font-semibold leading-tight text-slate-700 md:text-base">{{ action.title }}</span>
                </button>
            </div>

            <div class="mt-5 hidden gap-4 lg:grid lg:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="action in quickActions"
                    :key="action.title"
                    class="rounded-3xl bg-slate-50 p-5 ring-1 ring-slate-200/70 transition hover:-translate-y-0.5 hover:shadow-[0_12px_28px_rgba(15,23,42,0.08)]"
                >
                    <div
                        class="flex size-12 items-center justify-center rounded-2xl ring-1"
                        :class="actionTone(action.icon).chip"
                    >
                        <component :is="iconMap[action.icon as keyof typeof iconMap]" class="size-5" />
                    </div>
                    <h3 class="mt-4 text-lg font-bold text-slate-950">{{ action.title }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ action.description }}</p>
                    <button
                        type="button"
                        class="mt-4 inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-2"
                        :class="actionTone(action.icon).button"
                        @click="openActionModal(action)"
                    >
                        Buka Form
                        <ArrowUpRight class="size-4" />
                    </button>
                </article>
            </div>
        </article>

        <TenantFormModal
            v-if="isTenantAction"
            :open="actionModalOpen"
            :kost-options="props.kostOptions"
            @update:open="actionModalOpen = $event"
            @save="handleTenantSave"
        />

        <PaymentUpdateModal
            v-else-if="isPaymentAction"
            :open="actionModalOpen"
            :kost-options="props.kostOptions"
            :tenants="props.paymentTenants"
            @update:open="actionModalOpen = $event"
            @save="handlePaymentSave"
        />

        <ExpenseFormModal
            v-else-if="isExpenseAction"
            :open="actionModalOpen"
            :viewer="viewer"
            :regions="regions"
            :kost-options="kostOptions"
            @update:open="actionModalOpen = $event"
            @save="handleExpenseSave"
        />

        <KostFormModal
            v-else-if="isAddKostAction || (isListKostAction && selectedKost)"
            :open="actionModalOpen"
            :viewer="viewer"
            :regions="regions"
            :kost="selectedKost"
            :view-only="isAdmin"
            @update:open="actionModalOpen = $event"
            @save="handleKostSave"
            @delete="handleKostDelete"
        />

        <BaseModal
            v-else-if="isListKostAction"
            :open="actionModalOpen"
            title="Pilih Kost"
            description="Pilih kost yang ingin dilihat atau diedit."
            max-width-class="sm:max-w-xl"
            @update:open="actionModalOpen = $event"
        >
            <div class="space-y-5">
                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label class="text-slate-900">Filter Region</Label>
                        <select
                            v-model="selectedRegionId"
                            class="rounded-2xl border border-slate-900/50 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-teal-600 focus:ring-2 focus:ring-teal-200"
                        >
                            <option v-for="region in regions" :key="region.id" :value="region.id" class="text-slate-900">
                                {{ region.name }}
                            </option>
                        </select>
                    </div>

                    <div class="grid gap-2">
                        <Label class="text-slate-900">Cari Kost</Label>
                        <div class="flex items-center gap-2 rounded-2xl border border-slate-900/50 bg-white px-4 py-3 focus-within:border-teal-600 focus-within:ring-2 focus-within:ring-teal-200">
                            <Search class="size-4 text-slate-400" />
                            <input
                                v-model="kostSearch"
                                type="text"
                                class="w-full bg-transparent text-sm text-slate-900 outline-none placeholder:text-slate-400"
                                placeholder="Cari nama kost atau alamat"
                            />
                        </div>
                    </div>
                </div>

                <div class="max-h-80 space-y-2 overflow-y-auto pr-1">
                    <button
                        v-for="kost in filteredKostOptions"
                        :key="kost.id"
                        type="button"
                        class="flex w-full items-center justify-between rounded-2xl border border-slate-900/10 bg-white px-4 py-4 text-left transition hover:border-teal-300 hover:bg-teal-50/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-2"
                        @click="selectedKost = kost"
                    >
                        <div>
                            <p class="text-sm font-semibold text-slate-900">{{ kost.name }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ kost.address || 'Alamat belum diisi' }}</p>
                        </div>
                        <ArrowUpRight class="size-4 text-slate-400" />
                    </button>

                    <div
                        v-if="filteredKostOptions.length === 0"
                        class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500"
                    >
                        {{ kostSearch ? 'Tidak ada kost yang cocok dengan pencarian.' : 'Tidak ada kost tersedia.' }}
                    </div>
                </div>
            </div>
            <template #footer>
                <Button type="button" variant="outline" @click="closeActionModal">Batal</Button>
            </template>
        </BaseModal>
    </section>
</template>
