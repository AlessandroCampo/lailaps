<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useVirtualList } from '@vueuse/core';
import {
    Activity,
    ArrowDownToLine,
    ArrowLeft,
    Bot,
    Brain,
    CircleStop,
    Clipboard,
    Download,
    Gauge,
    LoaderCircle,
    Play,
    RotateCcw,
    ShieldAlert,
    ShieldCheck,
    Terminal,
    Wrench,
    XCircle,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AuditRunSummary, AuditStatus } from '@/types';

interface Preset {
    id: string;
    category: string;
    benchmark?: boolean;
}

interface CompactFinding {
    id: string;
    title: string;
    status: string;
    severity?: string | null;
    category?: string | null;
}

interface RunSnapshot {
    status: AuditStatus;
    error: string | null;
    exitCode: number | null;
    startedAt: string | null;
    finishedAt: string | null;
    logBytes: number;
    confirmed: number;
    suspected: number;
    findings: CompactFinding[];
    usage: Record<string, unknown>;
}

type LogKind =
    | 'reasoning'
    | 'output'
    | 'tool'
    | 'tool_result'
    | 'usage'
    | 'error'
    | 'system'
    | 'plain';

interface LogRow {
    id: number;
    text: string;
    kind: LogKind;
    boundary: boolean;
}

const props = withDefaults(
    defineProps<{
        run: AuditRunSummary | null;
        initialSnapshot?: RunSnapshot | null;
        presets?: Preset[];
    }>(),
    { initialSnapshot: null, presets: () => [] },
);

const form = useForm({
    type: 'audit',
    preset: props.presets[0]?.id || 'dvwa',
    path: '',
    target_mode: 'sandbox',
    url: '',
    db: '',
    health_path: '',
    skip_health: false,
    authorized: false,
    categories: [
        props.presets[0]?.category || 'A01:2025 Broken Access Control',
    ],
    reader_model: '',
    reviewer_model: '',
    confirmer_model: '',
    worker_model: '',
    judge_model: '',
    image: '',
    dockerfile: '',
    compose: '',
    mount: '',
    port: '',
    service: '',
    ttl: 9000,
    test: true,
    keep: false,
    benchmark_id: '',
});

const snapshot = ref<RunSnapshot | null>(props.initialSnapshot);
const rows = ref<LogRow[]>([]);
const partialLine = ref('');
const loadedFrom = ref(0);
const receivedOffset = ref(0);
const streamConnected = ref(false);
const streamError = ref(false);
const autoFollow = ref(true);
const showToolOutput = ref(false);
const copying = ref(false);
const copyMessage = ref('');
let decoder = new TextDecoder();
const TAIL_BYTES = 1024 * 1024;
let rowId = 0;
let activeLogKind: LogKind = 'plain';
let logTimer: number | null = null;
let logGeneration = 0;
let polling: number | null = null;

const status = computed<AuditStatus>(
    () => snapshot.value?.status || props.run?.status || 'queued',
);
const terminal = computed(() =>
    ['completed', 'failed', 'cancelled'].includes(status.value),
);
const visibleRows = computed<LogRow[]>(() =>
    showToolOutput.value
        ? rows.value
        : rows.value.filter((row) => row.kind !== 'tool_result'),
);
const hiddenToolRows = computed(
    () => rows.value.length - visibleRows.value.length,
);
const usageRows = computed(() =>
    Object.entries(snapshot.value?.usage || {}).filter(
        ([key, value]) => key !== 'role_usage' && typeof value !== 'object',
    ),
);
const logDownloadUrl = computed(() =>
    props.run
        ? `/audits/${props.run.id}/artifacts/${props.run.auditId}-logs.php`
        : '#',
);
const waitingLabel = computed(() => {
    if (status.value === 'queued')
        return 'Run accodata: in attesa del queue worker…';
    if (status.value === 'preparing') return 'Preparazione del target…';
    if (status.value === 'finalizing')
        return 'Finalizzazione di report e metriche…';
    return 'In attesa del primo output…';
});

const {
    list: virtualRows,
    containerProps,
    wrapperProps,
    scrollTo,
} = useVirtualList(visibleRows, { itemHeight: 20, overscan: 40 });

function submit() {
    form.transform((data) => ({
        ...data,
        categories: data.categories.filter(Boolean),
    })).post('/audit-console/runs');
}

function cancel() {
    if (props.run) router.post(`/audits/${props.run.id}/cancel`);
}

