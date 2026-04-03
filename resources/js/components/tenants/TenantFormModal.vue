<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type TenantStatus = 'LUNAS' | 'ON HOLD' | 'DP';

export type TenantFormKostOption = {
    id: string;
    name: string;
    totalUnits: number;
    occupiedUnits: number;
    regionId?: string;
    address?: string | null;
    notes?: string | null;
};

export type EditableTenant = {
    id: string;
    kostId: string;
    name: string;
    phone: string | null;
    startDate: string | null;
    rentPrice: number;
    trashFee: number;
    securityFee: number;
    adminFee: number;
    status: TenantStatus;
    dpAmount: number | null;
    dpPaidAmount?: number;
    dpRemainingAmount?: number;
    dpDueDate: string | null;
    isDp?: boolean;
    prepaidBalance?: number;
    paidUntil?: string | null;
    nextBillingDate?: string | null;
    currentDueAmount?: number;
    totalOutstandingAmount?: number;
    isActive: boolean;
};

export type TenantFormPayload = {
    kost_id: string;
    name: string;
    phone: string;
    start_date: string;
    rent_price: number;
    trash_fee: number;
    security_fee: number;
    admin_fee: number;
    status: TenantStatus;
    dp_amount: number;
    dp_due_date: string;
};

const props = withDefaults(defineProps<{
    open: boolean;
    tenant?: EditableTenant | null;
    kostOptions: TenantFormKostOption[];
    fixedKostId?: string | null;
}>(), {
    tenant: null,
    fixedKostId: null,
});

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'save', payload: TenantFormPayload): void;
}>();

const form = reactive<TenantFormPayload>({
    kost_id: '',
    name: '',
    phone: '',
    start_date: '',
    rent_price: 0,
    trash_fee: 0,
    security_fee: 0,
    admin_fee: 0,
    status: 'LUNAS',
    dp_amount: 0,
    dp_due_date: '',
});

const isEdit = computed(() => Boolean(props.tenant));
const selectedKost = computed(() => props.kostOptions.find((kost) => kost.id === form.kost_id) ?? null);
const canEditKost = computed(() => isEdit.value || !props.fixedKostId);
const maxDpAmount = computed(() => Math.max(0, Number(form.rent_price || 0) - 1));

const effectiveOccupiedUnits = computed(() => {
    if (!selectedKost.value) {
        return 0;
    }

    let occupiedUnits = selectedKost.value.occupiedUnits;

    if (
        props.tenant &&
        props.tenant.kostId === selectedKost.value.id &&
        props.tenant.isActive &&
        ['LUNAS', 'ON HOLD', 'DP'].includes(props.tenant.status)
    ) {
        occupiedUnits = Math.max(0, occupiedUnits - 1);
    }

    return occupiedUnits;
});

const isCapacityFull = computed(() => {
    if (!selectedKost.value) {
        return false;
    }

    if (!['LUNAS', 'ON HOLD', 'DP'].includes(form.status)) {
        return false;
    }

    return effectiveOccupiedUnits.value >= selectedKost.value.totalUnits;
});

const phoneError = computed(() => {
    const raw = form.phone.trim();

    if (!raw) {
        return '';
    }

    if (!/^\+?[\d\s().-]+$/.test(raw)) {
        return 'Nomor HP hanya boleh berisi angka.';
    }

    const digits = raw.replace(/\D/g, '');

    if (digits.length < 10 || digits.length > 15) {
        return 'Nomor HP harus 10-15 digit.';
    }

    return '';
});

const globalError = computed(() => {
    if (!form.name.trim()) {
        return 'Nama lengkap wajib diisi.';
    }

    if (!form.kost_id) {
        return 'Silakan pilih kost terlebih dahulu.';
    }

    if (!form.start_date) {
        return 'Tanggal masuk wajib diisi.';
    }

    if (phoneError.value) {
        return phoneError.value;
    }

    if (isCapacityFull.value) {
        return 'Kost sudah penuh. Silakan pilih kost lain.';
    }

    if (form.status === 'DP' && form.dp_amount <= 0) {
        return 'Nominal DP wajib diisi untuk status DP.';
    }

    if (form.status === 'DP' && form.dp_amount >= Number(form.rent_price || 0)) {
        return 'Nominal DP harus lebih kecil dari biaya sewa bulanan.';
    }

    if (form.status === 'DP' && !form.dp_due_date) {
        return 'Batas pelunasan wajib diisi untuk status DP.';
    }

    return '';
});

