<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertCircle, ArrowLeft, ArrowRight } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SourceUpload from '@/components/projects/SourceUpload.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, store } from '@/routes/projects';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Projects', href: index() },
            { title: 'New project', href: '#' },
        ],
    },
});
const form = useForm<{
    name: string;
    source_type: string;
    source: File | null;
}>({ name: '', source_type: 'zip', source: null });
const submitError = ref('');

function submit() {
    submitError.value = '';
    form.post(store.url(), {
        forceFormData: true,
        onHttpException: (response) => {
            submitError.value =
                response.status === 413
                    ? 'The file exceeds the server upload limit.'
                    : 'Unable to create the project.';
            return false;
        },
    });
}
</script>

<template>
    <Head title="New project" />
    <div class="page-container space-y-8">
        <Link
            :href="index()"
            class="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
            ><ArrowLeft class="size-4" /> All projects</Link
        >
        <header class="space-y-2">
            <h1 class="page-title">New project</h1>
        </header>
        <div class="max-w-2xl">
            <Card>
                <CardContent>
                    <form class="space-y-6" @submit.prevent="submit">
                        <Alert v-if="submitError" variant="destructive"
                            ><AlertCircle /><AlertTitle
                                >Upload failed</AlertTitle
                            ><AlertDescription>{{
                                submitError
                            }}</AlertDescription></Alert
                        >
                        <div class="space-y-2">
                            <Label for="project-name">Project name</Label>
                            <Input
                                id="project-name"
                                v-model="form.name"
                                placeholder="Customer portal"
                                required
                                maxlength="255"
                                autocomplete="off"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.name"
                                aria-describedby="name-error"
                            />
                            <InputError
                                id="name-error"
                                :message="form.errors.name"
                            />
                        </div>
                        <SourceUpload
                            v-model="form.source"
                            :disabled="form.processing"
                            :error="form.errors.source"
                        />
                        <InputError :message="form.errors.source_type" />
                        <div
                            v-if="form.progress"
                            class="space-y-2"
                            role="status"
                            aria-live="polite"
                        >
                            <div
                                class="flex justify-between text-xs text-muted-foreground"
                            >
                                <span>Uploading ZIP</span
                                ><span>{{ form.progress.percentage }}%</span>
                            </div>
                            <progress
                                :value="form.progress.percentage"
                                max="100"
                                class="h-1.5 w-full accent-foreground"
                                aria-label="Upload progress"
                            />
                        </div>
                        <div
                            class="flex flex-wrap justify-end gap-3 border-t pt-6"
                        >
                            <Button variant="outline" as-child
                                ><Link :href="index()">Cancel</Link></Button
                            >
                            <Button
                                type="submit"
                                :disabled="
                                    form.processing ||
                                    !form.name.trim() ||
                                    !form.source
                                "
                                ><Spinner v-if="form.processing" /><ArrowRight
                                    v-else
                                />
                                {{
                                    form.processing
                                        ? 'Uploading…'
                                        : 'Create project'
                                }}</Button
                            >
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
