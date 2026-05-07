<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, ChevronDown, MapPinned, Pencil, Plus, Search, ShieldCheck, X } from 'lucide-vue-next';
import BaseModal from '@/components/BaseModal.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Viewer } from '@/types/kost';

type RegionSummary = {
    id: string;
    name: string;
    totalKosts: number;
    activeAdmins: number;
};

type RegionOption = {
    id: string;
    name: string;
};

type AdminSummary = {
    id: string;
    name: string;
    email: string;
    role: 'admin' | 'it';
    regionIds: string[];
    region: string;
};

const props = defineProps<{
    viewer: Viewer;
    activeTab: 'region' | 'admin';
    regions: RegionSummary[];
    regionOptions: RegionOption[];
    admins: AdminSummary[];
}>();

const page = usePage<{ errors?: Record<string, string> }>();
const activeTab = ref<'region' | 'admin'>(props.activeTab);
const editingRegionId = ref<string | null>(null);
const editingAdminId = ref<string | null>(null);
const regionModalOpen = ref(false);
const adminModalOpen = ref(false);
const regionAssignmentOpen = ref(false);
const regionSearch = ref('');
const confirmDeleteRegionOpen = ref(false);
const confirmDeleteAdminOpen = ref(false);
const confirmPurgeRegionOpen = ref(false);
const pendingDeleteRegion = ref<RegionSummary | null>(null);
const pendingDeleteAdmin = ref<AdminSummary | null>(null);
const pendingPurgeRegion = ref<RegionSummary | null>(null);

const regionForm = useForm({
    name: '',
});

const adminForm = useForm({
    name: '',
    email: '',
    role: 'admin' as 'admin' | 'it',
    region_ids: [] as string[],
    password: '',
    password_confirmation: '',
});

const regionDeleteError = computed(() => page.props.errors?.region_delete);
const adminDeleteError = computed(() => page.props.errors?.admin_delete);
const isEditingRegion = computed(() => editingRegionId.value !== null);
const isEditingAdmin = computed(() => editingAdminId.value !== null);
const isItRole = computed(() => adminForm.role === 'it');
const selectedRegionNames = computed(() =>
    props.regionOptions
        .filter((region) => adminForm.region_ids.includes(region.id))
        .map((region) => region.name),
);
const filteredRegionOptions = computed(() => {
    const keyword = regionSearch.value.trim().toLowerCase();

    if (!keyword) {
        return props.regionOptions;
    }

    return props.regionOptions.filter((region) =>
        region.name.toLowerCase().includes(keyword),
    );
});

const resetRegionForm = () => {
    editingRegionId.value = null;
    regionForm.reset();
    regionForm.clearErrors();
    regionModalOpen.value = false;
};

const resetAdminForm = () => {
    editingAdminId.value = null;
    adminForm.reset();
    adminForm.role = 'admin';
    adminForm.region_ids = [];
    adminForm.clearErrors();
    adminModalOpen.value = false;
    regionAssignmentOpen.value = false;
    regionSearch.value = '';
};

const openRegionCreate = () => {
    activeTab.value = 'region';
    editingRegionId.value = null;
    regionForm.reset();
    regionForm.clearErrors();
    regionModalOpen.value = true;
};

const startRegionEdit = (region: RegionSummary) => {
    activeTab.value = 'region';
    editingRegionId.value = region.id;
    regionForm.name = region.name;
    regionForm.clearErrors();
    regionModalOpen.value = true;
};

const submitRegion = () => {
    if (editingRegionId.value) {
        regionForm.patch(`/settings/regions/${editingRegionId.value}?tab=region`, {
            preserveScroll: true,
            onSuccess: () => resetRegionForm(),
        });

        return;
    }

    regionForm.post('/settings/regions?tab=region', {
        preserveScroll: true,
        onSuccess: () => resetRegionForm(),
    });
};

const deleteRegion = (region: RegionSummary) => {
    pendingDeleteRegion.value = region;
    confirmDeleteRegionOpen.value = true;
};

const executeDeleteRegion = () => {
    if (!pendingDeleteRegion.value) {
        return;
    }

    router.delete(`/settings/regions/${pendingDeleteRegion.value.id}?tab=region`, {
        preserveScroll: true,
        onFinish: () => {
            confirmDeleteRegionOpen.value = false;
            pendingDeleteRegion.value = null;
        },
    });
};

