<script setup lang="ts">
import BaseModal from '@/components/BaseModal.vue';
import { Button } from '@/components/ui/button';

withDefaults(defineProps<{
    open: boolean;
    title: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    variant?: 'danger' | 'warning' | 'info';
    loading?: boolean;
    confirmDisabled?: boolean;
}>(), {
    description: '',
    confirmLabel: 'Ya, Lanjutkan',
    cancelLabel: 'Batal',
    variant: 'danger',
    loading: false,
    confirmDisabled: false,
});

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm'): void;
}>();

const variantClasses = {
    danger: 'bg-rose-600 text-white hover:bg-rose-700 focus:ring-rose-500',
    warning: 'bg-amber-500 text-white hover:bg-amber-600 focus:ring-amber-400',
    info: 'bg-teal-600 text-white hover:bg-teal-700 focus:ring-teal-500',
} as const;
</script>

<template>
    <BaseModal
        :open="open"
        :title="title"
        :description="description"
        max-width-class="sm:max-w-md"
        @update:open="emit('update:open', $event)"
    >
        <slot />

        <template #footer>
            <Button
                type="button"
                variant="outline"
                :disabled="loading"
                @click="emit('update:open', false)"
            >
                {{ cancelLabel }}
            </Button>
            <Button
                type="button"
                :class="variantClasses[variant]"
                :disabled="loading || confirmDisabled"
                @click="emit('confirm')"
            >
                {{ confirmLabel }}
            </Button>
        </template>
    </BaseModal>
</template>