function retry() {
    if (props.run) router.post(`/audit-console/runs/${props.run.id}/retry`);
}

function decodeBase64(value: string): Uint8Array {
    const binary = window.atob(value);
    const bytes = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }
    return bytes;
}

function classifyLine(line: string): Pick<LogRow, 'kind' | 'boundary'> {
    const marker = line.match(/^\s*\[([^\]]+)]/);
    if (marker) {
        const tag = marker[1].toLowerCase();
        if (tag.includes(':thinking') || tag.includes(':reasoning')) {
            activeLogKind = 'reasoning';
        } else if (tag.includes(':output') || tag.startsWith('handoff:')) {
            activeLogKind = 'output';
        } else if (tag.includes(':result')) {
            activeLogKind = 'tool_result';
        } else if (tag.includes(':tool')) {
            activeLogKind = 'tool';
        } else if (tag.includes(':usage') || tag.startsWith('budget:')) {
            activeLogKind = 'usage';
        } else if (/error|fatal|failed|warning|exhausted|retry/.test(tag)) {
            activeLogKind = 'error';
        } else {
            activeLogKind = 'system';
        }

        return { kind: activeLogKind, boundary: true };
    }

    if (
        /^(?:===|Avvio agente|target ->|source ->|source_root ->|triple-agent ->|command tools ->|Artefatti e metriche)/i.test(
            line,
        )
    ) {
        activeLogKind = 'system';
        return { kind: activeLogKind, boundary: true };
    }

    return { kind: activeLogKind, boundary: false };
}

function pushLogRow(text: string) {
    // Machine-readable events share the canonical log; the operator view hides them.
    if (text.includes('LAILAPS_EVENT ')) {
        return;
    }

    rows.value.push({ id: ++rowId, text, ...classifyLine(text) });
}

function rowTone(kind: LogKind): string {
    return {
        reasoning: 'bg-violet-400/[.06] text-violet-100',
        output: 'bg-cyan-400/[.05] text-cyan-50',
        tool: 'bg-amber-400/[.06] text-amber-100',
        tool_result: 'bg-emerald-400/[.05] text-emerald-100',
        usage: 'bg-sky-400/[.05] text-sky-200',
        error: 'bg-red-400/[.08] text-red-200',
        system: 'text-zinc-400',
        plain: 'text-zinc-300',
    }[kind];
}

function gutterTone(kind: LogKind): string {
    return {
        reasoning: 'text-violet-400',
        output: 'text-cyan-400',
        tool: 'text-amber-400',
        tool_result: 'text-emerald-400',
        usage: 'text-sky-400',
        error: 'text-red-400',
        system: 'text-zinc-500',
        plain: 'text-zinc-700',
    }[kind];
}

function rowIcon(kind: LogKind) {
    return {
        reasoning: Brain,
        output: Bot,
        tool: Wrench,
        tool_result: Activity,
        usage: Gauge,
        error: XCircle,
        system: Terminal,
        plain: Terminal,
    }[kind];
}

function appendText(text: string) {
    if (partialLine.value !== '') rows.value.pop();
    const parts = `${partialLine.value}${text}`.split(/\r?\n/);
    partialLine.value = parts.pop() || '';
    for (const line of parts) {
        pushLogRow(line);
    }
    if (partialLine.value !== '') {
        pushLogRow(partialLine.value);
    }
    if (autoFollow.value) {
        nextTick(() => scrollTo(Math.max(0, visibleRows.value.length - 1)));
    }
}

