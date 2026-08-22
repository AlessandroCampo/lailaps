<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    CirclePlus,
    Clock3,
    FileText,
    MonitorCog,
    Search,
    ShieldCheck,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { AuditRunSummary } from '@/types';

const props = defineProps<{ runs: AuditRunSummary[] }>();
const query = ref('');
const status = ref('all');

const filteredRuns = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return props.runs.filter((run) => {
        const matchesQuery =
            !needle ||
            [run.target, run.auditId, run.type, ...run.categories]
                .join(' ')
                .toLowerCase()
                .includes(needle);

        return (
            matchesQuery &&
            (status.value === 'all' || run.status === status.value)
        );
    });
});

const active = computed(
    () =>
        props.runs.filter(
            (run) => !['completed', 'failed', 'cancelled'].includes(run.status),
        ).length,
);
const completed = computed(
    () => props.runs.filter((run) => run.status === 'completed').length,
);
const date = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('it-IT', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
const badgeVariant = (value: string) =>
    value === 'completed'
        ? 'default'
        : value === 'failed'
          ? 'destructive'
          : 'secondary';
const canDelete = (value: string) =>
    ['completed', 'failed', 'cancelled'].includes(value);
const destroy = (run: AuditRunSummary) => {
    if (
        canDelete(run.status) &&
        window.confirm(
            `Eliminare ${run.target}? Verranno rimossi record DB, log e artefatti della run.`,
        )
    ) {
        router.delete(`/audit-console/runs/${run.id}`, {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="Audit Console" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-primary">Audit Console</p>
                <h1 class="text-3xl font-semibold tracking-tight">
                    Run archiviate
                </h1>
                <p class="mt-1 text-muted-foreground">
                    Recupera log, report e benchmark anche dopo la fine
                    dell’esecuzione.
                </p>
            </div>
            <Button as-child>
                <Link href="/audit-console/new"><CirclePlus /> Nuova run</Link>
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border bg-card p-5">
                <div
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Activity class="size-4" /> Attive
                </div>
                <p class="mt-2 text-3xl font-semibold">{{ active }}</p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <div
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <ShieldCheck class="size-4" /> Completate
                </div>
                <p class="mt-2 text-3xl font-semibold">{{ completed }}</p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <div
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Clock3 class="size-4" /> Totali
                </div>
                <p class="mt-2 text-3xl font-semibold">
                    {{ props.runs.length }}
                </p>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <div class="relative min-w-64 flex-1">
                <Search
                    class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                />
                <Input
                    v-model="query"
                    class="pl-9"
                    placeholder="Cerca target, audit ID o categoria"
                />
            </div>
            <select
                v-model="status"
                class="h-10 rounded-md border bg-background px-3 text-sm"
            >
                <option value="all">Tutti gli stati</option>
                <option value="queued">queued</option>
                <option value="preparing">preparing</option>
                <option value="running">running</option>
                <option value="finalizing">finalizing</option>
                <option value="completed">completed</option>
                <option value="failed">failed</option>
                <option value="cancelled">cancelled</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-xl border bg-card">
            <div
                v-if="filteredRuns.length === 0"
                class="p-12 text-center text-muted-foreground"
            >
                {{
                    props.runs.length === 0
                        ? 'Non ci sono ancora run.'
                        : 'Nessuna run corrisponde ai filtri.'
                }}
            </div>
            <div v-else class="divide-y">
                <div
                    v-for="run in filteredRuns"
                    :key="run.id"
                    class="flex flex-wrap items-center gap-4 p-4 transition-colors hover:bg-muted/40"
                >
                    <MonitorCog class="size-5 shrink-0 text-primary" />
                    <Link
                        :href="`/audit-console/runs/${run.id}`"
                        class="min-w-0 flex-1"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-medium">{{
                                run.target
                            }}</span>
                            <Badge :variant="badgeVariant(run.status)">{{
                                run.status
                            }}</Badge>
                        </div>
                        <p class="mt-1 truncate text-xs text-muted-foreground">
                            {{ run.auditId }} ·
                            {{ run.categories.join(' · ') || run.type }}
                        </p>
                    </Link>
                    <div class="text-right text-sm text-muted-foreground">
                        <div>{{ date(run.createdAt) }}</div>
                        <div v-if="run.startedAt" class="text-xs">
                            avvio {{ date(run.startedAt) }}
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <Button size="sm" variant="outline" as-child>
                            <Link :href="`/audit-console/runs/${run.id}`"
                                ><FileText /> Apri run</Link
                            >
                        </Button>
                        <Button
                            size="sm"
                            variant="destructive"
                            :disabled="!canDelete(run.status)"
                            title="Le run attive vanno prima annullate"
                            @click="destroy(run)"
                        >
                            <Trash2 /> Elimina
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
