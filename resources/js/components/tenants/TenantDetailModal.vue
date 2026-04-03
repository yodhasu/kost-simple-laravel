<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
        LUNAS: 'LUNAS',
        DP: 'DP',
        'BELUM LUNAS': 'BELUM LUNAS',
        'JATUH TEMPO': 'JATUH TEMPO',
        'TELAT BAYAR': 'TELAT BAYAR',
        'ON HOLD': 'ON HOLD',
    })[status] ?? status;

const statusTone = (status: string) =>
    ({
        LUNAS: 'bg-emerald-100 text-emerald-700',
        DP: 'bg-amber-100 text-amber-700',
        'BELUM LUNAS': 'bg-rose-100 text-rose-700',
        'JATUH TEMPO': 'bg-orange-100 text-orange-800',
        'TELAT BAYAR': 'bg-rose-100 text-rose-700',
        'ON HOLD': 'bg-sky-100 text-sky-700',
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

const formatDate = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('id-ID', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        }).format(new Date(value))
        : '-';

const netRevenue = computed(() => {
    if (!props.tenant) {
        return 0;
    }
    return props.tenant.rentPrice - props.tenant.trashFee - props.tenant.securityFee - props.tenant.adminFee;
});

const formatBillingMonth = (value: Date) =>
    new Intl.DateTimeFormat('id-ID', {
        month: 'long',
        year: 'numeric',
    }).format(value);

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

const billingItems = computed(() => {
    if (!props.tenant || props.tenant.isDp || props.tenant.status === 'LUNAS' || !props.tenant.nextBillingDate) {
        return [];
    }

    const rentPrice = Math.max(0, props.tenant.rentPrice);
    const carryover = Math.max(0, props.tenant.prepaidBalance);
    const totalOutstanding = Math.max(0, props.tenant.totalOutstandingAmount ?? 0);

    if (rentPrice <= 0 && carryover <= 0 && totalOutstanding <= 0) {
        return [];
    }

    const billedCycleCount = rentPrice > 0
        ? Math.ceil((totalOutstanding + carryover) / rentPrice)
        : 0;

    return Array.from({ length: billedCycleCount }, (_, index) => {
        const cycleDate = addMonths(props.tenant!.nextBillingDate!, index);

        return {
            key: `${props.tenant!.id}-${index}`,
            label: `Biaya Sewa ${formatBillingMonth(cycleDate)}`,
            rent: rentPrice,
        };
    });
});

const summaryLabel = computed(() => {
    if (!props.tenant) {
        return 'Pendapatan Bersih';
    }

    return props.tenant.status === 'LUNAS' ? 'Pendapatan Bersih' : 'Total Tagihan';
});

const summaryAmount = computed(() => {
    if (!props.tenant) {
        return 0;
    }

    return props.tenant.status === 'LUNAS'
        ? netRevenue.value
        : (props.tenant.totalOutstandingAmount ?? props.tenant.currentDueAmount);
});

const dpRemaining = computed(() => {
    if (!props.tenant) {
        return 0;
    }
    return props.tenant.dpRemainingAmount ?? Math.max(0, props.tenant.rentPrice - (props.tenant.dpAmount ?? 0));
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
            dpPaidAmount: props.tenant.dpPaidAmount,
            dpRemainingAmount: props.tenant.dpRemainingAmount,
            dpDueDate: props.tenant.dpDueDate,
            isDp: props.tenant.isDp,
            prepaidBalance: props.tenant.prepaidBalance,
            paidUntil: props.tenant.paidUntil,
            nextBillingDate: props.tenant.nextBillingDate,
            currentDueAmount: props.tenant.currentDueAmount,
            totalOutstandingAmount: props.tenant.totalOutstandingAmount,
            isActive: props.tenant.isActive,
        }]
        : [],
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            showPaymentModal.value = false;
        }
    },
);

watch(
    () => props.tenant?.id,
    () => {
        showPaymentModal.value = false;
    },
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
                    <template v-if="tenant.isDp">
                        <div class="flex items-center justify-between">
                            <span>Biaya Sewa</span>
                            <span>{{ formatCurrency(tenant.rentPrice) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>DP Dibayar</span>
                            <span>-{{ formatCurrency(tenant.dpPaidAmount ?? tenant.dpAmount) }}</span>
                        </div>
                        <div class="flex items-center justify-between" v-if="tenant.dpDueDate">
                            <span>Tanggal Jatuh Tempo</span>
                            <span>{{ formatDate(tenant.dpDueDate) }}</span>
                        </div>
                        <div class="flex items-center justify-between border-t border-emerald-200 pt-3 font-bold text-emerald-700">
                            <span>Biaya Pelunasan</span>
                            <span>{{ formatCurrency(dpRemaining) }}</span>
                        </div>
                    </template>
                    <template v-else-if="tenant.status === 'LUNAS'">
                        <div v-if="tenant.nextBillingDate" class="flex items-center justify-between">
                            <span>Tagihan Berikutnya</span>
                            <span>{{ formatDate(tenant.nextBillingDate) }}</span>
                        </div>
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
                            <span>{{ summaryLabel }}</span>
                            <span>{{ formatCurrency(summaryAmount) }}</span>
                        </div>
                    </template>
                    <template v-else>
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
                            <span>-{{ formatCurrency(tenant.prepaidBalance) }}</span>
                        </div>
                        <div class="flex items-center justify-between border-t border-emerald-200 pt-3 font-bold text-emerald-700">
                            <span>{{ summaryLabel }}</span>
                            <span>{{ formatCurrency(summaryAmount) }}</span>
                        </div>
                    </template>
                </div>
            </div>

            <div class="space-y-3">
                <a
                    v-if="tenant.isActive && tenant.phone"
                    :href="whatsappLink"
                    target="_blank"
                    class="flex items-center justify-center rounded-2xl bg-emerald-500 px-4 py-3 text-sm font-semibold text-white"
                >
                    Beritahu via WhatsApp
                </a>
                <div v-else-if="tenant.isActive" class="flex items-center justify-center rounded-2xl bg-slate-200 px-4 py-3 text-sm font-semibold text-slate-600">
                    Nomor WhatsApp Tidak Tersedia
                </div>

                <div v-if="tenant.isActive" class="grid gap-3 sm:grid-cols-3">
                    <Button type="button" variant="outline" :disabled="tenant.status === 'ON HOLD'" @click="showPaymentModal = true">
                        Update Pembayaran
                    </Button>
                    <Button type="button" variant="outline" @click="emit('edit')">
                        Edit Penyewa
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
