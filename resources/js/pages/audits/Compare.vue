<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, BarChart3 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';

interface Compared {
    id: string;
    auditId: string;
    target: string;
    benchmark: Record<string, any> | null;
}
interface Available {
    id: string;
    audit_id: string;
    parameters: Record<string, any>;
}
const props = defineProps<{ runs: Compared[]; available: Available[] }>();
const selected = ref(props.runs.map((run) => run.id));
const compare = () =>
    router.get('/benchmarks/compare', { runs: selected.value });
const metric = (run: Compared, path: string[]) =>
    path.reduce<any>((value, key) => value?.[key], run.benchmark) ?? '—';
</script>

<template>
    <Head title="Confronta benchmark" />
    <div class="mx-auto w-full max-w-7xl p-4 md:p-8">
        <Link
            href="/audits"
            class="inline-flex items-center gap-2 text-sm text-muted-foreground"
            ><ArrowLeft class="size-4" /> Run</Link
        >
        <div class="mt-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="flex items-center gap-2 text-3xl font-semibold">
                    <BarChart3 /> Confronta benchmark
                </h1>
                <p class="mt-1 text-muted-foreground">
                    Metriche e costi affiancati sulle run concluse.
                </p>
            </div>
            <Button :disabled="selected.length < 2" @click="compare"
                >Aggiorna confronto</Button
            >
        </div>
        <div class="mt-6 flex flex-wrap gap-3">
            <label
                v-for="run in available"
                :key="run.id"
                class="flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm"
                ><input v-model="selected" :value="run.id" type="checkbox" />{{
                    run.parameters.benchmark_id ||
                    run.parameters.preset ||
                    run.audit_id
                }}</label
            >
        </div>
        <div
            v-if="runs.length < 2"
            class="mt-6 rounded-xl border p-12 text-center text-muted-foreground"
        >
            Seleziona almeno due benchmark.
        </div>
        <div v-else class="mt-6 overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[760px] text-sm">
                <thead class="bg-muted/60 text-left">
                    <tr>
                        <th class="p-4">Metrica</th>
                        <th v-for="run in runs" :key="run.id" class="p-4">
                            {{ run.target
                            }}<span
                                class="block font-mono text-xs font-normal text-muted-foreground"
                                >{{ run.auditId }}</span
                            >
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in [
                            {
                                l: 'Detection recall',
                                p: ['detection', 'recall'],
                            },
                            {
                                l: 'Confirmation recall',
                                p: ['confirmation', 'recall'],
                            },
                            { l: 'Detection precision', p: ['detection', 'precision'] },
                            { l: 'Detection F1', p: ['detection', 'f1'] },
                            { l: 'Token', p: ['cost', 'total_tokens'] },
                            { l: 'Tool call', p: ['cost', 'tool_calls'] },
                            { l: 'HTTP', p: ['cost', 'http_requests'] },
                        ]"
                        :key="row.l"
                        class="border-t"
                    >
                        <th class="p-4 font-medium">{{ row.l }}</th>
                        <td
                            v-for="run in runs"
                            :key="run.id"
                            class="p-4 text-lg font-semibold"
                        >
                            {{ metric(run, row.p) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
