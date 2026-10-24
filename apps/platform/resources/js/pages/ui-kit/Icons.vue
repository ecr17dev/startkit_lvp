<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref } from 'vue';
import Icon from '@/components/kit/Icon.vue';
import Example from '@/components/ui-kit/Example.vue';
import { Input } from '@starter/ui/components/input';
import { iconGroups } from '@/lib/icons';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';
import { index, show } from '@/routes/ui-kit';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'UI kit', href: index() },
            { title: 'Icons', href: show('icons') },
        ],
    },
});

const search = ref('');
const { copy } = useClipboard({ legacy: true });

const pascal = (name: string) =>
    name
        .split('-')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join('');

const groups = computed(() => {
    const query = search.value.trim().toLowerCase();

    return iconGroups
        .map((group) => ({
            ...group,
            icons: group.icons.filter((name) => !query || name.includes(query)),
        }))
        .filter((group) => group.icons.length > 0);
});

async function pick(name: string) {
    await copy(`import { ${pascal(name)} } from '@lucide/vue';`);
    notify.success(t('Import of :name copied.', { name: pascal(name) }));
}

const code = `
<!-- In code: import the component (only the icons you use reach the bundle) -->
<script setup lang="ts">
import { Users } from '@lucide/vue';
<\u002Fscript>
<Users class="size-4" />

<!-- Size and color come from the classes; decorative icons need nothing else -->
<Users class="size-5 text-muted-foreground" />

<!-- Name that comes from data (navigation, settings): curated map of lib/icons.ts -->
<Icon name="users" class="size-4" />

<!-- An icon that means something on its own needs a label -->
<Icon name="circle-alert" :label="t('Error')" class="size-4 text-destructive" />
`;
</script>

<template>
    <Head :title="t('Icons')" />

    <Example
        :title="t('How to use icons')"
        :description="
            t('Lucide icons (lucide.dev). Click an icon to copy its import.')
        "
        :code="code"
    >
        <div class="grid gap-6">
            <div class="relative max-w-sm">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    class="pl-9"
                    :placeholder="t('Search icons')"
                    :aria-label="t('Search icons')"
                />
            </div>

            <section
                v-for="group in groups"
                :key="group.label"
                class="grid gap-2"
            >
                <h3 class="text-sm font-medium text-muted-foreground">
                    {{ t(group.label) }}
                </h3>
                <div
                    class="grid grid-cols-[repeat(auto-fill,minmax(6.5rem,1fr))] gap-2"
                >
                    <button
                        v-for="name in group.icons"
                        :key="name"
                        type="button"
                        class="flex flex-col items-center gap-2 rounded-md border p-3 text-center hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                        :title="pascal(name)"
                        @click="pick(name)"
                    >
                        <Icon :name="name" class="size-5" />
                        <span
                            class="w-full truncate text-[11px] text-muted-foreground"
                            >{{ name }}</span
                        >
                    </button>
                </div>
            </section>

            <p v-if="!groups.length" class="text-sm text-muted-foreground">
                {{
                    t(
                        'Not in this list? Search lucide.dev/icons and import it from @lucide/vue.',
                    )
                }}
            </p>
        </div>
    </Example>
</template>
