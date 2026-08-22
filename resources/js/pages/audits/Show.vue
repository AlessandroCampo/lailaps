<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowDown,
    Ban,
    Brain,
    Bot,
    CheckCircle,
    Copy,
    ClipboardCheck,
    Eye,
    FileJson,
    Flag,
    Gauge,
    LoaderCircle,
    Pause,
    Play,
    Radio,
    RotateCcw,
    ShieldAlert,
    ShieldCheck,
    ShieldQuestion,
    Terminal,
    Trash2,
    Wrench,
    XCircle,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, type Component } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import LiveRunWorkspace from '@/components/audits/LiveRunWorkspace.vue';
import type {
    AuditArtifact,
    AuditEvent,
    AuditRunSummary,
    AuditStatus,
} from '@/types';

const props = defineProps<{
    run: AuditRunSummary;
    initialEvents: AuditEvent[];
    report: Record<string, any> | null;
    benchmark: Record<string, any> | null;
    telemetry: Record<string, any> | null;
    models: Array<Record<string, any>>;
    experiment: { id: string; name: string } | null;
    evaluations: Array<Record<string, any>>;
    artifacts: AuditArtifact[];
}>();
const stageLabels: Record<string, string> = {
    not_reached: 'Not reached',
    file_reached: 'File reached',
    anchor_reached: 'Anchor reached',
    suspected: 'Suspected',
    statically_validated: 'Statically validated',
    dynamically_confirmed: 'Dynamically confirmed',
};
const tabs = [
    'Live',
    'Report',
    'Benchmark',
    'Metriche',
    'Prompt',
    'Artefatti',
] as const;
const tab = ref<(typeof tabs)[number]>('Live');
const events = ref<AuditEvent[]>([...props.initialEvents]);
const status = ref<AuditStatus>(props.run.status);
const typeFilter = ref('all');
const roleFilter = ref('all');
const autoScroll = ref(true);
const visibleLimit = ref(600);
const timeline = ref<HTMLElement | null>(null);
const showRaw = ref(false);
const rawVisibleLimit = ref(600);
const newActivityCount = ref(0);
const copyOpen = ref(false);
const copyStatus = ref('');
const copyOptions = ref({
    toolOutputs: false,
    reasoning: true,
    prompts: false,
    telemetry: true,
    rawPayloads: false,
});
let source: EventSource | null = null;

type ActivityKind = 'text' | 'tool' | 'finding' | 'milestone';
type ActivityTone = 'violet' | 'cyan' | 'amber' | 'green' | 'red' | 'slate';
type ToolState = 'running' | 'completed' | 'failed';

interface ActivityItem {
    id: string;
    sequence: number;
    kind: ActivityKind;
    title: string;
    label: string;
    content?: string;
    tone: ActivityTone;
    icon: Component;
    role?: string | null;
    category?: string | null;
    event?: AuditEvent;
    toolName?: string;
    toolState?: ToolState;
    callId?: string | null;
    arguments?: unknown;
    output?: string;
    artifactRef?: string | null;
    findingId?: string;
    findingState?: string;
    finding?: Record<string, any>;
    related?: AuditEvent[];
}

const terminal = computed(() =>
    ['completed', 'failed', 'cancelled'].includes(status.value),
);
const roles = computed(
    () =>
        [
            ...new Set(events.value.map((event) => event.role).filter(Boolean)),
        ] as string[],
);
const types = computed(() => [
    ...new Set(events.value.map((event) => event.type)),
]);
const matchingEvents = computed(() =>
    events.value.filter(
        (event) =>
            (typeFilter.value === 'all' || event.type === typeFilter.value) &&
            (roleFilter.value === 'all' || event.role === roleFilter.value),
    ),
);
const rawEvents = computed(() =>
    matchingEvents.value.slice(-rawVisibleLimit.value),
);
const filteredEvents = computed(() =>
    matchingEvents.value.slice(-visibleLimit.value),
);
const prompts = computed(() =>
    events.value.filter((event) => event.type === 'system_prompt'),
);
const findings = computed(() => [
    ...(props.report?.confirmed || []).map((item: any) => ({
        ...item,
        _state: 'confirmed',
    })),
    ...(props.report?.suspected || []).map((item: any) => ({
        ...item,
        _state: 'suspected',
    })),
    ...(props.report?.rejected_inconclusive || []).map((item: any) => ({
        ...item,
        _state: 'rejected',
    })),
    ...(props.report?.findings || []).map((item: any) => ({
        ...item,
        _state: item.status || 'finding',
    })),
]);
const eventText = (event: AuditEvent) =>
    String(
        event.payload.content ??
            event.payload.output ??
            event.payload.message ??
            event.payload.preview ??
            JSON.stringify(event.payload, null, 2),
    );
const eventTitle = (event: AuditEvent) =>
    event.type === 'tool_call'
        ? String(event.payload.name || 'Tool call')
        : event.type === 'tool_result'
          ? `Risultato · ${String(event.payload.name || 'tool')}`
          : event.type.replaceAll('_', ' ');
const artifactUrl = (path?: string | null) =>
    `/audits/${props.run.id}/artifacts/${(path || '').split('/').map(encodeURIComponent).join('/')}`;
const fmtBytes = (bytes: number) =>
    bytes < 1024
        ? `${bytes} B`
        : bytes < 1048576
          ? `${(bytes / 1024).toFixed(1)} KB`
          : `${(bytes / 1048576).toFixed(1)} MB`;
