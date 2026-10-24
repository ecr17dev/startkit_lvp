<script setup lang="ts">
import {
    Check,
    FileText,
    LoaderCircle,
    Mic,
    Paperclip,
    SendHorizontal,
    Square,
    X,
} from '@lucide/vue';
import { xsrfToken } from '@starter/chat';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Button } from '@starter/ui/components/button';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Textarea } from '@starter/ui/components/textarea';
import type { UseChat } from '@/composables/useChat';
import { useRecorder } from '@/composables/useRecorder';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { store as transcriptionsStore } from '@/routes/ai/transcriptions';

const props = defineProps<{
    chat: UseChat;
    ready: boolean;
    compact?: boolean;
    surface: 'page' | 'floating';
}>();

type Pending = { file: File; preview: string | null };

const input = ref('');
const files = ref<Pending[]>([]);
const localError = ref<string | null>(null);
const transcribing = ref(false);
const picker = ref<HTMLInputElement | null>(null);
let transcription: AbortController | null = null;

const recorder = useRecorder();
const bootstrap = computed(() => props.chat.bootstrap.value);
const agent = computed(() => props.chat.agent.value);
const busy = computed(() => props.chat.streaming.value);
const attachments = computed(() =>
    agent.value?.attachments?.mime_types.length
        ? agent.value.attachments
        : null,
);
const microphone = computed(() => agent.value?.microphone ?? null);

const connections = computed(() => [
    ...new Set(
        (bootstrap.value?.models ?? []).map((model) => model.connection),
    ),
]);

const agentValue = computed({
    get: () =>
        props.chat.agentId.value ? String(props.chat.agentId.value) : '',
    set: (value: string) => {
        props.chat.agentId.value = value ? Number(value) : null;
        clearFiles();
    },
});

const modelValue = computed({
    get: () =>
        props.chat.modelId.value ? String(props.chat.modelId.value) : 'default',
    set: (value: string) =>
        (props.chat.modelId.value = value === 'default' ? null : Number(value)),
});

