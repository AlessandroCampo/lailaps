<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, BarChart3, ExternalLink, Filter } from '@lucide/vue';
import { useResizeObserver } from '@vueuse/core';
import * as echarts from 'echarts';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Summary {
    median: number | null;
    q1: number | null;
    q3: number | null;
}
interface RunRow {
    evaluationId: string;
    runId: string;
    auditId: string;
    experiment?: string | null;
    repetition?: number | null;
    status: string;
    artifactState: string;
    environmentState: string;
    score: number | null;
    pending: number;
    detection: Record<string, number>;
    staticValidation: Record<string, number>;
    confirmation: Record<string, number>;
    cost: Record<string, number>;
}
interface Group {
    key: string;
    target: string;
    category: string;
    harnessRevision?: string | null;
    targetCommit?: string | null;
    models: {
        reader?: string | null;
        worker?: string | null;
        confirmer?: string | null;
    };
    n: number;
    status: string;
    completionRate: number;
    metrics: Record<string, Summary>;
    runs: RunRow[];
}
interface Options {
    targets: string[];
    categories: string[];
    statuses: string[];
    artifacts: string[];
    environments: string[];
    revisions: string[];
    experiments: Record<string, string>;
    readers: string[];
    workers: string[];
    confirmers: string[];
}
const props = defineProps<{
    groups: Group[];
    filters: Record<string, string>;
    options: Options;
}>();
const form = reactive({ ...props.filters });
const metric = ref('globalScore');
const metricMax = computed(() => (metric.value === 'globalScore' ? 100 : 1));
const heatmapEl = ref<HTMLElement | null>(null);
const scatterEl = ref<HTMLElement | null>(null);
let heatmap: echarts.ECharts | null = null;
let scatter: echarts.ECharts | null = null;

const modelLabel = (group: Group) =>
    [group.models.reader, group.models.worker, group.models.confirmer]
        .map((value) => value?.split('/').pop() || '—')
        .join(' / ');
const rowLabel = (group: Group) => `${group.target} · ${group.category}`;
const format = (value: number | null, digits = 3) =>
    value === null
        ? '—'
        : new Intl.NumberFormat('it-IT', {
              maximumFractionDigits: digits,
          }).format(value);
const percent = (value: number | null) =>
    value === null ? '—' : `${format(value * 100, 1)}%`;
const unique = <T,>(values: T[]) => [...new Set(values)];
const rows = computed(() => unique(props.groups.map(rowLabel)));
const columns = computed(() => unique(props.groups.map(modelLabel)));

