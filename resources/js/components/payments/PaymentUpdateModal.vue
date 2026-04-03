<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { TenantFormKostOption } from '@/components/tenants/TenantFormModal.vue';

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
    dpDueDate: string | null;
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
    (e: 'save', payload: { kost_id: string; tenant_id: string; amount: number; transaction_date: string }): void;
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
    const activeItems = props.tenants.filter((tenant) => tenant.kostId === form.kost_id && tenant.isActive !== false);

    if (showAllTenants.value) {
        return activeItems;
    }

    return activeItems.filter((tenant) => tenant.status === 'telat' || tenant.status === 'dp');
});

const selectedTenant = computed(() => filteredTenants.value.find((tenant) => tenant.id === form.tenant_id) ?? null);

const totalTagihan = computed(() => {
    if (!selectedTenant.value) {
        return 0;
    }

    if (selectedTenant.value.status === 'dp') {
        return Math.max(0, selectedTenant.value.rentPrice - (selectedTenant.value.dpAmount ?? 0));
    }

    return selectedTenant.value.rentPrice;
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
        form.tenant_id = '';
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
        aktif: 'Aktif',
        dp: 'DP',
        telat: 'Telat',
        inaktif: 'Tidak Aktif',
    };

    return labels[status] ?? status;
};

const submit = () => {
    if (errorMessage.value) {
        return;
    }

    emit('save', {
        kost_id: form.kost_id,
        tenant_id: form.tenant_id,
        amount: form.amount,
        transaction_date: form.transaction_date,
    });
};
</script>

<template>
    <BaseModal
        :open="open"
        title="Form Pembayaran Sewa"
        description="Migrasi alur pembayaran dari app lama: pilih kost, pilih penyewa, review tagihan, lalu isi nominal dan tanggal bayar."
        max-width-class="sm:max-w-2xl"
        @update:open="emit('update:open', $event)"
    >
        <form id="payment-update-form" class="space-y-5" @submit.prevent="submit">
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
                    {{ showAllTenants.value ? 'Tidak ada penyewa.' : 'Tidak ada penyewa dengan status DP atau Telat.' }}
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
                    <template v-if="selectedTenant.status !== 'dp'">
                        <div class="flex items-center justify-between">
                            <span>Biaya Sampah</span>
                            <span>-{{ formatCurrency(selectedTenant.trashFee) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Biaya Keamanan</span>
                            <span>-{{ formatCurrency(selectedTenant.securityFee) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Biaya Admin</span>
                            <span>-{{ formatCurrency(selectedTenant.adminFee) }}</span>
                        </div>
                    </template>
                    <div v-if="selectedTenant.status === 'dp'" class="flex items-center justify-between">
                        <span>DP Dibayar</span>
                        <span>-{{ formatCurrency(selectedTenant.dpAmount) }}</span>
                    </div>
                    <div v-if="selectedTenant.status === 'dp' && selectedTenant.dpDueDate" class="flex items-center justify-between">
                        <span>Batas Pelunasan</span>
                        <span>{{ formatDate(selectedTenant.dpDueDate) }}</span>
                    </div>
                    <div class="flex items-center justify-between border-t border-emerald-200 pt-3 text-base font-bold text-emerald-700">
                        <span>Total Tagihan</span>
                        <span>{{ formatCurrency(totalTagihan) }}</span>
                    </div>
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
</template>
