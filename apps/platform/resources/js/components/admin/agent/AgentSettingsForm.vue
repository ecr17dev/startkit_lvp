<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@starter/ui/components/badge';
import { Checkbox } from '@starter/ui/components/checkbox';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Switch } from '@starter/ui/components/switch';
import {
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@starter/ui/components/tabs';
import { Textarea } from '@starter/ui/components/textarea';
import { t } from '@/lib/i18n';
import type { AgentConfig, AgentOptions, AgentToolOption } from '@/types';

export type AgentForm = { config: AgentConfig; notes: string };

const props = defineProps<{
    form: InertiaForm<AgentForm>;
    options: AgentOptions;
}>();

const config = computed(() => props.form.config);

const toggleCollection = (id: number, checked: boolean | string) => {
    const current = config.value.rag.collections;
    config.value.rag.collections = checked
        ? [...new Set([...current, id])]
        : current.filter((item) => item !== id);
};
const error = (key: string) =>
    (props.form.errors as Record<string, string | undefined>)[`config.${key}`];

const languageLabels: Record<string, string> = {
    auto: 'Same as the user',
    es: 'Spanish',
    en: 'English',
};
const toneLabels: Record<string, string> = {
    neutral: 'Neutral',
    formal: 'Formal',
    friendly: 'Friendly',
    concise: 'Concise',
};
const styleLabels: Record<string, string> = {
    markdown: 'Markdown',
    plain: 'Plain text',
};
const surfaceLabels: Record<string, string> = {
    page: 'Chat page',
    floating: 'Floating chat',
    api: 'REST API',
};
const kindLabels: Record<string, string> = {
    read: 'Read',
    write: 'Change',
    sensitive: 'Sensitive',
};
const groupLabels: Record<string, string> = {
    users: 'Users',
    roles: 'Roles',
    permissions: 'Permissions',
};
const capabilityLabels: Record<string, string> = {
    supported: 'supported',
    unsupported: 'not supported',
    unknown: 'unknown',
};

const toggle = (list: string[], value: string, checked: boolean | string) => {
    const index = list.indexOf(value);

    if (checked && index === -1) list.push(value);
    if (!checked && index !== -1) list.splice(index, 1);
};

const toggleNumber = (
    list: number[],
    value: number,
    checked: boolean | string,
) => {
    const index = list.indexOf(value);

    if (checked && index === -1) list.push(value);
    if (!checked && index !== -1) list.splice(index, 1);
};

const modelId = computed({
    get: () =>
        config.value.execution.model_id === null
            ? 'default'
            : String(config.value.execution.model_id),
    set: (value: string) => {
        config.value.execution.model_id =
            value === 'default' ? null : Number(value);
    },
});

const transcriptionModelId = computed({
    get: () =>
        config.value.microphone.transcription_model_id === null
            ? 'none'
            : String(config.value.microphone.transcription_model_id),
    set: (value: string) => {
        config.value.microphone.transcription_model_id =
            value === 'none' ? null : Number(value);
    },
});

const selectedModel = computed(() =>
    config.value.execution.model_id === null
        ? (props.options.models.find((model) => model.is_default) ??
          props.options.models[0])
        : props.options.models.find(
              (model) => model.id === config.value.execution.model_id,
          ),
);

const toolGroups = computed(() => {
    const groups: Record<string, AgentToolOption[]> = {};

    for (const tool of props.options.tools) {
        (groups[tool.group] ??= []).push(tool);
    }

    return Object.entries(groups);
});

const placeholder = (name: string) => '{{' + name + '}}';

const insertVariable = (name: string) => {
    config.value.instructions.system_prompt += placeholder(name);

    if (!config.value.instructions.variables.includes(name)) {
        config.value.instructions.variables.push(name);
    }
};
</script>

<template>
    <Tabs default-value="identity" class="gap-4">
        <TabsList class="h-auto flex-wrap">
            <TabsTrigger value="identity">{{ t('Identity') }}</TabsTrigger>
            <TabsTrigger value="instructions">{{
                t('Instructions')
            }}</TabsTrigger>
            <TabsTrigger value="execution">{{ t('Model') }}</TabsTrigger>
            <TabsTrigger value="media">{{ t('Context & media') }}</TabsTrigger>
            <TabsTrigger value="tools">{{ t('Tools') }}</TabsTrigger>
            <TabsTrigger value="access">{{ t('Access & limits') }}</TabsTrigger>
        </TabsList>

        <TabsContent value="identity" class="grid gap-4">
            <div class="grid gap-4 sm:grid-cols-[8rem_1fr]">
                <div class="grid gap-2">
                    <Label for="avatar">{{ t('Avatar') }}</Label>
                    <Input
                        id="avatar"
                        v-model="config.identity.avatar"
                        maxlength="8"
                        placeholder="✨"
                    />
                    <InputError :message="error('identity.avatar')" />
                </div>
                <div class="grid gap-2">
                    <Label for="greeting">{{ t('Greeting') }}</Label>
                    <Input
                        id="greeting"
                        v-model="config.identity.greeting"
                        maxlength="500"
                    />
                    <InputError :message="error('identity.greeting')" />
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label>{{ t('Language') }}</Label>
                    <Select v-model="config.identity.language">
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="language in options.languages"
                                :key="language"
                                :value="language"
                                >{{
                                    t(languageLabels[language] ?? language)
                                }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label>{{ t('Tone') }}</Label>
                    <Select v-model="config.identity.tone">
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="tone in options.tones"
                                :key="tone"
                                :value="tone"
                                >{{ t(toneLabels[tone] ?? tone) }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
            </div>
        </TabsContent>

        <TabsContent value="instructions" class="grid gap-4">
            <div class="grid gap-2">
                <Label for="system_prompt">{{ t('System prompt') }}</Label>
                <Textarea
                    id="system_prompt"
                    v-model="config.instructions.system_prompt"
                    rows="14"
                    class="font-mono text-sm"
                />
                <InputError :message="error('instructions.system_prompt')" />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('Allowed variables') }}</Label>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Only the checked variables are replaced; any other placeholder stays literal. Click a name to insert it.',
                        )
                    }}
                </p>
                <div class="flex flex-wrap gap-3">
                    <div
                        v-for="variable in options.variables"
                        :key="variable"
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :id="`var-${variable}`"
                            :model-value="
                                config.instructions.variables.includes(variable)
                            "
                            @update:model-value="
                                (checked) =>
                                    toggle(
                                        config.instructions.variables,
                                        variable,
                                        checked,
                                    )
                            "
                        />
                        <button
                            type="button"
                            class="font-mono text-xs hover:underline"
                            @click="insertVariable(variable)"
                        >
                            {{ placeholder(variable) }}
                        </button>
                    </div>
                </div>
            </div>
            <div class="grid max-w-xs gap-2">
                <Label>{{ t('Answer format') }}</Label>
                <Select v-model="config.responses.style">
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="style in options.styles"
                            :key="style"
                            :value="style"
                            >{{ t(styleLabels[style] ?? style) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
        </TabsContent>

        <TabsContent value="execution" class="grid gap-4">
            <div class="grid gap-2">
                <Label>{{ t('Model') }}</Label>
                <Select v-model="modelId">
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="default">{{
                            t('Catalog default model')
                        }}</SelectItem>
                        <SelectItem
                            v-for="model in options.models"
                            :key="model.id"
                            :value="String(model.id)"
                            >{{ model.label }} ·
                            {{ model.connection }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <p
                    v-if="selectedModel?.capabilities"
                    class="flex flex-wrap gap-2 text-xs text-muted-foreground"
                >
                    <span
                        v-for="(
                            status, capability
                        ) in selectedModel.capabilities"
                        :key="capability"
                        >{{ t(capability) }}:
                        {{ t(capabilityLabels[status] ?? status) }}</span
                    >
                </p>
                <InputError :message="error('execution.model_id')" />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('Fallback models (up to 3)') }}</Label>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Used in order when the model is rate limited, overloaded or unreachable after the retries.',
                        )
                    }}
                </p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <Label
                        v-for="model in options.models"
                        :key="model.id"
                        class="flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            :model-value="
                                config.execution.fallback_model_ids.includes(
                                    model.id,
                                )
                            "
                            :disabled="
                                !config.execution.fallback_model_ids.includes(
                                    model.id,
                                ) &&
                                config.execution.fallback_model_ids.length >= 3
                            "
                            @update:model-value="
                                (checked) =>
                                    toggleNumber(
                                        config.execution.fallback_model_ids,
                                        model.id,
                                        checked,
                                    )
                            "
                        />
                        {{ model.label }}
                        <span class="text-xs text-muted-foreground">{{
                            model.connection
                        }}</span>
                    </Label>
                </div>
                <InputError :message="error('execution.fallback_model_ids')" />
            </div>
            <div class="grid items-end gap-4 sm:grid-cols-4">
                <div class="grid gap-2">
                    <Label for="timeout">{{ t('Timeout (s)') }}</Label>
                    <Input
                        id="timeout"
                        v-model.number="config.execution.timeout"
                        type="number"
                        min="5"
                        max="600"
                    />
                    <InputError :message="error('execution.timeout')" />
                </div>
                <div class="grid gap-2">
                    <Label for="retries">{{ t('Retries') }}</Label>
                    <Input
                        id="retries"
                        v-model.number="config.execution.retries"
                        type="number"
                        min="0"
                        max="3"
                    />
                    <InputError :message="error('execution.retries')" />
                </div>
                <div class="grid gap-2">
                    <Label for="max_tokens">{{ t('Max output tokens') }}</Label>
                    <Input
                        id="max_tokens"
                        v-model.number="config.responses.max_tokens"
                        type="number"
                        min="16"
                    />
                    <InputError :message="error('responses.max_tokens')" />
                </div>
                <div class="grid gap-2">
                    <Label for="temperature">{{ t('Temperature') }}</Label>
                    <Input
                        id="temperature"
                        v-model.number="config.responses.temperature"
                        type="number"
                        min="0"
                        max="2"
                        step="0.1"
                        :placeholder="t('Model default')"
                    />
                    <InputError :message="error('responses.temperature')" />
                </div>
            </div>
        </TabsContent>

        <TabsContent value="media" class="grid gap-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="history">{{
                        t('History messages sent')
                    }}</Label>
                    <Input
                        id="history"
                        v-model.number="config.context.history_messages"
                        type="number"
                        min="0"
                        max="200"
                    />
                    <InputError :message="error('context.history_messages')" />
                </div>
                <div class="grid gap-2">
                    <Label for="retention">{{
                        t('Conversation retention (days)')
                    }}</Label>
                    <Input
                        id="retention"
                        v-model.number="config.context.retention_days"
                        type="number"
                        min="1"
                        :placeholder="t('Keep')"
                    />
                    <InputError :message="error('context.retention_days')" />
                </div>
            </div>

            <div class="grid gap-3 rounded-lg border p-4">
                <p class="text-sm font-medium">{{ t('Attachments') }}</p>
                <div class="flex items-center justify-between gap-4">
                    <Label for="images">{{ t('Allow images') }}</Label>
                    <Switch id="images" v-model="config.multimedia.images" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <Label for="documents">{{ t('Allow documents') }}</Label>
                    <Switch
                        id="documents"
                        v-model="config.multimedia.documents"
                    />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="max_files">{{
                            t('Files per message')
                        }}</Label>
                        <Input
                            id="max_files"
                            v-model.number="config.multimedia.max_files"
                            type="number"
                            min="1"
                            max="10"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="max_file_mb">{{
                            t('Maximum size per file (MB)')
                        }}</Label>
                        <Input
                            id="max_file_mb"
                            v-model.number="config.multimedia.max_file_mb"
                            type="number"
                            min="1"
                            max="20"
                        />
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <Label
                        v-for="type in [
                            ...options.image_types,
                            ...options.document_types,
                        ]"
                        :key="type"
                        class="flex items-center gap-2 font-mono text-xs font-normal"
                    >
                        <Checkbox
                            :model-value="
                                config.multimedia.mime_types.includes(type)
                            "
                            @update:model-value="
                                (checked) =>
                                    toggle(
                                        config.multimedia.mime_types,
                                        type,
                                        checked,
                                    )
                            "
                        />
                        {{ type }}
                    </Label>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Files are checked by content, size and model capability. They are never added to a knowledge collection automatically.',
                        )
                    }}
                </p>
            </div>

            <div class="grid gap-3 rounded-lg border p-4">
                <div class="flex items-center justify-between gap-4">
                    <Label for="microphone" class="text-sm font-medium">{{
                        t('Microphone (transcription)')
                    }}</Label>
                    <Switch
                        id="microphone"
                        v-model="config.microphone.enabled"
                    />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label>{{ t('Transcription model') }}</Label>
                        <Select v-model="transcriptionModelId">
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">{{
                                    t('None')
                                }}</SelectItem>
                                <SelectItem
                                    v-for="model in options.transcription_models"
                                    :key="model.id"
                                    :value="String(model.id)"
                                    >{{ model.label }} ·
                                    {{ model.connection }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError
                            :message="
                                error('microphone.transcription_model_id')
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="mic_language">{{
                            t('Audio language')
                        }}</Label>
                        <Input
                            id="mic_language"
                            v-model="config.microphone.language"
                            maxlength="10"
                            :placeholder="t('Detect')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="max_seconds">{{
                            t('Maximum duration (s)')
                        }}</Label>
                        <Input
                            id="max_seconds"
                            v-model.number="config.microphone.max_seconds"
                            type="number"
                            min="5"
                            max="600"
                        />
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <Label for="auto_send">{{
                            t('Send automatically after transcribing')
                        }}</Label>
                        <Switch
                            id="auto_send"
                            v-model="config.microphone.auto_send"
                        />
                    </div>
                </div>
            </div>

            <div class="grid gap-4 rounded-lg border p-4" data-test="agent-rag">
                <div class="flex items-center justify-between gap-4">
                    <div class="grid gap-1">
                        <p class="text-sm font-medium">
                            {{ t('Knowledge (RAG)') }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'Answers grounded on authorized collections, with validated [n] citations. The access lists of each user apply inside the search.',
                                )
                            }}
                        </p>
                    </div>
                    <Select
                        v-model="config.rag.mode"
                        :disabled="!options.knowledge.feature"
                    >
                        <SelectTrigger class="w-56" data-test="agent-rag-mode">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="off">{{ t('Off') }}</SelectItem>
                            <SelectItem value="always">{{
                                t('Search before every message')
                            }}</SelectItem>
                            <SelectItem value="tool">{{
                                t('The model decides (tool)')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <p
                    v-if="!options.knowledge.feature"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t(
                            'Knowledge is disabled in this installation (STARTER_FEATURE_RAG).',
                        )
                    }}
                </p>
                <p
                    v-else-if="!options.knowledge.available"
                    class="text-xs text-amber-600 dark:text-amber-400"
                >
                    {{
                        t(
                            'Knowledge (RAG) is not active: the agent answers without retrieval until it is activated.',
                        )
                    }}
                </p>
                <template v-if="config.rag.mode !== 'off'">
                    <div class="grid gap-2">
                        <Label>{{ t('Collections') }}</Label>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <Label
                                v-for="collection in options.knowledge
                                    .collections"
                                :key="collection.id"
                                class="flex items-center gap-2 rounded-md border p-2 text-sm font-normal"
                            >
                                <Checkbox
                                    :model-value="
                                        config.rag.collections.includes(
                                            collection.id,
                                        )
                                    "
                                    @update:model-value="
                                        (checked) =>
                                            toggleCollection(
                                                collection.id,
                                                checked,
                                            )
                                    "
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    collection.name
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    collection.acl.join(', ')
                                }}</span>
                            </Label>
                        </div>
                        <p
                            v-if="!options.knowledge.collections.length"
                            class="text-xs text-muted-foreground"
                        >
                            {{ t('No collections yet.') }}
                        </p>
                        <InputError :message="error('rag.collections')" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="grid gap-2">
                            <Label>{{ t('Method') }}</Label>
                            <Select
                                :model-value="config.rag.method ?? 'default'"
                                @update:model-value="
                                    (value) =>
                                        (config.rag.method =
                                            value === 'default'
                                                ? null
                                                : (value as
                                                      | 'vector'
                                                      | 'text'
                                                      | 'hybrid'))
                                "
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="default">{{
                                        t('Collection setting')
                                    }}</SelectItem>
                                    <SelectItem value="hybrid">{{
                                        t('Hybrid (RRF)')
                                    }}</SelectItem>
                                    <SelectItem value="vector">{{
                                        t('Vector')
                                    }}</SelectItem>
                                    <SelectItem value="text">{{
                                        t('Full text')
                                    }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="rag-top-k">Top-k</Label>
                            <Input
                                id="rag-top-k"
                                v-model.number="config.rag.top_k"
                                type="number"
                                min="1"
                                max="20"
                            />
                            <InputError :message="error('rag.top_k')" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rag-context">{{
                                t('Context budget (tokens)')
                            }}</Label>
                            <Input
                                id="rag-context"
                                v-model.number="config.rag.max_context_tokens"
                                type="number"
                                min="200"
                                max="32000"
                            />
                            <InputError
                                :message="error('rag.max_context_tokens')"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rag-min-score">{{
                                t('Minimum similarity')
                            }}</Label>
                            <Input
                                id="rag-min-score"
                                v-model.number="config.rag.min_score"
                                type="number"
                                min="0"
                                max="1"
                                step="0.01"
                                :placeholder="t('none')"
                            />
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label>{{ t('Without enough evidence') }}</Label>
                            <Select v-model="config.rag.evidence">
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="required">{{
                                        t('Abstain (evidence required)')
                                    }}</SelectItem>
                                    <SelectItem value="general">{{
                                        t(
                                            'Answer in general terms, clearly identified',
                                        )
                                    }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="rag-min-hits">{{
                                t('Minimum passages')
                            }}</Label>
                            <Input
                                id="rag-min-hits"
                                v-model.number="config.rag.min_hits"
                                type="number"
                                min="1"
                                max="10"
                            />
                        </div>
                    </div>
                </template>
            </div>
        </TabsContent>

        <TabsContent value="tools" class="grid gap-4">
            <p
                v-if="!options.tools.length"
                class="text-sm text-muted-foreground"
            >
                {{ t('No tools are registered in this application.') }}
            </p>
            <div
                v-for="[group, tools] in toolGroups"
                :key="group"
                class="rounded-lg border"
            >
                <p class="border-b bg-muted/50 px-4 py-2 text-sm font-semibold">
                    {{ t(groupLabels[group] ?? group) }}
                </p>
                <div class="grid gap-2 p-4 sm:grid-cols-2">
                    <Label
                        v-for="tool in tools"
                        :key="tool.name"
                        class="flex items-start gap-2 font-normal"
                    >
                        <Checkbox
                            :model-value="
                                config.tools.allowed.includes(tool.name)
                            "
                            @update:model-value="
                                (checked) =>
                                    toggle(
                                        config.tools.allowed,
                                        tool.name,
                                        checked,
                                    )
                            "
                        />
                        <span class="grid gap-0.5">
                            <span class="flex items-center gap-2">
                                {{ t(tool.label) }}
                                <Badge
                                    :variant="
                                        tool.kind === 'sensitive'
                                            ? 'destructive'
                                            : tool.kind === 'write'
                                              ? 'secondary'
                                              : 'outline'
                                    "
                                    >{{ t(kindLabels[tool.kind]) }}</Badge
                                >
                            </span>
                            <code class="text-xs text-muted-foreground">{{
                                tool.name
                            }}</code>
                        </span>
                    </Label>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="max_steps">{{ t('Maximum tool steps') }}</Label>
                    <Input
                        id="max_steps"
                        v-model.number="config.tools.max_steps"
                        type="number"
                        min="1"
                        max="20"
                    />
                    <InputError :message="error('tools.max_steps')" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <Label for="confirm_writes">{{
                        t('Confirm every change, not only sensitive ones')
                    }}</Label>
                    <Switch
                        id="confirm_writes"
                        v-model="config.tools.confirm_writes"
                    />
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'A tool is only offered to users holding its permission, and the service authorizes again when it runs. Deletions and role or permission changes always wait for a confirmed proposal.',
                    )
                }}
            </p>
            <InputError :message="error('tools.allowed')" />
        </TabsContent>

        <TabsContent value="access" class="grid gap-6">
            <div class="grid gap-2">
                <Label>{{ t('Roles') }}</Label>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Leave empty to allow everyone with chat access (ai.chat).',
                        )
                    }}
                </p>
                <div class="flex flex-wrap gap-3">
                    <Label
                        v-for="role in options.roles"
                        :key="role"
                        class="flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            :model-value="config.access.roles.includes(role)"
                            @update:model-value="
                                (checked) =>
                                    toggle(config.access.roles, role, checked)
                            "
                        />
                        {{ role }}
                    </Label>
                </div>
                <InputError :message="error('access.roles')" />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('Available in') }}</Label>
                <div class="flex flex-wrap gap-3">
                    <Label
                        v-for="surface in options.surfaces"
                        :key="surface"
                        class="flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            :model-value="
                                config.access.surfaces.includes(surface)
                            "
                            @update:model-value="
                                (checked) =>
                                    toggle(
                                        config.access.surfaces,
                                        surface,
                                        checked,
                                    )
                            "
                        />
                        {{ t(surfaceLabels[surface] ?? surface) }}
                    </Label>
                </div>
                <InputError :message="error('access.surfaces')" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="rpm">{{
                        t('Messages per minute per user')
                    }}</Label>
                    <Input
                        id="rpm"
                        v-model.number="config.limits.requests_per_minute"
                        type="number"
                        min="1"
                        :placeholder="t('Unlimited')"
                    />
                    <InputError
                        :message="error('limits.requests_per_minute')"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="concurrent">{{
                        t('Simultaneous answers per user')
                    }}</Label>
                    <Input
                        id="concurrent"
                        v-model.number="config.limits.concurrent_per_user"
                        type="number"
                        min="1"
                        :placeholder="t('Unlimited')"
                    />
                    <InputError
                        :message="error('limits.concurrent_per_user')"
                    />
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Spending limits per agent are configured as budgets with the "One agent" scope.',
                    )
                }}
            </p>
        </TabsContent>
    </Tabs>
</template>