const resetForm = () => {
    form.kost_id = props.fixedKostId ?? '';
    form.name = '';
    form.phone = '';
    form.start_date = '';
    form.rent_price = 0;
    form.trash_fee = 0;
    form.security_fee = 0;
    form.admin_fee = 0;
    form.status = 'LUNAS';
    form.dp_amount = 0;
    form.dp_due_date = '';

    if (!props.tenant) {
        return;
    }

    form.kost_id = props.tenant.kostId;
    form.name = props.tenant.name;
    form.phone = props.tenant.phone ?? '';
    form.start_date = props.tenant.startDate ?? '';
    form.rent_price = props.tenant.rentPrice ?? 0;
    form.trash_fee = props.tenant.trashFee ?? 0;
    form.security_fee = props.tenant.securityFee ?? 0;
    form.admin_fee = props.tenant.adminFee ?? 0;
    form.status = props.tenant.status === 'DP' || props.tenant.status === 'ON HOLD' ? props.tenant.status : 'LUNAS';
    form.dp_amount = props.tenant.dpAmount ?? 0;
    form.dp_due_date = props.tenant.dpDueDate ?? '';
};

watch(
    () => [props.open, props.tenant, props.fixedKostId],
    ([open]) => {
        if (open) {
            resetForm();
        }
    },
    { immediate: true },
);

watch(
    () => form.status,
    (status) => {
        if (status !== 'DP') {
            form.dp_amount = 0;
            form.dp_due_date = '';
        }
    },
);

const confirmSaveOpen = ref(false);

const requestSave = () => {
    if (globalError.value) {
        return;
    }

    confirmSaveOpen.value = true;
};

const executeSave = () => {
    confirmSaveOpen.value = false;

    emit('save', {
        kost_id: form.kost_id,
        name: form.name.trim(),
        phone: form.phone.trim(),
        start_date: form.start_date,
        rent_price: Number(form.rent_price || 0),
        trash_fee: Number(form.trash_fee || 0),
        security_fee: Number(form.security_fee || 0),
        admin_fee: Number(form.admin_fee || 0),
        status: form.status,
        dp_amount: Number(form.dp_amount || 0),
        dp_due_date: form.dp_due_date,
    });
};
</script>

