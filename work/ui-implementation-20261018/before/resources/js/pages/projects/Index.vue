<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Folder, Plus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, show } from '@/routes/projects';
import type { PaginatedProjects } from '@/types/project';

defineProps<{ projects: PaginatedProjects }>();
</script>

<template>
    <Head title="Projects" />
    <div class="page-container space-y-8">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <h1 class="page-title">Projects</h1>
            </div>
            <Button as-child
                ><Link :href="create()"><Plus /> New project</Link></Button
            >
        </header>

        <template v-if="projects.data.length">
            <div class="flex items-center gap-2 border-b pb-4">
                <h2 class="text-sm font-medium">All projects</h2>
                <span
                    class="rounded-md bg-secondary px-2 py-0.5 text-xs text-muted-foreground tabular-nums"
                    >{{ projects.total }}</span
                >
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="project in projects.data"
                    :key="project.id"
                    :href="show(project.id)"
                    class="group rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <Card
                        class="h-full transition-colors group-hover:border-foreground/30"
                    >
                        <CardContent class="space-y-6">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span
                                        class="flex size-9 shrink-0 items-center justify-center rounded-md border bg-muted"
                                        ><Folder class="size-4"
                                    /></span>
                                    <h3
                                        class="truncate font-medium"
                                        :title="project.name"
                                    >
                                        {{ project.name }}
                                    </h3>
                                </div>
                                <ArrowRight
                                    class="mt-2 size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                                />
                            </div>
                            <div class="space-y-2">
                                <p class="text-sm">
                                    {{
                                        project.sources_count
                                            ? 'Source uploaded'
                                            : 'No source'
                                    }}
                                </p>
                                <p class="metadata">
                                    Created
                                    {{
                                        new Date(
                                            project.created_at,
                                        ).toLocaleDateString('en-US', {
                                            dateStyle: 'medium',
                                        })
                                    }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </Link>
            </div>
            <nav
                v-if="projects.last_page > 1"
                aria-label="Project pagination"
                class="flex items-center justify-between border-t pt-5"
            >
                <span class="metadata"
                    >Page {{ projects.current_page }} of
                    {{ projects.last_page }}</span
                >
                <div class="flex gap-2">
                    <Button
                        v-if="projects.prev_page_url"
                        variant="outline"
                        size="sm"
                        as-child
                        ><Link :href="projects.prev_page_url"
                            >Previous</Link
                        ></Button
                    >
                    <Button v-else variant="outline" size="sm" disabled
                        >Previous</Button
                    >
                    <Button
                        v-if="projects.next_page_url"
                        variant="outline"
                        size="sm"
                        as-child
                        ><Link :href="projects.next_page_url"
                            >Next</Link
                        ></Button
                    >
                    <Button v-else variant="outline" size="sm" disabled
                        >Next</Button
                    >
                </div>
            </nav>
        </template>

        <section
            v-else
            class="flex min-h-80 flex-col items-center justify-center gap-4 rounded-lg border border-dashed bg-muted px-6 py-12 text-center"
        >
            <span
                class="flex size-12 items-center justify-center rounded-lg border bg-background"
                ><Folder class="size-5 text-muted-foreground"
            /></span>
            <div class="max-w-sm space-y-2">
                <h2 class="section-title">No projects yet</h2>
            </div>
            <Button as-child
                ><Link :href="create()"><Plus /> Create project</Link></Button
            >
        </section>
    </div>
</template>
