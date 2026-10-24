<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Bold,
    Heading1,
    List,
    Monitor,
    MousePointerClick,
    RotateCcw,
    Send,
    Smartphone,
    Sparkles,
    Trash2,
} from '@lucide/vue';
import { requestJson } from '@starter/chat';
import { watchDebounced } from '@vueuse/core';
import { computed, nextTick, onMounted, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { FormField, Modal, StatusAlert, SubmitButton } from '@/components/kit';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { Input } from '@starter/ui/components/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Spinner } from '@starter/ui/components/spinner';
import {
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@starter/ui/components/tabs';
import { Textarea } from '@starter/ui/components/textarea';
import {
    ToggleGroup,
    ToggleGroupItem,
} from '@starter/ui/components/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@starter/ui/components/tooltip';
import { usePermissions } from '@/composables/usePermissions';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';
import { edit as editAutomation } from '@/routes/admin/mail/automations';
import {
    destroy,
    edit,
    generate,
    index,
    preview,
    test,
    update,
} from '@/routes/admin/mail/templates';

type Format = 'markdown' | 'html';

type Template = {
    key: string;
    name: string;
    description: string;
    custom: boolean;
    customized: boolean;
    locale: string;
    subject: string;
    body: string;
    format: Format;
};

type Variable = { name: string; description: string; sample: string };

type Preview = {
    subject: string;
    html: string;
    text: string;
    unknown: string[];
};

const props = defineProps<{
    template: Template;
    variables: Variable[];
    locales: Record<string, string>;
    usedBy: { id: number; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mail', href: index() }],
    },
});

const { can } = usePermissions();
const editable = computed(() => can('mail.manage'));

const form = useForm({
    locale: props.template.locale,
    name: props.template.custom ? props.template.name : '',
    subject: props.template.subject,
    body: props.template.body,
    format: props.template.format,
});

const save = () =>
    form.put(update(props.template.key).url, { preserveScroll: true });

const switchLocale = (locale: unknown) => {
    if (typeof locale === 'string' && locale !== props.template.locale) {
        router.get(edit(props.template.key, { query: { locale } }).url);
    }
};

// Insert variables and snippets where the cursor was (subject or body)...
const active = ref<HTMLInputElement | HTMLTextAreaElement | null>(null);

const remember = (event: FocusEvent) => {
    active.value = event.target as HTMLInputElement | HTMLTextAreaElement;
};

const bodyElement = () =>
    document.getElementById('template-body') as HTMLTextAreaElement | null;

const insert = (text: string, wrap = '') => {
    const element = active.value ?? bodyElement();
    const field: 'subject' | 'body' =
        element?.id === 'template-subject' ? 'subject' : 'body';
    const value = form[field];
    const start = element?.selectionStart ?? value.length;
    const end = element?.selectionEnd ?? value.length;
    const selected = value.slice(start, end);
    const inserted = wrap ? `${wrap}${selected || text}${wrap}` : text;

    form[field] = value.slice(0, start) + inserted + value.slice(end);

    void nextTick(() => {
        element?.focus();
        element?.setSelectionRange(
            start + inserted.length,
            start + inserted.length,
        );
    });
};

const insertVariable = (name: string) => insert(`{{ ${name} }}`);

const insertBlock = (text: string) => {
    active.value = bodyElement();
    insert(`\n\n${text}\n\n`);
};

const tools = computed(() => [
    {
        label: t('Title'),
        icon: Heading1,
        run: () => insertBlock(`# ${t('Title')}`),
        markdown: true,
    },
    {
        label: t('Bold'),
        icon: Bold,
        run: () => {
            active.value = bodyElement();
            insert(t('text'), '**');
        },
        markdown: true,
    },
    {
        label: t('List'),
        icon: List,
        run: () => insertBlock(`- ${t('First item')}\n- ${t('Second item')}`),
        markdown: true,
    },
    {
        label: t('Button'),
        icon: MousePointerClick,
        run: () => insertBlock(`[[${t('Open')}|{{ login_url }}]]`),
        markdown: false,
    },
]);

// Live preview with the sample values...
const rendered = ref<Preview | null>(null);
const previewError = ref<string | null>(null);
const previewing = ref(false);
const device = ref<'desktop' | 'phone'>('desktop');

const refresh = async () => {
    previewing.value = true;

    try {
        rendered.value = await requestJson<Preview>(
            preview(props.template.key).url,
            {
                method: 'POST',
                body: {
                    subject: form.subject,
                    body: form.body,
                    format: form.format,
                },
            },
        );
        previewError.value = null;
    } catch (error) {
        previewError.value = (error as Error).message;
    } finally {
        previewing.value = false;
    }
};

onMounted(refresh);

watchDebounced(
    () => [form.subject, form.body, form.format],
    () => void refresh(),
    { debounce: 400 },
);

// Send the editor content to oneself...
const testing = ref(false);

const sendTest = () =>
    router.post(test(props.template.key).url, form.data(), {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (testing.value = true),
        onFinish: () => (testing.value = false),
        onError: (errors) =>
            notify.error(t('Could not send the test.'), {
                description: Object.values(errors)[0],
            }),
    });

// Draft with AI (nothing is saved until "Save")...
const aiOpen = ref(false);
const aiDescription = ref('');
const aiFormat = ref<Format>(props.template.format);
const aiError = ref<string | null>(null);
const generating = ref(false);

const generateDraft = async () => {
    generating.value = true;
    aiError.value = null;

    try {
        const draft = await requestJson<{
            subject: string;
            body: string;
            format: Format;
            simulated: boolean;
            unknown: string[];
        }>(generate(props.template.key).url, {
            method: 'POST',
            body: {
                description: aiDescription.value,
                format: aiFormat.value,
                locale: form.locale,
            },
        });

        form.subject = draft.subject;
        form.body = draft.body;
        form.format = draft.format;
        aiOpen.value = false;

        if (draft.simulated) {
            notify.warning(t('Simulated draft'), {
                description: t(
                    'The simulated provider wrote it without AI. Connect a provider in AI → Connections for real drafts.',
                ),
            });
        } else {
            notify.success(t('Draft ready. Review it and save.'));
        }
    } catch (error) {
        aiError.value = (error as Error).message;
    } finally {
        generating.value = false;
    }
};

const remove = () =>
    router.delete(destroy(props.template.key).url, {
        data: { locale: props.template.custom ? undefined : form.locale },
        preserveScroll: true,
        onError: (errors) => notify.error(Object.values(errors)[0]),
    });

const unknown = computed(() => rendered.value?.unknown ?? []);
const unknownList = computed(() =>
    unknown.value.map((name) => `{{ ${name} }}`).join(', '),
);
</script>

<template>
    <Head :title="template.name" />

    <PageHeader :title="template.name" :description="template.description">
        <template #icon>
            <Button
                variant="ghost"
                size="icon"
                as-child
                :aria-label="t('Back')"
            >
                <Link :href="index()"><ArrowLeft /></Link>
            </Button>
        </template>

        <Select
            :model-value="template.locale"
            @update:model-value="switchLocale"
        >
            <SelectTrigger class="w-36" :aria-label="t('Language')">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="(label, code) in locales"
                    :key="code"
                    :value="code"
                    >{{ label }}</SelectItem
                >
            </SelectContent>
        </Select>
    </PageHeader>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <Badge v-if="template.custom" variant="secondary">{{
            t('Custom template')
        }}</Badge>
        <Badge v-else-if="template.customized">{{ t('Customized') }}</Badge>
        <Badge v-else variant="outline">{{ t('Default content') }}</Badge>
        <code class="text-xs text-muted-foreground">{{ template.key }}</code>
        <template v-if="usedBy.length">
            <span class="text-muted-foreground">·</span>
            <span class="text-muted-foreground">{{ t('Used by') }}</span>
            <Link
                v-for="automation in usedBy"
                :key="automation.id"
                :href="editAutomation(automation.id)"
                class="font-medium underline-offset-4 hover:underline"
                >{{ automation.name }}</Link
            >
        </template>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <form class="grid content-start gap-5" @submit.prevent="save">
            <FormField
                v-if="template.custom"
                :label="t('Name')"
                :error="form.errors.name"
                required
                v-slot="field"
            >
                <Input
                    v-model="form.name"
                    v-bind="field"
                    :disabled="!editable"
                />
            </FormField>

            <FormField
                id="template-subject"
                :label="t('Subject')"
                :error="form.errors.subject"
                required
                v-slot="field"
            >
                <Input
                    v-model="form.subject"
                    v-bind="field"
                    :disabled="!editable"
                    data-test="template-subject"
                    @focus="remember"
                />
            </FormField>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <ToggleGroup
                    :model-value="form.format"
                    type="single"
                    variant="outline"
                    :disabled="!editable"
                    :aria-label="t('Format')"
                    @update:model-value="
                        (value) => value && (form.format = value as Format)
                    "
                >
                    <ToggleGroupItem
                        value="markdown"
                        data-test="format-markdown"
                    >
                        {{ t('Simple') }}
                    </ToggleGroupItem>
                    <ToggleGroupItem value="html" data-test="format-html">
                        HTML
                    </ToggleGroupItem>
                </ToggleGroup>

                <div v-if="editable" class="flex items-center gap-1">
                    <Tooltip
                        v-for="tool in tools.filter(
                            (tool) =>
                                !tool.markdown || form.format === 'markdown',
                        )"
                        :key="tool.label"
                    >
                        <TooltipTrigger as-child>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="tool.label"
                                @click="tool.run"
                            >
                                <component :is="tool.icon" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>{{ tool.label }}</TooltipContent>
                    </Tooltip>
                    <Modal
                        v-model:open="aiOpen"
                        :title="t('Write with AI')"
                        :description="
                            t(
                                'Describe the email and the AI writes the subject and the body with the variables of this template. Nothing is saved until you save.',
                            )
                        "
                    >
                        <template #trigger>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                data-test="ai-open"
                            >
                                <Sparkles />
                                {{ t('Generate with AI') }}
                            </Button>
                        </template>

                        <form
                            id="ai-draft"
                            class="grid gap-4"
                            @submit.prevent="generateDraft"
                        >
                            <FormField
                                :label="t('What should the email say?')"
                                :error="aiError ?? undefined"
                                v-slot="field"
                            >
                                <Textarea
                                    v-model="aiDescription"
                                    v-bind="field"
                                    rows="5"
                                    data-test="ai-description"
                                    :placeholder="
                                        t(
                                            'Welcome new users, explain the first three steps and add a button to log in. Friendly tone.',
                                        )
                                    "
                                />
                            </FormField>
                            <FormField :label="t('Format')" v-slot="field">
                                <Select v-model="aiFormat">
                                    <SelectTrigger
                                        v-bind="field"
                                        class="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="markdown">{{
                                            t('Simple (Markdown)')
                                        }}</SelectItem>
                                        <SelectItem value="html">{{
                                            t('Designed HTML')
                                        }}</SelectItem>
                                    </SelectContent>
                                </Select>
                            </FormField>
                        </form>

                        <template #footer>
                            <SubmitButton
                                form="ai-draft"
                                :processing="generating"
                                :disabled="aiDescription.trim().length < 10"
                                data-test="ai-generate"
                            >
                                <Sparkles />
                                {{ t('Generate draft') }}
                            </SubmitButton>
                        </template>
                    </Modal>
                </div>
            </div>

            <FormField
                id="template-body"
                :label="t('Body')"
                :error="form.errors.body"
                required
                v-slot="field"
                :description="
                    form.format === 'markdown'
                        ? t(
                              'Write like a message: # title, **bold**, - list. [[Text|link]] on its own line is a button.',
                          )
                        : t(
                              'Your HTML is sent as is (scripts and event handlers are removed). [[Text|link]] on its own line is a button.',
                          )
                "
            >
                <Textarea
                    v-model="form.body"
                    v-bind="field"
                    rows="18"
                    spellcheck="false"
                    class="font-mono text-sm"
                    :disabled="!editable"
                    data-test="template-body"
                    @focus="remember"
                />
            </FormField>

            <div class="space-y-2">
                <p class="text-sm font-medium">{{ t('Variables') }}</p>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Click one to insert it where the cursor is. The preview uses sample values.',
                        )
                    }}
                </p>
                <div class="flex flex-wrap gap-2" data-test="variables">
                    <Tooltip v-for="variable in variables" :key="variable.name">
                        <TooltipTrigger as-child>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                class="h-7 font-mono text-xs"
                                :disabled="!editable"
                                @click="insertVariable(variable.name)"
                                >{{ variable.name }}</Button
                            >
                        </TooltipTrigger>
                        <TooltipContent>
                            {{ variable.description }} ·
                            <span class="font-mono">{{ variable.sample }}</span>
                        </TooltipContent>
                    </Tooltip>
                </div>
            </div>

            <StatusAlert
                v-if="unknown.length"
                tone="warning"
                :title="t('Unknown variables')"
                data-test="unknown-variables"
            >
                {{
                    t(
                        'These placeholders are not variables of this template and would be sent as is: :variables.',
                        { variables: unknownList },
                    )
                }}
            </StatusAlert>

            <div v-if="editable" class="flex flex-wrap items-center gap-2">
                <SubmitButton
                    :processing="form.processing"
                    data-test="template-save"
                    >{{ t('Save') }}</SubmitButton
                >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="testing"
                    data-test="template-test"
                    @click="sendTest"
                >
                    <Spinner v-if="testing" />
                    <Send v-else />
                    {{ t('Send me a test') }}
                </Button>
                <ConfirmDialog
                    v-if="!template.custom && template.customized"
                    :title="t('Restore the default content?')"
                    :description="
                        t(
                            'Your changes in this language are discarded and the template goes back to the content of the platform.',
                        )
                    "
                    :confirm-text="t('Restore')"
                    @confirm="remove"
                >
                    <Button type="button" variant="ghost">
                        <RotateCcw />
                        {{ t('Restore default') }}
                    </Button>
                </ConfirmDialog>
                <ConfirmDialog
                    v-if="template.custom"
                    :title="t('Delete this template?')"
                    :description="
                        t(
                            'It is deleted in every language. Templates used by an automation cannot be deleted.',
                        )
                    "
                    :confirm-text="t('Delete')"
                    @confirm="remove"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        class="text-destructive"
                    >
                        <Trash2 />
                        {{ t('Delete') }}
                    </Button>
                </ConfirmDialog>
            </div>
        </form>

        <section
            class="flex min-w-0 flex-col gap-3 xl:sticky xl:top-4 xl:self-start"
            :aria-label="t('Preview')"
        >
            <div class="flex items-center justify-between gap-3">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    {{ t('Preview') }}
                    <Spinner v-if="previewing" class="size-3.5" />
                </h2>
                <ToggleGroup
                    v-model="device"
                    type="single"
                    variant="outline"
                    size="sm"
                    :aria-label="t('Screen width')"
                >
                    <ToggleGroupItem
                        value="desktop"
                        :aria-label="t('Computer')"
                    >
                        <Monitor />
                    </ToggleGroupItem>
                    <ToggleGroupItem value="phone" :aria-label="t('Phone')">
                        <Smartphone />
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            <StatusAlert
                v-if="previewError"
                tone="error"
                :title="t('The preview failed')"
                >{{ previewError }}</StatusAlert
            >

            <div class="overflow-hidden rounded-lg border bg-card">
                <div class="space-y-1 border-b px-4 py-3 text-sm">
                    <p class="text-muted-foreground">{{ t('Subject') }}</p>
                    <p class="font-medium" data-test="preview-subject">
                        {{ rendered?.subject || '—' }}
                    </p>
                </div>

                <Tabs default-value="html">
                    <TabsList class="m-2">
                        <TabsTrigger value="html">{{ t('Email') }}</TabsTrigger>
                        <TabsTrigger value="text">{{
                            t('Plain text')
                        }}</TabsTrigger>
                    </TabsList>
                    <TabsContent value="html" class="m-0">
                        <div class="flex justify-center bg-muted/40 p-2">
                            <iframe
                                v-if="rendered"
                                :srcdoc="rendered.html"
                                sandbox=""
                                :title="t('Email preview')"
                                data-test="preview-frame"
                                class="h-[620px] w-full rounded-md border bg-white transition-[max-width]"
                                :class="
                                    device === 'phone'
                                        ? 'max-w-[375px]'
                                        : 'max-w-full'
                                "
                            />
                        </div>
                    </TabsContent>
                    <TabsContent value="text" class="m-0">
                        <pre
                            class="max-h-[620px] overflow-auto p-4 text-sm whitespace-pre-wrap"
                            >{{ rendered?.text }}</pre>
                    </TabsContent>
                </Tabs>
            </div>
        </section>
    </div>
</template>
