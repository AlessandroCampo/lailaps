<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Beaker, Play, Shield } from '@lucide/vue';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Preset {
    id: string;
    category: string;
    benchmark?: boolean;
}
interface Benchmark {
    id: string;
    name: string;
    category?: string;
    categories: string[];
}
const props = defineProps<{ presets: Preset[]; benchmarks: Benchmark[] }>();
const form = useForm({
    type: 'audit',
    preset: '',
    path: '',
    target_mode: 'sandbox',
    url: '',
    db: '',
    health_path: '',
    skip_health: false,
    authorized: false,
    global: false,
    reader_concurrency: 4,
    confirmer_concurrency: 4,
    worker_concurrency: 2,
    reader_points: '' as string | number,
    worker_points: '' as string | number,
    depth: 1,
    categories: [''] as string[],
    reader_model: '',
    reviewer_model: '',
    confirmer_model: '',
    worker_model: '',
    image: '',
    dockerfile: '',
    compose: '',
    mount: '',
    port: '' as string | number,
    service: '',
    ttl: 9000,
    test: false,
    keep: false,
    benchmark_id: '',
});
watch(
    () => form.preset,
    (id) => {
        const preset = props.presets.find((item) => item.id === id);
        if (preset) {
            form.categories = preset.benchmark ? [''] : [preset.category];
        }
    },
);
watch(
    () => form.benchmark_id,
    () => {
        form.categories = [''];
    },
);
const submit = () =>
    form
        .transform((data) => ({
            ...data,
            reviewer_model: data.global ? '' : data.reviewer_model,
            categories: data.global ? [] : data.categories.filter(Boolean),
        }))
        .post('/audits');
</script>

