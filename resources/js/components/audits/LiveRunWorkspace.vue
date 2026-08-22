<script setup lang="ts">
import {
    ArrowDown,
    Bot,
    Brain,
    CheckCircle,
    Eye,
    Flag,
    Gauge,
    LoaderCircle,
    Pause,
    Radio,
    ShieldAlert,
    ShieldCheck,
    ShieldQuestion,
    Terminal,
    Wrench,
    XCircle,
} from '@lucide/vue';
import { computed, onMounted, ref, watch, type Component } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { AuditEvent, AuditRunSummary, AuditStatus } from '@/types';

const props = defineProps<{
    run: AuditRunSummary;
    events: AuditEvent[];
    status: AuditStatus;
    terminal: boolean;
}>();

type Tone = 'violet' | 'cyan' | 'amber' | 'green' | 'red' | 'slate';
type ToolState = 'running' | 'completed' | 'failed';
interface ActivityItem {
    id: string;
    sequence: number;
    kind: 'text' | 'tool' | 'finding' | 'milestone';
    title: string;
    label: string;
    tone: Tone;
    icon: Component;
    content?: string;
    role?: string | null;
    category?: string | null;
    toolName?: string;
    toolState?: ToolState;
    arguments?: unknown;
    output?: string;
    artifactRef?: string | null;
    findingId?: string;
    findingState?: string;
    finding?: Record<string, any>;
    event?: AuditEvent;
}

const timeline = ref<HTMLElement | null>(null);
const autoScroll = ref(true);
const showRaw = ref(false);
const showToolOutputs = ref(false);
const newActivityCount = ref(0);
const typeFilter = ref('all');
const roleFilter = ref('all');
const rawVisibleLimit = ref(600);
let observedEventCount = props.events.length;
const types = computed(() => [
    ...new Set(props.events.map((event) => event.type)),
]);
const roles = computed(
    () =>
        [
            ...new Set(props.events.map((event) => event.role).filter(Boolean)),
        ] as string[],
);
const matchingEvents = computed(() =>
    props.events.filter(
        (event) =>
            (showToolOutputs.value || event.type !== 'tool_result') &&
            (typeFilter.value === 'all' || event.type === typeFilter.value) &&
            (roleFilter.value === 'all' || event.role === roleFilter.value),
    ),
);
const rawEvents = computed(() =>
    matchingEvents.value.slice(-rawVisibleLimit.value),
);
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
const payloadText = (value: unknown) =>
    typeof value === 'string' ? value : JSON.stringify(value ?? {}, null, 2);
const artifactUrl = (path: string) =>
    `/audits/${props.run.id}/artifacts/${path.split('/').map(encodeURIComponent).join('/')}`;

const findingState = (event: AuditEvent) => {
    const name = String(event.payload.event || event.payload.type || '');
    return name.includes('confirmed')
        ? 'confirmed'
        : name.includes('reject') ||
            name.includes('block') ||
            name.includes('closed')
          ? 'rejected'
          : name.includes('confirmation')
            ? 'confirming'
            : 'suspected';
};
const findingData = (event: AuditEvent) =>
    (event.payload.payload as Record<string, any> | undefined) || event.payload;
const liveFindings = computed(() => {
    const map = new Map<string, ActivityItem>();
    props.events
        .filter((event) => event.type === 'finding_event')
        .forEach((event) => {
            const data = findingData(event);
            const id = String(
                event.payload.lead_id ||
                    event.payload.candidate_id ||
                    data.finding_id ||
                    `event-${event.sequence}`,
            );
            const state = findingState(event);
            map.set(id, {
                id: `finding-${id}`,
                sequence: event.sequence,
                kind: 'finding',
                title: String(data.title || id),
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
                event,
                findingId: id,
                findingState: state,
                finding: {
                    ...(map.get(id)?.finding || {}),
                    ...data,
                    status: state,
                },
            });
        });
    return [...map.values()].sort((a, b) => a.sequence - b.sequence);
});