const isLive = (value: string) =>
    ['queued', 'preparing', 'running', 'finalizing'].includes(value);
const adjudicate = (
    evaluationId: string,
    finding: Record<string, any>,
    decision: string,
) =>
    router.post(
        `/benchmarks/evaluations/${evaluationId}/adjudications`,
        {
            fingerprint: finding.fingerprint,
            decision,
            comment: 'Adjudication dalla pagina run',
        },
        { preserveScroll: true },
    );

const payloadText = (value: unknown) =>
    typeof value === 'string' ? value : JSON.stringify(value ?? {}, null, 2);
const findingEventName = (event: AuditEvent) =>
    String(event.payload.event || event.payload.type || 'finding_event');
const findingPayload = (event: AuditEvent) =>
    (event.payload.payload as Record<string, any> | undefined) || event.payload;
const findingStateFromEvent = (event: AuditEvent) => {
    const name = findingEventName(event);
    if (name.includes('confirmed')) return 'confirmed';
    if (
        name.includes('reject') ||
        name.includes('block') ||
        name.includes('closed')
    )
        return 'rejected';
    if (name.includes('confirmation')) return 'confirming';
    return 'suspected';
};

const liveFindings = computed(() => {
    const byId = new Map<string, ActivityItem>();
    events.value
        .filter((event) => event.type === 'finding_event')
        .forEach((event) => {
            const payload = findingPayload(event);
            const findingId = String(
                event.payload.lead_id ||
                    event.payload.candidate_id ||
                    payload.finding_id ||
                    `event-${event.sequence}`,
            );
            const state = findingStateFromEvent(event);
            const previous = byId.get(findingId);
            byId.set(findingId, {
                id: `finding-${findingId}`,
                sequence: event.sequence,
                kind: 'finding',
                title: String(payload.title || findingId),
                label:
                    state === 'confirmed'
                        ? 'Confirmed via HTTP'
                        : state === 'confirming'
                          ? 'Verifica in corso'
                          : state === 'rejected'
                            ? 'Non confermato'
                            : 'Suspect · da verificare',
                tone:
                    state === 'confirmed'
                        ? 'green'
                        : state === 'rejected'
                          ? 'slate'
                          : 'amber',
                icon:
                    state === 'confirmed'
                        ? ShieldCheck
                        : state === 'rejected'
                          ? XCircle
                          : ShieldQuestion,
                category: event.category,
                role: event.role,
                event,
                findingId,
                findingState: state,
                finding: {
                    ...(previous?.finding || {}),
                    ...payload,
                    status: state,
                },
            });
        });
    return [...byId.values()].sort((a, b) => a.sequence - b.sequence);
});

const activity = computed<ActivityItem[]>(() => {
    const items: ActivityItem[] = [];
    const tools = new Map<string, ActivityItem>();
    let lastText: ActivityItem | null = null;
    const pushText = (event: AuditEvent, kind: 'reasoning' | 'output') => {
        const content = String(event.payload.content || '');
        if (!content) return;
        if (
            lastText &&
            lastText.label === kind &&
            lastText.sequence < event.sequence
        ) {
            lastText.content = `${lastText.content || ''}${content}`;
            lastText.sequence = event.sequence;
            lastText.event = event;
            return;
        }
        lastText = {
            id: `${kind}-${event.sequence}`,
            sequence: event.sequence,
            kind: 'text',
            title: kind === 'reasoning' ? 'Reasoning' : 'Model output',
            label: kind,
            content,
            tone: kind === 'reasoning' ? 'violet' : 'cyan',
            icon: kind === 'reasoning' ? Brain : Bot,
            role: event.role,
            category: event.category,
            event,
        };
        items.push(lastText);
    };
    const pushMilestone = (
        event: AuditEvent,
        title: string,
        label: string,
        tone: ActivityTone,
        icon: Component,
    ) => {
        lastText = null;
        items.push({
            id: `milestone-${event.sequence}`,
            sequence: event.sequence,
            kind: 'milestone',
            title,
            label,
            tone,
            icon,
            event,
            role: event.role,
            category: event.category,
        });
    };
    events.value.forEach((event) => {
        if (event.type === 'reasoning_delta')
            return pushText(event, 'reasoning');
        if (event.type === 'model_output_delta')
            return pushText(event, 'output');
        if (event.type === 'tool_call') {
            lastText = null;
            const callId = String(
                event.payload.call_id || `sequence-${event.sequence}`,
            );
            const item: ActivityItem = {
                id: `tool-${callId}`,
                sequence: event.sequence,
                kind: 'tool',
                title: String(event.payload.name || 'Tool call'),
                label: 'Tool call',
                tone: 'amber',
                icon: Wrench,
                role: event.role,
                category: event.category,
                event,
                toolName: String(event.payload.name || 'tool'),
                toolState: 'running',
                callId,
                arguments: event.payload.arguments,
                related: [event],
            };
            tools.set(callId, item);
            items.push(item);
            return;
        }
        if (event.type === 'tool_result') {
            const callId = String(event.payload.call_id || '');
            const item = tools.get(callId);
            if (item) {
                item.toolState = 'completed';
                item.output = String(
                    event.payload.preview ||
                        event.payload.output ||
                        event.payload.content ||
                        '',
                );
                item.artifactRef = event.artifact_ref;
                item.related?.push(event);
            } else {
                items.push({
                    id: `tool-result-${event.sequence}`,
                    sequence: event.sequence,
                    kind: 'tool',
                    title: String(event.payload.name || 'Tool result'),
                    label: 'Tool result',
                    tone: 'green',
                    icon: CheckCircle,
                    event,
                    toolName: String(event.payload.name || 'tool'),
                    toolState: 'completed',
                    callId: event.payload.call_id as string | null,
                    output: String(
                        event.payload.preview || event.payload.output || '',
                    ),
                    artifactRef: event.artifact_ref,
                    related: [event],
                });
            }
            return;
        }
        if (event.type === 'finding_event') {
            lastText = null;
            const finding = liveFindings.value.find(
                (item) => item.event?.sequence === event.sequence,
            );
            if (finding) items.push(finding);
            return;
        }
        lastText = null;
        if (event.type === 'status') {
            const next = String(event.payload.status || '');
            pushMilestone(
                event,
                next,
                'Run status',
                next === 'failed' ? 'red' : 'slate',
                next === 'running'
                    ? Play
                    : next === 'completed'
                      ? CheckCircle
                      : XCircle,
            );
        } else if (event.type === 'error') {
            pushMilestone(
                event,
                'Errore',
                String(event.payload.message || eventText(event)),
                'red',
                XCircle,
            );
        } else if (event.type === 'usage') {
            pushMilestone(
                event,
                'Usage aggiornato',
                `${event.payload.total_tokens || 0} token`,
                'slate',
                Gauge,
            );
        } else if (event.type === 'system_prompt') {
            pushMilestone(
                event,
                'System prompt',
                'Snapshot disponibile nella tab Prompt',
                'slate',
                Terminal,
            );
        } else if (
            event.type === 'report_published' ||
            event.type === 'benchmark_published'
        ) {
            pushMilestone(
                event,
                event.type === 'report_published'
                    ? 'Report pubblicato'
                    : 'Benchmark pubblicato',
                'Artefatto disponibile',
                'green',
                Flag,
            );
        } else if (event.type === 'log') {
            pushMilestone(event, 'Log', eventText(event), 'slate', Terminal);
        }
    });
    return items;
});

