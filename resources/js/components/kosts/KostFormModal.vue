<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { TenantFormKostOption } from '@/components/tenants/TenantFormModal.vue';
import type { RegionOption, Viewer } from '@/types/kost';

const props = withDefaults(defineProps<{
    open: boolean;
    viewer: Viewer;
    regions: RegionOption[];
    kost?: TenantFormKostOption | null;
    viewOnly?: boolean;
}>(), {
    kost: null,
    viewOnly: false,
});

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'save', payload: {
        region_id: string;
        name: string;
        address: string;
        total_units: number;
        notes: string;
    }): void;
    (e: 'delete'): void;
}>();

const form = reactive({
    region_id: '',
    name: '',
    address: '',
    total_units: 1,
    notes: '',
});

const isEditMode = computed(() => Boolean(props.kost));
const isOwner = computed(() => props.viewer.role === 'owner');
const activeTenantCount = computed(() => props.kost?.occupiedUnits ?? 0);
const unitError = computed(() => {
    if (!isEditMode.value) {
        return '';
    }
    if (form.total_units < activeTenantCount.value) {
        return `Jumlah unit tidak boleh kurang dari jumlah penyewa aktif (${activeTenantCount.value}).`;
    }
    return '';
});

const errorMessage = computed(() => {
    if ((isOwner.value || props.viewer.role === 'it') && !form.region_id) {
        return 'Region wajib dipilih.';
    }
    if (!form.name.trim()) {
        return 'Nama kost wajib diisi.';
    }
    if (form.total_units < Math.max(1, activeTenantCount.value)) {
        return unitError.value;
    }
    return '';
});

const resetForm = () => {
    form.region_id = props.kost?.regionId ?? props.regions.find((region) => region.id !== 'all')?.id ?? '';
    form.name = props.kost?.name ?? '';
    form.address = props.kost?.address ?? '';
    form.total_units = props.kost?.totalUnits ?? 1;
    form.notes = props.kost?.notes ?? '';
};

watch(
    () => [props.open, props.kost],
    ([open]) => {
        if (open) {
            resetForm();
        }
    },
    { immediate: true },
);

const confirmSaveOpen = ref(false);
const confirmDeleteOpen = ref(false);

const requestSave = () => {
    if (props.viewOnly || errorMessage.value) {
        return;
    }

    confirmSaveOpen.value = true;
};

const executeSave = () => {
    confirmSaveOpen.value = false;

    emit('save', {
        region_id: form.region_id,
        name: form.name.trim(),
        address: form.address.trim(),
        total_units: form.total_units,
        notes: form.notes.trim(),
    });
};

const requestDelete = () => {
    confirmDeleteOpen.value = true;
};

const executeDelete = () => {
    confirmDeleteOpen.value = false;
    emit('delete');
};
</script>

<template>
    <BaseModal
        :open="open"
        :title="viewOnly ? 'Kost Detail' : isEditMode ? 'Edit Kost' : 'Tambah Kost Baru'"
        :description="viewOnly ? 'Detail informasi kost (hanya lihat).' : isEditMode ? 'Perbarui informasi kost Anda.' : 'Isi informasi kost baru Anda.'"
        max-width-class="sm:max-w-2xl"
        @update:open="emit('update:open', $event)"
    >
        <form id="kost-form-modal" class="space-y-5" @submit.prevent="requestSave">
            <div v-if="isOwner || viewer.role === 'it'" class="grid gap-2">
                <Label class="text-slate-900">Region <span class="text-rose-500">*</span></Label>
                <select
                    v-model="form.region_id"
                    class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900"
                    :disabled="isEditMode || viewOnly"
                >
                    <option value="" disabled class="text-slate-900">Pilih Region</option>
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

            <div class="grid gap-2">
                <Label class="text-slate-900">Nama Kost <span class="text-rose-500">*</span></Label>
                <Input
                    v-model="form.name"
                    placeholder="Contoh: Kost Harmoni"
                    class="border-slate-200 !bg-white !text-slate-900 placeholder:!text-slate-400"
                    :disabled="viewOnly"
                />
            </div>

            <div class="grid gap-2">
                <Label class="text-slate-900">Alamat</Label>
                <textarea
                    v-model="form.address"
                    rows="2"
                    class="min-h-20 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                    placeholder="Contoh: Jl. Merdeka No. 123, Jakarta"
                    :disabled="viewOnly"
                />
            </div>

            <div class="grid gap-2">
                <Label class="text-slate-900">Jumlah Unit/Kamar <span class="text-rose-500">*</span></Label>
                <Input
                    v-model.number="form.total_units"
                    type="number"
                    :min="Math.max(1, activeTenantCount)"
                    class="border-slate-200 !bg-white !text-slate-900"
                    :disabled="viewOnly"
                />
                <p v-if="isEditMode" class="text-sm text-slate-400">Penyewa aktif saat ini: {{ activeTenantCount }}</p>
                <p v-if="unitError" class="text-sm text-rose-300">{{ unitError }}</p>
            </div>

            <div class="grid gap-2">
                <Label class="text-slate-900">Catatan</Label>
                <textarea
                    v-model="form.notes"
                    rows="3"
                    class="min-h-24 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                    placeholder="Catatan tambahan tentang kost..."
                    :disabled="viewOnly"
                />
            </div>

            <div v-if="isEditMode && !viewOnly" class="rounded-2xl border border-rose-200 bg-rose-50 p-4">
                <Button type="button" variant="outline" class="border-rose-200 text-rose-700" @click="requestDelete">
                    Hapus Kost
                </Button>
                <p class="mt-3 text-sm text-slate-500">
                    {{
                        activeTenantCount > 0
                            ? 'Kost masih memiliki penyewa aktif dan sebaiknya tidak dihapus.'
                            : 'Tindakan ini akan menghapus kost. Pastikan Anda yakin.'
                    }}
                </p>
            </div>

            <p v-if="errorMessage" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ errorMessage }}
            </p>
        </form>

        <template #footer>
            <Button v-if="viewOnly" type="button" variant="outline" @click="emit('update:open', false)">Tutup</Button>
            <template v-else>
                <Button type="button" variant="outline" @click="emit('update:open', false)">Batal</Button>
                <Button type="submit" form="kost-form-modal" :disabled="Boolean(errorMessage)">
                    {{ isEditMode ? 'Simpan Perubahan' : 'Tambah Kost' }}
                </Button>
            </template>
        </template>
    </BaseModal>

    <ConfirmModal
        :open="confirmSaveOpen"
        :title="isEditMode ? 'Simpan Perubahan Kost' : 'Tambah Kost Baru'"
        :description="isEditMode ? `Simpan perubahan untuk kost '${form.name.trim()}'?` : `Tambah kost baru '${form.name.trim()}'?`"
        confirm-label="Ya, Simpan"
        variant="info"
        @update:open="confirmSaveOpen = $event"
        @confirm="executeSave"
    />

    <ConfirmModal
        :open="confirmDeleteOpen"
        title="Hapus Kost"
        :description="`Yakin ingin menghapus kost '${form.name.trim()}'? Tindakan ini tidak dapat dibatalkan.`"
        confirm-label="Ya, Hapus"
        variant="danger"
        @update:open="confirmDeleteOpen = $event"
        @confirm="executeDelete"
    />
</template>