const renderCharts = async () => {
    await nextTick();
    if (!heatmapEl.value || !scatterEl.value) return;
    heatmap ??= echarts.init(heatmapEl.value);
    scatter ??= echarts.init(scatterEl.value);
    const heatData = props.groups.map((group) => [
        columns.value.indexOf(modelLabel(group)),
        rows.value.indexOf(rowLabel(group)),
        group.metrics[metric.value]?.median ?? null,
        group.n,
        group.status,
        group.key,
    ]);
    heatmap.setOption(
        {
            animationDuration: 250,
            tooltip: {
                formatter: (p: any) => {
                    const group = props.groups.find(
                        (item) => item.key === p.data[5],
                    );
                    return group
                        ? `<b>${rowLabel(group)}</b><br>${modelLabel(group)}<br>${metric.value}: ${format(p.data[2])}<br>n=${group.n} · ${group.status}`
                        : '';
                },
            },
            grid: { top: 24, right: 40, bottom: 100, left: 180 },
            xAxis: {
                type: 'category',
                data: columns.value,
                axisLabel: { rotate: 25, width: 180, overflow: 'truncate' },
            },
            yAxis: {
                type: 'category',
                data: rows.value,
                axisLabel: { width: 165, overflow: 'truncate' },
            },
            visualMap: {
                min: 0,
                max: metricMax.value,
                calculable: true,
                orient: 'horizontal',
                left: 'center',
                bottom: 5,
                inRange: { color: ['#7f1d1d', '#f59e0b', '#10b981'] },
            },
            series: [
                {
                    type: 'heatmap',
                    data: heatData,
                    label: {
                        show: true,
                        formatter: (p: any) =>
                            p.data[2] === null
                                ? '—'
                                : `${format(p.data[2], 2)}\nn=${p.data[3]}`,
                    },
                    emphasis: { itemStyle: { shadowBlur: 10 } },
                },
            ],
        },
        true,
    );
    const scatterData = props.groups
        .map((group, index) => [
            group.metrics.tokensPerConfirmedTp?.median ?? null,
            group.metrics.confirmationF1?.median ?? null,
            group.metrics.providerCostUsd?.median ?? 0,
            index,
        ])
        .filter((item) => item[0] !== null && item[1] !== null);
    scatter.setOption(
        {
            tooltip: {
                formatter: (p: any) => {
                    const group = props.groups[p.data[3]];
                    return `<b>${rowLabel(group)}</b><br>${modelLabel(group)}<br>Token/confirmed TP: ${format(p.data[0], 0)}<br>Confirmation F1: ${format(p.data[1])}<br>Cost: $${format(p.data[2], 4)}`;
                },
            },
            grid: { top: 24, right: 30, bottom: 55, left: 75 },
            xAxis: {
                name: 'Token / confirmed TP',
                type: 'value',
                nameLocation: 'middle',
                nameGap: 35,
            },
            yAxis: { name: 'Confirmation F1', type: 'value', min: 0, max: 1 },
            series: [
                {
                    type: 'scatter',
                    data: scatterData,
                    symbolSize: (data: any) =>
                        Math.max(
                            12,
                            Math.min(
                                42,
                                12 + Math.sqrt(Number(data[2]) || 0) * 10,
                            ),
                        ),
                    itemStyle: { color: '#2563eb', opacity: 0.8 },
                },
            ],
        },
        true,
    );
};
const applyFilters = () =>
    router.get(
        '/benchmarks',
        Object.fromEntries(Object.entries(form).filter(([, value]) => value)),
        { preserveState: true },
    );
const resetFilters = () => {
    Object.keys(form).forEach((key) => delete form[key]);
    applyFilters();
};

watch(() => props.groups, renderCharts, { deep: true });
watch(metric, renderCharts);
onMounted(renderCharts);
useResizeObserver(heatmapEl, () => heatmap?.resize());
useResizeObserver(scatterEl, () => scatter?.resize());
onBeforeUnmount(() => {
    heatmap?.dispose();
    scatter?.dispose();
});
</script>