const formatSize = (bytes: number) =>
    bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / 1024 / 1024).toFixed(1)} MB`;

const clearFiles = () => {
    files.value.forEach(
        (item) => item.preview && URL.revokeObjectURL(item.preview),
    );
    files.value = [];
};

onBeforeUnmount(() => {
    clearFiles();
    transcription?.abort();
});

const addFiles = (event: Event) => {
    const target = event.target as HTMLInputElement;
    const selected = Array.from(target.files ?? []);
    target.value = '';
    localError.value = null;

    const rules = attachments.value;

    if (!rules) {
        return;
    }

    for (const file of selected) {
        if (files.value.length >= rules.max_files) {
            localError.value = t('You can attach up to :max files.').replace(
                ':max',
                String(rules.max_files),
            );
            break;
        }

        if (!rules.mime_types.includes(file.type)) {
            localError.value = `${file.name}: ${t('This type of file is not accepted by the agent.')}`;
            continue;
        }

        if (file.size > rules.max_file_mb * 1024 * 1024) {
            localError.value = `${file.name}: ${t('The file is larger than :max MB.').replace(':max', String(rules.max_file_mb))}`;
            continue;
        }

        files.value.push({
            file,
            preview: file.type.startsWith('image/')
                ? URL.createObjectURL(file)
                : null,
        });
    }
};

const removeFile = (index: number) => {
    const [item] = files.value.splice(index, 1);

    if (item?.preview) {
        URL.revokeObjectURL(item.preview);
    }
};

const submit = async () => {
    if (
        !props.ready ||
        busy.value ||
        (!input.value.trim() && !files.value.length)
    ) {
        return;
    }

    const message = input.value;
    const selected = files.value.map((item) => item.file);

    input.value = '';
    clearFiles();
    localError.value = null;

    await props.chat.sendMessage(message, selected);
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void submit();
    }
};

const record = async () => {
    if (!microphone.value) {
        return;
    }

    localError.value = null;
    const recording = await recorder.start(microphone.value.max_seconds);

    if (!recording) {
        return;
    }

    transcribing.value = true;
    transcription = new AbortController();

    const form = new FormData();
    form.append('audio', recording.blob, `recording.${recording.extension}`);
    form.append('duration', recording.duration.toFixed(1));
    form.append('surface', props.surface);

    if (props.chat.agentId.value) {
        form.append('agent_id', String(props.chat.agentId.value));
    }

    try {
        const response = await fetch(transcriptionsStore().url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: form,
            signal: transcription.signal,
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            const errors = data?.errors
                ? Object.values(data.errors).flat().join(' ')
                : null;
            localError.value =
                errors || data?.message || `HTTP ${response.status}`;

            return;
        }

        const text = String(data.text ?? '').trim();
        input.value = input.value.trim()
            ? `${input.value.trim()} ${text}`
            : text;

        if (microphone.value?.auto_send && text) {
            await submit();
        }
    } catch (exception) {
        if ((exception as Error).name !== 'AbortError') {
            localError.value = (exception as Error).message;
        }
    } finally {
        transcribing.value = false;
        transcription = null;
    }
};

const cancelTranscription = () => transcription?.abort();

const clock = computed(() => {
    const value = recorder.seconds.value;

    return `${Math.floor(value / 60)}:${String(value % 60).padStart(2, '0')}`;
});

const error = computed(
    () => localError.value ?? recorder.error.value ?? props.chat.error.value,
);
</script>

<template>
    <form
        :class="
            cn('mx-auto grid gap-2 py-3', compact ? 'px-3' : 'max-w-3xl px-4')
        "
        @submit.prevent="submit"
    >
        <div
            v-if="
                bootstrap &&
                (bootstrap.agents.length > 1 ||
                    (bootstrap.canChooseModel && bootstrap.models.length))
            "
            class="flex flex-wrap gap-2"
        >
            <Select v-if="bootstrap.agents.length > 1" v-model="agentValue">
                <SelectTrigger size="sm" class="w-44">
                    <SelectValue :placeholder="t('Agent')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in bootstrap.agents"
                        :key="option.id"
                        :value="String(option.id)"
                        >{{ option.avatar ? `${option.avatar} ` : ''
                        }}{{ option.name }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select
                v-if="bootstrap.canChooseModel && bootstrap.models.length"
                v-model="modelValue"
            >
                <SelectTrigger size="sm" class="w-52">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="default">{{
                        t('Agent model')
                    }}</SelectItem>
                    <SelectGroup
                        v-for="connection in connections"
                        :key="connection"
                    >
                        <SelectLabel>{{ connection }}</SelectLabel>
                        <SelectItem
                            v-for="model in bootstrap.models.filter(
                                (m) => m.connection === connection,
                            )"
                            :key="model.id"
                            :value="String(model.id)"
                            >{{ model.label }}</SelectItem
                        >
                    </SelectGroup>
                </SelectContent>
            </Select>
        </div>

        <div v-if="files.length" class="flex flex-wrap gap-2">
            <div
                v-for="(item, index) in files"
                :key="index"
                class="flex items-center gap-2 rounded-md border bg-muted/40 p-1 pr-2 text-xs"
            >
                <img
                    v-if="item.preview"
                    :src="item.preview"
                    :alt="item.file.name"
                    class="size-8 rounded object-cover"
                />
                <FileText v-else class="size-6 text-muted-foreground" />
                <span class="max-w-36 truncate">{{ item.file.name }}</span>
                <span class="text-muted-foreground">{{
                    formatSize(item.file.size)
                }}</span>
                <button
                    type="button"
                    class="rounded p-0.5 text-muted-foreground hover:text-destructive"
                    :aria-label="t('Remove')"
                    @click="removeFile(index)"
                >
                    <X class="size-3.5" />
                </button>
            </div>
        </div>

        <div
            v-if="recorder.recording.value || transcribing"
            class="flex items-center gap-3 rounded-md border bg-muted/40 px-3 py-2 text-sm"
        >
            <template v-if="recorder.recording.value">
                <span
                    class="size-2.5 animate-pulse rounded-full bg-destructive"
                />
                <span class="font-mono">{{ clock }}</span>
                <span class="text-muted-foreground"
                    >/ {{ microphone?.max_seconds }} s</span
                >
                <span class="flex-1" />
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="recorder.cancel()"
                >
                    <X class="size-4" />
                    {{ t('Cancel') }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    data-test="chat-mic-done"
                    @click="recorder.stop()"
                >
                    <Check class="size-4" />
                    {{ t('Done') }}
                </Button>
            </template>
            <template v-else>
                <LoaderCircle class="size-4 animate-spin" />
                <span class="flex-1">{{ t('Transcribing…') }}</span>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="cancelTranscription"
                >
                    <X class="size-4" />
                    {{ t('Cancel') }}
                </Button>
            </template>
        </div>

        <div class="relative">
            <Textarea
                v-model="input"
                :rows="compact ? 2 : 3"
                class="resize-none pr-24"
                :disabled="!ready"
                :placeholder="
                    t(
                        'Write a message… (Enter to send, Shift+Enter for a new line)',
                    )
                "
                data-test="chat-input"
                @keydown="onKeydown"
            />
            <div class="absolute right-2 bottom-2 flex items-center gap-1">
                <template v-if="attachments">
                    <input
                        ref="picker"
                        type="file"
                        class="hidden"
                        multiple
                        :accept="attachments.mime_types.join(',')"
                        data-test="chat-files"
                        @change="addFiles"
                    />
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        :disabled="!ready || busy"
                        :aria-label="t('Attach files')"
                        @click="picker?.click()"
                    >
                        <Paperclip class="size-4" />
                    </Button>
                </template>
                <Button
                    v-if="microphone"
                    type="button"
                    size="icon"
                    variant="ghost"
                    :disabled="
                        !ready ||
                        busy ||
                        recorder.recording.value ||
                        transcribing
                    "
                    :aria-label="t('Record a voice message')"
                    data-test="chat-mic"
                    @click="record"
                >
                    <Mic class="size-4" />
                </Button>
                <Button
                    v-if="busy"
                    type="button"
                    size="icon"
                    variant="secondary"
                    :aria-label="t('Stop')"
                    @click="chat.stop()"
                >
                    <Square class="size-4" />
                </Button>
                <Button
                    v-else
                    type="submit"
                    size="icon"
                    :disabled="!ready || (!input.trim() && !files.length)"
                    :aria-label="t('Send')"
                    data-test="chat-send"
                >
                    <SendHorizontal class="size-4" />
                </Button>
            </div>
        </div>
        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
    </form>
</template>
