<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import AccessListInput from '@/components/knowledge/AccessListInput.vue';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Textarea } from '@starter/ui/components/textarea';
import { t } from '@/lib/i18n';
import { languageLabels, methodLabels } from '@/lib/knowledge';
import type { Chunking, EmbeddingProfile, SearchSettings } from '@/types';

export type CollectionFormData = {
    name: string;
    slug: string;
    description: string;
    embedding_profile_id: number | null;
    language: string;
    chunking: Chunking;
    search: SearchSettings;
    acl: string[];
    retention_days: number | null;
};

const props = defineProps<{
    form: InertiaForm<CollectionFormData>;
    profiles: EmbeddingProfile[];
    languages: string[];
    editing?: boolean;
}>();

const error = (key: string) =>
    (props.form.errors as Record<string, string | undefined>)[key];

const profileValue = (value: unknown) =>
    (props.form.embedding_profile_id = value ? Number(value) : null);
</script>

<template>
    <div class="grid gap-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="collection-name">{{ t('Name') }}</Label>
                <Input id="collection-name" v-model="form.name" required />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="collection-slug">{{ t('Identifier') }}</Label>
                <Input
                    id="collection-slug"
                    v-model="form.slug"
                    placeholder="manual-interno"
                />
                <InputError :message="form.errors.slug" />
            </div>
        </div>
        <div class="grid gap-2">
            <Label for="collection-description">{{ t('Description') }}</Label>
            <Textarea
                id="collection-description"
                v-model="form.description"
                rows="2"
            />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label>{{ t('Embedding profile') }}</Label>
                <Select
                    :model-value="
                        form.embedding_profile_id
                            ? String(form.embedding_profile_id)
                            : ''
                    "
                    @update:model-value="profileValue"
                >
                    <SelectTrigger data-test="collection-profile">
                        <SelectValue :placeholder="t('Choose a profile')" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="profile in profiles"
                            :key="profile.id"
                            :value="String(profile.id)"
                            >{{ profile.name }} ·
                            {{ profile.dimensions }}d</SelectItem
                        >
                    </SelectContent>
                </Select>
                <p v-if="editing" class="text-xs text-muted-foreground">
                    {{
                        t(
                            'A different profile is another vector space: it applies after a reindex.',
                        )
                    }}
                </p>
                <InputError :message="form.errors.embedding_profile_id" />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('Language (full text search)') }}</Label>
                <Select v-model="form.language">
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="language in languages"
                            :key="language"
                            :value="language"
                            >{{
                                t(languageLabels[language] ?? language)
                            }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
        </div>

        <fieldset class="grid gap-3 rounded-md border p-3">
            <legend class="px-1 text-sm font-medium">
                {{ t('Chunking (estimated tokens, ≈4 characters each)') }}
            </legend>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="grid gap-1.5">
                    <Label for="chunk-target" class="text-xs">{{
                        t('Target size')
                    }}</Label>
                    <Input
                        id="chunk-target"
                        v-model.number="form.chunking.target_tokens"
                        type="number"
                        min="100"
                        max="2000"
                    />
                    <InputError :message="error('chunking.target_tokens')" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="chunk-overlap" class="text-xs">{{
                        t('Overlap')
                    }}</Label>
                    <Input
                        id="chunk-overlap"
                        v-model.number="form.chunking.overlap_tokens"
                        type="number"
                        min="0"
                    />
                    <InputError :message="error('chunking.overlap_tokens')" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="chunk-max" class="text-xs">{{
                        t('Maximum')
                    }}</Label>
                    <Input
                        id="chunk-max"
                        v-model.number="form.chunking.max_tokens"
                        type="number"
                        max="4000"
                    />
                    <InputError :message="error('chunking.max_tokens')" />
                </div>
            </div>
        </fieldset>

        <fieldset class="grid gap-3 rounded-md border p-3">
            <legend class="px-1 text-sm font-medium">{{ t('Search') }}</legend>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="grid gap-1.5">
                    <Label class="text-xs">{{ t('Method') }}</Label>
                    <Select v-model="form.search.method">
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="(label, value) in methodLabels"
                                :key="value"
                                :value="value"
                                >{{ t(label) }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="search-k" class="text-xs">Top-k</Label>
                    <Input
                        id="search-k"
                        v-model.number="form.search.top_k"
                        type="number"
                        min="1"
                        max="50"
                    />
                    <InputError :message="error('search.top_k')" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="search-min" class="text-xs">{{
                        t('Minimum similarity')
                    }}</Label>
                    <Input
                        id="search-min"
                        v-model.number="form.search.min_score"
                        type="number"
                        min="0"
                        max="1"
                        step="0.01"
                        :placeholder="t('none')"
                    />
                    <InputError :message="error('search.min_score')" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="search-context" class="text-xs">{{
                        t('Context budget (tokens)')
                    }}</Label>
                    <Input
                        id="search-context"
                        v-model.number="form.search.max_context_tokens"
                        type="number"
                        min="200"
                    />
                    <InputError :message="error('search.max_context_tokens')" />
                </div>
            </div>
        </fieldset>

        <div class="grid gap-2">
            <Label for="collection-acl">{{ t('Access') }}</Label>
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Who can retrieve this knowledge. The filter is applied inside the search query, before anything reaches the model.',
                    )
                }}
            </p>
            <AccessListInput id="collection-acl" v-model="form.acl" />
            <InputError :message="form.errors.acl ?? error('acl.0')" />
        </div>
    </div>
</template>
