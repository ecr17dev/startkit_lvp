<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import AccessListInput from '@/components/knowledge/AccessListInput.vue';
import { Button } from '@starter/ui/components/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@starter/ui/components/dialog';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { t } from '@/lib/i18n';
import { scheduleLabels, sourceTypeLabels } from '@/lib/knowledge';
import sourceRoutes from '@/routes/admin/knowledge/sources';
import type { KnowledgeOptions, SourceSummary } from '@/types';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    collectionId: number;
    options: KnowledgeOptions;
    source?: SourceSummary | null;
}>();

const editing = computed(() => !!props.source);

const examples = {
    url: 'https://docs.example.com/',
    sitemap: 'https://docs.example.com/sitemap.xml',
};

const blank = () => ({
    type: 'upload',
    name: '',
    files: [] as File[],
    config: {
        urls: [''] as string[],
        depth: 0,
        max_pages: 50,
        path_prefix: '',
        url: '',
        adapter: '',
        disk: '',
        prefix: '',
        max_files: 500,
    },
    acl: [] as string[],
    schedule: 'none',
});

const form = useForm(blank());

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    const fresh = blank();

    if (props.source) {
        const config = props.source.config;
        fresh.type = props.source.type;
        fresh.name = props.source.name;
        fresh.config = {
            ...fresh.config,
            urls: config.urls?.length ? [...config.urls] : [''],
            depth: config.depth ?? 0,
            max_pages: config.max_pages ?? 50,
            path_prefix: config.path_prefix ?? '',
            url: config.url ?? '',
            adapter: config.adapter ?? '',
            disk: config.disk ?? '',
            prefix: config.prefix ?? '',
            max_files: config.max_files ?? 500,
        };
        fresh.acl = props.source.acl ?? [];
        fresh.schedule = props.source.schedule ?? 'none';
    }

    form.defaults(fresh);
    form.reset();
});

const error = (key: string) =>
    (form.errors as Record<string, string | undefined>)[key];

const fileErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key.startsWith('files'))
        .map(([, message]) => message),
);

const pickFiles = (event: Event) => {
    form.files = Array.from((event.target as HTMLInputElement).files ?? []);
};

const configFor = () => {
    const config = form.config;

    switch (form.type) {
        case 'url':
            return {
                urls: config.urls.map((url) => url.trim()).filter(Boolean),
                depth: Number(config.depth),
                max_pages: Number(config.max_pages),
                path_prefix: config.path_prefix || null,
            };
        case 'sitemap':
            return {
                url: config.url.trim(),
                max_pages: Number(config.max_pages),
                path_prefix: config.path_prefix || null,
            };
        case 'adapter':
            return { adapter: config.adapter };
        case 'disk':
            return {
                disk: config.disk,
                prefix: config.prefix || null,
                max_files: Number(config.max_files),
            };
        default:
            return {};
    }
};

