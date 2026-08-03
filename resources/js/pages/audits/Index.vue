<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    BarChart3,
    CirclePlus,
    Clock3,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { AuditRunSummary } from '@/types';

const props = defineProps<{ runs: AuditRunSummary[] }>();
const selected = ref<string[]>([]);
const active = computed(
    () =>
        props.runs.filter(
            (run) => !['completed', 'failed', 'cancelled'].includes(run.status),
        ).length,
);
const completed = computed(
    () => props.runs.filter((run) => run.status === 'completed').length,
);
const benchmarkSelection = computed(() =>
    selected.value.filter((id) =>
        props.runs.some(
            (run) =>
                run.id === id && run.hasBenchmark && run.status === 'completed',
        ),
    ),
);

const badgeVariant = (status: string) =>
    status === 'completed'
        ? 'default'
        : status === 'failed'
          ? 'destructive'
          : 'secondary';
const date = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('it-IT', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
</script>

<template>
    <Head title="Audit" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-primary">Audit workspace</p>
                <h1 class="text-3xl font-semibold tracking-tight">
                    Run e benchmark
                </h1>
                <p class="mt-1 text-muted-foreground">
                    Lancia, osserva e confronta ogni analisi da un unico posto.
                </p>
            </div>
            <div class="flex gap-2">
                <Button
                    v-if="benchmarkSelection.length >= 2"
                    variant="outline"
                    as-child
                >
                    <Link
                        :href="`/benchmarks/compare?${benchmarkSelection.map((id) => `runs[]=${encodeURIComponent(id)}`).join('&')}`"
                        ><BarChart3 /> Confronta</Link
                    >
                </Button>
                <Button as-child
                    ><Link href="/audits/create"
                        ><CirclePlus /> Nuova run</Link
                    ></Button
                >
            </div>
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
                    <Clock3 class="size-4" /> Storico
                </div>
                <p class="mt-2 text-3xl font-semibold">{{ runs.length }}</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border bg-card">
            <div
                v-if="runs.length === 0"
                class="p-12 text-center text-muted-foreground"
            >
                Non ci sono ancora run. Creane una per iniziare.
            </div>
            <div v-else class="divide-y">
                <div
                    v-for="run in runs"
                    :key="run.id"
                    class="grid items-center gap-4 p-4 transition-colors hover:bg-muted/40 md:grid-cols-[auto_1fr_auto_auto_auto]"
                >
                    <input
                        v-if="run.hasBenchmark && run.status === 'completed'"
                        v-model="selected"
                        :value="run.id"
                        type="checkbox"
                        class="size-4 rounded border"
                        aria-label="Seleziona benchmark"
                    />
                    <span v-else class="w-4" />
                    <Link :href="`/audits/${run.id}`" class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="truncate font-medium">{{
                                run.target
                            }}</span
                            ><Badge v-if="run.legacy" variant="outline"
                                >legacy</Badge
                            >
                        </div>
                        <p class="mt-1 truncate text-xs text-muted-foreground">
                            {{ run.auditId }} ·
                            {{ run.categories.join(' · ') || run.type }}
                        </p>
                    </Link>
                    <div class="text-sm">
                        <span class="font-medium">{{ run.confirmed }}</span
                        ><span class="text-muted-foreground"> confermati</span>
                    </div>
                    <div class="text-sm text-muted-foreground">
                        {{ date(run.createdAt) }}
                    </div>
                    <Badge :variant="badgeVariant(run.status)">{{
                        run.status
                    }}</Badge>
                </div>
            </div>
        </div>
    </div>
</template>