const purgeRegion = (region: RegionSummary) => {
    pendingPurgeRegion.value = region;
    confirmPurgeRegionOpen.value = true;
};

const executePurgeRegion = () => {
    if (!pendingPurgeRegion.value) {
        return;
    }

    router.post(`/settings/regions/${pendingPurgeRegion.value.id}/purge?tab=region`, {}, {
        preserveScroll: true,
        onFinish: () => {
            confirmPurgeRegionOpen.value = false;
            pendingPurgeRegion.value = null;
        },
    });
};

const openAdminCreate = () => {
    activeTab.value = 'admin';
    editingAdminId.value = null;
    adminForm.reset();
    adminForm.role = 'admin';
    adminForm.region_ids = [];
    adminForm.clearErrors();
    adminModalOpen.value = true;
    regionAssignmentOpen.value = false;
    regionSearch.value = '';
};

const startAdminEdit = (admin: AdminSummary) => {
    activeTab.value = 'admin';
    editingAdminId.value = admin.id;
    adminForm.name = admin.name;
    adminForm.email = admin.email;
    adminForm.role = admin.role;
    adminForm.region_ids = [...admin.regionIds];
    adminForm.password = '';
    adminForm.password_confirmation = '';
    adminForm.clearErrors();
    adminModalOpen.value = true;
    regionAssignmentOpen.value = false;
    regionSearch.value = '';
};

const toggleAdminRegion = (regionId: string) => {
    if (isItRole.value) {
        return;
    }

    if (adminForm.region_ids.includes(regionId)) {
        adminForm.region_ids = adminForm.region_ids.filter((id) => id !== regionId);
        return;
    }

    adminForm.region_ids = [...adminForm.region_ids, regionId];
};

const submitAdmin = () => {
    if (editingAdminId.value) {
        adminForm.patch(`/settings/admins/${editingAdminId.value}?tab=admin`, {
            preserveScroll: true,
            onSuccess: () => resetAdminForm(),
        });

        return;
    }

    adminForm.post('/settings/admins?tab=admin', {
        preserveScroll: true,
        onSuccess: () => resetAdminForm(),
    });
};

const deleteAdmin = (admin: AdminSummary) => {
    pendingDeleteAdmin.value = admin;
    confirmDeleteAdminOpen.value = true;
};

const executeDeleteAdmin = () => {
    if (!pendingDeleteAdmin.value) {
        return;
    }

    router.delete(`/settings/admins/${pendingDeleteAdmin.value.id}?tab=admin`, {
        preserveScroll: true,
        onFinish: () => {
            confirmDeleteAdminOpen.value = false;
            pendingDeleteAdmin.value = null;
        },
    });
};
</script>

