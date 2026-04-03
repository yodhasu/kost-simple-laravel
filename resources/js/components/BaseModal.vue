<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { computed, useSlots } from 'vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

withDefaults(defineProps<{
    open: boolean;
    title: string;
    description?: string;
    maxWidthClass?: string;
}>(), {
    description: '',
    maxWidthClass: 'sm:max-w-2xl',
});

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const slots = useSlots();
const hasFooter = computed(() => Boolean(slots.footer));
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            :show-close-button="false"
            :class="[
                'flex flex-col rounded-xl border border-slate-200 bg-white p-4 text-slate-900 shadow-[0_24px_60px_rgba(15,23,42,0.18)]',
                'max-w-[calc(100%-1.5rem)] max-h-[calc(100dvh-2rem)]',
                'sm:max-w-[calc(100%-2rem)] sm:max-h-[calc(100dvh-4rem)] sm:rounded-[1.6rem] sm:p-5',
                maxWidthClass,
            ]"
        >
            <DialogHeader class="relative shrink-0 space-y-1.5 sm:space-y-2.5">
                <DialogTitle class="text-sm font-bold text-slate-950 sm:text-base">{{ title }}</DialogTitle>
                <DialogDescription v-if="description" class="text-xs text-slate-500 sm:text-base">
                    {{ description }}
                </DialogDescription>
                <button
                    type="button"
                    class="absolute right-0 top-0 text-slate-400 transition hover:text-slate-700"
                    @click="emit('update:open', false)"
                >
                    <X class="size-4 sm:size-5" />
                    <span class="sr-only">Close</span>
                </button>
            </DialogHeader>

            <div class="-mr-1 flex-1 min-h-0 overflow-y-auto pr-1">
                <div class="space-y-3 sm:space-y-5">
                    <slot />
                </div>
            </div>

            <DialogFooter v-if="hasFooter" class="shrink-0 gap-2">
                <slot name="footer" />
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