const suspectCount = computed(
    () =>
        liveFindings.value.filter(
            (item) =>
                item.findingState === 'suspected' ||
                item.findingState === 'confirming',
        ).length,
);
const confirmedCount = computed(
    () =>
        liveFindings.value.filter((item) => item.findingState === 'confirmed')
            .length,
);
const activeToolCount = computed(
    () =>
        activity.value.filter(
            (item) => item.kind === 'tool' && item.toolState === 'running',
        ).length,
);
const latestUsage = computed(
    () =>
        [...events.value].reverse().find((event) => event.type === 'usage')
            ?.payload || null,
);
const displayedActivities = computed(() => activity.value);
const toneClasses = (tone: ActivityTone) =>
    ({
        violet: 'border-fuchsia-500/35 bg-fuchsia-500/[0.07] text-fuchsia-100',
        cyan: 'border-cyan-500/35 bg-cyan-500/[0.07] text-cyan-50',
        amber: 'border-amber-500/40 bg-amber-500/[0.08] text-amber-50',
        green: 'border-emerald-500/40 bg-emerald-500/[0.08] text-emerald-50',
        red: 'border-red-500/45 bg-red-500/[0.08] text-red-50',
        slate: 'border-zinc-700 bg-zinc-900/70 text-zinc-200',
    })[tone];
const iconClasses = (tone: ActivityTone) =>
    ({
        violet: 'bg-fuchsia-400/15 text-fuchsia-300',
        cyan: 'bg-cyan-400/15 text-cyan-300',
        amber: 'bg-amber-400/15 text-amber-300',
        green: 'bg-emerald-400/15 text-emerald-300',
        red: 'bg-red-400/15 text-red-300',
        slate: 'bg-zinc-700 text-zinc-300',
    })[tone];
const toolStateLabel = (state?: ToolState) =>
    state === 'running'
        ? 'In esecuzione'
        : state === 'failed'
          ? 'Errore'
          : 'Completato';
const findingForActivity = (item: ActivityItem) => item.finding || {};
const scrollToLatest = () => {
    autoScroll.value = true;
    newActivityCount.value = 0;
    window.requestAnimationFrame(() =>
        timeline.value?.scrollTo({
            top: timeline.value.scrollHeight,
            behavior: 'smooth',
        }),
    );
};
const onTimelineScroll = () => {
    const element = timeline.value;
    if (!element) return;
    const atBottom =
        element.scrollHeight - element.scrollTop - element.clientHeight < 48;
    if (atBottom) {
        autoScroll.value = true;
        newActivityCount.value = 0;
    } else autoScroll.value = false;
};

const connect = () => {
    if (!isLive(status.value)) return;
    const after = events.value.at(-1)?.sequence || 0;
    source = new EventSource(`/audits/${props.run.id}/events?after=${after}`);
    const names = [
        'status',
        'log',
        'system_prompt',
        'reasoning_delta',
        'model_output_delta',
        'tool_call',
        'tool_result',
        'usage',
        'finding_event',
        'report_published',
        'benchmark_published',
        'error',
    ];
    names.forEach((name) =>
        source?.addEventListener(name, (message) => {
            if (!(message instanceof MessageEvent) || !message.data) {
                return;
            }
            const event = JSON.parse(message.data) as AuditEvent;
            if (!events.value.some((item) => item.sequence === event.sequence))
                events.value.push(event);
            if (
                event.type === 'status' &&
                typeof event.payload.status === 'string'
            ) {
                status.value = event.payload.status as AuditStatus;
                if (terminal.value) {
                    source?.close();
                    window.setTimeout(
                        () =>
                            router.reload({
                                only: [
                                    'run',
                                    'report',
                                    'benchmark',
                                    'artifacts',
                                ],
                            }),
                        300,
                    );
                }
            }
            if (autoScroll.value)
                window.requestAnimationFrame(() =>
                    timeline.value?.scrollTo({
                        top: timeline.value.scrollHeight,
                        behavior: 'smooth',
                    }),
                );
            else newActivityCount.value += 1;
        }),
    );
};
const cancel = () => router.post(`/audits/${props.run.id}/cancel`);
const rerun = () => router.post(`/audits/${props.run.id}/rerun`);
const remove = () => {
    if (window.confirm('Eliminare definitivamente run, eventi e artefatti?'))
        router.delete(`/audits/${props.run.id}`);
};
const copy = (value: unknown) =>
    navigator.clipboard.writeText(
        typeof value === 'string' ? value : JSON.stringify(value, null, 2),
    );