<template>
    <BaseModal
        :open="open"
        :title="isEdit ? 'Edit Penyewa' : 'Tambah Penyewa Baru'"
        description="Migrasi form penyewa dari app lama: data diri, status hunian, field DP, dan rincian biaya dalam satu modal."
        max-width-class="sm:max-w-5xl"
        @update:open="emit('update:open', $event)"
    >
        <form id="tenant-form-modal" class="space-y-6" @submit.prevent="requestSave">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
                <div class="space-y-5">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-sky-500/15 text-sky-300 ring-1 ring-sky-400/20">
                            <span class="text-lg">+</span>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-700">Data Diri</h3>
                            <p class="mt-1 text-sm text-slate-500">Form inti penyewa mengikuti susunan form lama.</p>
                        </div>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="tenant-name" class="text-slate-900">Nama Lengkap <span class="text-rose-500">*</span></Label>
                            <Input
                                id="tenant-name"
                                v-model="form.name"
                                placeholder="Masukkan nama lengkap"
                                class="border-slate-200 !bg-white !text-slate-900 placeholder:!text-slate-400"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="tenant-phone" class="text-slate-900">Nomor Handphone</Label>
                            <Input
                                id="tenant-phone"
                                v-model="form.phone"
                                type="tel"
                                inputmode="tel"
                                placeholder="+62 812 3456 7890"
                                class="border-slate-200 !bg-white !text-slate-900 placeholder:!text-slate-400"
                            />
                            <p v-if="phoneError" class="text-sm text-rose-400">{{ phoneError }}</p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="tenant-kost" class="text-slate-900">Pilih Kost <span class="text-rose-500">*</span></Label>
                            <select
                                id="tenant-kost"
                                v-model="form.kost_id"
                                class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900"
                                :disabled="!canEditKost"
                            >
                                <option value="" disabled class="text-slate-900">Pilih Kost</option>
                                <option
                                    v-for="kost in kostOptions"
                                    :key="kost.id"
                                    :value="kost.id"
                                    class="text-slate-900"
                                >
                                    {{ kost.name }}
                                </option>
                            </select>
                            <p v-if="selectedKost" class="text-sm text-slate-500">
                                Terisi {{ effectiveOccupiedUnits }}/{{ selectedKost.totalUnits }} kamar
                            </p>
                            <p v-if="isCapacityFull" class="text-sm text-rose-400">
                                Kost sudah penuh. Silakan pilih kost lain.
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="tenant-start-date" class="text-slate-900">Tanggal Masuk <span class="text-rose-500">*</span></Label>
                            <Input
                                id="tenant-start-date"
                                v-model="form.start_date"
                                type="date"
                                class="border-slate-200 !bg-white !text-slate-900"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="tenant-status" class="text-slate-900">Status <span class="text-rose-500">*</span></Label>
                            <select
                                id="tenant-status"
                                v-model="form.status"
                                class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900"
                            >
                                <option value="LUNAS" class="text-slate-900">LUNAS</option>
                                <option value="ON HOLD" class="text-slate-900">ON HOLD</option>
                                <option value="DP" class="text-slate-900">DP</option>
                            </select>
                        </div>

                        <div v-if="form.status === 'DP'" class="grid gap-2">
                            <Label for="tenant-dp-amount" class="text-slate-900">Nominal DP</Label>
                            <div class="flex items-center overflow-hidden rounded-xl border border-slate-200 bg-white">
                                <span class="px-4 text-sm text-slate-500">Rp</span>
                                <input
                                    id="tenant-dp-amount"
                                    v-model.number="form.dp_amount"
                                    type="number"
                                    min="0"
                                    :max="maxDpAmount"
                                    class="w-full bg-transparent px-0 py-2.5 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                                    placeholder="0"
                                />
                            </div>
                            <p class="text-sm text-slate-500">
                                Maksimal Rp{{ maxDpAmount.toLocaleString('id-ID') }}
                            </p>
                        </div>

                        <div v-if="form.status === 'DP'" class="grid gap-2">
                            <Label for="tenant-dp-due-date" class="text-slate-900">Batas Pelunasan</Label>
                            <Input
                                id="tenant-dp-due-date"
                                v-model="form.dp_due_date"
                                type="date"
                                class="border-slate-200 !bg-white !text-slate-900"
                            />
                        </div>
                    </div>
                </div>

                <div class="rounded-[1.5rem] border border-sky-200 bg-sky-50 p-5">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-sky-100 text-sky-700 ring-1 ring-sky-200">
                            <span class="text-base font-semibold">Rp</span>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-700">Rincian Biaya</h3>
                            <p class="mt-1 text-sm text-slate-500">Susunan biaya dibuat sama seperti modal tenant di app lama.</p>
                        </div>
                    </div>

                    <div class="mt-5 space-y-4">
                        <div class="grid gap-2">
                            <Label for="tenant-rent-price" class="text-slate-900">Biaya Sewa (Per Bulan)</Label>
                            <div class="flex items-center overflow-hidden rounded-xl border border-slate-200 bg-white">
                                <span class="px-4 text-sm text-slate-500">Rp</span>
                                <input
                                    id="tenant-rent-price"
                                    v-model.number="form.rent_price"
                                    type="number"
                                    min="0"
                                    class="w-full bg-transparent px-0 py-2.5 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                                    placeholder="0"
                                />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="tenant-trash-fee" class="text-slate-900">Biaya Sampah</Label>
                            <div class="flex items-center overflow-hidden rounded-xl border border-slate-200 bg-white">
                                <span class="px-4 text-sm text-slate-500">Rp</span>
                                <input
                                    id="tenant-trash-fee"
                                    v-model.number="form.trash_fee"
                                    type="number"
                                    min="0"
                                    class="w-full bg-transparent px-0 py-2.5 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                                    placeholder="0"
                                />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="tenant-security-fee" class="text-slate-900">Biaya Keamanan</Label>
                            <div class="flex items-center overflow-hidden rounded-xl border border-slate-200 bg-white">
                                <span class="px-4 text-sm text-slate-500">Rp</span>
                                <input
                                    id="tenant-security-fee"
                                    v-model.number="form.security_fee"
                                    type="number"
                                    min="0"
                                    class="w-full bg-transparent px-0 py-2.5 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                                    placeholder="0"
                                />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="tenant-admin-fee" class="text-slate-900">Biaya Admin</Label>
                            <div class="flex items-center overflow-hidden rounded-xl border border-slate-200 bg-white">
                                <span class="px-4 text-sm text-slate-500">Rp</span>
                                <input
                                    id="tenant-admin-fee"
                                    v-model.number="form.admin_fee"
                                    type="number"
                                    min="0"
                                    class="w-full bg-transparent px-0 py-2.5 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                                    placeholder="0"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p v-if="globalError" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ globalError }}
            </p>
        </form>

        <template #footer>
            <Button type="button" variant="outline" @click="emit('update:open', false)">Batal</Button>
            <Button type="submit" form="tenant-form-modal" :disabled="Boolean(globalError)">
                Simpan
            </Button>
        </template>
    </BaseModal>

    <ConfirmModal
        :open="confirmSaveOpen"
        :title="isEdit ? 'Simpan Perubahan Penyewa' : 'Tambah Penyewa Baru'"
        :description="isEdit ? `Simpan perubahan data untuk '${form.name.trim()}'?` : `Tambah penyewa baru '${form.name.trim()}'?`"
        confirm-label="Ya, Simpan"
        variant="info"
        @update:open="confirmSaveOpen = $event"
        @confirm="executeSave"
    />
</template>