<template>
    <Head title="Nuova run" />
    <div class="mx-auto w-full max-w-4xl p-4 md:p-8">
        <Link
            href="/audits"
            class="mb-5 inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
            ><ArrowLeft class="size-4" /> Torna agli audit</Link
        >
        <div class="mb-7">
            <h1 class="text-3xl font-semibold tracking-tight">Nuova run</h1>
            <p class="mt-1 text-muted-foreground">
                Configura lo stesso flusso disponibile da shell.
            </p>
        </div>
        <form class="space-y-6" @submit.prevent="submit">
            <section class="rounded-xl border bg-card p-5">
                <h2 class="flex items-center gap-2 font-semibold">
                    <Beaker class="size-4" /> Tipo di esecuzione
                </h2>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <button
                        type="button"
                        class="rounded-lg border p-4 text-left"
                        :class="
                            form.type === 'audit' &&
                            'border-primary bg-primary/5'
                        "
                        @click="form.type = 'audit'"
                    >
                        <Shield class="mb-2 size-5" /><strong>Audit</strong>
                        <p class="text-xs text-muted-foreground">
                            Preset, sandbox o target remoto
                        </p>
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border p-4 text-left"
                        :class="
                            form.type === 'benchmark' &&
                            'border-primary bg-primary/5'
                        "
                        @click="form.type = 'benchmark'"
                    >
                        <Beaker class="mb-2 size-5" /><strong>Benchmark</strong>
                        <p class="text-xs text-muted-foreground">
                            Catalogo real-world autorizzato
                        </p>
                    </button>
                </div>
            </section>

            <section
                v-if="form.type === 'benchmark'"
                class="rounded-xl border bg-card p-5"
            >
                <Label for="benchmark">Benchmark</Label>
                <select
                    id="benchmark"
                    v-model="form.benchmark_id"
                    class="mt-2 h-10 w-full rounded-md border bg-background px-3 text-sm"
                >
                    <option value="">Seleziona…</option>
                    <option
                        v-for="item in benchmarks"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name
                        }}{{ item.category ? ` — ${item.category}` : '' }}
                    </option>
                </select>
                <p
                    v-if="benchmarks.length === 0"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    Il catalogo real-world è vuoto.
                </p>
                <InputError :message="form.errors.benchmark_id" />
                <div v-if="form.benchmark_id && !form.global" class="mt-4">
                    <Label for="benchmark-category">Sotto-categoria</Label>
                    <select
                        id="benchmark-category"
                        v-model="form.categories[0]"
                        class="mt-2 h-10 w-full rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">Tutte le sotto-categorie</option>
                        <option
                            v-for="category in benchmarks.find(
                                (item) => item.id === form.benchmark_id,
                            )?.categories || []"
                            :key="category"
                            :value="category"
                        >
                            {{ category }}
                        </option>
                    </select>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Facoltativa: se non selezionata, il benchmark esegue
                        tutte le sotto-categorie.
                    </p>
                    <InputError :message="form.errors.categories" />
                </div>
            </section>

            <section class="rounded-xl border bg-card p-5">
                <h2 class="font-semibold">Copertura e profondita'</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <label
                        class="flex items-start gap-3 rounded-lg border p-4 text-sm"
                    >
                        <input
                            v-model="form.global"
                            type="checkbox"
                            class="mt-0.5"
                        />
                        <span
                            ><strong>Scansione globale per superfici</strong
                            ><br />
                            <span class="text-muted-foreground"
                                >Una sola Recon e una sola pipeline
                                investigativa, senza sweep per categoria.</span
                            >
                        </span>
                    </label>
                    <div>
                        <Label for="depth">Pass sequenziali</Label>
                        <Input
                            id="depth"
                            v-model.number="form.depth"
                            type="number"
                            min="1"
                            class="mt-2"
                        />
                        <InputError :message="form.errors.depth" />
                    </div>
                </div>
                <p
                    v-if="form.depth > 1"
                    class="mt-3 text-sm text-amber-700 dark:text-amber-300"
                >
                    Ogni pass riparte con contesto, cookie e budget nuovi; i
                    dati gia' creati sul target non vengono ripristinati.
                </p>
            </section>

            <template v-if="form.type !== 'benchmark'">
                <section class="rounded-xl border bg-card p-5">
                    <h2 class="font-semibold">Sorgente e target</h2>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <Label for="preset">Preset</Label
                            ><select
                                id="preset"
                                v-model="form.preset"
                                class="mt-2 h-10 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="">Path personalizzato</option>
                                <option
                                    v-for="item in presets"
                                    :key="item.id"
                                    :value="item.id"
                                >
                                    {{ item.id }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label for="path">Path locale</Label
                            ><Input
                                id="path"
                                v-model="form.path"
                                class="mt-2"
                                :disabled="!!form.preset"
                                placeholder="C:/…/progetto"
                            /><InputError :message="form.errors.path" />
                        </div>
                        <div>
                            <Label for="target-mode">Ambiente</Label
                            ><select
                                id="target-mode"
                                v-model="form.target_mode"
                                class="mt-2 h-10 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="sandbox">Sandbox Docker</option>
                                <option value="remote">URL già attivo</option>
                            </select>
                        </div>
                        <div v-if="form.target_mode === 'remote'">
                            <Label for="url">URL remoto</Label
                            ><Input
                                id="url"
                                v-model="form.url"
                                class="mt-2"
                                placeholder="https://staging.example.test"
                            /><InputError :message="form.errors.url" />
                        </div>
                    </div>
                    <label
                        v-if="form.target_mode === 'remote'"
                        class="mt-4 flex gap-3 rounded-lg border border-amber-500/30 bg-amber-500/5 p-4 text-sm"
                        ><input
                            v-model="form.authorized"
                            type="checkbox"
                            class="mt-0.5 size-4"
                        /><span
                            >Confermo di essere autorizzato a testare questo
                            host. Le richieste possono contenere tentativi di
                            exploit.</span
                        ></label
                    >
                    <InputError :message="form.errors.authorized" />
                </section>

                <section class="rounded-xl border bg-card p-5">
                    <h2 class="font-semibold">Agente</h2>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div v-if="!form.global">
                            <Label>Categoria 1</Label
                            ><Input
                                v-model="form.categories[0]"
                                class="mt-2"
                                placeholder="A01:2025 Broken Access Control"
                            />
                        </div>
                        <div v-if="!form.global">
                            <Label>Categoria 2</Label
                            ><Input
                                :model-value="form.categories[1] || ''"
                                class="mt-2"
                                placeholder="Opzionale"
                                @update:model-value="
                                    form.categories[1] = String($event)
                                "
                            />
                        </div>
                        <div>
                            <Label>Reader model</Label
                            ><Input
                                v-model="form.reader_model"
                                class="mt-2"
                                placeholder="Default configurato"
                            />
                        </div>
                        <div>
                            <Label>Worker model</Label
                            ><Input
                                v-model="form.worker_model"
                                class="mt-2"
                                placeholder="Default configurato"
                            />
                        </div>
                        <div v-if="!form.global">
                            <Label>Exploration Reviewer model</Label
                            ><Input
                                v-model="form.reviewer_model"
                                class="mt-2"
                                placeholder="Default: Reader model"
                            />
                        </div>
                        <div>
                            <Label>Confirmer model</Label
                            ><Input
                                v-model="form.confirmer_model"
                                class="mt-2"
                                placeholder="Default configurato"
                            />
                        </div>
                    </div>
                    <div
                        v-if="form.global"
                        class="mt-4 grid gap-4 md:grid-cols-3"
                    >
                        <div>
                            <Label>Reader contemporanei</Label
                            ><Input
                                v-model="form.reader_concurrency"
                                type="number"
                                min="1"
                                max="4"
                                class="mt-2"
                            /><InputError
                                :message="form.errors.reader_concurrency"
                            />
                        </div>
                        <div>
                            <Label>Confirmer contemporanei</Label
                            ><Input
                                v-model="form.confirmer_concurrency"
                                type="number"
                                min="1"
                                class="mt-2"
                            /><InputError
                                :message="form.errors.confirmer_concurrency"
                            />
                        </div>
                        <div>
                            <Label>Worker contemporanei</Label
                            ><Input
                                v-model="form.worker_concurrency"
                                type="number"
                                min="1"
                                class="mt-2"
                            /><InputError
                                :message="form.errors.worker_concurrency"
                            />
                        </div>
                        <div>
                            <Label>Cap Reader (EP totali)</Label
                            ><Input
                                v-model="form.reader_points"
                                type="number"
                                min="1"
                                placeholder="Default del preset"
                                class="mt-2"
                            /><InputError
                                :message="form.errors.reader_points"
                            />
                        </div>
                        <div>
                            <Label>Budget per Worker (EP)</Label
                            ><Input
                                v-model="form.worker_points"
                                type="number"
                                min="1"
                                placeholder="1000000"
                                class="mt-2"
                            /><InputError
                                :message="form.errors.worker_points"
                            />
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-5 text-sm">
                        <label class="flex items-center gap-2"
                            ><input v-model="form.test" type="checkbox" />
                            Test/debug e reasoning</label
                        ><label class="flex items-center gap-2"
                            ><input v-model="form.keep" type="checkbox" />
                            Mantieni sandbox</label
                        >
                    </div>
                </section>

                <details class="rounded-xl border bg-card p-5">
                    <summary class="cursor-pointer font-semibold">
                        Parametri avanzati
                    </summary>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <Label>Image</Label
                            ><Input v-model="form.image" class="mt-2" />
                        </div>
                        <div>
                            <Label>Dockerfile</Label
                            ><Input v-model="form.dockerfile" class="mt-2" />
                        </div>
                        <div>
                            <Label>Compose</Label
                            ><Input v-model="form.compose" class="mt-2" />
                        </div>
                        <div>
                            <Label>Mount</Label
                            ><Input v-model="form.mount" class="mt-2" />
                        </div>
                        <div>
                            <Label>Porta</Label
                            ><Input
                                v-model.number="form.port"
                                type="number"
                                class="mt-2"
                            />
                        </div>
                        <div>
                            <Label>Servizio</Label
                            ><Input v-model="form.service" class="mt-2" />
                        </div>
                        <div>
                            <Label>DB DSN</Label
                            ><Input v-model="form.db" class="mt-2" />
                        </div>
                        <div>
                            <Label>Health path</Label
                            ><Input v-model="form.health_path" class="mt-2" />
                        </div>
                        <div>
                            <Label>TTL (secondi)</Label
                            ><Input
                                v-model.number="form.ttl"
                                type="number"
                                class="mt-2"
                            />
                        </div>
                    </div>
                    <label class="mt-4 flex items-center gap-2 text-sm"
                        ><input v-model="form.skip_health" type="checkbox" />
                        Salta health check remoto</label
                    >
                </details>
            </template>
            <div class="flex justify-end">
                <Button type="submit" size="lg" :disabled="form.processing"
                    ><Play />
                    {{ form.processing ? 'Accodamento…' : 'Avvia run' }}</Button
                >
            </div>
        </form>
    </div>
</template>
