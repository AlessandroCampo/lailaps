<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Question { key: string; kind: 'text' | 'choice' | 'secret'; prompt: string; options: string[]; answered: boolean }
interface Preparation {
    id: string; status: string; summary: string | null; questions: Question[]; configuration: Record<string, unknown>;
    actors: string[]; capabilities: Record<string, { available: boolean; verified: boolean }> | null;
    doctorUsage: Record<string, unknown> | null; activeSeconds: number; failureReason: string | null; fingerprint: string | null;
    project: { id: string; name: string }; revision: { id: string; provider: string; hash: string };
}
const props = defineProps<{ preparation: Preparation }>();
const answers = useForm<{ answers: Record<string, string> }>({ answers: Object.fromEntries(props.preparation.questions.map((question) => [question.key, ''])) });
const audit = useForm<{ categories: string[] }>({ categories: [] });
const cancellation = useForm({});
let timer: ReturnType<typeof setInterval> | null = null;
const active = computed(() => ['queued', 'discovering', 'preparing', 'diagnosing'].includes(props.preparation.status));
onMounted(() => {
    if (active.value) timer = setInterval(() => router.reload({ only: ['preparation'] }), 3000);
});
onBeforeUnmount(() => { if (timer) clearInterval(timer); });
</script>

<template>
    <Head title="Preparazione" />
    <AppLayout>
        <div class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-8">
            <div><Link :href="`/projects/${preparation.project.id}`" class="text-sm text-muted-foreground">← {{ preparation.project.name }}</Link><h1 class="mt-2 text-3xl font-semibold">Environment Doctor</h1><p class="mt-1 text-muted-foreground">{{ preparation.revision.provider }} · {{ preparation.revision.hash?.slice(0, 16) }}</p></div>

            <section class="rounded-xl border bg-card p-5">
                <div class="flex flex-wrap items-center justify-between gap-3"><strong>Stato: {{ preparation.status }}</strong><span class="text-sm text-muted-foreground">{{ preparation.activeSeconds }}s di lavoro attivo</span></div>
                <p v-if="preparation.summary" class="mt-4 whitespace-pre-wrap text-sm">{{ preparation.summary }}</p>
                <p v-if="preparation.failureReason" class="mt-3 text-sm text-destructive">{{ preparation.failureReason }}</p>
            </section>

            <form v-if="preparation.status === 'awaiting_input'" class="rounded-xl border bg-card p-5" @submit.prevent="answers.post(`/preparations/${preparation.id}/answers`)">
                <h2 class="font-semibold">Informazioni richieste</h2>
                <div v-for="question in preparation.questions" :key="question.key" class="mt-4">
                    <Label :for="question.key">{{ question.prompt }}</Label>
                    <select v-if="question.kind === 'choice'" :id="question.key" v-model="answers.answers[question.key]" class="mt-2 h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="">Seleziona…</option><option v-for="option in question.options" :key="option" :value="option">{{ option }}</option></select>
                    <Input v-else :id="question.key" v-model="answers.answers[question.key]" class="mt-2" :type="question.kind === 'secret' ? 'password' : 'text'" autocomplete="off" />
                    <p v-if="answers.errors[`answers.${question.key}`]" class="mt-1 text-sm text-destructive">{{ answers.errors[`answers.${question.key}`] }}</p>
                </div>
                <Button class="mt-5" :disabled="answers.processing">Rispondi e riprendi</Button>
            </form>

            <section v-if="preparation.capabilities" class="rounded-xl border bg-card p-5">
                <h2 class="font-semibold">Preflight</h2>
                <div class="mt-3 grid gap-2 sm:grid-cols-2"><div v-for="(capability, name) in preparation.capabilities" :key="name" class="rounded-lg border p-3 text-sm"><strong>{{ name }}</strong><span class="ml-2 text-muted-foreground">{{ capability.available ? (capability.verified ? 'verificato' : 'disponibile, non verificato') : 'non disponibile' }}</span></div></div>
            </section>

            <form v-if="preparation.status === 'ready'" class="rounded-xl border bg-card p-5" @submit.prevent="audit.post(`/preparations/${preparation.id}/audits`)">
                <h2 class="font-semibold">Avvia audit pulito</h2><p class="mt-1 text-sm text-muted-foreground">La sandbox verrà ricreata e il preflight ripetuto con keep=false e test=false.</p>
                <Button class="mt-4" :disabled="audit.processing">Avvia audit</Button>
            </form>

            <form v-if="active" @submit.prevent="cancellation.post(`/preparations/${preparation.id}/cancel`)"><Button type="submit" variant="outline">Annulla preparazione</Button></form>
        </div>
    </AppLayout>
</template>
