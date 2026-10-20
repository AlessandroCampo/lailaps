<script setup lang="ts">
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowRight,
    CircleCheck,
    FileArchive,
    FlaskConical,
    RefreshCw,
    Upload,
    Radar,
    FolderArchive
} from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import SourceStatus from '@/components/projects/SourceStatus.vue';
import SourceUpload from '@/components/projects/SourceUpload.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { index, show as showProject } from '@/routes/projects';
import { show, store, retry_setup } from '@/routes/projects/sources';
import type {
    ProjectPreparationStatus,
    Project,
    ProjectSource,
} from '@/types/project';

const props = defineProps<{
    project: Project;
    source: ProjectSource | null;
    step: 'source' | 'environment';
    preparation_status: ProjectPreparationStatus | null;
}>();
const form = useForm<{ source_type: string; source: File | null }>({
    source_type: 'zip',
    source: null,
});
const submitError = ref('');
const refreshing = ref(false);
const { start: startPolling, stop: stopPolling } = usePoll(
    3000,
    { only: ['source', 'preparation_status'] },
    { autoStart: false },
);

watch(
    () => [props.step, props.preparation_status],
    () => {
        if (
            props.step === 'environment' &&
            props.preparation_status === 'running'
        ) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

function submit() {
    submitError.value = '';
    form.post(store.url(props.project.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onHttpException: (response) => {
            submitError.value =
                response.status === 413
                    ? 'The file exceeds the server upload limit.'
                    : 'Unable to upload the source. Please try again.';
            return false;
        },
    });
}

function retry() {
   
    router.post(retry_setup.url([props.project.id, props.source.id]));
}

function refresh() {
    refreshing.value = true;
    router.reload({
        only: ['source', 'preparation_status'],
        onFinish: () => (refreshing.value = false),
    });
}
</script>

<template>
    <Head :title="project.name" />
    <div class="page-container space-y-8">
        <Link
            :href="index()"
            class="text-sm break-words text-muted-foreground hover:text-foreground"
            >Projects /
            <span class="text-foreground">{{ project.name }}</span></Link
        >
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-2">
                <h1 class="page-title break-words">{{ project.name }}</h1>
            </div>
            <div class="flex justify-end gap-4">
                <Button
                    variant="outline"
                    :disabled="refreshing"
                    @click="refresh"
                    ><RefreshCw :class="{ 'animate-spin': refreshing }" />
                    Refresh</Button
                >
                <Button
                    :disabled="refreshing"
                    @click="refresh"
                    ><Radar />
                    Start scan</Button
                >
            </div>
        </header>
        <nav
            aria-label="Project steps"
            class="flex flex-wrap gap-2 border-b pb-4"
        >
            <Button
                :variant="step === 'source' ? 'secondary' : 'ghost'"
                as-child
            >
                <Link
                    :href="
                        showProject(project.id, { query: { step: 'source' } })
                    "
                    :aria-current="step === 'source' ? 'step' : undefined"
                >
                    <FileArchive /> Source
                </Link>
            </Button>
            <Button
                :variant="step === 'environment' ? 'secondary' : 'ghost'"
                as-child
            >
                <Link
                    :href="
                        showProject(project.id, {
                            query: { step: 'environment' },
                        })
                    "
                    :aria-current="step === 'environment' ? 'step' : undefined"
                >
                    <FlaskConical /> Status
                </Link>
            </Button>
        </nav>
        <div
            class="grid items-start gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
        >
            <div v-if="step === 'source'" class="space-y-6">
                <Card>
                    <CardHeader
                        ><CardTitle>Active source</CardTitle></CardHeader
                    >
                    <CardContent v-if="source" class="space-y-5">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div class="flex items-center gap-3">
                                <FileArchive
                                    class="size-5 text-muted-foreground"
                                /><span class="font-medium">{{project.name}} - {{ source.id }}</span>
                            </div>
                            <SourceStatus :status="source.status" />
                        </div>
                        <dl class="space-y-3 border-t pt-5">
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Type</dt>
                                <div class="flex gap-2">
                                    <FolderArchive :size="18" />
                                    <dd class="uppercase">
                                        {{ source.source_type }}
                                    </dd>
                                </div>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Created</dt>
                                <dd>
                                    {{
                                        new Date(
                                            source.created_at,
                                        ).toLocaleDateString('en-US', {
                                            dateStyle: 'medium',
                                        })
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <Alert v-if="source.error" variant="destructive"
                            ><AlertCircle /><AlertTitle
                                >Import failed</AlertTitle
                            ><AlertDescription class="break-words">{{
                                source.error
                            }}</AlertDescription></Alert
                        >
                        <Button variant="outline" size="sm" as-child
                            ><Link :href="show([project.id, source.id])"
                                >View source <ArrowRight /></Link
                        ></Button>
                    </CardContent>
                    <CardContent
                        v-else
                        class="flex flex-col items-center gap-3 py-6 text-center"
                        ><FileArchive class="size-6 text-muted-foreground" />
                        <p class="font-medium">
                            No source uploaded
                        </p></CardContent
                    >
                </Card>
                <Card>
                    <CardHeader
                        ><CardTitle>{{
                            source ? 'Upload new source' : 'Upload source'
                        }}</CardTitle></CardHeader
                    >
                    <CardContent>
                        <form class="space-y-5" @submit.prevent="submit">
                            <Alert v-if="submitError" variant="destructive"
                                ><AlertCircle /><AlertTitle
                                    >Upload failed</AlertTitle
                                ><AlertDescription>{{
                                    submitError
                                }}</AlertDescription></Alert
                            >
                            <SourceUpload
                                v-model="form.source"
                                :disabled="form.processing"
                                :error="form.errors.source"
                            />
                            <InputError :message="form.errors.source_type" />
                            <div
                                v-if="form.progress"
                                role="status"
                                aria-live="polite"
                                class="space-y-2"
                            >
                                <p class="metadata">
                                    Uploading: {{ form.progress.percentage }}%
                                </p>
                                <progress
                                    :value="form.progress.percentage"
                                    max="100"
                                    class="h-1.5 w-full accent-foreground"
                                    aria-label="Upload progress"
                                />
                            </div>
                            <div class="flex justify-end border-t pt-5">
                                <Button
                                    type="submit"
                                    :disabled="form.processing || !form.source"
                                    ><Spinner v-if="form.processing" /><Upload
                                        v-else
                                    />
                                    {{
                                        form.processing
                                            ? 'Uploading…'
                                            : 'Upload source'
                                    }}</Button
                                >
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
            <div v-else class="min-w-0">
                <Card>
                    <CardHeader><CardTitle>Status</CardTitle></CardHeader>
                    <CardContent>
                        <div
                            v-if="preparation_status === 'ready'"
                            role="status"
                            aria-live="polite"
                            class="flex items-center gap-3"
                        >
                        
                            <CircleCheck
                                class="size-5 text-success"
                                aria-hidden="true"
                            />
                            <span>Ready</span>
                        </div>
                        <Alert
                            v-else-if="preparation_status === 'failed'"
                            variant="destructive"
                        >
                            <AlertCircle />
                            <AlertTitle>Setup failed</AlertTitle>
                            <AlertDescription
                                >Unable to prepare the
                                project.</AlertDescription
                            >
                            <Button size="sm" style="width: 150px" class="my-2" @click="retry">
                                Retry
                            </Button>
                        </Alert>
                        <div
                            v-else-if="preparation_status === 'running'"
                            role="status"
                            aria-live="polite"
                            class="flex items-center gap-3 text-muted-foreground"
                        >
                            <Spinner />
                            <span>Loading…</span>
                        </div>
                        <Badge v-else variant="secondary">Not prepared</Badge>
                    </CardContent>
                </Card>
            </div>
            <aside class="min-w-0 space-y-5 rounded-lg border bg-muted p-5">
                <h2 class="section-title">Project details</h2>
                <dl class="space-y-4">
                    <div class="space-y-1">
                        <dt class="metadata">Name</dt>
                        <dd class="break-words">{{ project.name }}</dd>
                    </div>
                    <div class="space-y-1">
                        <dt class="metadata">Created</dt>
                        <dd>
                            {{
                                new Date(project.created_at).toLocaleDateString(
                                    'en-US',
                                    { dateStyle: 'medium' },
                                )
                            }}
                        </dd>
                    </div>
                    <div class="space-y-1">
                        <dt class="metadata">Visibility</dt>
                        <dd>Private</dd>
                    </div>
                </dl>
            </aside>
        </div>
    </div>
</template>
