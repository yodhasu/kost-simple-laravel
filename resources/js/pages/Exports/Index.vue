<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { CalendarRange, Download, MapPinned } from 'lucide-vue-next';
import ConfirmModal from '@/components/ConfirmModal.vue';
import { Input } from '@/components/ui/input';
import type { RegionOption, Viewer } from '@/types/kost';

const props = defineProps<{
    viewer: Viewer;
    regions: RegionOption[];
    dataTypes: Array<{
        value: string;
        label: string;
    }>;
}>();

const form = ref({
    startDate: '',
    endDate: '',
    regionId: 'all',
    dataTypes: ['tenants'],
});

const submitted = ref(false);
const exportError = ref('');

const dateRangeError = computed(() => {
    if (!form.value.startDate || !form.value.endDate) {
        return '';
    }

    return form.value.endDate < form.value.startDate
        ? 'Tanggal selesai harus setelah tanggal mulai.'
        : '';
});

const canExport = computed(
    () =>
        !!form.value.startDate &&
        !!form.value.endDate &&
        form.value.dataTypes.length > 0 &&
        !dateRangeError.value,
);

const confirmExportOpen = ref(false);
const exporting = ref(false);

const requestExport = () => {
    exportError.value = '';
    submitted.value = true;

    if (!canExport.value) {
        return;
    }

    confirmExportOpen.value = true;
};

const executeExport = () => {
    exporting.value = true;
    exportError.value = '';
    confirmExportOpen.value = false;

    const params = new URLSearchParams({
        start_date: form.value.startDate,
        end_date: form.value.endDate,
    });

    if (form.value.regionId && form.value.regionId !== 'all') {
        params.set('region_id', form.value.regionId);
    }

    form.value.dataTypes.forEach((type) => {
        params.append('data_types[]', type);
    });

    window.location.href = `/api/exports/download?${params.toString()}`;
};
</script>

<template>
    <Head title="Ekspor Data" />

    <section class="space-y-2 md:space-y-5">
        <!-- Desktop hero (hidden on mobile) -->
        <div class="hidden rounded-4xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70 lg:block">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-teal-700">Export Center</p>
            <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950">Siapkan unduhan Excel laporan monetisasi</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                Pilih rentang tanggal, region, dan jenis data yang ingin dibawa keluar dari sistem.
                Untuk pilihan Laporan Keuangan, sistem akan membuat workbook manajemen yang berisi ringkasan kolektibilitas,
                laba bersih, piutang penyewa, detail pembayaran, detail pengeluaran, dan rekonsiliasi.
            </p>
        </div>

        <article class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200/70 md:rounded-4xl md:p-5">
            <!-- Date range -->
            <div class="grid grid-cols-2 gap-2 md:gap-5">
                <label class="block">
                    <span class="mb-1 inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500 md:mb-2 md:gap-2 md:text-xs md:tracking-[0.2em]">
                        <CalendarRange class="size-3 md:size-4" />
                        Dari
                    </span>
                    <Input
                        v-model="form.startDate"
                        type="date"
                        class="w-full rounded-lg border border-slate-200 bg-white! px-2.5 py-3 text-xs text-slate-700 focus:border-teal-500 focus:outline-none md:rounded-2xl md:px-4 md:py-2.5 md:text-base"
                    />
                </label>

                <label class="block">
                    <span class="mb-1 inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500 md:mb-2 md:gap-2 md:text-xs md:tracking-[0.2em]">
                        <CalendarRange class="size-3 md:size-4" />
                        Sampai
                    </span>
                    <Input
                        v-model="form.endDate"
                        type="date"
                        class="w-full rounded-lg border border-slate-200 bg-white! px-2.5 py-3 text-xs text-slate-700 focus:border-teal-500 focus:outline-none md:rounded-2xl md:px-4 md:py-2.5 md:text-base"
                    />
                </label>
            </div>
            <div v-if="submitted && dateRangeError" class="mt-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700 md:rounded-2xl md:px-4 md:py-3 md:text-base">
                {{ dateRangeError }}
            </div>

            <!-- Region -->
            <label class="mt-3 block md:mt-5">
                <span class="mb-1 inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500 md:mb-2 md:gap-2 md:text-xs md:tracking-[0.2em]">
                    <MapPinned class="size-3 md:size-4" />
                    Region
                </span>
                <select
                    v-model="form.regionId"
                    class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-3 text-xs text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200 md:rounded-2xl md:px-4 md:py-2.5 md:text-base"
                >
                    <option v-for="region in regions" :key="region.id" :value="region.id">
                        {{ region.name }}
                    </option>
                </select>
            </label>

            <!-- Data types -->
            <div class="mt-3 space-y-1.5 md:mt-5 md:space-y-2.5">
                <p class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500 md:text-xs md:tracking-[0.2em]">Jenis Data</p>
                <div class="grid grid-cols-2 gap-1.5 md:gap-2.5 lg:grid-cols-2">
                    <label
                        v-for="item in props.dataTypes"
                        :key="item.value"
                        class="flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 px-2.5 py-2 text-xs text-slate-700 transition-colors has-[:checked]:border-teal-300 has-[:checked]:bg-teal-50/60 md:min-h-0 md:gap-2.5 md:rounded-2xl md:px-4 md:py-4 md:text-base"
                    >
                        <input v-model="form.dataTypes" :value="item.value" type="checkbox" class="size-4 accent-teal-600 md:size-4" />
                        <span class="font-medium">{{ item.label }}</span>
                    </label>
                </div>
            </div>

            <!-- Actions -->
            <div class="mt-3 md:mt-5 md:flex md:items-center md:justify-between md:gap-2.5">
                <p class="hidden text-sm leading-6 text-slate-500 md:block">
                    Laporan Keuangan tidak lagi berupa dump database, tetapi workbook monetisasi yang lebih siap dibaca owner dan admin.
                </p>
                <button
                    type="button"
                    class="inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-xs font-semibold text-white transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-2 md:min-h-0 md:w-auto md:min-w-40 md:rounded-2xl md:px-5 md:py-2.5 md:text-base"
                    :class="canExport && !exporting ? 'bg-teal-600 hover:bg-teal-700 active:scale-[0.99]' : 'bg-slate-300 text-slate-500'"
                    :disabled="!canExport || exporting"
                    @click="requestExport"
                >
                    <Download class="size-3.5 md:size-4" />
                    {{ exporting ? 'Menyiapkan...' : 'Unduh Data' }}
                </button>
            </div>
            <div v-if="exportError" class="mt-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 md:mt-2.5 md:rounded-2xl md:px-4 md:py-3 md:text-base">{{ exportError }}</div>
            <p v-if="submitted && canExport" class="mt-2 text-xs font-medium text-emerald-700 md:mt-2.5 md:text-base">
                Export dikirim ke endpoint Laravel. Jika data valid, browser akan langsung mengunduh satu file `.xlsx`
                berisi sheet sesuai pilihan Anda.
            </p>
        </article>
    </section>

    <ConfirmModal
        :open="confirmExportOpen"
        title="Unduh Data Export"
        :description="`Unduh file Excel untuk periode ${form.startDate} s/d ${form.endDate}?`"
        confirm-label="Ya, Unduh"
        variant="info"
        @update:open="confirmExportOpen = $event"
        @confirm="executeExport"
    />
</template>
