<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Revision {
    id: string;
    provider: string;
    status: string;
    content_hash: string | null;
    github_full_name: string | null;
    github_commit_sha: string | null;
    provider_metadata: { warnings?: string[] } | null;
    error: string | null;
}
interface Preparation { id: string; source_revision_id: string; status: string; summary: string | null }
interface Repository { id: number; full_name: string; default_branch: string; private: boolean }
const props = defineProps<{
    project: { id: string; name: string; revisions: Revision[]; preparations: Preparation[] };
    repositories: { items: Repository[]; page: number; has_more: boolean } | null;
    githubError: string | null;
}>();

const zip = useForm<{ archive: File | null; application_subdirectory: string }>({ archive: null, application_subdirectory: '' });
const github = useForm({ repository_id: '', full_name: '', ref: '', application_subdirectory: '' });
const prepare = useForm({ source_revision_id: '', based_on_id: '', configuration: { service: '', health_path: '', compose_file: '', dockerfile: '', audit_profile: '' } });
const branches = ref<{ name: string }[]>([]);
const commits = ref<{ sha: string; commit?: { message?: string } }[]>([]);
const selectedRepository = computed(() => props.repositories?.items.find((item) => String(item.id) === github.repository_id));

watch(() => github.repository_id, async () => {
    const repository = selectedRepository.value;
    branches.value = [];
    commits.value = [];
    if (!repository) return;
    github.full_name = repository.full_name;
    github.ref = repository.default_branch;
    const query = encodeURIComponent(repository.full_name);
    const [branchResponse, commitResponse] = await Promise.all([
        fetch(`/projects/${props.project.id}/github/branches?repository=${query}`, { headers: { Accept: 'application/json' } }),
        fetch(`/projects/${props.project.id}/github/commits?repository=${query}&sha=${encodeURIComponent(repository.default_branch)}`, { headers: { Accept: 'application/json' } }),
    ]);
    if (branchResponse.ok) branches.value = await branchResponse.json();
    if (commitResponse.ok) commits.value = await commitResponse.json();
});

const submitZip = () => zip.post(`/projects/${props.project.id}/zip`, { forceFormData: true });
const submitGithub = () => github.post(`/projects/${props.project.id}/github`);
const submitPreparation = () => prepare.post(`/projects/${props.project.id}/preparations`);
</script>

