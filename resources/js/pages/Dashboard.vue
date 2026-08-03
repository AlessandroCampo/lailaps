<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { ArrowRight, ShieldCheck } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 items-center justify-center p-6">
        <div class="w-full max-w-3xl rounded-2xl border bg-card p-8 md:p-12">
            <div
                class="mb-6 flex size-12 items-center justify-center rounded-xl bg-primary text-primary-foreground"
            >
                <ShieldCheck class="size-6" />
            </div>
            <h1 class="text-3xl font-semibold tracking-tight">
                Audit security workspace
            </h1>
            <p class="mt-3 max-w-xl text-muted-foreground">
                Avvia audit e benchmark, osserva il loop agentico in tempo reale
                e consulta report strutturati senza tornare alla shell.
            </p>
            <Button class="mt-7" as-child
                ><Link href="/audits">Apri gli audit <ArrowRight /></Link
            ></Button>
        </div>
    </div>
</template>
