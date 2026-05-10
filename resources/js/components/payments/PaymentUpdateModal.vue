<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import type { TenantFormKostOption } from '@/components/tenants/TenantFormModal.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type PaymentTenantOption = {
    id: string;
    kostId: string;
    name: string;
    status: string;
    rentPrice: number;
    trashFee: number;
    securityFee: number;
    adminFee: number;
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
    open: boolean;
    kostOptions: TenantFormKostOption[];
    tenants: PaymentTenantOption[];
    fixedKostId?: string | null;
    fixedTenantId?: string | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'save', payload: { kost_id: string; tenant_id: string; amount: number; transaction_date: string; allow_carryover?: boolean }): void;
}>();

const form = reactive({
    kost_id: '',
    tenant_id: '',
    amount: 0,
    transaction_date: new Date().toISOString().split('T')[0] ?? '',
});

const showAllTenants = reactive({
    value: false,
});

const filteredTenants = computed(() => {
    const activeItems = props.tenants.filter(
        (tenant) => tenant.kostId === form.kost_id && tenant.isActive !== false && tenant.status !== 'ON HOLD',
    );

    if (showAllTenants.value) {
        return activeItems;
    }

    return activeItems.filter((tenant) => tenant.isDp || tenant.status !== 'LUNAS' || tenant.prepaidBalance > 0);
});

const selectedTenant = computed(() => filteredTenants.value.find((tenant) => tenant.id === form.tenant_id) ?? null);
const isCarryoverPayment = computed(() =>
    Boolean(selectedTenant.value && !selectedTenant.value.isDp && selectedTenant.value.status === 'LUNAS' && totalTagihan.value <= 0 && form.amount > 0),
);

const parseLocalDate = (value: string) => {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, (month ?? 1) - 1, day ?? 1);
};

const addMonths = (value: string, monthsToAdd: number) => {
    const date = parseLocalDate(value);
    const day = date.getDate();
    date.setMonth(date.getMonth() + monthsToAdd, day);

    return date;
};

const formatBillingMonth = (value: Date) =>
    new Intl.DateTimeFormat('id-ID', {
        month: 'long',
        year: 'numeric',
    }).format(value);

const totalTagihan = computed(() => {
    if (!selectedTenant.value) {
        return 0;
    }

    if (selectedTenant.value.isDp) {
        return selectedTenant.value.dpRemainingAmount ?? Math.max(0, selectedTenant.value.rentPrice - (selectedTenant.value.dpAmount ?? 0));
    }

    return selectedTenant.value.totalOutstandingAmount ?? selectedTenant.value.currentDueAmount;
});

const projectedCoverage = computed(() => {
    if (!selectedTenant.value || form.amount <= 0 || selectedTenant.value.isDp) {
        return {
            fullMonths: 0,
            remainder: 0,
        };
    }

    const currentDue = selectedTenant.value.currentDueAmount;
    const remainderAfterCurrent = Math.max(0, form.amount - currentDue);
    const fullMonths = Math.floor(remainderAfterCurrent / Math.max(1, selectedTenant.value.rentPrice));
    const remainder = remainderAfterCurrent % Math.max(1, selectedTenant.value.rentPrice);

    return {
        fullMonths,
        remainder,
    };
});

const billingItems = computed(() => {
    if (!selectedTenant.value || selectedTenant.value.isDp || selectedTenant.value.status === 'LUNAS' || !selectedTenant.value.nextBillingDate) {
        return [];
    }

    const rentPrice = Math.max(0, selectedTenant.value.rentPrice);
    const carryover = Math.max(0, selectedTenant.value.prepaidBalance);
    const totalOutstanding = Math.max(0, selectedTenant.value.totalOutstandingAmount ?? 0);

    if (rentPrice <= 0 && carryover <= 0 && totalOutstanding <= 0) {
        return [];
    }

    const billedCycleCount = rentPrice > 0
        ? Math.ceil((totalOutstanding + carryover) / rentPrice)
        : 0;

    return Array.from({ length: billedCycleCount }, (_, index) => {
        const cycleDate = addMonths(selectedTenant.value!.nextBillingDate!, index);

        return {
            key: `${selectedTenant.value!.id}-${index}`,
            label: `Biaya Sewa ${formatBillingMonth(cycleDate)}`,
            rent: rentPrice,
        };
    });
});