<template>
    <Head :title="project.name" />
    <AppLayout>
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-8">
            <div><Link href="/projects" class="text-sm text-muted-foreground">← Progetti</Link><h1 class="mt-2 text-3xl font-semibold">{{ project.name }}</h1></div>

            <div class="grid gap-5 lg:grid-cols-2">
                <form class="rounded-xl border bg-card p-5" @submit.prevent="submitZip">
                    <h2 class="font-semibold">Importa ZIP</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Il file viene validato ed estratto dalla coda, senza eseguire codice.</p>
                    <Label class="mt-4 block" for="archive">Archivio</Label>
                    <Input id="archive" class="mt-2" type="file" accept=".zip,application/zip" @change="zip.archive = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    <Label class="mt-4 block" for="zip-subdir">Sottodirectory applicativa (facoltativa)</Label>
                    <Input id="zip-subdir" v-model="zip.application_subdirectory" class="mt-2" placeholder="apps/web" />
                    <p v-for="error in zip.errors" :key="error" class="mt-1 text-sm text-destructive">{{ error }}</p>
                    <Button class="mt-4" :disabled="zip.processing">Importa</Button>
                </form>

                <form class="rounded-xl border bg-card p-5" @submit.prevent="submitGithub">
                    <h2 class="font-semibold">Importa da GitHub</h2>
                    <p v-if="githubError" class="mt-2 text-sm text-destructive">{{ githubError }}</p>
                    <template v-if="repositories">
                        <Label class="mt-4 block" for="repository">Repository autorizzato</Label>
                        <select id="repository" v-model="github.repository_id" class="mt-2 h-10 w-full rounded-md border bg-background px-3 text-sm">
                            <option value="">Seleziona…</option>
                            <option v-for="repository in repositories.items" :key="repository.id" :value="String(repository.id)">{{ repository.full_name }}{{ repository.private ? ' · private' : '' }}</option>
                        </select>
                        <Label class="mt-4 block" for="ref">Branch, commit o SHA esplicito</Label>
                        <Input id="ref" v-model="github.ref" class="mt-2" list="github-refs" />
                        <datalist id="github-refs">
                            <option v-for="branch in branches" :key="branch.name" :value="branch.name" />
                            <option v-for="commit in commits" :key="commit.sha" :value="commit.sha">{{ commit.commit?.message }}</option>
                        </datalist>
                        <Label class="mt-4 block" for="github-subdir">Sottodirectory applicativa (facoltativa)</Label>
                        <Input id="github-subdir" v-model="github.application_subdirectory" class="mt-2" />
                        <p v-for="error in github.errors" :key="error" class="mt-1 text-sm text-destructive">{{ error }}</p>
                        <Button class="mt-4" :disabled="github.processing">Risolvi commit e importa</Button>
                    </template>
                    <p v-else class="mt-4 text-sm text-muted-foreground">Collega la GitHub App dalla pagina Progetti.</p>
                </form>
            </div>

            <section class="rounded-xl border bg-card p-5">
                <h2 class="font-semibold">Revisioni</h2>
                <div class="mt-4 space-y-3">
                    <div v-for="revision in project.revisions" :key="revision.id" class="rounded-lg border p-4">
                        <div class="flex flex-wrap justify-between gap-2"><strong>{{ revision.provider.toUpperCase() }} · {{ revision.status }}</strong><code v-if="revision.content_hash" class="text-xs">{{ revision.content_hash.slice(0, 16) }}</code></div>
                        <p v-if="revision.github_full_name" class="mt-1 text-sm">{{ revision.github_full_name }} @ {{ revision.github_commit_sha }}</p>
                        <p v-if="revision.error" class="mt-1 text-sm text-destructive">{{ revision.error }}</p>
                        <p v-for="warning in revision.provider_metadata?.warnings || []" :key="warning" class="mt-1 text-sm text-amber-600">{{ warning }}</p>
                    </div>
                    <p v-if="project.revisions.length === 0" class="text-sm text-muted-foreground">Nessuna revisione.</p>
                </div>
            </section>

            <form class="rounded-xl border bg-card p-5" @submit.prevent="submitPreparation">
                <h2 class="font-semibold">Nuova preparazione</h2>
                <select v-model="prepare.source_revision_id" class="mt-4 h-10 w-full rounded-md border bg-background px-3 text-sm">
                    <option value="">Seleziona una revisione pronta…</option>
                    <option v-for="revision in project.revisions.filter((item) => item.status === 'ready')" :key="revision.id" :value="revision.id">{{ revision.provider }} · {{ revision.content_hash?.slice(0, 16) }}</option>
                </select>
                <details class="mt-4 rounded-lg border p-4">
                    <summary class="cursor-pointer text-sm font-medium">Configurazione avanzata</summary>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <Input v-model="prepare.configuration.service" placeholder="Servizio web" />
                        <Input v-model="prepare.configuration.health_path" placeholder="Health path, es. /up" />
                        <Input v-model="prepare.configuration.compose_file" placeholder="compose.yml" />
                        <Input v-model="prepare.configuration.dockerfile" placeholder="Dockerfile" />
                        <Input v-model="prepare.configuration.audit_profile" placeholder="lailaps.audit.yaml" />
                    </div>
                </details>
                <p v-for="error in prepare.errors" :key="error" class="mt-1 text-sm text-destructive">{{ error }}</p>
                <Button class="mt-4" :disabled="prepare.processing">Avvia Environment Doctor</Button>
            </form>

            <section class="rounded-xl border bg-card p-5">
                <h2 class="font-semibold">Preparazioni</h2>
                <div class="mt-4 space-y-2">
                    <Link v-for="item in project.preparations" :key="item.id" :href="`/preparations/${item.id}`" class="block rounded-lg border p-4 hover:border-primary/50"><strong>{{ item.status }}</strong><p class="mt-1 line-clamp-2 text-sm text-muted-foreground">{{ item.summary || 'In attesa del Doctor…' }}</p></Link>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
