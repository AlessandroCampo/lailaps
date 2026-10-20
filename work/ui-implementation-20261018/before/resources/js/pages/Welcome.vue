<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, FileArchive } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { index } from '@/routes/projects';

const page = usePage();
</script>

<template>
    <Head title="Lailaps" />
    <div class="min-h-svh bg-background">
        <header
            class="mx-auto flex h-20 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8"
        >
            <span class="flex items-center gap-2 font-semibold"
                ><span
                    class="flex size-7 items-center justify-center rounded-md bg-primary text-primary-foreground"
                    >L</span
                >Lailaps</span
            >
            <Button v-if="page.props.auth.user" variant="outline" as-child
                ><Link :href="index()">Open workspace <ArrowRight /></Link
            ></Button>
            <Button v-else variant="outline" as-child
                ><Link :href="login()">Log in</Link></Button
            >
        </header>
        <main
            class="page-container flex min-h-[60svh] max-w-3xl flex-col justify-center gap-8"
        >
            <span
                class="flex size-12 items-center justify-center rounded-lg border bg-muted"
                ><FileArchive class="size-5"
            /></span>
            <div class="space-y-4">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-5xl">
                    Lailaps
                </h1>
            </div>
            <div class="flex flex-wrap gap-3">
                <Button v-if="page.props.auth.user" as-child
                    ><Link :href="index()">Projects <ArrowRight /></Link
                ></Button>
                <template v-else>
                    <Button as-child
                        ><Link :href="register()"
                            >Create account <ArrowRight /></Link
                    ></Button>
                    <Button variant="outline" as-child
                        ><Link :href="login()">Log in</Link></Button
                    >
                </template>
            </div>
        </main>
    </div>
</template>