function connectLog(after: number, align = after > 0) {
    if (!props.run) return;
    const generation = ++logGeneration;
    if (logTimer !== null) window.clearTimeout(logTimer);
    streamConnected.value = false;
    streamError.value = false;
    receivedOffset.value = after;

    const poll = async (cursor: number, shouldAlign: boolean) => {
        if (!props.run || generation !== logGeneration) return;
        try {
            const response = await fetch(
                `/audits/${props.run.id}/log-chunk?after=${Math.max(0, cursor)}${shouldAlign ? '&align=1' : ''}`,
                { headers: { Accept: 'application/json' }, cache: 'no-store' },
            );
            if (!response.ok) throw new Error('Transcript non disponibile');
            const payload = (await response.json()) as {
                start: number;
                offset: number;
                base64: string;
                eof: boolean;
                status: AuditStatus;
            };
            if (generation !== logGeneration) return;
            if (shouldAlign) loadedFrom.value = payload.start;
            receivedOffset.value = payload.offset;
            streamConnected.value = true;
            streamError.value = false;
            if (snapshot.value) snapshot.value.status = payload.status;
            if (payload.base64) {
                appendText(
                    decoder.decode(decodeBase64(payload.base64), {
                        stream: true,
                    }),
                );
            }
            if (!(
                payload.eof &&
                ['completed', 'failed', 'cancelled'].includes(payload.status)
            )) {
                logTimer = window.setTimeout(
                    () => void poll(payload.offset, false),
                    payload.eof ? 250 : 0,
                );
            } else {
                streamConnected.value = false;
            }
        } catch {
            if (generation !== logGeneration) return;
            streamConnected.value = false;
            streamError.value = true;
            logTimer = window.setTimeout(
                () => void poll(cursor, shouldAlign),
                1000,
            );
        }
    };

    void poll(after, align);
}

async function refreshSnapshot() {
    if (!props.run) return;
    try {
        const response = await fetch(`/audits/${props.run.id}/snapshot`, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) return;
        snapshot.value = (await response.json()) as RunSnapshot;
        if (
            terminal.value &&
            receivedOffset.value >= (snapshot.value?.logBytes || 0)
        ) {
            streamConnected.value = false;
        }
    } catch {
        // The transcript remains usable while a short snapshot poll fails.
    }
}

function startPolling() {
    if (polling !== null) window.clearInterval(polling);
    polling = window.setInterval(
        refreshSnapshot,
        terminal.value ? 10_000 : 2_000,
    );
}

function resetTranscript() {
    logGeneration += 1;
    if (logTimer !== null) window.clearTimeout(logTimer);
    rows.value = [];
    partialLine.value = '';
    decoder = new TextDecoder();
    rowId = 0;
    activeLogKind = 'plain';
}

function loadCompleteLog() {
    resetTranscript();
    loadedFrom.value = 0;
    connectLog(0);
}

async function copyCompleteLog() {
    if (!props.run) return;
    copying.value = true;
    copyMessage.value = '';
    try {
        const response = await fetch(logDownloadUrl.value, {
            cache: 'no-store',
        });
        if (!response.ok) throw new Error('Log non disponibile');
        const transcript = await response.text();
        await navigator.clipboard.writeText(
            humanTranscript(transcript, showToolOutput.value),
        );
        copyMessage.value = showToolOutput.value
            ? 'Transcript copiato con gli output dei tool'
            : 'Transcript copiato senza gli output dei tool';
    } catch {
        copyMessage.value = 'Copia non riuscita: usa Scarica log';
    } finally {
        copying.value = false;
        window.setTimeout(() => (copyMessage.value = ''), 3000);
    }
}

function humanTranscript(
    transcript: string,
    includeToolResults: boolean,
): string {
    let inToolResult = false;
    return transcript
        .split('\n')
        .filter((line) => {
            if (line.includes('LAILAPS_EVENT ')) {
                return false;
            }

            const marker = line.match(/^\s*\[([^\]]+)]/);
            if (marker) {
                const tag = marker[1].toLowerCase();
                inToolResult = tag.includes(':result');
            } else if (
                /^(?:===|Avvio agente|target ->|source ->|source_root ->|triple-agent ->|command tools ->|Artefatti e metriche)/i.test(
                    line,
                )
            ) {
                inToolResult = false;
            }
            return includeToolResults || !inToolResult;
        })
        .join('\n');
}

function initializeRun() {
    if (!props.run) return;
    snapshot.value = props.initialSnapshot;
    resetTranscript();
    const bytes = snapshot.value?.logBytes || 0;
    const start = Math.max(0, bytes - TAIL_BYTES);
    loadedFrom.value = start;
    connectLog(start);
    void refreshSnapshot();
    startPolling();
}

watch(
    () => form.preset,
    (id) => {
        const preset = props.presets.find((item) => item.id === id);
        if (preset) form.categories = preset.category ? [preset.category] : [];
    },
);
watch(() => props.run?.id, initializeRun);
onMounted(initializeRun);
onBeforeUnmount(() => {
    logGeneration += 1;
    if (logTimer !== null) window.clearTimeout(logTimer);
    if (polling !== null) window.clearInterval(polling);
});
</script>