const errorMessage = computed(() => {
    if (!form.kost_id) {
        return 'Pilih kost terlebih dahulu.';
    }

    if (!form.tenant_id) {
        return 'Pilih penyewa terlebih dahulu.';
    }

    if (form.amount <= 0) {
        return 'Nominal pembayaran harus lebih besar dari 0.';
    }

    if (selectedTenant.value?.isDp && form.amount > totalTagihan.value) {
        return `Nominal pelunasan DP maksimal ${formatCurrency(totalTagihan.value)}.`;
    }

    if (!form.transaction_date) {
        return 'Tanggal bayar wajib diisi.';
    }

    return '';
});

const resetForm = () => {
    form.kost_id = props.fixedKostId ?? props.kostOptions[0]?.id ?? '';
    form.tenant_id = props.fixedTenantId ?? '';
    form.amount = 0;
    form.transaction_date = new Date().toISOString().split('T')[0] ?? '';
    showAllTenants.value = false;
};

watch(
    () => props.open,
    (open) => {
        if (open) {
            resetForm();
        }
    },
    { immediate: true },
);

watch(
    () => form.kost_id,
    () => {
        form.tenant_id = props.fixedTenantId ?? '';
        form.amount = 0;
    },
);

watch(
    () => showAllTenants.value,
    () => {
        form.tenant_id = '';
        form.amount = 0;
    },
);

watch(selectedTenant, (tenant) => {
    if (tenant) {
        form.amount = totalTagihan.value;
    }
});

watch(
    () => form.amount,
    (amount) => {
        if (selectedTenant.value?.isDp && amount > totalTagihan.value) {
            form.amount = totalTagihan.value;
        }
    },
);

const formatCurrency = (amount: number | null) =>
    new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
    }).format(amount ?? 0);

const formatDate = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('id-ID', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          }).format(new Date(value))
        : '-';

const statusLabel = (status: string) => {
    const labels: Record<string, string> = {
        LUNAS: 'LUNAS',
        DP: 'DP',
        'BELUM LUNAS': 'BELUM LUNAS',
        'JATUH TEMPO': 'JATUH TEMPO',
        'TELAT BAYAR': 'TELAT BAYAR',
        'ON HOLD': 'ON HOLD',
    };

    return labels[status] ?? status;
};

const confirmSaveOpen = ref(false);
const carryoverConsentOpen = ref(false);
const carryoverConsent = reactive({
    understandsAlreadyPaid: false,
    understandsFutureCredit: false,
    typedPhrase: '',
});
const carryoverConsentValid = computed(() =>
    carryoverConsent.understandsAlreadyPaid
    && carryoverConsent.understandsFutureCredit
    && carryoverConsent.typedPhrase.trim().toUpperCase() === 'CARRYOVER',
);

const requestSave = () => {
    if (errorMessage.value) {
        return;
    }

    if (isCarryoverPayment.value) {
        carryoverConsent.understandsAlreadyPaid = false;
        carryoverConsent.understandsFutureCredit = false;
        carryoverConsent.typedPhrase = '';
        carryoverConsentOpen.value = true;

        return;
    }

    confirmSaveOpen.value = true;
};

const acceptCarryoverConsent = () => {
    if (!carryoverConsentValid.value) {
        return;
    }

    carryoverConsentOpen.value = false;
    confirmSaveOpen.value = true;
};

const executeSave = () => {
    confirmSaveOpen.value = false;

    emit('save', {
        kost_id: form.kost_id,
        tenant_id: form.tenant_id,
        amount: form.amount,
        transaction_date: form.transaction_date,
        allow_carryover: isCarryoverPayment.value,
    });
};
</script>

