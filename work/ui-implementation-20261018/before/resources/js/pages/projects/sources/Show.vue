<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { AlertCircle, ArrowLeft, FileArchive } from '@lucide/vue';
import SourceStatus from '@/components/projects/SourceStatus.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show } from '@/routes/projects';
import type { Project, ProjectSource } from '@/types/project';

defineProps<{ project: Project; source: ProjectSource }>();
</script>

<template>
    <Head :title="`Source code — ${project.name}`" />
    <div class="page-container space-y-8">
        <Link
            :href="show(project.id)"
            class="inline-flex max-w-full items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
            ><ArrowLeft class="size-4 shrink-0" /><span
                class="min-w-0 break-words"
                >{{ project.name }}</span
            ></Link
        >
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0 space-y-2">
                <h1 class="page-title">Source code</h1>
            </div>
            <SourceStatus :status="source.status" />
        </header>
        <Card class="max-w-3xl">
            <CardHeader
                ><CardTitle class="flex items-center gap-2"
                    ><FileArchive class="size-4" /> Source details</CardTitle
                ></CardHeader
            >
            <CardContent class="space-y-6">
                <dl class="grid gap-5 sm:grid-cols-2">
                    <div class="space-y-1">
                        <dt class="metadata">Type</dt>
                        <dd class="uppercase">{{ source.source_type }}</dd>
                    </div>
                    <div class="space-y-1">
                        <dt class="metadata">Created</dt>
                        <dd>
                            {{
                                new Date(source.created_at).toLocaleDateString(
                                    'en-US',
                                    { dateStyle: 'medium' },
                                )
                            }}
                        </dd>
                    </div>
                    <div class="space-y-1">
                        <dt class="metadata">Uploaded</dt>
                        <dd>
                            {{
                                source.uploaded_at
                                    ? new Date(
                                          source.uploaded_at,
                                      ).toLocaleString('en-US')
                                    : 'Not recorded'
                            }}
                        </dd>
                    </div>
                    <div class="space-y-1">
                        <dt class="metadata">Project</dt>
                        <dd>
                            <Link
                                :href="show(project.id)"
                                class="break-words underline underline-offset-4"
                                >{{ project.name }}</Link
                            >
                        </dd>
                    </div>
                </dl>
                <Alert v-if="source.error" variant="destructive"
                    ><AlertCircle /><AlertTitle>Import failed</AlertTitle
                    ><AlertDescription class="break-words">{{
                        source.error
                    }}</AlertDescription></Alert
                >
            </CardContent>
        </Card>
    </div>
</template>