const copyJson = (value: unknown) => JSON.stringify(value ?? null, null, 2);
const copyEvent = (event: AuditEvent) => {
    const payload = event.payload;

    if (event.type === 'tool_call') {
        const name = String(payload.name || payload.tool_name || 'tool');
        return copyOptions.value.rawPayloads
            ? copyJson(payload)
            : `Tool chiamato: ${name}`;
    }
    if (event.type === 'tool_result') {
        if (!copyOptions.value.toolOutputs) return 'Output del tool escluso';
        return copyOptions.value.rawPayloads
            ? copyJson(payload)
            : String(
                  payload.preview || payload.output || payload.content || '',
              );
    }
    if (event.type === 'status')
        return `Stato: ${String(payload.status || '—')}`;
    if (event.type === 'usage') {
        return `Token: ${String(payload.total_tokens || '—')} · Tool call: ${String(payload.number_of_tool_calls || '—')}`;
    }

    return copyOptions.value.rawPayloads
        ? copyJson(payload)
        : String(
              payload.content ??
                  payload.message ??
                  payload.preview ??
                  JSON.stringify(payload, null, 2),
          );
};
const copyRun = async () => {
    const lines = [
        `# Audit run ${props.run.auditId}`,
        '',
        `- Target: ${props.run.target}`,
        `- Tipo: ${props.run.type}`,
        `- Stato: ${status.value}`,
        `- Creato: ${props.run.createdAt}`,
        `- Inizio: ${props.run.startedAt || '—'}`,
        `- Fine: ${props.run.finishedAt || '—'}`,
        `- Categorie: ${props.run.categories.join(', ') || '—'}`,
        `- Confermati: ${props.run.confirmed}`,
        `- Suspect: ${props.run.suspected}`,
        '',
        '## Parametri',
        '```json',
        copyJson(props.run.parameters),
        '```',
        '',
        '## Timeline',
    ];

    events.value.forEach((event) => {
        if (event.type === 'tool_result' && !copyOptions.value.toolOutputs)
            return;
        if (event.type === 'reasoning_delta' && !copyOptions.value.reasoning)
            return;
        if (event.type === 'system_prompt' && !copyOptions.value.prompts)
            return;

        lines.push(
            `### #${event.sequence} · ${event.type}${event.role ? ` · ${event.role}` : ''}`,
            copyEvent(event),
            '',
        );
    });

    lines.push('## Report', '```json', copyJson(props.report), '```', '');
    lines.push('## Benchmark', '```json', copyJson(props.benchmark), '```', '');
    if (copyOptions.value.telemetry) {
        lines.push(
            '## Telemetria',
            '```json',
            copyJson(props.telemetry),
            '```',
            '',
        );
    }
    lines.push(
        '## Riproducibilità',
        '```json',
        copyJson({
            models: props.models,
            experiment: props.experiment,
            evaluations: props.evaluations,
        }),
        '```',
        '',
    );
    lines.push(
        '## Artefatti',
        ...(props.artifacts.length
            ? props.artifacts.map(
                  (artifact) => `- ${artifact.path} (${artifact.bytes} bytes)`,
              )
            : ['- Nessun artefatto disponibile']),
    );

    try {
        await navigator.clipboard.writeText(lines.join('\n'));
        copyStatus.value = 'Run copiata negli appunti';
        window.setTimeout(() => (copyStatus.value = ''), 2500);
    } catch {
        copyStatus.value =
            'Copia non riuscita: verifica i permessi del browser';
    }
};
onMounted(connect);
onBeforeUnmount(() => source?.close());
</script>

