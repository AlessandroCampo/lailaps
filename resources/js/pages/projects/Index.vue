<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { FolderGit2, Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Project {
    id: string;
    name: string;
    revisions_count: number;
    preparations_count: number;
}

defineProps<{
    projects: Project[];
    github: { login: string; revoked: boolean } | null;
    githubConfigured: boolean;
}>();

const form = useForm({ name: '' });
const disconnect = useForm({});
</script>

<template>
    <Head title="Progetti" />
    <AppLayout>
        <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-8">
            <div>
                <h1 class="text-3xl font-semibold">Progetti</h1>
                <p class="text-muted-foreground">Importa una revisione immutabile e preparala prima dell'audit.</p>
            </div>

            <section class="rounded-xl border bg-card p-5">
                <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="form.post('/projects')">
                    <div class="grow">
                        <Label for="name">Nuovo progetto</Label>
                        <Input id="name" v-model="form.name" class="mt-2" placeholder="Nome del progetto" />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-destructive">{{ form.errors.name }}</p>
                    </div>
                    <Button :disabled="form.processing"><Plus class="size-4" /> Crea</Button>
                </form>
            </section>

            <section class="rounded-xl border bg-card p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">GitHub App</h2>
                        <p class="text-sm text-muted-foreground">
                            {{ github ? `Collegata come ${github.login}` : 'Non collegata' }}
                        </p>
                    </div>
                    <Link v-if="githubConfigured && !github" href="/github/connect"><Button>Collega GitHub</Button></Link>
                    <form v-else-if="github" @submit.prevent="disconnect.delete('/github/connection')">
                        <Button variant="outline" type="submit">Disconnetti</Button>
                    </form>
                    <span v-else class="text-sm text-muted-foreground">Configura le credenziali della GitHub App.</span>
                </div>
            </section>

            <div class="grid gap-3 md:grid-cols-2">
                <Link v-for="project in projects" :key="project.id" :href="`/projects/${project.id}`" class="rounded-xl border bg-card p-5 hover:border-primary/50">
                    <div class="flex items-center gap-2 font-semibold"><FolderGit2 class="size-4" /> {{ project.name }}</div>
                    <p class="mt-2 text-sm text-muted-foreground">{{ project.revisions_count }} revisioni · {{ project.preparations_count }} preparazioni</p>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
