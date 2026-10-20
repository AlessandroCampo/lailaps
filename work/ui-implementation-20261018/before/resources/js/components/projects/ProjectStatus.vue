<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { ProjectSource } from '@/types/project';

defineProps<{ status: ProjectSource['status'] }>();

const statuses = {
    pending: { label: 'Pending', variant: 'secondary' },
    importing: { label: 'Importing', variant: 'info' },
    ready: { label: 'Ready', variant: 'success' },
    failed: { label: 'Failed', variant: 'destructive' },
} as const;
</script>

<template>
    <Badge :variant="statuses[status].variant">
        <Spinner v-if="status === 'importing'" class="size-3" />
        {{ statuses[status].label }}
    </Badge>
</template>