const activities = computed<ActivityItem[]>(() => {
    const output: ActivityItem[] = [];
    const tools = new Map<string, ActivityItem>();
    let lastText: ActivityItem | null = null;
    const textBlock = (event: AuditEvent, type: 'reasoning' | 'output') => {
        const content = String(event.payload.content || '');
        if (!content) return;
        if (lastText?.label === type) {
            lastText.content = `${lastText.content || ''}${content}`;
            lastText.sequence = event.sequence;
            return;
        }
        lastText = {
            id: `${type}-${event.sequence}`,
            sequence: event.sequence,
            kind: 'text',
            title: type === 'reasoning' ? 'Reasoning' : 'Model output',
            label: type,
            content,
            tone: type === 'reasoning' ? 'violet' : 'cyan',
            icon: type === 'reasoning' ? Brain : Bot,
            role: event.role,
            category: event.category,
            event,
        };
        output.push(lastText);
    };
    const milestone = (
        event: AuditEvent,
        title: string,
        label: string,
        tone: Tone,
        icon: Component,
    ) => {
        lastText = null;
        output.push({
            id: `milestone-${event.sequence}`,
            sequence: event.sequence,
            kind: 'milestone',
            title,
            label,
            tone,
            icon,
            event,
        });
    };
    props.events.forEach((event) => {
        if (event.type === 'reasoning_delta')
            return textBlock(event, 'reasoning');
        if (event.type === 'model_output_delta')
            return textBlock(event, 'output');
        lastText = null;
        if (event.type === 'tool_call') {
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
                arguments: event.payload.arguments,
            };
            tools.set(callId, item);
            output.push(item);
            return;
        }
        if (event.type === 'tool_result') {
            const item = tools.get(String(event.payload.call_id || ''));
            if (item) {
                item.toolState = 'completed';
                item.output = String(
                    event.payload.preview ||
                        event.payload.output ||
                        event.payload.content ||
                        '',
                );
                item.artifactRef = event.artifact_ref;
            } else
                output.push({
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
                    output: String(
                        event.payload.preview || event.payload.output || '',
                    ),
                    artifactRef: event.artifact_ref,
                });
            return;
        }
        if (event.type === 'finding_event') {
            const finding = liveFindings.value.find(
                (item) => item.event?.sequence === event.sequence,
            );
            if (finding) output.push(finding);
            return;
        }
        if (event.type === 'status') {
            const value = String(event.payload.status || '');
            milestone(
                event,
                value,
                'Run status',
                value === 'failed' ? 'red' : 'slate',
                value === 'completed'
                    ? CheckCircle
                    : value === 'running'
                      ? Radio
                      : XCircle,
            );
        } else if (event.type === 'error')
            milestone(event, 'Errore', eventText(event), 'red', XCircle);
        else if (event.type === 'usage')
            milestone(
                event,
                'Usage aggiornato',
                `${event.payload.total_tokens || 0} token`,
                'slate',
                Gauge,
            );
        else if (event.type === 'system_prompt')
            milestone(
                event,
                'System prompt',
                'Snapshot disponibile nella tab Prompt',
                'slate',
                Terminal,
            );
        else if (
            event.type === 'report_published' ||
            event.type === 'benchmark_published'
        )
            milestone(
                event,
                event.type === 'report_published'
                    ? 'Report pubblicato'
                    : 'Benchmark pubblicato',
                'Artefatto disponibile',
                'green',
                Flag,
            );
        else if (event.type === 'log')
            milestone(event, 'Log', eventText(event), 'slate', Terminal);
    });
    return output;
});
const latestUsage = computed(
    () =>
        [...props.events].reverse().find((event) => event.type === 'usage')
            ?.payload || null,
);
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
        activities.value.filter(
            (item) => item.kind === 'tool' && item.toolState === 'running',
        ).length,
);
const toneClasses = (tone: Tone) =>
    ({
        violet: 'border-fuchsia-500/35 bg-fuchsia-500/[0.07] text-fuchsia-100',
        cyan: 'border-cyan-500/35 bg-cyan-500/[0.07] text-cyan-50',
        amber: 'border-amber-500/40 bg-amber-500/[0.08] text-amber-50',
        green: 'border-emerald-500/40 bg-emerald-500/[0.08] text-emerald-50',
        red: 'border-red-500/45 bg-red-500/[0.08] text-red-50',
        slate: 'border-zinc-700 bg-zinc-900/70 text-zinc-200',
    })[tone];
const iconClasses = (tone: Tone) =>
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
    const el = timeline.value;
    if (!el) return;
    if (el.scrollHeight - el.scrollTop - el.clientHeight < 48) {
        autoScroll.value = true;
        newActivityCount.value = 0;
    } else autoScroll.value = false;
};
watch(
    () => props.events.length,
    () => {
        const added = Math.max(0, props.events.length - observedEventCount);
        observedEventCount = props.events.length;
        if (!added) return;
        if (autoScroll.value)
            window.requestAnimationFrame(() =>
                timeline.value?.scrollTo({
                    top: timeline.value.scrollHeight,
                    behavior: 'smooth',
                }),
            );
        else newActivityCount.value += added;
    },
);
onMounted(() =>
    window.requestAnimationFrame(() =>
        timeline.value?.scrollTo({ top: timeline.value.scrollHeight }),
    ),
);
</script>