<template>
    <BaseModal
        :open="open"
        title="Form Pembayaran Sewa"
        description="Pilih penyewa, review tagihan bulan berjalan, lalu sistem akan otomatis mengalokasikan ke bulan berikutnya bila ada kelebihan bayar."
        max-width-class="sm:max-w-2xl"
        @update:open="emit('update:open', $event)"
    >
        <form id="payment-update-form" class="space-y-5" @submit.prevent="requestSave">
            <div class="grid gap-2">
                <Label for="payment-kost" class="text-slate-900">Pilih Kost</Label>
                <select
                    id="payment-kost"
                    v-model="form.kost_id"
                    class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900"
                >
                    <option value="" disabled class="text-slate-900">Pilih Kost</option>
                    <option v-for="kost in kostOptions" :key="kost.id" :value="kost.id" class="text-slate-900">
                        {{ kost.name }}
                    </option>
                </select>
            </div>

            <div class="grid gap-2">
                <Label for="payment-tenant" class="text-slate-900">Nama Penyewa</Label>
                <select
                    id="payment-tenant"
                    v-model="form.tenant_id"
                    class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900"
                    :disabled="!form.kost_id"
                >
                    <option value="" disabled class="text-slate-900">
                        {{ !form.kost_id ? 'Pilih kost terlebih dahulu' : 'Pilih penyewa' }}
                    </option>
                    <option v-for="tenant in filteredTenants" :key="tenant.id" :value="tenant.id" class="text-slate-900">
                        {{ tenant.name }} ({{ statusLabel(tenant.status) }})
                    </option>
                </select>

                <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                    <input v-model="showAllTenants.value" type="checkbox" class="size-4 rounded border-slate-300 bg-white" />
                    <span>Tampilkan semua penyewa</span>
                </label>

                <p v-if="form.kost_id && filteredTenants.length === 0" class="text-sm text-slate-500">
                    {{ showAllTenants.value ? 'Tidak ada penyewa.' : 'Tidak ada penyewa yang perlu ditinjau pada filter cepat ini.' }}
                </p>
            </div>

            <div
                v-if="selectedTenant"
                class="rounded-[1.35rem] border border-emerald-200 bg-emerald-50 p-4"
            >
                <p class="text-sm font-semibold text-emerald-700">Status Pembayaran</p>
                <div class="mt-3 space-y-2 text-sm text-emerald-800">
                    <div class="flex items-center justify-between">
                        <span>Biaya Sewa</span>
                        <span>{{ formatCurrency(selectedTenant.rentPrice) }}</span>
                    </div>
                    <template v-if="!selectedTenant.isDp">
                        <div class="flex items-center justify-between">
                            <span>Status Saat Ini</span>
                            <span>{{ statusLabel(selectedTenant.status) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Carryover Tersimpan</span>
                            <span>{{ formatCurrency(selectedTenant.prepaidBalance) }}</span>
                        </div>
                        <template v-if="selectedTenant.status !== 'LUNAS'">
                            <details
                                v-for="item in billingItems"
                                :key="item.key"
                                class="rounded-2xl border border-emerald-200/70 bg-white/55 px-4 py-3"
                            >
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 font-semibold text-emerald-800">
                                    <span>{{ item.label }}</span>
                                    <span>{{ formatCurrency(item.rent) }}</span>
                                </summary>
                                <div class="mt-3 border-t border-emerald-100 pt-3">
                                    <div class="flex items-center justify-between">
                                        <span>Biaya Sewa</span>
                                        <span>{{ formatCurrency(item.rent) }}</span>
                                    </div>
                                </div>
                            </details>
                            <div class="flex items-center justify-between">
                                <span>Carryover Balance</span>
                                <span>-{{ formatCurrency(selectedTenant.prepaidBalance) }}</span>
                            </div>
                        </template>
                    </template>
                    <div v-if="selectedTenant.isDp" class="flex items-center justify-between">
                        <span>DP Dibayar</span>
                        <span>-{{ formatCurrency(selectedTenant.dpPaidAmount ?? selectedTenant.dpAmount) }}</span>
                    </div>
                    <div v-if="selectedTenant.isDp && selectedTenant.dpDueDate" class="flex items-center justify-between">
                        <span>Tanggal Jatuh Tempo</span>
                        <span>{{ formatDate(selectedTenant.dpDueDate) }}</span>
                    </div>
                    <div class="flex items-center justify-between border-t border-emerald-200 pt-3 text-base font-bold text-emerald-700">
                        <span>Total Tagihan</span>
                        <span>{{ formatCurrency(totalTagihan) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>Total Dibayar</span>
                        <span>{{ formatCurrency(form.amount) }}</span>
                    </div>
                    <template v-if="!selectedTenant.isDp && form.amount > 0">
                        <div class="flex items-center justify-between">
                            <span>Pembayaran untuk Bulan Berikutnya</span>
                            <span>{{ projectedCoverage.fullMonths }} bulan</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Sisa Carryover Baru</span>
                            <span>{{ formatCurrency(projectedCoverage.remainder) }}</span>
                        </div>
                    </template>
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="payment-amount" class="text-slate-900">Nominal (Rp)</Label>
                    <div class="flex items-center overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <span class="px-4 text-sm text-slate-500">Rp</span>
                        <input
                            id="payment-amount"
                            v-model.number="form.amount"
                            type="number"
                            min="0"
                            :max="selectedTenant?.isDp ? totalTagihan : undefined"
                            class="w-full bg-transparent px-0 py-2.5 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                            placeholder="0"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="payment-date" class="text-slate-900">Tanggal Bayar</Label>
                    <Input
                        id="payment-date"
                        v-model="form.transaction_date"
                        type="date"
                        class="border-slate-200 !bg-white !text-slate-900"
                    />
                </div>
            </div>

            <p v-if="errorMessage" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ errorMessage }}
            </p>
        </form>

        <template #footer>
            <Button type="button" variant="outline" @click="emit('update:open', false)">Batal</Button>
            <Button type="submit" form="payment-update-form" :disabled="Boolean(errorMessage)">
                Simpan Pembayaran
            </Button>
        </template>
    </BaseModal>

    <ConfirmModal
        :open="carryoverConsentOpen"
        title="Penyewa Sudah LUNAS"
        :description="`${selectedTenant?.name ?? 'Penyewa ini'} sudah lunas. Pembayaran ${formatCurrency(form.amount)} akan diperlakukan sebagai carryover/uang muka untuk siklus berikutnya, bukan tagihan bulan ini.`"
        confirm-label="Saya Paham, Lanjut"
        variant="warning"
        :confirm-disabled="!carryoverConsentValid"
        @update:open="carryoverConsentOpen = $event"
        @confirm="acceptCarryoverConsent"
    >
        <div class="space-y-4 text-sm text-slate-700">
            <label class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3">
                <input v-model="carryoverConsent.understandsAlreadyPaid" type="checkbox" class="mt-1 size-4 rounded border-amber-300 bg-white" />
                <span>Saya paham penyewa ini statusnya sudah <strong>LUNAS</strong> dan tidak punya tagihan berjalan.</span>
            </label>
            <label class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3">
                <input v-model="carryoverConsent.understandsFutureCredit" type="checkbox" class="mt-1 size-4 rounded border-amber-300 bg-white" />
                <span>Saya paham uang ini akan masuk sebagai pembayaran/carryover untuk bulan berikutnya.</span>
            </label>
            <div class="grid gap-2">
                <Label for="carryover-phrase" class="text-slate-900">Ketik CARRYOVER untuk konfirmasi</Label>
                <Input
                    id="carryover-phrase"
                    v-model="carryoverConsent.typedPhrase"
                    class="border-amber-200 !bg-white !text-slate-900"
                    placeholder="CARRYOVER"
                />
            </div>
        </div>
    </ConfirmModal>

    <ConfirmModal
        :open="confirmSaveOpen"
        title="Konfirmasi Pembayaran"
        :description="isCarryoverPayment
            ? `Konfirmasi terakhir: catat ${formatCurrency(form.amount)} sebagai pembayaran carryover untuk ${selectedTenant?.name ?? 'penyewa'}?`
            : `Catat pembayaran ${formatCurrency(form.amount)} untuk ${selectedTenant?.name ?? 'penyewa'}?`"
        confirm-label="Ya, Simpan"
        variant="info"
        @update:open="confirmSaveOpen = $event"
        @confirm="executeSave"
    />
</template>
