<script setup lang="ts">
import { FileArchive, Upload, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

const props = withDefaults(
    defineProps<{ disabled?: boolean; error?: string }>(),
    { disabled: false },
);
const file = defineModel<File | null>({ required: true });
const input = ref<HTMLInputElement>();
const selectionError = ref('');
const dragging = ref(false);
const size = computed(() =>
    file.value
        ? file.value.size < 1024
            ? `${file.value.size} B`
            : `${(file.value.size / 1024 / 1024).toFixed(2)} MB`
        : '',
);

watch(file, (value) => {
    if (!value && input.value) input.value.value = '';
});

function select(files: FileList | null) {
    if (props.disabled || !files?.length) return;
    selectionError.value = '';
    if (files.length !== 1 || !files[0].name.toLowerCase().endsWith('.zip')) {
        selectionError.value = 'Select a single ZIP file.';
        return;
    }
    file.value = files[0];
}

function drop(event: DragEvent) {
    dragging.value = false;
    select(event.dataTransfer?.files ?? null);
}

function clear() {
    file.value = null;
    selectionError.value = '';
}
</script>

<template>
    <div class="space-y-2">
        <label class="text-sm font-medium" for="source-file">Source code</label>
        <div
            class="relative rounded-lg border border-dashed bg-muted transition-colors"
            :class="{ 'border-ring bg-info-muted': dragging }"
            @dragover.prevent="dragging = !disabled"
            @dragleave.prevent="dragging = false"
            @drop.prevent="drop"
        >
            <input
                id="source-file"
                ref="input"
                type="file"
                name="source"
                accept=".zip,application/zip"
                :disabled="disabled"
                class="peer absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0 disabled:cursor-not-allowed"
                aria-describedby="source-error"
                :aria-invalid="!!(error || selectionError)"
                @change="select(($event.target as HTMLInputElement).files)"
            />
            <div
                class="flex min-h-44 flex-col items-center justify-center gap-3 rounded-lg px-6 py-8 text-center peer-focus-visible:ring-2 peer-focus-visible:ring-ring"
            >
                <FileArchive v-if="file" class="size-6 text-foreground" />
                <Upload v-else class="size-6 text-muted-foreground" />
                <div class="max-w-full min-w-0 space-y-1">
                    <p class="truncate font-medium">
                        {{ file ? file.name : 'Choose or drop a ZIP file' }}
                    </p>
                    <p v-if="file" class="metadata">{{ size }}</p>
                </div>
                <span class="text-xs font-medium text-muted-foreground">{{
                    file ? 'Change file' : 'Browse files'
                }}</span>
            </div>
        </div>
        <div v-if="file" class="flex justify-end">
            <Button
                type="button"
                variant="ghost"
                size="xs"
                :disabled="disabled"
                @click="clear"
                ><X /> Remove file</Button
            >
        </div>
        <InputError id="source-error" :message="selectionError || error" />
    </div>
</template>