<template>
    <Head title="Experiment Explorer" />
    <div class="mx-auto w-full max-w-[1600px] space-y-6 p-4 md:p-8">
        <Link
            href="/audits"
            class="inline-flex items-center gap-2 text-sm text-muted-foreground"
            ><ArrowLeft class="size-4" /> Run</Link
        >
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="flex items-center gap-2 text-3xl font-semibold">
                    <BarChart3 /> Experiment Explorer
                </h1>
                <p class="mt-1 text-muted-foreground">
                    Qualità, consumo e configurazioni modello aggregate su
                    esperimenti ripetuti.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <label class="text-sm text-muted-foreground">Heatmap</label
                ><select
                    v-model="metric"
                    class="h-9 rounded-md border bg-background px-3 text-sm"
                >
                    <option value="globalScore">Global score</option>
                    <option value="fileRecall">File recall</option>
                    <option value="anchorRecall">Anchor recall</option>
                    <option value="staticValidationRecall">
                        Static validation recall
                    </option>
                    <option value="confirmationRecall">
                        Confirmation recall
                    </option>
                    <option value="confirmationF1">Confirmation F1</option>
                    <option value="detectionRecall">Detection recall</option>
                </select>
            </div>
        </header>

        <section class="rounded-xl border bg-card p-4">
            <div class="mb-3 flex items-center gap-2 font-medium">
                <Filter class="size-4" /> Filtri
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <select
                    v-model="form.target"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">Tutti i target</option>
                    <option v-for="value in options.targets" :key="value">
                        {{ value }}
                    </option>
                </select>
                <select
                    v-model="form.category"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">Tutte le categorie</option>
                    <option v-for="value in options.categories" :key="value">
                        {{ value }}
                    </option>
                </select>
                <select
                    v-model="form.reader"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">Reader: tutti</option>
                    <option v-for="value in options.readers" :key="value">
                        {{ value }}
                    </option>
                </select>
                <select
                    v-model="form.worker"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">Worker: tutti</option>
                    <option v-for="value in options.workers" :key="value">
                        {{ value }}
                    </option>
                </select>
                <select
                    v-model="form.confirmer"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">Confirmer: tutti</option>
                    <option v-for="value in options.confirmers" :key="value">
                        {{ value }}
                    </option>
                </select>
                <select
                    v-model="form.status"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">Stato: tutti</option>
                    <option v-for="value in options.statuses" :key="value">
                        {{ value }}
                    </option>
                </select>
                <select
                    v-model="form.artifact"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">Artifact: tutti</option>
                    <option v-for="value in options.artifacts" :key="value">
                        {{ value }}
                    </option>
                </select>
                <select
                    v-model="form.environment"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">Environment: validi/unknown</option>
                    <option v-for="value in options.environments" :key="value">
                        {{ value }}
                    </option>
                </select>
                <select
                    v-model="form.experiment"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">Esperimento: tutti</option>
                    <option
                        v-for="(name, id) in options.experiments"
                        :key="id"
                        :value="id"
                    >
                        {{ name }}
                    </option>
                </select>
                <input
                    v-model="form.from"
                    type="date"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                />
                <input
                    v-model="form.to"
                    type="date"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                />
                <div class="flex gap-2">
                    <Button class="flex-1" @click="applyFilters">Applica</Button
                    ><Button variant="outline" @click="resetFilters"
                        >Reset</Button
                    >
                </div>
            </div>
        </section>

        <div
            v-if="groups.length === 0"
            class="rounded-xl border p-16 text-center text-muted-foreground"
        >
            Nessuna evaluation indicizzata per i filtri selezionati.
        </div>
        <template v-else>
            <section class="grid gap-4 xl:grid-cols-2">
                <div class="rounded-xl border bg-card p-3">
                    <h2 class="px-2 pt-2 font-semibold">
                        Target × categoria × modelli
                    </h2>
                    <div ref="heatmapEl" class="h-[520px] w-full" />
                </div>
                <div class="rounded-xl border bg-card p-3">
                    <h2 class="px-2 pt-2 font-semibold">
                        Qualità × token efficiency
                    </h2>
                    <div ref="scatterEl" class="h-[520px] w-full" />
                </div>
            </section>
            <section class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="group in groups"
                    :key="`${group.key}-reader-efficiency`"
                    class="rounded-xl border bg-card p-4 text-sm"
                >
                    <div class="font-medium">{{ rowLabel(group) }}</div>
                    <div class="mt-2 grid grid-cols-2 gap-3">
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Reader cache hit
                            </div>
                            <div class="font-semibold">
                                {{
                                    percent(
                                        group.metrics.readerCacheHitRate.median,
                                    )
                                }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Candidate / 1k token Reader
                            </div>
                            <div class="font-semibold">
                                {{
                                    format(
                                        group.metrics
                                            .readerCandidatesPer1kTokens.median,
                                        2,
                                    )
                                }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <section class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full min-w-[1300px] text-sm">
                    <thead class="bg-muted/60 text-left">
                        <tr>
                            <th class="p-3">Target / categoria</th>
                            <th class="p-3">Modelli R / W / C</th>
                            <th class="p-3">Revisioni</th>
                            <th class="p-3">n</th>
                            <th class="p-3">Score</th>
                            <th class="p-3">Detection</th>
                            <th class="p-3">Static</th>
                            <th class="p-3">Confirmation</th>
                            <th class="p-3">Token</th>
                            <th class="p-3">Token / TP</th>
                            <th class="p-3">Costo</th>
                            <th class="p-3">Stato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="group in groups" :key="group.key"
                            ><tr class="border-t align-top">
                                <td class="p-3 font-medium">
                                    {{ group.target
                                    }}<span
                                        class="block text-xs text-muted-foreground"
                                        >{{ group.category }}</span
                                    >
                                </td>
                                <td class="p-3 text-xs">
                                    <div>
                                        R {{ group.models.reader || '—' }}
                                    </div>
                                    <div>
                                        W {{ group.models.worker || '—' }}
                                    </div>
                                    <div>
                                        C {{ group.models.confirmer || '—' }}
                                    </div>
                                </td>
                                <td class="p-3 font-mono text-xs">
                                    {{
                                        group.harnessRevision?.slice(0, 8) ||
                                        '—'
                                    }}<span class="block text-muted-foreground"
                                        >target
                                        {{
                                            group.targetCommit?.slice(0, 8) ||
                                            '—'
                                        }}</span
                                    >
                                </td>
                                <td class="p-3">
                                    {{ group.n
                                    }}<span
                                        class="block text-xs text-muted-foreground"
                                        >complete
                                        {{
                                            percent(group.completionRate)
                                        }}</span
                                    >
                                </td>
                                <td class="p-3">
                                    {{
                                        format(
                                            group.metrics.globalScore.median,
                                            1,
                                        )
                                    }}
                                    <span
                                        class="block text-xs text-muted-foreground"
                                    >
                                        IQR
                                        {{
                                            format(
                                                group.metrics.globalScore.q1,
                                                1,
                                            )
                                        }}
                                        -
                                        {{
                                            format(
                                                group.metrics.globalScore.q3,
                                                1,
                                            )
                                        }}
                                    </span>
                                </td>
                                <td class="p-3">
                                    {{
                                        percent(
                                            group.metrics.detectionRecall
                                                .median,
                                        )
                                    }}
                                </td>
                                <td class="p-3">
                                    {{
                                        percent(
                                            group.metrics.staticValidationRecall
                                                .median,
                                        )
                                    }}
                                </td>
                                <td class="p-3">
                                    {{
                                        percent(
                                            group.metrics.confirmationRecall
                                                .median,
                                        )
                                    }}<span
                                        class="block text-xs text-muted-foreground"
                                        >F1
                                        {{
                                            format(
                                                group.metrics.confirmationF1
                                                    .median,
                                            )
                                        }}</span
                                    >
                                </td>
                                <td class="p-3">
                                    {{
                                        format(
                                            group.metrics.totalTokens.median,
                                            0,
                                        )
                                    }}<span
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            format(
                                                group.metrics.durationSeconds
                                                    .median,
                                                0,
                                            )
                                        }}s</span
                                    >
                                </td>
                                <td class="p-3">
                                    {{
                                        format(
                                            group.metrics.tokensPerConfirmedTp
                                                .median,
                                            0,
                                        )
                                    }}
                                </td>
                                <td class="p-3">
                                    ${{
                                        format(
                                            group.metrics.providerCostUsd
                                                .median,
                                            4,
                                        )
                                    }}
                                </td>
                                <td class="p-3">
                                    <Badge
                                        :variant="
                                            group.status === 'scored'
                                                ? 'default'
                                                : 'secondary'
                                        "
                                        >{{ group.status }}</Badge
                                    >
                                </td>
                            </tr>
                            <tr class="border-t bg-muted/20">
                                <td colspan="12" class="p-2">
                                    <details>
                                        <summary
                                            class="cursor-pointer px-2 text-xs font-medium"
                                        >
                                            Drill-down:
                                            {{ group.runs.length }} evaluation
                                        </summary>
                                        <div
                                            class="mt-2 grid gap-2 md:grid-cols-2 xl:grid-cols-3"
                                        >
                                            <Link
                                                v-for="run in group.runs"
                                                :key="run.evaluationId"
                                                :href="`/audits/${run.runId}`"
                                                class="flex items-center gap-2 rounded-lg border bg-background p-3 hover:bg-muted"
                                                ><div class="min-w-0 flex-1">
                                                    <div
                                                        class="truncate font-mono text-xs"
                                                    >
                                                        {{ run.auditId }}
                                                    </div>
                                                    <div
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        rep
                                                        {{
                                                            run.repetition ||
                                                            '—'
                                                        }}
                                                        · TP
                                                        {{
                                                            run.confirmation
                                                                .tp || 0
                                                        }}
                                                        ·
                                                        {{ run.pending }}
                                                        pending
                                                    </div>
                                                </div>
                                                <ExternalLink class="size-4"
                                            /></Link>
                                        </div>
                                    </details>
                                </td></tr
                        ></template>
                    </tbody>
                </table>
            </section>
        </template>
    </div>
</template>