<template>
    <Head :title="`Audit ${run.auditId}`" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-5 p-4 md:p-8">
        <Link
            href="/audits"
            class="inline-flex w-fit items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
            ><ArrowLeft class="size-4" /> Tutte le run</Link
        >
        <header class="rounded-xl border bg-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <Badge
                            :variant="
                                status === 'failed'
                                    ? 'destructive'
                                    : terminal
                                      ? 'default'
                                      : 'secondary'
                            "
                            >{{ status }}</Badge
                        >
                    </div>
                    <h1 class="mt-3 text-2xl font-semibold">
                        {{ run.target }}
                    </h1>
                    <p class="mt-1 font-mono text-xs text-muted-foreground">
                        {{ run.auditId }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="!terminal"
                        variant="destructive"
                        :disabled="run.cancellationRequested"
                        @click="cancel"
                        ><Ban />
                        {{
                            run.cancellationRequested
                                ? 'Stop richiesto'
                                : 'Annulla'
                        }}</Button
                    ><Button variant="outline" @click="rerun"
                        ><RotateCcw /> Rilancia</Button
                    ><Button v-if="terminal" variant="ghost" @click="remove"
                        ><Trash2 /> Elimina</Button
                    ><Button variant="outline" @click="copyOpen = !copyOpen"
                        ><Copy /> Copia run</Button
                    >
                </div>
            </div>
            <div class="mt-5 grid gap-3 border-t pt-4 text-sm sm:grid-cols-4">
                <div>
                    <span class="text-muted-foreground">Tipo</span>
                    <p class="font-medium">{{ run.type }}</p>
                </div>
                <div>
                    <span class="text-muted-foreground">Categorie</span>
                    <p class="truncate font-medium">
                        {{ run.categories.join(', ') || '—' }}
                    </p>
                </div>
                <div>
                    <span class="text-muted-foreground">Confermati</span>
                    <p class="font-medium">{{ run.confirmed }}</p>
                </div>
                <div>
                    <span class="text-muted-foreground">Score</span>
                    <p class="font-medium">{{ run.score ?? '—' }}</p>
                </div>
            </div>
            <div v-if="copyOpen" class="mt-5 rounded-xl border bg-muted/40 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="flex items-center gap-2 font-medium">
                            <Copy class="size-4" /> Copia smart
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Genera un Markdown pronto da incollare con timeline,
                            report e benchmark.
                        </p>
                    </div>
                    <Button size="sm" @click="copyRun">
                        <ClipboardCheck /> Copia negli appunti
                    </Button>
                </div>
                <div
                    class="mt-4 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-5"
                >
                    <label class="flex items-center gap-2">
                        <input
                            v-model="copyOptions.toolOutputs"
                            type="checkbox"
                        />
                        Output dei tool
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="copyOptions.reasoning"
                            type="checkbox"
                        />
                        Reasoning
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="copyOptions.prompts" type="checkbox" />
                        Prompt di sistema
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="copyOptions.telemetry"
                            type="checkbox"
                        />
                        Telemetria
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="copyOptions.rawPayloads"
                            type="checkbox"
                        />
                        Payload grezzi
                    </label>
                </div>
                <p v-if="copyStatus" class="mt-3 text-sm text-primary">
                    {{ copyStatus }}
                </p>
            </div>
        </header>

        <nav
            class="flex gap-1 overflow-x-auto rounded-lg border bg-muted/40 p-1"
        >
            <button
                v-for="name in tabs"
                :key="name"
                class="rounded-md px-4 py-2 text-sm font-medium transition-colors"
                :class="
                    tab === name
                        ? 'bg-background shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="tab = name"
            >
                {{ name }}
            </button>
        </nav>

        <LiveRunWorkspace
            v-if="tab === 'Live'"
            :run="run"
            :events="events"
            :status="status"
            :terminal="terminal"
        />

        <section
            v-if="false && tab === 'Live'"
            class="grid min-h-[620px] gap-4 lg:grid-cols-[1fr_280px]"
        >
            <div
                class="flex min-w-0 flex-col overflow-hidden rounded-xl border bg-zinc-950 text-zinc-100"
            >
                <div
                    class="flex flex-wrap items-center gap-2 border-b border-zinc-800 p-3"
                >
                    <Terminal class="size-4 text-emerald-400" /><span
                        class="text-sm font-medium"
                        >Event stream</span
                    ><span
                        v-if="!terminal"
                        class="ml-1 size-2 animate-pulse rounded-full bg-emerald-400"
                    />
                    <select
                        v-model="typeFilter"
                        class="ml-auto h-8 rounded border-zinc-700 bg-zinc-900 px-2 text-xs"
                    >
                        <option value="all">Tutti i tipi</option>
                        <option v-for="value in types" :key="value">
                            {{ value }}
                        </option>
                    </select>
                    <select
                        v-model="roleFilter"
                        class="h-8 rounded border-zinc-700 bg-zinc-900 px-2 text-xs"
                    >
                        <option value="all">Tutti i ruoli</option>
                        <option v-for="value in roles" :key="value">
                            {{ value }}
                        </option>
                    </select>
                </div>
                <div
                    ref="timeline"
                    class="flex-1 space-y-2 overflow-y-auto p-3 font-mono text-xs"
                >
                    <button
                        v-if="matchingEvents.length > filteredEvents.length"
                        class="w-full rounded-lg border border-zinc-800 p-2 text-zinc-400 hover:text-zinc-100"
                        @click="visibleLimit += 600"
                    >
                        Carica eventi precedenti ({{
                            matchingEvents.length - filteredEvents.length
                        }})
                    </button>
                    <div
                        v-if="filteredEvents.length === 0"
                        class="p-8 text-center text-zinc-500"
                    >
                        In attesa di eventi…
                    </div>
                    <details
                        v-for="event in filteredEvents"
                        :key="event.sequence"
                        class="group rounded-lg border border-zinc-800 bg-zinc-900/70"
                        :open="['status', 'error'].includes(event.type)"
                    >
                        <summary
                            class="flex cursor-pointer list-none items-center gap-2 p-3"
                        >
                            <span class="text-zinc-600"
                                >#{{ event.sequence }}</span
                            ><Badge
                                variant="outline"
                                class="border-zinc-700 text-zinc-300"
                                >{{
                                    event.role || event.category || 'system'
                                }}</Badge
                            ><span
                                :class="
                                    event.type === 'error'
                                        ? 'text-red-400'
                                        : event.type === 'reasoning_delta'
                                          ? 'text-fuchsia-300'
                                          : event.type.startsWith('tool')
                                            ? 'text-amber-300'
                                            : 'text-emerald-300'
                                "
                                >{{ eventTitle(event) }}</span
                            ><span class="ml-auto text-zinc-600">{{
                                event.occurred_at?.slice(11, 19)
                            }}</span>
                        </summary>
                        <pre
                            class="max-h-96 overflow-auto border-t border-zinc-800 p-3 leading-relaxed break-words whitespace-pre-wrap text-zinc-300"
                            >{{ eventText(event) }}</pre>
                        <a
                            v-if="event.artifact_ref"
                            :href="artifactUrl(event.artifact_ref)"
                            target="_blank"
                            class="block border-t border-zinc-800 p-2 text-right text-cyan-400"
                            >Apri payload completo →</a
                        >
                    </details>
                </div>
                <label
                    class="flex items-center gap-2 border-t border-zinc-800 p-3 text-xs text-zinc-400"
                    ><input v-model="autoScroll" type="checkbox" /> Segui
                    automaticamente</label
                >
            </div>
            <aside class="space-y-4">
                <div class="rounded-xl border bg-card p-4">
                    <h3 class="flex items-center gap-2 font-medium">
                        <Gauge class="size-4" /> Ultimo consumo
                    </h3>
                    <pre
                        class="mt-3 overflow-auto text-xs whitespace-pre-wrap text-muted-foreground"
                        >{{
                            JSON.stringify(
                                [...events]
                                    .reverse()
                                    .find((e) => e.type === 'usage')
                                    ?.payload || { status: 'non disponibile' },
                                null,
                                2,
                            )
                        }}</pre>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <h3 class="font-medium">Parametri</h3>
                    <pre
                        class="mt-3 max-h-80 overflow-auto text-xs whitespace-pre-wrap text-muted-foreground"
                        >{{ JSON.stringify(run.parameters, null, 2) }}</pre>
                </div>
                <div
                    v-if="run.error"
                    class="rounded-xl border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
                >
                    <AlertTriangle class="mb-2 size-4" />{{ run.error }}
                </div>
            </aside>
        </section>

        <section v-else-if="tab === 'Report'" class="space-y-4">
            <div
                v-if="!report"
                class="rounded-xl border p-12 text-center text-muted-foreground"
            >
                <ShieldAlert class="mx-auto mb-3 size-8" />Il report non è
                ancora disponibile.
            </div>
            <template v-else>
                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="rounded-xl border bg-card p-4">
                        <span class="text-sm text-muted-foreground"
                            >Outcome</span
                        >
                        <p class="mt-1 text-xl font-semibold">
                            {{
                                report.outcome ||
                                report.termination_reason ||
                                '—'
                            }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <span class="text-sm text-muted-foreground"
                            >Confermati</span
                        >
                        <p class="mt-1 text-xl font-semibold">
                            {{ report.confirmed?.length || 0 }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <span class="text-sm text-muted-foreground"
                            >Sospetti</span
                        >
                        <p class="mt-1 text-xl font-semibold">
                            {{ report.suspected?.length || 0 }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <span class="text-sm text-muted-foreground"
                            >Blocker</span
                        >
                        <p class="mt-1 text-xl font-semibold">
                            {{ report.blockers?.length || 0 }}
                        </p>
                    </div>
                </div>
                <article
                    v-for="(finding, index) in findings"
                    :key="finding.finding_id || index"
                    class="rounded-xl border bg-card p-5"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            :variant="
                                finding._state === 'confirmed'
                                    ? 'default'
                                    : 'secondary'
                            "
                            >{{ finding._state }}</Badge
                        ><Badge variant="outline">{{
                            finding.severity || 'unknown'
                        }}</Badge
                        ><span class="text-xs text-muted-foreground">{{
                            finding.owasp_category
                        }}</span>
                    </div>
                    <h3 class="mt-3 text-lg font-semibold">
                        {{ finding.title }}
                    </h3>
                    <p
                        class="mt-2 text-sm whitespace-pre-wrap text-muted-foreground"
                    >
                        {{ finding.description || finding.hypothesis }}
                    </p>
                    <div
                        v-if="finding.locations?.length"
                        class="mt-4 rounded-lg bg-muted p-3 font-mono text-xs"
                    >
                        <div
                            v-for="location in finding.locations"
                            :key="location.file"
                        >
                            {{ location.file
                            }}{{ location.line ? `:${location.line}` : '' }}
                            <pre class="mt-2 whitespace-pre-wrap">{{
                                location.snippet
                            }}</pre>
                        </div>
                    </div>
                    <details class="mt-4">
                        <summary class="cursor-pointer text-sm font-medium">
                            Evidenza e remediation
                        </summary>
                        <pre
                            class="mt-2 overflow-auto rounded-lg bg-muted p-3 text-xs whitespace-pre-wrap"
                            >{{
                                JSON.stringify(
                                    {
                                        exploit: finding.exploit,
                                        recommendation: finding.recommendation,
                                        reason: finding.reason,
                                    },
                                    null,
                                    2,
                                )
                            }}</pre>
                    </details>
                </article>
                <details class="rounded-xl border bg-card p-5">
                    <summary class="cursor-pointer font-medium">
                        Report JSON completo
                    </summary>
                    <pre
                        class="mt-4 max-h-[700px] overflow-auto text-xs whitespace-pre-wrap"
                        >{{ JSON.stringify(report, null, 2) }}</pre>
                </details>
            </template>
        </section>

        <section v-else-if="tab === 'Benchmark'">
            <div
                v-if="!benchmark"
                class="rounded-xl border p-12 text-center text-muted-foreground"
            >
                <Gauge class="mx-auto mb-3 size-8" />Nessun benchmark prodotto
                per questa run.
            </div>
            <div v-else class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-4">
                    <div
                        v-for="(value, label) in {
                            detection_recall: benchmark.detection?.recall,
                            detection_precision: benchmark.detection?.precision,
                            confirmation_recall: benchmark.confirmation?.recall,
                            confirmation_precision:
                                benchmark.confirmation?.precision,
                        }"
                        :key="label"
                        class="rounded-xl border bg-card p-4"
                    >
                        <span
                            class="text-sm text-muted-foreground capitalize"
                            >{{ label }}</span
                        >
                        <p class="mt-1 text-2xl font-semibold">
                            {{ value ?? '—' }}
                        </p>
                    </div>
                </div>
                <pre
                    class="max-h-[750px] overflow-auto rounded-xl border bg-card p-5 text-xs whitespace-pre-wrap"
                    >{{ JSON.stringify(benchmark, null, 2) }}</pre>
            </div>
        </section>

        <section v-else-if="tab === 'Metriche'" class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <span class="text-sm text-muted-foreground"
                        >Token totali</span
                    >
                    <p class="mt-1 text-2xl font-semibold">
                        {{ telemetry?.total_tokens?.toLocaleString?.() ?? '—' }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <span class="text-sm text-muted-foreground"
                        >Cached input</span
                    >
                    <p class="mt-1 text-2xl font-semibold">
                        {{
                            telemetry?.cached_model_input_tokens?.toLocaleString?.() ??
                            '—'
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <span class="text-sm text-muted-foreground">Tool call</span>
                    <p class="mt-1 text-2xl font-semibold">
                        {{ telemetry?.number_of_tool_calls ?? '—' }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <span class="text-sm text-muted-foreground"
                        >Costo provider</span
                    >
                    <p class="mt-1 text-2xl font-semibold">
                        ${{
                            Number(telemetry?.provider_cost_usd || 0).toFixed(4)
                        }}
                    </p>
                </div>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <article class="rounded-xl border bg-card p-5">
                    <h3 class="font-semibold">Modelli effettivi</h3>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="model in models"
                            :key="model.role"
                            class="flex items-center gap-3 rounded-lg bg-muted p-3 text-sm"
                        >
                            <Badge>{{ model.role }}</Badge
                            ><span
                                class="min-w-0 flex-1 truncate font-mono text-xs"
                                >{{ model.effective || '—' }}</span
                            ><span class="text-xs text-muted-foreground">{{
                                model.reasoningEffort
                            }}</span>
                        </div>
                        <p
                            v-if="models.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Disponibili dopo la finalizzazione v2.
                        </p>
                    </div>
                </article>
                <article class="rounded-xl border bg-card p-5">
                    <h3 class="font-semibold">Riproducibilità</h3>
                    <dl class="mt-3 grid grid-cols-[140px_1fr] gap-2 text-sm">
                        <dt class="text-muted-foreground">Esperimento</dt>
                        <dd>
                            <Link
                                v-if="experiment"
                                :href="`/benchmarks?experiment=${experiment.id}`"
                                class="text-primary underline"
                                >{{ experiment.name }}</Link
                            ><span v-else>—</span>
                        </dd>
                    </dl>
                </article>
            </div>
            <article
                v-for="evaluation in evaluations"
                :key="evaluation.id"
                class="rounded-xl border bg-card p-5"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="font-semibold">{{ evaluation.benchmarkId }}</h3>
                    <Badge
                        :variant="
                            evaluation.status === 'scored'
                                ? 'default'
                                : 'secondary'
                        "
                        >{{ evaluation.status }}</Badge
                    ><span class="text-xs text-muted-foreground"
                        >evaluator {{ evaluation.evaluatorVersion }}</span
                    >
                </div>
                <div
                    class="mt-2 flex flex-wrap gap-2 text-xs text-muted-foreground"
                >
                    <span>artifact: {{ evaluation.artifactState }}</span
                    ><span>run: {{ evaluation.runState }}</span
                    ><span>environment: {{ evaluation.environmentState }}</span
                    ><span
                        >adjudication: {{ evaluation.adjudicationState }}</span
                    ><span v-if="evaluation.terminationReason"
                        >termination: {{ evaluation.terminationReason }}</span
                    >
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div class="rounded-lg bg-muted p-3">
                        <span class="text-xs text-muted-foreground"
                            >Global score</span
                        >
                        <p class="text-lg font-semibold">
                            {{
                                evaluation.adjudicatedScore ??
                                evaluation.score ??
                                '—'
                            }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-muted p-3">
                        <span class="text-xs text-muted-foreground"
                            >File recall</span
                        >
                        <p class="text-lg font-semibold">
                            {{ evaluation.reach?.file_reached?.recall ?? '—' }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-muted p-3">
                        <span class="text-xs text-muted-foreground"
                            >Anchor recall</span
                        >
                        <p class="text-lg font-semibold">
                            {{
                                evaluation.reach?.anchor_reached?.recall ?? '—'
                            }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-muted p-3">
                        <span class="text-xs text-muted-foreground"
                            >Suspected recall</span
                        >
                        <p class="text-lg font-semibold">
                            {{ evaluation.detection?.recall ?? '—' }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-muted p-3">
                        <span class="text-xs text-muted-foreground"
                            >Static recall</span
                        >
                        <p class="text-lg font-semibold">
                            {{ evaluation.staticValidation?.recall ?? '—' }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-muted p-3">
                        <span class="text-xs text-muted-foreground"
                            >Dynamic recall</span
                        >
                        <p class="text-lg font-semibold">
                            {{ evaluation.confirmation?.recall ?? '—' }}
                        </p>
                    </div>
                </div>
                <div
                    v-if="evaluation.cases?.length"
                    class="mt-4 overflow-x-auto"
                >
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-xs text-muted-foreground">
                            <tr>
                                <th class="p-2">Target</th>
                                <th class="p-2">Level</th>
                                <th class="p-2">Points</th>
                                <th class="p-2">Match</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="benchmarkCase in evaluation.cases"
                                :key="benchmarkCase.case_id"
                                class="border-b last:border-0"
                            >
                                <td class="p-2 font-mono text-xs">
                                    {{ benchmarkCase.case_id }}
                                </td>
                                <td class="p-2">
                                    <Badge
                                        :variant="
                                            benchmarkCase.stage ===
                                            'dynamically_confirmed'
                                                ? 'default'
                                                : 'secondary'
                                        "
                                        >{{
                                            stageLabels[benchmarkCase.stage] ||
                                            benchmarkCase.stage
                                        }}</Badge
                                    >
                                </td>
                                <td class="p-2">
                                    {{ benchmarkCase.stage_score }}
                                </td>
                                <td class="p-2 text-xs text-muted-foreground">
                                    {{ benchmarkCase.match_mode }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="
                        [
                            ...(evaluation.ambiguous || []),
                            ...(evaluation.unmatched || []),
                        ].length
                    "
                    class="mt-4 space-y-2"
                >
                    <h4 class="text-sm font-medium">Adjudication open-world</h4>
                    <div
                        v-for="finding in [
                            ...(evaluation.ambiguous || []),
                            ...(evaluation.unmatched || []),
                        ]"
                        :key="finding.fingerprint"
                        class="flex flex-wrap items-center gap-2 rounded-lg border p-3 text-sm"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ finding.title }}</p>
                            <p
                                class="truncate font-mono text-xs text-muted-foreground"
                            >
                                {{ finding.reason }} · {{ finding.fingerprint }}
                            </p>
                        </div>
                        <Button
                            size="sm"
                            variant="destructive"
                            @click="
                                adjudicate(
                                    evaluation.id,
                                    finding,
                                    'false_positive',
                                )
                            "
                            >Falso positivo</Button
                        ><Button
                            size="sm"
                            variant="outline"
                            @click="
                                adjudicate(
                                    evaluation.id,
                                    finding,
                                    'valid_out_of_catalog',
                                )
                            "
                            >Valido fuori catalogo</Button
                        ><Button
                            size="sm"
                            variant="ghost"
                            @click="
                                adjudicate(
                                    evaluation.id,
                                    finding,
                                    'not_scorable',
                                )
                            "
                            >Non valutabile</Button
                        >
                    </div>
                </div>
            </article>
            <details class="rounded-xl border bg-card p-5">
                <summary class="cursor-pointer font-medium">
                    Telemetria completa
                </summary>
                <pre
                    class="mt-4 max-h-[700px] overflow-auto text-xs whitespace-pre-wrap"
                    >{{
                        JSON.stringify({ telemetry }, null, 2)
                    }}</pre>
            </details>
        </section>

        <section v-else-if="tab === 'Prompt'" class="space-y-4">
            <div
                class="flex gap-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-600" />
                <p>
                    I prompt e le tracce possono contenere codice, credenziali o
                    dati del target. Non condividerli senza revisione.
                </p>
            </div>
            <div
                v-if="prompts.length === 0"
                class="rounded-xl border p-12 text-center text-muted-foreground"
            >
                Nessuno snapshot disponibile per questa run.
            </div>
            <article
                v-for="prompt in prompts"
                :key="prompt.sequence"
                class="overflow-hidden rounded-xl border bg-card"
            >
                <header class="flex items-center gap-2 border-b p-4">
                    <Badge>{{ prompt.role || 'agent' }}</Badge
                    ><span class="text-sm text-muted-foreground">{{
                        prompt.category
                    }}</span
                    ><Button
                        size="sm"
                        variant="ghost"
                        class="ml-auto"
                        @click="copy(prompt.payload.content)"
                        ><Copy /> Copia</Button
                    >
                </header>
                <pre
                    class="max-h-[650px] overflow-auto p-5 text-xs leading-relaxed whitespace-pre-wrap"
                    >{{ prompt.payload.content }}</pre>
            </article>
        </section>

        <section v-else class="overflow-hidden rounded-xl border bg-card">
            <div
                v-if="artifacts.length === 0"
                class="p-12 text-center text-muted-foreground"
            >
                Nessun artefatto disponibile.
            </div>
            <a
                v-for="artifact in artifacts"
                :key="artifact.path"
                :href="artifactUrl(artifact.path)"
                target="_blank"
                class="flex items-center gap-3 border-b p-4 last:border-0 hover:bg-muted/40"
                ><FileJson class="size-4 text-muted-foreground" /><span
                    class="min-w-0 flex-1 truncate font-mono text-sm"
                    >{{ artifact.path }}</span
                ><span class="text-xs text-muted-foreground">{{
                    fmtBytes(artifact.bytes)
                }}</span></a
            >
        </section>
    </div>
</template>