<template>
    <Head :title="run ? `${run.target} · Audit Console` : 'Nuova audit run'" />

    <main class="min-h-screen bg-[#090b10] text-zinc-100">
        <header class="border-b border-white/10 px-4 py-3 md:px-7">
            <div class="mx-auto flex max-w-[1800px] items-center gap-3">
                <Link
                    href="/audit-console"
                    class="inline-flex items-center gap-2 text-sm text-zinc-400 hover:text-white"
                >
                    <ArrowLeft class="size-4" /> Audit Console
                </Link>
                <template v-if="run">
                    <span class="ml-auto font-mono text-xs text-zinc-500">{{
                        run.auditId
                    }}</span>
                    <span
                        class="rounded-full px-2.5 py-1 text-xs font-semibold"
                        :class="
                            terminal
                                ? 'bg-zinc-800'
                                : 'bg-cyan-400/10 text-cyan-300'
                        "
                        >{{ status }}</span
                    >
                    <Button
                        v-if="!terminal"
                        size="sm"
                        variant="destructive"
                        @click="cancel"
                        ><CircleStop /> Ferma</Button
                    >
                    <Button
                        v-if="status === 'failed'"
                        size="sm"
                        variant="outline"
                        @click="retry"
                        ><RotateCcw /> Riprova</Button
                    >
                </template>
            </div>
        </header>

        <section v-if="!run" class="mx-auto max-w-4xl px-4 py-8 md:px-7">
            <p class="font-mono text-xs tracking-[0.2em] text-cyan-400">
                NUOVA RUN
            </p>
            <h1 class="mt-2 text-3xl font-semibold">Avvia un audit</h1>
            <p class="mt-2 text-zinc-400">
                La pagina si collega al transcript persistente subito dopo
                l’accodamento. Per benchmark e configurazioni avanzate usa la
                <Link
                    href="/audits/create"
                    class="text-cyan-300 hover:underline"
                    >configurazione completa</Link
                >.
            </p>

            <form
                class="mt-7 grid gap-5 rounded-2xl border border-white/10 bg-white/[.03] p-6 md:grid-cols-2"
                @submit.prevent="submit"
            >
                <div>
                    <Label>Preset</Label>
                    <select
                        v-model="form.preset"
                        class="mt-2 h-10 w-full rounded-md border border-white/10 bg-black/30 px-3 text-sm"
                    >
                        <option value="">Path personalizzato</option>
                        <option
                            v-for="preset in presets"
                            :key="preset.id"
                            :value="preset.id"
                        >
                            {{ preset.id }}
                        </option>
                    </select>
                </div>
                <div>
                    <Label>Path sorgente</Label>
                    <Input
                        v-model="form.path"
                        class="mt-2 bg-black/20"
                        :disabled="!!form.preset"
                        placeholder="C:/…/progetto"
                    />
                    <p
                        v-if="form.errors.path"
                        class="mt-1 text-xs text-red-400"
                    >
                        {{ form.errors.path }}
                    </p>
                </div>
                <div class="md:col-span-2">
                    <Label>Categoria OWASP</Label>
                    <Input
                        v-model="form.categories[0]"
                        class="mt-2 bg-black/20"
                    />
                </div>
                <div>
                    <Label>Target</Label>
                    <select
                        v-model="form.target_mode"
                        class="mt-2 h-10 w-full rounded-md border border-white/10 bg-black/30 px-3 text-sm"
                    >
                        <option value="sandbox">Sandbox Docker</option>
                        <option value="remote">URL già attivo</option>
                    </select>
                </div>
                <div v-if="form.target_mode === 'remote'">
                    <Label>URL</Label>
                    <Input
                        v-model="form.url"
                        class="mt-2 bg-black/20"
                        placeholder="https://staging.example.test"
                    />
                </div>
                <label
                    v-if="form.target_mode === 'remote'"
                    class="flex gap-2 text-sm text-amber-200 md:col-span-2"
                >
                    <input v-model="form.authorized" type="checkbox" /> Confermo
                    di essere autorizzato a testare questo host.
                </label>
                <details class="md:col-span-2">
                    <summary class="cursor-pointer text-sm text-zinc-400">
                        Opzioni agente
                    </summary>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <Input
                            v-model="form.reader_model"
                            placeholder="Reader model (default)"
                        />
                        <Input
                            v-model="form.reviewer_model"
                            placeholder="Reviewer model (default)"
                        />
                        <Input
                            v-model="form.confirmer_model"
                            placeholder="Confirmer model (default)"
                        />
                        <Input
                            v-model="form.worker_model"
                            placeholder="Worker model (default)"
                        />
                        <Input
                            v-model="form.judge_model"
                            placeholder="Dynamic Judge model (default)"
                        />
                        <label
                            class="flex items-center gap-2 text-sm text-zinc-300"
                        >
                            TTL
                            <Input
                                v-model.number="form.ttl"
                                type="number"
                                class="w-32"
                            />
                        </label>
                    </div>
                </details>
                <div class="flex justify-end md:col-span-2">
                    <Button type="submit" :disabled="form.processing">
                        <LoaderCircle
                            v-if="form.processing"
                            class="animate-spin"
                        />
                        <Play v-else />
                        {{ form.processing ? 'Accodamento…' : 'Avvia audit' }}
                    </Button>
                </div>
            </form>
        </section>

        <section
            v-else
            class="mx-auto grid max-w-[1800px] gap-5 px-4 py-5 md:px-7 xl:grid-cols-[minmax(0,1fr)_340px]"
        >
            <div class="min-w-0 space-y-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <p
                            class="font-mono text-xs tracking-[0.2em] text-cyan-400"
                        >
                            TRANSCRIPT PERSISTENTE
                        </p>
                        <h1 class="mt-1 text-2xl font-semibold">
                            {{ run.target }}
                        </h1>
                        <p class="mt-1 text-sm text-zinc-400">
                            {{ run.categories.join(' · ') || run.type }} ·
                            {{
                                new Date(run.createdAt).toLocaleString('it-IT')
                            }}
                        </p>
                    </div>
                    <div class="ml-auto flex flex-wrap gap-2">
                        <Button
                            v-if="loadedFrom > 0"
                            size="sm"
                            variant="outline"
                            @click="loadCompleteLog"
                            ><RotateCcw /> Carica dall’inizio</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="copying || !snapshot?.logBytes"
                            @click="copyCompleteLog"
                            ><Clipboard />
                            {{
                                copying ? 'Copia…' : 'Copia transcript'
                            }}</Button
                        >
                        <Button size="sm" variant="outline" as-child
                            ><a :href="logDownloadUrl" download
                                ><Download /> Scarica log</a
                            ></Button
                        >
                    </div>
                </div>

                <p v-if="copyMessage" class="text-sm text-cyan-300">
                    {{ copyMessage }}
                </p>
                <div
                    v-if="loadedFrom > 0"
                    class="rounded-lg border border-amber-400/20 bg-amber-400/5 px-3 py-2 text-xs text-amber-200"
                >
                    Per apertura rapida sono mostrati gli ultimi
                    {{ (snapshot?.logBytes || 0) - loadedFrom }} byte. Il log
                    completo resta copiabile/scaricabile.
                </div>
                <div
                    v-if="streamError && !terminal"
                    class="rounded-lg border border-amber-400/20 bg-amber-400/5 px-3 py-2 text-sm text-amber-200"
                >
                    Stream temporaneamente disconnesso; il browser sta
                    riconnettendo senza perdere il cursore.
                </div>

                <div
                    class="overflow-hidden rounded-xl border border-white/10 bg-[#0d1119]"
                >
                    <div
                        class="flex items-center gap-2 border-b border-white/10 px-3 py-2 text-xs text-zinc-400"
                    >
                        <Terminal class="size-4 text-cyan-300" /> logs.php
                        <span
                            class="size-2 rounded-full"
                            :class="
                                streamConnected
                                    ? 'bg-emerald-400'
                                    : 'bg-zinc-600'
                            "
                        />
                        <span
                            >{{
                                (
                                    snapshot?.logBytes || receivedOffset
                                ).toLocaleString('it-IT')
                            }}
                            byte</span
                        >
                        <label class="ml-auto flex items-center gap-2"
                            ><input v-model="showToolOutput" type="checkbox" />
                            Output tool
                            <span v-if="hiddenToolRows" class="text-zinc-600"
                                >({{ hiddenToolRows }})</span
                            ></label
                        >
                        <label class="flex items-center gap-2"
                            ><input v-model="autoFollow" type="checkbox" />
                            Segui</label
                        >
                        <button
                            class="inline-flex items-center gap-1 text-cyan-300"
                            @click="
                                scrollTo(Math.max(0, visibleRows.length - 1))
                            "
                        >
                            <ArrowDownToLine class="size-3.5" /> Fine
                        </button>
                    </div>
                    <div
                        v-bind="containerProps"
                        class="h-[calc(100vh-245px)] min-h-[520px] overflow-auto font-mono text-[12px] leading-5"
                    >
                        <div
                            v-if="visibleRows.length === 0"
                            class="grid h-full place-items-center text-zinc-500"
                        >
                            <div class="text-center">
                                <LoaderCircle
                                    v-if="!terminal"
                                    class="mx-auto mb-3 size-6 animate-spin text-cyan-400"
                                />
                                <Terminal v-else class="mx-auto mb-3 size-6" />
                                {{
                                    terminal
                                        ? 'Il transcript è vuoto.'
                                        : waitingLabel
                                }}
                            </div>
                        </div>
                        <div v-else v-bind="wrapperProps">
                            <div
                                v-for="item in virtualRows"
                                :key="item.data.id"
                                class="flex h-5 min-w-max items-center whitespace-pre hover:brightness-125"
                                :class="rowTone(item.data.kind)"
                            >
                                <span
                                    class="sticky left-0 flex h-5 w-7 shrink-0 items-center justify-center bg-[#0d1119]"
                                    :class="gutterTone(item.data.kind)"
                                >
                                    <component
                                        :is="rowIcon(item.data.kind)"
                                        v-if="item.data.boundary"
                                        class="size-3.5"
                                    />
                                </span>
                                <span
                                    class="pr-3"
                                    :class="
                                        item.data.boundary && 'font-semibold'
                                    "
                                    >{{ item.data.text || ' ' }}</span
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div
                        class="rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-4"
                    >
                        <ShieldCheck class="size-4 text-emerald-400" />
                        <p class="mt-2 text-2xl font-semibold">
                            {{ snapshot?.confirmed || 0 }}
                        </p>
                        <p class="text-xs text-zinc-400">Confermati</p>
                    </div>
                    <div
                        class="rounded-xl border border-amber-400/20 bg-amber-400/5 p-4"
                    >
                        <ShieldAlert class="size-4 text-amber-400" />
                        <p class="mt-2 text-2xl font-semibold">
                            {{ snapshot?.suspected || 0 }}
                        </p>
                        <p class="text-xs text-zinc-400">Suspect</p>
                    </div>
                </div>

                <div
                    class="rounded-xl border border-white/10 bg-white/[.03] p-4"
                >
                    <h2 class="flex items-center gap-2 font-medium">
                        <Gauge class="size-4 text-cyan-300" /> Usage live
                    </h2>
                    <dl v-if="usageRows.length" class="mt-3 space-y-2 text-xs">
                        <div
                            v-for="[key, value] in usageRows"
                            :key="key"
                            class="flex justify-between gap-3"
                        >
                            <dt class="text-zinc-500">
                                {{ key.replaceAll('_', ' ') }}
                            </dt>
                            <dd class="font-mono text-zinc-200">
                                {{ Number(value).toLocaleString('it-IT') }}
                            </dd>
                        </div>
                    </dl>
                    <p v-else class="mt-3 text-sm text-zinc-500">
                        Metriche non ancora disponibili.
                    </p>
                </div>

                <div
                    class="rounded-xl border border-white/10 bg-white/[.03] p-4"
                >
                    <h2 class="font-medium">Findings live</h2>
                    <div
                        v-if="snapshot?.findings.length"
                        class="mt-3 max-h-[420px] space-y-2 overflow-auto"
                    >
                        <div
                            v-for="finding in snapshot.findings"
                            :key="finding.id"
                            class="rounded-lg border border-white/10 p-3 text-xs"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-2 rounded-full"
                                    :class="
                                        finding.status === 'confirmed'
                                            ? 'bg-emerald-400'
                                            : 'bg-amber-400'
                                    "
                                />
                                <strong>{{ finding.title }}</strong>
                            </div>
                            <p class="mt-1 text-zinc-500">
                                {{ finding.id }} ·
                                {{ finding.severity || finding.status }}
                            </p>
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-zinc-500">
                        Nessun finding persistito.
                    </p>
                </div>

                <div
                    v-if="snapshot?.error"
                    class="rounded-xl border border-red-400/25 bg-red-400/5 p-4 text-sm text-red-200"
                >
                    <ShieldAlert class="mb-2 size-4" />{{ snapshot.error }}
                </div>
            </aside>
        </section>
    </main>
</template>
