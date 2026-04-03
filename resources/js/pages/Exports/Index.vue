<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { CalendarRange, Download, MapPinned } from 'lucide-vue-next';
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

const handleExport = () => {
    exportError.value = '';
    submitted.value = true;

    if (!canExport.value) {
        return;
    }

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
            <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950">Siapkan unduhan Excel multi-sheet</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                Pilih rentang tanggal, region, dan jenis data yang ingin dibawa keluar dari sistem.
                Semua pilihan akan digabung ke satu file Excel multi-sheet agar lebih mudah dibaca dan dibagikan.
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
                        class="w-full rounded-lg border border-slate-200 bg-white! px-2.5 py-2 text-xs text-slate-700 focus:border-teal-500 focus:outline-none md:rounded-2xl md:px-4 md:py-2.5 md:text-base"
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
                        class="w-full rounded-lg border border-slate-200 bg-white! px-2.5 py-2 text-xs text-slate-700 focus:border-teal-500 focus:outline-none md:rounded-2xl md:px-4 md:py-2.5 md:text-base"
                    />
                </label>
            </div>
            <p v-if="dateRangeError" class="mt-1 text-xs font-medium text-rose-600 md:mt-2 md:text-base">{{ dateRangeError }}</p>

            <!-- Region -->
            <label class="mt-3 block md:mt-5">
                <span class="mb-1 inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500 md:mb-2 md:gap-2 md:text-xs md:tracking-[0.2em]">
                    <MapPinned class="size-3 md:size-4" />
                    Region
                </span>
                <select
                    v-model="form.regionId"
                    class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs text-slate-700 focus:border-teal-500 focus:outline-none md:rounded-2xl md:px-4 md:py-2.5 md:text-base"
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
                        class="flex items-center gap-2 rounded-lg border border-slate-200 px-2.5 py-2 text-xs text-slate-700 md:gap-2.5 md:rounded-2xl md:px-4 md:py-4 md:text-base"
                    >
                        <input v-model="form.dataTypes" :value="item.value" type="checkbox" class="size-3.5 accent-teal-600 md:size-4" />
                        <span class="font-medium">{{ item.label }}</span>
                    </label>
                </div>
            </div>

            <!-- Actions -->
            <div class="mt-3 md:mt-5 md:flex md:items-center md:justify-between md:gap-2.5">
                <p class="hidden text-sm leading-6 text-slate-500 md:block">
                    Sheet tambahan `Peta Kontrol Region & Kost` menampilkan hubungan region, kost, dan admin dalam satu file.
                </p>
                <button
                    type="button"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-xs font-semibold text-white md:w-auto md:min-w-40 md:rounded-2xl md:px-5 md:py-2.5 md:text-base"
                    :class="canExport ? 'bg-teal-600' : 'bg-slate-300'"
                    :disabled="!canExport"
                    @click="handleExport"
                >
                    <Download class="size-3.5 md:size-4" />
                    Unduh Data
                </button>
            </div>
            <p v-if="exportError" class="mt-2 text-xs text-rose-600 md:mt-2.5 md:text-base">{{ exportError }}</p>
            <p v-if="submitted" class="mt-2 text-xs font-medium text-emerald-700 md:mt-2.5 md:text-base">
                Export dikirim ke endpoint Laravel. Jika data valid, browser akan langsung mengunduh satu file `.xlsx`.
            </p>
        </article>
    </section>
</template>
