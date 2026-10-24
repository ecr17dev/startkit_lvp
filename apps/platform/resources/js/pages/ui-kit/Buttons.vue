<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Bold,
    ChevronDown,
    Download,
    Italic,
    Pencil,
    Plus,
    Trash2,
    Underline,
} from '@lucide/vue';
import { ref } from 'vue';
import CopyButton from '@/components/kit/CopyButton.vue';
import IconButton from '@/components/kit/IconButton.vue';
import Example from '@/components/ui-kit/Example.vue';
import { Button } from '@starter/ui/components/button';
import { ButtonGroup } from '@starter/ui/components/button-group';
import { Kbd, KbdGroup } from '@starter/ui/components/kbd';
import { Spinner } from '@starter/ui/components/spinner';
import { Toggle } from '@starter/ui/components/toggle';
import {
    ToggleGroup,
    ToggleGroupItem,
} from '@starter/ui/components/toggle-group';
import { t } from '@/lib/i18n';
import { index, show } from '@/routes/ui-kit';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'UI kit', href: index() },
            { title: 'Buttons', href: show('buttons') },
        ],
    },
});

const loading = ref(false);
const align = ref('left');
const bold = ref(true);

function simulate() {
    loading.value = true;
    setTimeout(() => (loading.value = false), 1500);
}

const variants = `
<Button>Primary</Button>
<Button variant="secondary">Secondary</Button>
<Button variant="outline">Outline</Button>
<Button variant="ghost">Ghost</Button>
<Button variant="link">Link</Button>
<Button variant="destructive">Delete</Button>
`;

const withIcons = `
<Button><Plus class="size-4" /> {{ t('New project') }}</Button>
<Button size="sm" variant="outline"><Download class="size-4" /> {{ t('Export') }}</Button>
<IconButton :icon="Pencil" :label="t('Edit')" />

<!-- Navigation: a Link styled as a button -->
<Button as-child><Link :href="create()">{{ t('New project') }}</Link></Button>
`;

const loadingCode = `
<SubmitButton :processing="form.processing">{{ t('Save') }}</SubmitButton>
<!-- or by hand -->
<Button :disabled="loading"><Spinner v-if="loading" /> {{ t('Save') }}</Button>
`;

const groups = `
<ButtonGroup>
    <Button variant="outline">{{ t('Day') }}</Button>
    <Button variant="outline">{{ t('Week') }}</Button>
    <Button variant="outline">{{ t('Month') }}</Button>
</ButtonGroup>

<ToggleGroup v-model="align" type="single" variant="outline">
    <ToggleGroupItem value="left" :aria-label="t('Left')"><AlignLeft /></ToggleGroupItem>
    ...
</ToggleGroup>
<Toggle v-model="bold" :aria-label="t('Bold')"><Bold /></Toggle>
`;
</script>

<template>
    <Head :title="t('Buttons')" />

    <Example
        :title="t('Variants')"
        :description="
            t(
                'One primary action per view; destructive only for actions that delete.',
            )
        "
        :code="variants"
    >
        <div class="flex flex-wrap gap-2">
            <Button>{{ t('Primary') }}</Button>
            <Button variant="secondary">{{ t('Secondary') }}</Button>
            <Button variant="outline">{{ t('Outline') }}</Button>
            <Button variant="ghost">{{ t('Ghost') }}</Button>
            <Button variant="link">{{ t('Link') }}</Button>
            <Button variant="destructive">{{ t('Delete') }}</Button>
        </div>
    </Example>

    <Example :title="t('Sizes')">
        <div class="flex flex-wrap items-center gap-2">
            <Button size="sm">{{ t('Small') }}</Button>
            <Button>{{ t('Default') }}</Button>
            <Button size="lg">{{ t('Large') }}</Button>
            <Button size="icon-sm" variant="outline" :aria-label="t('Add')">
                <Plus class="size-4" />
            </Button>
            <Button size="icon" variant="outline" :aria-label="t('Add')">
                <Plus class="size-4" />
            </Button>
            <Button size="icon-lg" variant="outline" :aria-label="t('Add')">
                <Plus class="size-4" />
            </Button>
            <Button disabled>{{ t('Disabled') }}</Button>
        </div>
    </Example>

    <Example
        :title="t('With icons')"
        :description="
            t(
                'Icon-only buttons always need a label: IconButton adds it and shows it as a tooltip.',
            )
        "
        :code="withIcons"
    >
        <div class="flex flex-wrap items-center gap-2">
            <Button><Plus class="size-4" /> {{ t('New project') }}</Button>
            <Button size="sm" variant="outline">
                <Download class="size-4" /> {{ t('Export') }}
            </Button>
            <Button variant="secondary">
                {{ t('Options') }} <ChevronDown class="size-4" />
            </Button>
            <IconButton :icon="Pencil" :label="t('Edit')" />
            <IconButton :icon="Trash2" :label="t('Delete')" variant="outline" />
            <CopyButton value="https://example.test/invite/abc123" />
        </div>
    </Example>

    <Example
        :title="t('Loading')"
        :description="
            t(
                'Disable the button while the request runs to avoid double submits.',
            )
        "
        :code="loadingCode"
    >
        <div class="flex flex-wrap items-center gap-2">
            <Button :disabled="loading" @click="simulate">
                <Spinner v-if="loading" />
                {{ loading ? t('Saving…') : t('Save') }}
            </Button>
            <Button variant="outline" disabled>
                <Spinner /> {{ t('Processing') }}
            </Button>
        </div>
    </Example>

    <Example
        :title="t('Groups and toggles')"
        :description="
            t(
                'ButtonGroup joins related actions; ToggleGroup picks one option (or several with type=multiple).',
            )
        "
        :code="groups"
    >
        <div class="flex flex-wrap items-center gap-4">
            <ButtonGroup>
                <Button variant="outline">{{ t('Day') }}</Button>
                <Button variant="outline">{{ t('Week') }}</Button>
                <Button variant="outline">{{ t('Month') }}</Button>
            </ButtonGroup>
            <ToggleGroup v-model="align" type="single" variant="outline">
                <ToggleGroupItem value="left" :aria-label="t('Left')">
                    <AlignLeft class="size-4" />
                </ToggleGroupItem>
                <ToggleGroupItem value="center" :aria-label="t('Center')">
                    <AlignCenter class="size-4" />
                </ToggleGroupItem>
                <ToggleGroupItem value="right" :aria-label="t('Right')">
                    <AlignRight class="size-4" />
                </ToggleGroupItem>
            </ToggleGroup>
            <div class="flex gap-1">
                <Toggle v-model="bold" :aria-label="t('Bold')">
                    <Bold class="size-4" />
                </Toggle>
                <Toggle :aria-label="t('Italic')">
                    <Italic class="size-4" />
                </Toggle>
                <Toggle :aria-label="t('Underline')">
                    <Underline class="size-4" />
                </Toggle>
            </div>
        </div>
    </Example>

    <Example :title="t('Keyboard shortcuts')">
        <p class="flex flex-wrap items-center gap-2 text-sm">
            {{ t('Open the command palette with') }}
            <KbdGroup><Kbd>⌘</Kbd><Kbd>K</Kbd></KbdGroup>
            {{ t('or') }}
            <KbdGroup><Kbd>Ctrl</Kbd><Kbd>K</Kbd></KbdGroup>
        </p>
    </Example>
</template>