<template>
    <section class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-xl border bg-card p-4">
                <div
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Radio class="size-4 text-emerald-500" /> Stato
                </div>
                <p class="mt-2 font-semibold capitalize">{{ status }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <div
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <ShieldQuestion class="size-4 text-amber-500" /> Suspect
                </div>
                <p class="mt-2 text-xl font-semibold">{{ suspectCount }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <div
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <ShieldCheck class="size-4 text-emerald-500" /> Confirmed
                </div>
                <p class="mt-2 text-xl font-semibold">{{ confirmedCount }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <div
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Wrench class="size-4 text-amber-500" /> Tool attivi
                </div>
                <p class="mt-2 text-xl font-semibold">{{ activeToolCount }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <div
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Gauge class="size-4 text-cyan-500" /> Token
                </div>
                <p class="mt-2 text-xl font-semibold">
                    {{ latestUsage?.total_tokens ?? '—' }}
                </p>
            </div>
        </div>
        <div
            class="grid min-h-[680px] gap-4 lg:grid-cols-[minmax(0,1fr)_300px]"
        >
            <div
                class="flex min-w-0 flex-col overflow-hidden rounded-xl border bg-zinc-950 text-zinc-100"
            >
                <div
                    class="flex flex-wrap items-center gap-2 border-b border-zinc-800 p-3"
                >
                    <Bot class="size-4 text-cyan-300" /><span
                        class="text-sm font-medium"
                        >Agent workspace</span
                    ><span
                        v-if="!terminal"
                        class="size-2 animate-pulse rounded-full bg-emerald-400"
                    /><span class="text-xs text-zinc-500"
                        >{{ activities.length }} blocchi aggregati</span
                    ><label
                        class="ml-auto flex items-center gap-2 text-xs text-zinc-400"
                        ><input v-model="showToolOutputs" type="checkbox" />
                        Output tool</label
                    ><Button
                        size="sm"
                        variant="ghost"
                        class="h-8 text-xs text-zinc-300 hover:bg-zinc-800 hover:text-white"
                        @click="showRaw = !showRaw"
                        ><Eye class="size-3.5" />
                        {{ showRaw ? 'Workspace' : 'Raw' }}</Button
                    ><select
                        v-model="typeFilter"
                        class="h-8 rounded border-zinc-700 bg-zinc-900 px-2 text-xs"
                    >
                        <option value="all">Tutti i tipi</option>
                        <option v-for="value in types" :key="value">
                            {{ value }}
                        </option></select
                    ><select
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
                    class="relative flex-1 space-y-3 overflow-y-auto p-4"
                    @scroll="onTimelineScroll"
                >
                    <button
                        v-if="!autoScroll && newActivityCount > 0"
                        class="sticky top-0 z-10 mx-auto flex items-center gap-2 rounded-full border border-cyan-400/40 bg-cyan-950 px-3 py-2 text-xs text-cyan-100 shadow-lg"
                        @click="scrollToLatest"
                    >
                        <ArrowDown class="size-3.5" />
                        {{ newActivityCount }} nuovi eventi · Vai all'ultimo
                    </button>
                    <div
                        v-if="!activities.length"
                        class="p-8 text-center text-zinc-500"
                    >
                        In attesa di eventi…
                    </div>
                    <template v-for="item in activities" :key="item.id">
                        <article
                            v-if="item.kind === 'text'"
                            class="rounded-xl border p-4"
                            :class="toneClasses(item.tone)"
                        >
                            <header
                                class="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide uppercase"
                            >
                                <span
                                    class="flex size-7 items-center justify-center rounded-full"
                                    :class="iconClasses(item.tone)"
                                    ><component
                                        :is="item.icon"
                                        class="size-4" /></span
                                >{{ item.title
                                }}<Badge
                                    variant="outline"
                                    class="border-current/30 text-[10px]"
                                    >{{ item.role || 'agent' }}</Badge
                                ><span
                                    class="ml-auto font-mono text-[10px] opacity-50"
                                    >#{{ item.sequence }}</span
                                >
                            </header>
                            <p class="text-sm leading-7 whitespace-pre-wrap">
                                {{ item.content }}
                            </p>
                        </article>
                        <details
                            v-else-if="item.kind === 'tool'"
                            class="group rounded-xl border p-3"
                            :class="toneClasses(item.tone)"
                        >
                            <summary
                                class="flex cursor-pointer list-none items-center gap-3"
                            >
                                <span
                                    class="flex size-8 items-center justify-center rounded-full"
                                    :class="iconClasses(item.tone)"
                                    ><component
                                        :is="
                                            item.toolState === 'running'
                                                ? LoaderCircle
                                                : item.toolState === 'failed'
                                                  ? XCircle
                                                  : Wrench
                                        "
                                        class="size-4"
                                        :class="
                                            item.toolState === 'running'
                                                ? 'animate-spin'
                                                : ''
                                        "
                                /></span>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="font-mono text-sm font-semibold"
                                            >{{ item.toolName }}</span
                                        ><Badge
                                            variant="outline"
                                            class="border-current/30 text-[10px]"
                                            >{{
                                                toolStateLabel(item.toolState)
                                            }}</Badge
                                        >
                                    </div>
                                    <p class="mt-1 truncate text-xs opacity-70">
                                        {{
                                            showToolOutputs && item.output
                                                ? item.output.slice(0, 180)
                                                : item.toolState === 'running'
                                                  ? 'In attesa del risultato…'
                                                  : 'Output nascosto'
                                        }}
                                    </p>
                                </div>
                                <span class="font-mono text-[10px] opacity-50"
                                    >#{{ item.sequence }}</span
                                >
                            </summary>
                            <div
                                class="mt-3 space-y-3 border-t border-current/15 pt-3 text-xs"
                            >
                                <div v-if="item.arguments !== undefined">
                                    <div
                                        class="mb-1 font-semibold tracking-wide uppercase opacity-60"
                                    >
                                        Arguments
                                    </div>
                                    <pre
                                        class="max-h-64 overflow-auto rounded-lg bg-black/25 p-3 whitespace-pre-wrap"
                                        >{{ payloadText(item.arguments) }}</pre>
                                </div>
                                <div v-if="showToolOutputs && item.output">
                                    <div
                                        class="mb-1 font-semibold tracking-wide uppercase opacity-60"
                                    >
                                        Output
                                    </div>
                                    <pre
                                        class="max-h-96 overflow-auto rounded-lg bg-black/25 p-3 whitespace-pre-wrap"
                                        >{{ item.output }}</pre>
                                </div>
                                <a
                                    v-if="item.artifactRef"
                                    :href="artifactUrl(item.artifactRef)"
                                    target="_blank"
                                    class="text-cyan-300 hover:text-cyan-100"
                                    >Apri payload completo →</a
                                >
                            </div>
                        </details>
                        <article
                            v-else-if="item.kind === 'finding'"
                            class="rounded-xl border p-4 shadow-lg"
                            :class="toneClasses(item.tone)"
                        >
                            <div class="flex items-start gap-3">
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-full"
                                    :class="iconClasses(item.tone)"
                                    ><component :is="item.icon" class="size-5"
                                /></span>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <Badge
                                            variant="outline"
                                            class="border-current/40 text-[10px]"
                                            >{{ item.label }}</Badge
                                        ><Badge
                                            v-if="item.finding?.severity"
                                            variant="outline"
                                            class="border-current/30 text-[10px]"
                                            >{{ item.finding.severity }}</Badge
                                        >
                                    </div>
                                    <h3 class="mt-2 text-base font-semibold">
                                        {{ item.title }}
                                    </h3>
                                    <p class="mt-1 text-xs opacity-75">
                                        {{ item.findingId }} ·
                                        {{
                                            item.category || 'security finding'
                                        }}
                                    </p>
                                    <p
                                        v-if="
                                            item.finding?.description ||
                                            item.finding?.hypothesis
                                        "
                                        class="mt-3 text-sm leading-6 opacity-90"
                                    >
                                        {{
                                            item.finding.description ||
                                            item.finding.hypothesis
                                        }}
                                    </p>
                                    <div
                                        v-if="item.finding?.exploit?.path"
                                        class="mt-3 rounded-lg bg-black/20 p-2 font-mono text-xs"
                                    >
                                        {{ item.finding.exploit.path }}
                                    </div>
                                </div>
                            </div>
                        </article>
                        <article
                            v-else
                            class="flex items-center gap-3 rounded-lg border px-3 py-2 text-xs"
                            :class="toneClasses(item.tone)"
                        >
                            <span
                                class="flex size-7 items-center justify-center rounded-full"
                                :class="iconClasses(item.tone)"
                                ><component :is="item.icon" class="size-4"
                            /></span>
                            <div class="min-w-0 flex-1">
                                <span class="font-semibold">{{
                                    item.title
                                }}</span
                                ><span class="ml-2 opacity-70">{{
                                    item.label
                                }}</span>
                            </div>
                            <span class="font-mono text-[10px] opacity-50"
                                >#{{ item.sequence }}</span
                            >
                        </article>
                    </template>
                </div>
                <div
                    class="flex items-center justify-between border-t border-zinc-800 p-3 text-xs text-zinc-400"
                >
                    <label class="flex items-center gap-2"
                        ><input v-model="autoScroll" type="checkbox" /> Segui
                        automaticamente</label
                    ><span
                        v-if="!autoScroll"
                        class="inline-flex items-center gap-1 text-amber-300"
                        ><Pause class="size-3" /> Follow in pausa</span
                    >
                </div>
            </div>
            <aside class="space-y-4">
                <div class="rounded-xl border bg-card p-4">
                    <h3 class="flex items-center gap-2 font-medium">
                        <ShieldAlert class="size-4 text-red-500" /> Findings
                        live
                    </h3>
                    <div v-if="liveFindings.length" class="mt-3 space-y-2">
                        <div
                            v-for="finding in liveFindings"
                            :key="finding.id"
                            class="rounded-lg border-l-2 p-3 text-xs"
                            :class="
                                finding.findingState === 'confirmed'
                                    ? 'border-emerald-500 bg-emerald-500/5'
                                    : 'border-amber-500 bg-amber-500/5'
                            "
                        >
                            <div class="flex items-center gap-2">
                                <component
                                    :is="finding.icon"
                                    class="size-4"
                                /><span class="font-semibold">{{
                                    finding.title
                                }}</span>
                            </div>
                            <p class="mt-1 text-muted-foreground">
                                {{ finding.label }}
                            </p>
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-muted-foreground">
                        Nessun finding emerso.
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <h3 class="flex items-center gap-2 font-medium">
                        <Gauge class="size-4" /> Ultimo consumo
                    </h3>
                    <pre
                        class="mt-3 overflow-auto text-xs whitespace-pre-wrap text-muted-foreground"
                        >{{
                            JSON.stringify(
                                latestUsage || { status: 'non disponibile' },
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
                    <ShieldAlert class="mb-2 size-4" />{{ run.error }}
                </div>
            </aside>
        </div>
        <details
            v-if="showRaw"
            class="overflow-hidden rounded-xl border bg-zinc-950 text-zinc-100"
        >
            <summary
                class="cursor-pointer border-b border-zinc-800 p-3 text-sm font-medium"
            >
                <Terminal class="mr-2 inline size-4 text-zinc-400" /> Raw events
                · {{ rawEvents.length }} visualizzati
            </summary>
            <div class="max-h-[650px] overflow-auto p-3 font-mono text-xs">
                <details
                    v-for="event in rawEvents"
                    :key="event.sequence"
                    class="border-b border-zinc-800/70 py-2 last:border-0"
                >
                    <summary
                        class="flex cursor-pointer list-none items-center gap-2"
                    >
                        <span class="text-zinc-600">#{{ event.sequence }}</span
                        ><Badge
                            variant="outline"
                            class="border-zinc-700 text-zinc-300"
                            >{{
                                event.role || event.category || 'system'
                            }}</Badge
                        ><span class="text-emerald-300">{{
                            eventTitle(event)
                        }}</span
                        ><span class="ml-auto text-zinc-600">{{
                            event.occurred_at?.slice(11, 19)
                        }}</span>
                    </summary>
                    <pre
                        class="mt-2 max-h-96 overflow-auto rounded bg-zinc-900 p-3 whitespace-pre-wrap text-zinc-300"
                        >{{ eventText(event) }}</pre>
                </details>
                <button
                    v-if="matchingEvents.length > rawEvents.length"
                    class="mt-3 w-full rounded-lg border border-zinc-800 p-2 text-zinc-400 hover:text-zinc-100"
                    @click="rawVisibleLimit += 600"
                >
                    Carica eventi precedenti ({{
                        matchingEvents.length - rawEvents.length
                    }})
                </button>
            </div>
        </details>
    </section>
</template>
