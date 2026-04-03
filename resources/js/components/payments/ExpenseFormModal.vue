<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { TenantFormKostOption } from '@/components/tenants/TenantFormModal.vue';
import type { RegionOption, Viewer } from '@/types/kost';

const props = defineProps<{
    open: boolean;
    viewer: Viewer;
    regions: RegionOption[];
    kostOptions: TenantFormKostOption[];
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'save', payload: {
        tingkat: 'region' | 'kost';
        region_id: string;
        kost_id: string;
        category: string;
        description: string;
        amount: number;
        transaction_date: string;
    }): void;
}>();

const form = reactive({
    tingkat: '' as '' | 'region' | 'kost',
    region_id: '',
    kost_id: '',
    category: '',
    description: '',
    amount: 0,
    transaction_date: new Date().toISOString().split('T')[0] ?? '',
});

const hasRegionAccess = computed(() => props.regions.filter((r: RegionOption) => r.id !== 'all').length > 0);
const filteredKosts = computed(() => props.kostOptions.filter((kost) => kost.regionId === form.region_id));

const errorMessage = computed(() => {
    if (!hasRegionAccess.value) {
        return 'Anda tidak memiliki akses region untuk menambah pengeluaran.';
    }
    if (!form.tingkat) {
        return 'Pilih tingkat pengeluaran terlebih dahulu.';
    }
    if (!form.region_id) {
        return 'Pilih region terlebih dahulu.';
    }
    if (form.tingkat === 'kost' && !form.kost_id) {
        return 'Pilih kost terlebih dahulu.';
    }
    if (!form.category) {
        return 'Tipe pengeluaran wajib dipilih.';
    }
    if (form.amount <= 0) {
        return 'Jumlah biaya harus lebih besar dari 0.';
    }
    if (!form.transaction_date) {
        return 'Tanggal transaksi wajib diisi.';
    }
    return '';
});

const resetForm = () => {
    form.tingkat = '';
    form.region_id = props.regions[0]?.id === 'all' ? props.regions[1]?.id ?? '' : props.regions[0]?.id ?? '';
    form.kost_id = '';
    form.category = '';
    form.description = '';
    form.amount = 0;
    form.transaction_date = new Date().toISOString().split('T')[0] ?? '';
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
    () => form.tingkat,
    () => {
        form.kost_id = '';
    },
);

watch(
    () => form.region_id,
    () => {
        form.kost_id = '';
    },
);

const confirmSaveOpen = ref(false);

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
    }).format(amount);

const requestSave = () => {
    if (errorMessage.value) {
        return;
    }

    confirmSaveOpen.value = true;
};

const executeSave = () => {
    confirmSaveOpen.value = false;

    emit('save', {
        ...form,
        tingkat: form.tingkat as 'region' | 'kost',
    });
};
</script>

<template>
    <BaseModal
        :open="open"
        title="Detail Pengeluaran"
        description="Isi informasi biaya pemeliharaan properti seperti pada app lama, dengan tingkat region atau kost."
        max-width-class="sm:max-w-2xl"
        @update:open="emit('update:open', $event)"
    >
        <form id="expense-form-modal" class="space-y-5" @submit.prevent="requestSave">
            <div class="grid gap-2">
                <Label class="text-slate-900">Pilih Tingkat <span class="text-rose-500">*</span></Label>
                <select
                    v-model="form.tingkat"
                    class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900"
                    :disabled="!hasRegionAccess"
                >
                    <option value="" disabled class="text-slate-900">Pilih tingkat</option>
                    <option value="region" class="text-slate-900">Region</option>
                    <option value="kost" class="text-slate-900">Kost</option>
                </select>
                <p v-if="!hasRegionAccess" class="text-sm text-rose-300">
                    Anda tidak memiliki akses region untuk menambah pengeluaran.
                </p>
            </div>

            <div v-if="form.tingkat" class="grid gap-2">
                <Label class="text-slate-900">Pilih Region <span class="text-rose-500">*</span></Label>
                <select
                    v-model="form.region_id"
                    class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900"
                >
                    <option value="" disabled class="text-slate-900">Pilih region</option>
                    <option
                        v-for="region in regions.filter((region) => region.id !== 'all')"
                        :key="region.id"
                        :value="region.id"
                        class="text-slate-900"
                    >
                        {{ region.name }}
                    </option>
                </select>
            </div>

            <div v-if="form.tingkat === 'kost' && form.region_id" class="grid gap-2">
                <Label class="text-slate-900">Pilih Kost <span class="text-rose-500">*</span></Label>
                <select
                    v-model="form.kost_id"
                    class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900"
                >
                    <option value="" disabled class="text-slate-900">Pilih kost</option>
                    <option v-for="kost in filteredKosts" :key="kost.id" :value="kost.id" class="text-slate-900">
                        {{ kost.name }}
                    </option>
                </select>
                <p v-if="filteredKosts.length === 0" class="text-sm text-slate-500">Tidak ada kost di region ini.</p>
            </div>

            <div class="grid gap-2">
                <Label class="text-slate-900">Tipe Pengeluaran <span class="text-rose-500">*</span></Label>
                <select
                    v-model="form.category"
                    class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900"
                >
                    <option value="" disabled class="text-slate-900">Pilih tipe pengeluaran</option>
                    <option value="electricity" class="text-slate-900">Token Listrik</option>
                    <option value="water" class="text-slate-900">Air/PDAM</option>
                    <option value="trashnsecurity" class="text-slate-900">Iuran Sampah dan Keamanan</option>
                    <option value="maintenance_and_repair" class="text-slate-900">Perawatan dan Pemeliharaan</option>
                    <option value="renovation" class="text-slate-900">Renovasi</option>
                    <option value="other" class="text-slate-900">Lainnya</option>
                </select>
            </div>

            <div class="grid gap-2">
                <Label class="text-slate-900">Keterangan</Label>
                <textarea
                    v-model="form.description"
                    rows="3"
                    maxlength="500"
                    class="min-h-24 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                    placeholder="Contoh: Penggantian keran air di kamar 102 yang bocor..."
                />
                <p class="text-right text-xs text-slate-500">{{ form.description.length }}/500 karakter</p>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label class="text-slate-900">Jumlah Biaya <span class="text-rose-500">*</span></Label>
                    <div class="flex items-center overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <span class="px-4 text-sm text-slate-500">Rp</span>
                        <input
                            v-model.number="form.amount"
                            type="number"
                            min="0"
                            class="w-full bg-transparent px-0 py-2.5 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                            placeholder="0"
                        />
                        <span class="pr-4 text-xs uppercase tracking-[0.16em] text-slate-500">IDR</span>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label class="text-slate-900">Tanggal Transaksi <span class="text-rose-500">*</span></Label>
                    <Input
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
            <Button type="submit" form="expense-form-modal" :disabled="Boolean(errorMessage)">
                Simpan Data
            </Button>
        </template>
    </BaseModal>

    <ConfirmModal
        :open="confirmSaveOpen"
        title="Konfirmasi Pengeluaran"
        :description="`Simpan pengeluaran ${formatCurrency(form.amount)} untuk kategori ${form.category || '-'}?`"
        confirm-label="Ya, Simpan"
        variant="warning"
        @update:open="confirmSaveOpen = $event"
        @confirm="executeSave"
    />
</template>