const submit = () => {
    const options = {
        preserveScroll: true,
        forceFormData: !editing.value && form.type === 'upload',
        onSuccess: () => (open.value = false),
    };

    form.transform((data) => ({
        ...(editing.value ? {} : { type: data.type }),
        name: data.name,
        config: configFor(),
        acl: data.acl,
        schedule: data.schedule === 'none' ? null : data.schedule,
        ...(!editing.value && data.type === 'upload'
            ? { files: data.files }
            : {}),
    }));

    if (editing.value && props.source) {
        form.put(sourceRoutes.update(props.source.id).url, options);
    } else {
        form.post(sourceRoutes.store(props.collectionId).url, options);
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{
                    editing ? t('Edit source') : t('New source')
                }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'Documents are read, extracted, chunked and embedded by queue jobs. Large ingestions ask for a budget first.',
                    )
                }}</DialogDescription>
            </DialogHeader>

            <form id="source-form" class="grid gap-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label>{{ t('Type') }}</Label>
                        <Select v-model="form.type" :disabled="editing">
                            <SelectTrigger data-test="source-type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="(label, value) in sourceTypeLabels"
                                    :key="value"
                                    :value="value"
                                    :disabled="
                                        (value === 'adapter' &&
                                            !Object.keys(options.adapters)
                                                .length) ||
                                        (value === 'disk' &&
                                            !options.disks.length)
                                    "
                                    >{{ t(label) }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="source-name">{{ t('Name') }}</Label>
                        <Input id="source-name" v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </div>
                </div>

                <div
                    v-if="form.type === 'upload' && !editing"
                    class="grid gap-2"
                >
                    <Label for="source-files">{{
                        t('Files (TXT, Markdown, PDF)')
                    }}</Label>
                    <Input
                        id="source-files"
                        type="file"
                        multiple
                        accept=".txt,.md,.markdown,.pdf"
                        data-test="source-files"
                        @change="pickFiles"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Up to :files files of :mb MB. Scanned PDFs without text are reported, not indexed.',
                                {
                                    files: options.limits.max_files,
                                    mb: options.limits.max_file_mb,
                                },
                            )
                        }}
                    </p>
                    <InputError
                        v-for="message in fileErrors"
                        :key="message"
                        :message="message"
                    />
                </div>

                <template v-if="form.type === 'url'">
                    <div class="grid gap-2">
                        <Label>{{ t('URLs') }}</Label>
                        <div
                            v-for="(url, position) in form.config.urls"
                            :key="position"
                            class="flex gap-2"
                        >
                            <Input
                                v-model="form.config.urls[position]"
                                type="url"
                                :placeholder="examples.url"
                                data-test="source-url"
                            />
                            <Button
                                v-if="form.config.urls.length > 1"
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="t('Remove')"
                                @click="form.config.urls.splice(position, 1)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                        <InputError
                            :message="
                                error('config.urls') ?? error('config.urls.0')
                            "
                        />
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="w-fit"
                            @click="form.config.urls.push('')"
                        >
                            <Plus class="size-4" />
                            {{ t('Add URL') }}
                        </Button>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="source-depth">{{
                                t('Link depth')
                            }}</Label>
                            <Input
                                id="source-depth"
                                v-model.number="form.config.depth"
                                type="number"
                                min="0"
                                :max="options.limits.max_depth"
                            />
                            <InputError :message="error('config.depth')" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="source-pages">{{
                                t('Maximum pages')
                            }}</Label>
                            <Input
                                id="source-pages"
                                v-model.number="form.config.max_pages"
                                type="number"
                                min="1"
                                :max="options.limits.max_pages"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="source-prefix">{{
                                t('Path prefix')
                            }}</Label>
                            <Input
                                id="source-prefix"
                                v-model="form.config.path_prefix"
                                placeholder="/docs"
                            />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Only public addresses are read: private networks, metadata endpoints, other ports and redirects to them are blocked.',
                            )
                        }}
                    </p>
                </template>

                <template v-if="form.type === 'sitemap'">
                    <div class="grid gap-2">
                        <Label for="source-sitemap">{{
                            t('Sitemap URL')
                        }}</Label>
                        <Input
                            id="source-sitemap"
                            v-model="form.config.url"
                            type="url"
                            :placeholder="examples.sitemap"
                        />
                        <InputError :message="error('config.url')" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="sitemap-pages">{{
                                t('Maximum pages')
                            }}</Label>
                            <Input
                                id="sitemap-pages"
                                v-model.number="form.config.max_pages"
                                type="number"
                                min="1"
                                :max="options.limits.max_pages"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="sitemap-prefix">{{
                                t('Path prefix')
                            }}</Label>
                            <Input
                                id="sitemap-prefix"
                                v-model="form.config.path_prefix"
                                placeholder="/docs"
                            />
                        </div>
                    </div>
                </template>

                <div v-if="form.type === 'adapter'" class="grid gap-2">
                    <Label>{{ t('Adapter') }}</Label>
                    <Select v-model="form.config.adapter" :disabled="editing">
                        <SelectTrigger>
                            <SelectValue
                                :placeholder="t('Choose an adapter')"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="(label, key) in options.adapters"
                                :key="key"
                                :value="key"
                                >{{ label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="error('config.adapter')" />
                </div>

                <template v-if="form.type === 'disk'">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label>{{ t('Disk') }}</Label>
                            <Select v-model="form.config.disk">
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="disk in options.disks"
                                        :key="disk"
                                        :value="disk"
                                        >{{ disk }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <InputError :message="error('config.disk')" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="disk-prefix">{{ t('Prefix') }}</Label>
                            <Input
                                id="disk-prefix"
                                v-model="form.config.prefix"
                                placeholder="manuales/"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="disk-max">{{
                                t('Maximum files')
                            }}</Label>
                            <Input
                                id="disk-max"
                                v-model.number="form.config.max_files"
                                type="number"
                                min="1"
                            />
                        </div>
                    </div>
                </template>

                <div class="grid gap-2">
                    <Label>{{ t('Automatic sync') }}</Label>
                    <Select v-model="form.schedule">
                        <SelectTrigger class="sm:w-60">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">{{
                                t('Manual')
                            }}</SelectItem>
                            <SelectItem
                                v-for="schedule in options.schedules"
                                :key="schedule"
                                :value="schedule"
                                >{{
                                    t(scheduleLabels[schedule] ?? schedule)
                                }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <p
                        v-if="!options.automatic && form.schedule !== 'none'"
                        class="text-xs text-amber-600 dark:text-amber-400"
                    >
                        {{
                            t(
                                'Scheduled syncs run only with STARTER_RAG_AUTOMATIC=true and the scheduler running.',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="source-acl">{{ t('Access (optional)') }}</Label>
                    <AccessListInput
                        id="source-acl"
                        v-model="form.acl"
                        allow-empty
                    />
                    <InputError :message="form.errors.acl ?? error('acl.0')" />
                </div>
                <InputError :message="error('rag')" />
            </form>

            <DialogFooter>
                <Button variant="outline" @click="open = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="source-form"
                    :disabled="form.processing"
                    data-test="source-submit"
                    >{{ editing ? t('Save') : t('Create and sync') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
