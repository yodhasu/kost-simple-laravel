<script setup lang="ts">
import { computed, ref } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import { Button } from '@/components/ui/button';
import PaymentUpdateModal, { type PaymentTenantOption } from '@/components/payments/PaymentUpdateModal.vue';
import type { TenantFormKostOption } from '@/components/tenants/TenantFormModal.vue';

type TenantDetail = {
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
    dpDueDate: string | null;
    isActive: boolean;
};

const props = defineProps<{
    open: boolean;
    tenant: TenantDetail | null;
    kostOptions: TenantFormKostOption[];
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'edit'): void;
    (e: 'set-inactive'): void;
    (e: 'pay', payload: { kost_id: string; tenant_id: string; amount: number; transaction_date: string }): void;
}>();

const showPaymentModal = ref(false);

const initials = computed(() =>
    props.tenant?.name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase() ?? '',
);

const avatarTone = computed(() => {
    const colors = ['bg-orange-200', 'bg-cyan-200', 'bg-pink-200', 'bg-violet-200', 'bg-sky-200', 'bg-emerald-200'];
    if (!props.tenant) {
        return colors[0];
    }
    return colors[props.tenant.name.charCodeAt(0) % colors.length];
});

const statusLabel = (status: string) =>
    ({
        aktif: 'Aktif',
        dp: 'DP',
        telat: 'Telat',
        renovasi: 'Renovasi',
        pindah: 'Pindahan/Kosong',
        inaktif: 'Tidak Aktif',
    })[status] ?? status;

const statusTone = (status: string) =>
    ({
        aktif: 'bg-emerald-100 text-emerald-700',
        dp: 'bg-amber-100 text-amber-700',
        telat: 'bg-rose-100 text-rose-700',
        renovasi: 'bg-sky-100 text-sky-700',
        pindah: 'bg-slate-200 text-slate-700',
        inaktif: 'bg-slate-100 text-slate-600',
    })[status] ?? 'bg-slate-100 text-slate-600';

const formatCurrency = (amount: number | null) =>
    new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
    }).format(amount ?? 0);

const formatDateMonth = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('id-ID', { month: 'short', year: 'numeric' }).format(new Date(value))
        : '-';

const netRevenue = computed(() => {
    if (!props.tenant) {
        return 0;
    }
    return props.tenant.rentPrice - props.tenant.trashFee - props.tenant.securityFee - props.tenant.adminFee;
});

const dpRemaining = computed(() => {
    if (!props.tenant) {
        return 0;
    }
    return Math.max(0, props.tenant.rentPrice - (props.tenant.dpAmount ?? 0));
});

const whatsappLink = computed(() => {
    const phone = props.tenant?.phone;
    if (!phone) {
        return '#';
    }
    let normalized = phone.replace(/\D/g, '');
    if (normalized.startsWith('0')) {
        normalized = `62${normalized.slice(1)}`;
    }
    return `https://wa.me/${normalized}`;
});

const paymentTenant = computed<PaymentTenantOption[]>(() =>
    props.tenant
        ? [{
            id: props.tenant.id,
            kostId: props.tenant.kostId,
            name: props.tenant.name,
            status: props.tenant.status,
            rentPrice: props.tenant.rentPrice,
            trashFee: props.tenant.trashFee,
            securityFee: props.tenant.securityFee,
            adminFee: props.tenant.adminFee,
            dpAmount: props.tenant.dpAmount,
            dpDueDate: props.tenant.dpDueDate,
            isActive: props.tenant.isActive,
        }]
        : [],
);
</script>