<template>
    <Head title="Pengaturan" />

    <section class="space-y-2 md:space-y-5">
        <!-- Desktop hero (hidden on mobile) -->
        <div class="hidden rounded-4xl bg-white p-6 shadow-[0_18px_40px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/80 lg:block">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-teal-700">Pengaturan</p>
            <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950">Kelola region dan akun admin</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                Halaman ini sekarang langsung terhubung ke Laravel untuk CRUD region, akun admin, dan profil admin.
            </p>
        </div>

        <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200/70 md:rounded-4xl md:p-5 md:shadow-[0_18px_40px_rgba(15,23,42,0.08)] md:ring-slate-200/80">
            <!-- Tab toggle: compact on mobile -->
            <div class="inline-flex rounded-lg bg-slate-100 p-0.5 md:rounded-3xl md:p-1.5">
                <button
                    type="button"
                    class="inline-flex min-h-10 items-center gap-1.5 rounded-md px-3 py-2 text-xs font-semibold transition md:min-h-0 md:gap-2 md:rounded-2xl md:px-4 md:py-2.5 md:text-base"
                    :class="activeTab === 'region' ? 'bg-teal-600 text-white shadow-sm' : 'text-slate-600'"
                    @click="activeTab = 'region'"
                >
                    <MapPinned class="size-3.5 md:size-4" />
                    Region
                </button>
                <button
                    type="button"
                    class="inline-flex min-h-10 items-center gap-1.5 rounded-md px-3 py-2 text-xs font-semibold transition md:min-h-0 md:gap-2 md:rounded-2xl md:px-4 md:py-2.5 md:text-base"
                    :class="activeTab === 'admin' ? 'bg-teal-600 text-white shadow-sm' : 'text-slate-600'"
                    @click="activeTab = 'admin'"
                >
                    <ShieldCheck class="size-3.5 md:size-4" />
                    Admin
                </button>
            </div>

            <!-- Region tab -->
            <div v-if="activeTab === 'region'" class="mt-3 space-y-2 md:mt-5 md:space-y-5">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-xs font-bold text-slate-950 md:text-xl">Region <span class="font-normal text-slate-500">({{ regions.length }})</span></h3>
                    <button type="button" class="inline-flex min-h-10 items-center gap-1 rounded-lg bg-teal-600 px-3 py-2 text-xs font-semibold text-white md:gap-2 md:rounded-xl md:px-4 md:py-2.5 md:text-base" @click="openRegionCreate">
                        <Plus class="size-3 md:size-4" />
                        <span class="hidden md:inline">Tambah Region</span>
                        <span class="md:hidden">Baru</span>
                    </button>
                </div>

                <div v-if="regionDeleteError" class="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 md:rounded-2xl md:px-4 md:py-2.5 md:text-base">
                    {{ regionDeleteError }}
                </div>

                <!-- Mobile card list -->
                <div class="space-y-1.5 lg:hidden md:space-y-2.5">
                    <div
                        v-for="item in regions"
                        :key="'m-r-' + item.id"
                        class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2.5 ring-1 ring-slate-200/70 md:rounded-2xl md:px-5 md:py-4"
                    >
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-slate-900 md:text-base">{{ item.name }}</p>
                            <p class="mt-0.5 text-[10px] text-slate-500 md:mt-1.5 md:text-base">{{ item.totalKosts }} kost · {{ item.activeAdmins }} admin</p>
                        </div>
                        <div class="flex shrink-0 gap-1 md:gap-2">
                            <button type="button" class="min-h-10 rounded-md bg-amber-50 px-2.5 py-2 text-xs font-semibold text-amber-700 md:min-h-0 md:rounded-lg md:px-4 md:py-2 md:text-base" @click="purgeRegion(item)">Purge</button>
                            <button type="button" class="min-h-10 rounded-md bg-sky-50 px-2.5 py-2 text-xs font-semibold text-sky-700 md:min-h-0 md:rounded-lg md:px-4 md:py-2 md:text-base" @click="startRegionEdit(item)">Edit</button>
                            <button type="button" class="min-h-10 rounded-md bg-rose-50 px-2.5 py-2 text-xs font-semibold text-rose-700 md:min-h-0 md:rounded-lg md:px-4 md:py-2 md:text-base" @click="deleteRegion(item)">Hapus</button>
                        </div>
                    </div>
                    <div v-if="regions.length === 0" class="rounded-lg bg-slate-50 px-3 py-4 text-center text-xs text-slate-500 md:rounded-2xl md:py-10 md:text-base">
                        Belum ada region.
                    </div>
                </div>

                <!-- Desktop table -->
                <div class="hidden overflow-hidden rounded-[1.75rem] border border-slate-200 lg:block">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 uppercase tracking-[0.18em] text-slate-500">
                            <tr>
                                <th class="px-6 py-4">Nama Region</th>
                                <th class="px-6 py-4">Total Kost</th>
                                <th class="px-6 py-4">Admin Aktif</th>
                                <th class="px-6 py-4">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in regions"
                                :key="item.id"
                                class="border-t border-slate-100 text-slate-600"
                            >
                                <td class="px-6 py-4 font-semibold text-slate-950">{{ item.name }}</td>
                                <td class="px-6 py-4">{{ item.totalKosts }}</td>
                                <td class="px-6 py-4">{{ item.activeAdmins }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex gap-2">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700"
                                            @click="purgeRegion(item)"
                                        >
                                            Purge
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-2 rounded-xl bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-700"
                                            @click="startRegionEdit(item)"
                                        >
                                            <Pencil class="size-3.5" />
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-xl bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700"
                                            @click="deleteRegion(item)"
                                        >
                                            Hapus
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="regions.length === 0">
                                <td colspan="4" class="px-6 py-8 text-center text-slate-500">
                                    Belum ada region.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Admin tab -->
            <div v-else class="mt-3 space-y-2 md:mt-5 md:space-y-5">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-xs font-bold text-slate-950 md:text-xl">Admin <span class="font-normal text-slate-500">({{ admins.length }})</span></h3>
                    <button type="button" class="inline-flex min-h-10 items-center gap-1 rounded-lg bg-teal-600 px-3 py-2 text-xs font-semibold text-white md:gap-2 md:rounded-xl md:px-4 md:py-2.5 md:text-base" @click="openAdminCreate">
                        <Plus class="size-3 md:size-4" />
                        <span class="hidden md:inline">Tambah Admin</span>
                        <span class="md:hidden">Baru</span>
                    </button>
                </div>

                <div v-if="adminDeleteError" class="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 md:rounded-2xl md:px-4 md:py-2.5 md:text-base">
                    {{ adminDeleteError }}
                </div>

                <!-- Mobile card list -->
                <div class="space-y-1.5 lg:hidden md:space-y-2.5">
                    <div
                        v-for="item in admins"
                        :key="'m-a-' + item.id"
                        class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2.5 ring-1 ring-slate-200/70 md:rounded-2xl md:px-5 md:py-4"
                    >
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-slate-900 md:text-base">{{ item.name }}</p>
                            <p class="mt-0.5 truncate text-[10px] text-slate-500 md:mt-1.5 md:text-base">{{ item.email }} · {{ item.role.toUpperCase() }}</p>
                        </div>
                        <div class="flex shrink-0 gap-1 md:gap-2">
                            <button type="button" class="min-h-10 rounded-md bg-sky-50 px-2.5 py-2 text-xs font-semibold text-sky-700 md:min-h-0 md:rounded-lg md:px-4 md:py-2 md:text-base" @click="startAdminEdit(item)">Edit</button>
                            <button type="button" class="min-h-10 rounded-md bg-rose-50 px-2.5 py-2 text-xs font-semibold text-rose-700 md:min-h-0 md:rounded-lg md:px-4 md:py-2 md:text-base" @click="deleteAdmin(item)">Hapus</button>
                        </div>
                    </div>
                    <div v-if="admins.length === 0" class="rounded-lg bg-slate-50 px-3 py-4 text-center text-xs text-slate-500 md:rounded-2xl md:py-10 md:text-base">
                        Belum ada akun admin.
                    </div>
                </div>

                <!-- Desktop table -->
                <div class="hidden overflow-hidden rounded-[1.75rem] border border-slate-200 lg:block">
                    <table class="min-w-full table-fixed text-left text-sm">
                        <thead class="bg-slate-50 uppercase tracking-[0.18em] text-slate-500">
                            <tr>
                                <th class="w-1/4 px-6 py-4">Nama Admin</th>
                                <th class="w-1/4 px-6 py-4">Email</th>
                                <th class="w-1/4 px-6 py-4">Role</th>
                                <th class="w-1/4 px-6 py-4">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in admins"
                                :key="item.id"
                                class="border-t border-slate-100 text-slate-600"
                            >
                                <td class="px-6 py-4 font-semibold text-slate-950">{{ item.name }}</td>
                                <td class="px-6 py-4 break-words">{{ item.email }}</td>
                                <td class="px-6 py-4 uppercase">{{ item.role }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex gap-2">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-2 rounded-xl bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-700"
                                            @click="startAdminEdit(item)"
                                        >
                                            <Pencil class="size-3.5" />
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-xl bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700"
                                            @click="deleteAdmin(item)"
                                        >
                                            Hapus
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="admins.length === 0">
                                <td colspan="4" class="px-6 py-8 text-center text-slate-500">
                                    Belum ada akun admin.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <BaseModal
            :open="regionModalOpen"
            :title="isEditingRegion ? 'Edit region' : 'Tambah region baru'"
            description="Isi nama region operasional. Modal yang sama dipakai untuk tambah dan edit agar alurnya tetap ringan."
            max-width-class="sm:max-w-lg"
            @update:open="regionModalOpen = $event"
        >
            <form id="region-modal-form" class="space-y-5" @submit.prevent="submitRegion">
                <div class="grid gap-2">
                    <Label for="modal-region-name" class="text-slate-900">Nama Region</Label>
                    <Input
                        id="modal-region-name"
                        v-model="regionForm.name"
                        placeholder="Contoh: Yogyakarta Selatan"
                        class="border-slate-200 !bg-white !text-slate-900 placeholder:!text-slate-400"
                    />
                    <InputError :message="regionForm.errors.name" />
                </div>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="regionForm.processing" class="focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-1" @click="resetRegionForm">
                    Batal
                </Button>
                <Button type="submit" form="region-modal-form" :disabled="regionForm.processing" class="focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-1" :class="regionForm.processing ? 'opacity-80' : 'hover:brightness-95 active:scale-[0.99]'">
                    {{ regionForm.processing ? 'Menyimpan...' : (isEditingRegion ? 'Simpan Perubahan' : 'Tambah Region') }}
                </Button>
            </template>
        </BaseModal>

        <BaseModal
            :open="adminModalOpen"
            :title="isEditingAdmin ? 'Edit akun admin' : 'Tambah akun admin baru'"
            description="Atur profil admin, role, region penugasan, dan password dari satu modal yang sama."
            max-width-class="sm:max-w-2xl"
            @update:open="adminModalOpen = $event"
        >
            <form id="admin-modal-form" class="space-y-5" @submit.prevent="submitAdmin">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="modal-admin-name" class="text-slate-900">Nama</Label>
                        <Input
                            id="modal-admin-name"
                            v-model="adminForm.name"
                            placeholder="Nama admin"
                            class="border-slate-200 !bg-white !text-slate-900 placeholder:!text-slate-400"
                        />
                        <InputError :message="adminForm.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="modal-admin-email" class="text-slate-900">Email</Label>
                        <Input
                            id="modal-admin-email"
                            v-model="adminForm.email"
                            type="email"
                            placeholder="admin@example.com"
                            class="border-slate-200 !bg-white !text-slate-900 placeholder:!text-slate-400"
                        />
                        <InputError :message="adminForm.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="modal-admin-role" class="text-slate-900">Role</Label>
                        <select
                            id="modal-admin-role"
                            v-model="adminForm.role"
                            class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                        >
                            <option value="admin">Admin</option>
                            <option value="it">IT</option>
                        </select>
                        <InputError :message="adminForm.errors.role" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="modal-admin-password" class="text-slate-900">
                            {{ isEditingAdmin ? 'Password Baru (opsional)' : 'Password' }}
                        </Label>
                        <Input
                            id="modal-admin-password"
                            v-model="adminForm.password"
                            type="password"
                            placeholder="Minimal 8 karakter"
                            class="border-slate-200 !bg-white !text-slate-900 placeholder:!text-slate-400"
                        />
                        <InputError :message="adminForm.errors.password" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="modal-admin-password-confirmation" class="text-slate-900">Konfirmasi Password</Label>
                        <Input
                            id="modal-admin-password-confirmation"
                            v-model="adminForm.password_confirmation"
                            type="password"
                            placeholder="Ulangi password"
                            class="border-slate-200 !bg-white !text-slate-900 placeholder:!text-slate-400"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label class="text-slate-900">Region Penugasan</Label>
                    <div class="relative">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between rounded-2xl border px-4 py-3 text-left text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-2"
                            :class="isItRole
                                ? 'cursor-not-allowed border-slate-900/20 bg-slate-100 text-slate-400'
                                : 'border-slate-900/50 bg-white text-slate-950 hover:border-teal-400'"
                            :disabled="isItRole"
                            @click="regionAssignmentOpen = !regionAssignmentOpen"
                        >
                            <div class="min-w-0">
                                <div v-if="isItRole" class="text-slate-500">
                                    Role IT otomatis mengakses semua region.
                                </div>
                                <div v-else-if="selectedRegionNames.length" class="flex flex-wrap gap-2">
                                    <span
                                        v-for="name in selectedRegionNames"
                                        :key="name"
                                        class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700 ring-1 ring-slate-200"
                                    >
                                        {{ name }}
                                    </span>
                                </div>
                                <span v-else class="text-slate-500">Cari dan pilih region penugasan</span>
                            </div>
                            <ChevronDown class="ml-3 size-4 shrink-0" :class="isItRole ? 'text-slate-400' : 'text-slate-600'" />
                        </button>

                        <div
                            v-if="regionAssignmentOpen && !isItRole"
                            class="mt-2 w-full rounded-2xl border border-slate-200 bg-white p-3 shadow-[0_18px_40px_rgba(15,23,42,0.14)]"
                        >
                            <div class="flex items-center gap-2 rounded-xl border border-slate-900/50 bg-white px-3 py-2">
                                <Search class="size-4 text-slate-400" />
                                <input
                                    v-model="regionSearch"
                                    type="text"
                                    class="w-full bg-transparent text-sm text-slate-900 outline-none placeholder:text-slate-400"
                                    placeholder="Cari dan pilih region penugasan"
                                />
                            </div>

                            <div class="mt-3 max-h-56 space-y-2 overflow-y-auto">
                                <button
                                    v-for="region in filteredRegionOptions"
                                    :key="region.id"
                                    type="button"
                                    class="flex w-full items-center justify-between rounded-xl px-3 py-3 text-left text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-1"
                                    :class="adminForm.region_ids.includes(region.id) ? 'bg-teal-50 text-teal-700' : 'bg-white text-slate-700 hover:bg-slate-50'"
                                    @click="toggleAdminRegion(region.id)"
                                >
                                    <span>{{ region.name }}</span>
                                    <Check
                                        v-if="adminForm.region_ids.includes(region.id)"
                                        class="size-4 text-teal-600"
                                    />
                                </button>

                                <div
                                    v-if="filteredRegionOptions.length === 0"
                                    class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-sm text-slate-500"
                                >
                                    Region tidak ditemukan.
                                </div>
                            </div>

                            <div class="mt-3 flex items-center justify-between border-t border-slate-200 pt-3">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 text-xs font-medium text-slate-500 hover:text-slate-900"
                                    @click="adminForm.region_ids = []"
                                >
                                    <X class="size-3.5" />
                                    Kosongkan pilihan
                                </button>
                                <Button type="button" variant="outline" size="sm" class="focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-1" @click="regionAssignmentOpen = false">
                                    Selesai
                                </Button>
                            </div>
                        </div>
                    </div>
                    <p v-if="isItRole" class="text-xs text-slate-500">
                        Penugasan region dimatikan karena akun IT mengikuti seluruh region yang tersedia.
                    </p>
                    <InputError :message="adminForm.errors.region_ids" />
                    <InputError :message="adminForm.errors['region_ids.0']" />
                </div>

            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="adminForm.processing" class="focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-1" @click="resetAdminForm">
                    Batal
                </Button>
                <Button type="submit" form="admin-modal-form" :disabled="adminForm.processing" class="focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-1" :class="adminForm.processing ? 'opacity-80' : 'hover:brightness-95 active:scale-[0.99]'">
                    {{ adminForm.processing ? 'Menyimpan...' : (isEditingAdmin ? 'Simpan Perubahan' : 'Tambah Admin') }}
                </Button>
            </template>
        </BaseModal>

        <ConfirmModal
            :open="confirmPurgeRegionOpen"
            title="Purge Data Region"
            :description="`Yakin purge data region '${pendingPurgeRegion?.name ?? ''}'? Semua data tenant dan transaksi pada region ini akan dihapus permanen.`"
            confirm-label="Ya, Purge"
            variant="danger"
            @update:open="confirmPurgeRegionOpen = $event"
            @confirm="executePurgeRegion"
        />

        <ConfirmModal
            :open="confirmDeleteRegionOpen"
            title="Hapus Region"
            :description="`Yakin ingin menghapus region '${pendingDeleteRegion?.name ?? ''}'? Semua kost dan penyewa di region ini akan terpengaruh.`"
            confirm-label="Ya, Hapus"
            variant="danger"
            @update:open="confirmDeleteRegionOpen = $event"
            @confirm="executeDeleteRegion"
        />

        <ConfirmModal
            :open="confirmDeleteAdminOpen"
            title="Hapus Akun Admin"
            :description="`Yakin ingin menghapus akun admin '${pendingDeleteAdmin?.name ?? ''}'?`"
            confirm-label="Ya, Hapus"
            variant="danger"
            @update:open="confirmDeleteAdminOpen = $event"
            @confirm="executeDeleteAdmin"
        />
    </section>
</template>
