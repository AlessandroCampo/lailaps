<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';
import { getInitials } from '@/composables/useInitials';
import { index } from '@/routes/projects';
import type { BreadcrumbItem } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});
const page = usePage();
const user = computed(() => page.props.auth.user);
const { resolvedAppearance, updateAppearance } = useAppearance();
</script>

<template>
    <header class="border-b bg-background">
        <div
            class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8"
        >
            <div class="flex min-w-0 items-center gap-3 sm:gap-5">
                <Link
                    :href="index()"
                    class="flex shrink-0 items-center gap-2 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    aria-label="Lailaps — Projects"
                >
                    <span
                        class="flex size-7 items-center justify-center rounded-md bg-primary text-sm font-semibold text-primary-foreground"
                        >L</span
                    >
                    <span class="font-semibold tracking-tight">Lailaps</span>
                </Link>
                <span class="text-border" aria-hidden="true">/</span>
                <span class="truncate text-sm text-muted-foreground"
                    >Personal workspace</span
                >
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <Button
                    variant="ghost"
                    size="icon-sm"
                    :aria-label="
                        resolvedAppearance === 'dark'
                            ? 'Switch to light mode'
                            : 'Switch to dark mode'
                    "
                    @click="
                        updateAppearance(
                            resolvedAppearance === 'dark' ? 'light' : 'dark',
                        )
                    "
                >
                    <Sun v-if="resolvedAppearance === 'dark'" />
                    <Moon v-else />
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label="Account menu"
                        >
                            <span
                                class="flex size-7 items-center justify-center rounded-full border bg-muted text-xs"
                                >{{ getInitials(user.name) }}</span
                            >
                            <ChevronDown class="size-3 text-muted-foreground" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-64">
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
        <nav
            aria-label="Main navigation"
            class="mx-auto flex h-11 max-w-7xl items-stretch px-4 sm:px-6 lg:px-8"
        >
            <Link
                :href="index()"
                class="flex items-center border-b-2 px-1 text-sm font-medium"
                :class="
                    page.url.startsWith('/projects')
                        ? 'border-foreground'
                        : 'border-transparent'
                "
                :aria-current="
                    page.url.startsWith('/projects') ? 'page' : undefined
                "
                >Projects</Link
            >
        </nav>
    </header>
    <div v-if="breadcrumbs.length > 1" class="border-b bg-muted">
        <div
            class="mx-auto flex min-h-11 max-w-6xl items-center px-4 sm:px-6 lg:px-8"
        >
            <Breadcrumbs :breadcrumbs="breadcrumbs" />
        </div>
    </div>
</template>