<template>
    <BaseModal
        :open="open"
        title="Detail Penyewa"
        description="Ringkasan profil, status pembayaran, dan aksi cepat tenant seperti di app lama."
        max-width-class="sm:max-w-2xl"
        @update:open="emit('update:open', $event)"
    >
        <div v-if="tenant" class="space-y-6">
            <div class="flex flex-col items-center">
                <div :class="avatarTone" class="flex size-20 items-center justify-center rounded-[1.25rem] text-2xl font-bold text-slate-900">
                    {{ initials }}
                </div>
                <h3 class="mt-4 text-2xl font-bold text-slate-950">{{ tenant.name }}</h3>
                <p class="mt-1 text-sm text-slate-500">Masuk sejak {{ formatDateMonth(tenant.startDate) }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Harga Sewa</p>
                    <p class="mt-2 text-base font-semibold text-slate-950">{{ formatCurrency(tenant.rentPrice) }}/bln</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">WhatsApp</p>
                    <p class="mt-2 text-base font-semibold text-slate-950">{{ tenant.phone || '-' }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Status</p>
                    <span class="mt-2 inline-flex rounded-full px-3 py-1 text-xs font-semibold" :class="statusTone(tenant.status)">
                        {{ statusLabel(tenant.status) }}
                    </span>
                </div>
            </div>

            <div class="rounded-[1.35rem] border border-emerald-200 bg-emerald-50 p-5">
                <p class="text-sm font-semibold text-emerald-700">Rincian Biaya</p>
                <div class="mt-4 space-y-2 text-sm text-emerald-800">
                    <template v-if="tenant.status === 'dp'">
                        <div class="flex items-center justify-between">
                            <span>Biaya Sewa</span>
                            <span>{{ formatCurrency(tenant.rentPrice) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>DP Dibayar</span>
                            <span>-{{ formatCurrency(tenant.dpAmount) }}</span>
                        </div>
                        <div class="flex items-center justify-between border-t border-emerald-200 pt-3 font-bold text-emerald-700">
                            <span>Biaya Pelunasan</span>
                            <span>{{ formatCurrency(dpRemaining) }}</span>
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex items-center justify-between">
                            <span>Biaya Sewa</span>
                            <span>{{ formatCurrency(tenant.rentPrice) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Biaya Sampah</span>
                            <span>-{{ formatCurrency(tenant.trashFee) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Biaya Keamanan</span>
                            <span>-{{ formatCurrency(tenant.securityFee) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Biaya Admin</span>
                            <span>-{{ formatCurrency(tenant.adminFee) }}</span>
                        </div>
                        <div class="flex items-center justify-between border-t border-emerald-200 pt-3 font-bold text-emerald-700">
                            <span>Pendapatan Bersih</span>
                            <span>{{ formatCurrency(netRevenue) }}</span>
                        </div>
                    </template>
                </div>
            </div>

            <div class="space-y-3">
                <a
                    v-if="tenant.status !== 'inaktif' && tenant.phone"
                    :href="whatsappLink"
                    target="_blank"
                    class="flex items-center justify-center rounded-2xl bg-emerald-500 px-4 py-3 text-sm font-semibold text-white"
                >
                    Beritahu via WhatsApp
                </a>
                <div v-else-if="tenant.status !== 'inaktif'" class="flex items-center justify-center rounded-2xl bg-slate-200 px-4 py-3 text-sm font-semibold text-slate-600">
                    Nomor WhatsApp Tidak Tersedia
                </div>

                <div v-if="tenant.status !== 'inaktif'" class="grid gap-3 sm:grid-cols-2">
                    <Button type="button" variant="outline" @click="tenant.status === 'dp' ? (showPaymentModal = true) : emit('edit')">
                        {{ tenant.status === 'dp' ? 'Update Pembayaran' : 'Edit Penyewa' }}
                    </Button>
                    <Button type="button" class="bg-rose-600 text-white hover:bg-rose-700" @click="emit('set-inactive')">
                        Hapus
                    </Button>
                </div>
            </div>
        </div>

        <PaymentUpdateModal
            :open="showPaymentModal"
            :kost-options="kostOptions"
            :tenants="paymentTenant"
            :fixed-kost-id="tenant?.kostId ?? null"
            :fixed-tenant-id="tenant?.id ?? null"
            @update:open="showPaymentModal = $event"
            @save="emit('pay', $event)"
        />
    </BaseModal>
</template>
