<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ImageUp, X } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import branding from '@/routes/admin/settings/branding';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'System settings', href: branding.edit() }],
    },
});

type BrandingSettings = {
    app_name: string;
    tagline: string | null;
    primary_color: string;
    primary_color_dark: string;
    radius: string;
    font_sans: string;
    font_heading: string | null;
    auth_layout: 'simple' | 'card' | 'split';
    default_appearance: 'light' | 'dark' | 'system';
    logo_url: string | null;
    logo_dark_url: string | null;
    favicon_url: string | null;
};

const props = defineProps<{
    settings: BrandingSettings;
    fonts: string[];
}>();

const form = useForm({
    app_name: props.settings.app_name,
    tagline: props.settings.tagline ?? '',
    primary_color: props.settings.primary_color,
    primary_color_dark: props.settings.primary_color_dark,
    radius: String(props.settings.radius ?? '0.5'),
    font_sans: props.settings.font_sans,
    font_heading: props.settings.font_heading ?? 'same',
    auth_layout: props.settings.auth_layout,
    default_appearance: props.settings.default_appearance,
    logo: null as File | null,
    logo_dark: null as File | null,
    favicon: null as File | null,
    remove_logo: false,
    remove_logo_dark: false,
    remove_favicon: false,
});

type AssetKey = 'logo' | 'logo_dark' | 'favicon';

const assets: { key: AssetKey; label: string; hint: string; accept: string }[] =
    [
        {
            key: 'logo',
            label: 'Logo',
            hint: 'PNG, JPG or WEBP, max 2 MB',
            accept: 'image/png,image/jpeg,image/webp',
        },
        {
            key: 'logo_dark',
            label: 'Logo for dark mode',
            hint: 'Optional',
            accept: 'image/png,image/jpeg,image/webp',
        },
        {
            key: 'favicon',
            label: 'Favicon',
            hint: 'PNG or ICO, max 512 KB',
            accept: 'image/png,image/x-icon,.ico',
        },
    ];

const previews = computed<Record<AssetKey, string | null>>(() => ({
    logo: preview('logo', props.settings.logo_url),
    logo_dark: preview('logo_dark', props.settings.logo_dark_url),
    favicon: preview('favicon', props.settings.favicon_url),
}));

function preview(key: AssetKey, current: string | null): string | null {
    const file = form[key];

    if (file) {
        return URL.createObjectURL(file);
    }

    return form[`remove_${key}`] ? null : current;
}

const pick = (key: AssetKey, event: Event) => {
    const input = event.target as HTMLInputElement;
    form[key] = input.files?.[0] ?? null;
    form[`remove_${key}`] = false;
};

const clear = (key: AssetKey) => {
    form[key] = null;
    form[`remove_${key}`] = true;
};

const submit = () => {
    form.transform((data) => ({
        ...data,
        font_heading: data.font_heading === 'same' ? null : data.font_heading,
    })).post(branding.update().url, {
        forceFormData: true,
        preserveScroll: true,
        // Reload the page so the new colors and fonts are applied everywhere...
        onSuccess: () => window.location.reload(),
    });
};

const layouts = [
    { value: 'simple', label: 'Simple' },
    { value: 'card', label: 'Card' },
    { value: 'split', label: 'Split screen' },
];

const appearances = [
    { value: 'system', label: 'System' },
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
];
</script>

<template>
    <Head :title="t('Branding')" />

    <form class="space-y-10" @submit.prevent="submit">
        <div class="space-y-6">
            <Heading
                variant="small"
                :title="t('Identity')"
                :description="t('Name, tagline and logos of your application')"
            />

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="app_name">{{ t('Application name') }}</Label>
                    <Input id="app_name" v-model="form.app_name" required />
                    <InputError :message="form.errors.app_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="tagline">{{ t('Tagline') }}</Label>
                    <Input
                        id="tagline"
                        v-model="form.tagline"
                        :placeholder="t('Shown in the split login layout')"
                    />
                    <InputError :message="form.errors.tagline" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div
                    v-for="asset in assets"
                    :key="asset.key"
                    class="grid gap-2 rounded-lg border p-3"
                >
                    <Label>{{ t(asset.label) }}</Label>
                    <div
                        class="flex h-20 items-center justify-center rounded-md bg-muted"
                        :class="{ 'bg-neutral-900': asset.key === 'logo_dark' }"
                    >
                        <img
                            v-if="previews[asset.key]"
                            :src="previews[asset.key] ?? ''"
                            class="max-h-16 max-w-full object-contain"
                            alt=""
                        />
                        <ImageUp v-else class="size-6 text-muted-foreground" />
                    </div>
                    <div class="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="relative flex-1 overflow-hidden"
                        >
                            {{ t('Upload') }}
                            <input
                                type="file"
                                :accept="asset.accept"
                                class="absolute inset-0 cursor-pointer opacity-0"
                                @change="(event) => pick(asset.key, event)"
                            />
                        </Button>
                        <Button
                            v-if="previews[asset.key]"
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="t('Remove')"
                            @click="clear(asset.key)"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ t(asset.hint) }}
                    </p>
                    <InputError :message="form.errors[asset.key]" />
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <Heading
                variant="small"
                :title="t('Colors')"
                :description="
                    t('Primary color used by buttons, links and highlights')
                "
            />

            <div class="grid gap-6 sm:grid-cols-2">
                <div
                    v-for="field in [
                        {
                            key: 'primary_color',
                            label: 'Primary color (light mode)',
                        },
                        {
                            key: 'primary_color_dark',
                            label: 'Primary color (dark mode)',
                        },
                    ] as const"
                    :key="field.key"
                    class="grid gap-2"
                >
                    <Label :for="field.key">{{ t(field.label) }}</Label>
                    <div class="flex items-center gap-2">
                        <input
                            :id="field.key"
                            v-model="form[field.key]"
                            type="color"
                            class="h-9 w-12 cursor-pointer rounded-md border bg-transparent p-1"
                        />
                        <Input v-model="form[field.key]" class="font-mono" />
                    </div>
                    <InputError :message="form.errors[field.key]" />
                </div>
            </div>

            <div
                class="flex flex-wrap items-center gap-3 rounded-lg border p-4"
            >
                <span class="text-sm text-muted-foreground"
                    >{{ t('Preview') }}:</span
                >
                <span
                    class="inline-flex h-9 items-center px-4 text-sm font-medium text-white"
                    :style="{
                        background: form.primary_color,
                        borderRadius: `${form.radius}rem`,
                    }"
                    >{{ t('Button') }}</span
                >
                <span
                    class="inline-flex h-9 items-center bg-neutral-900 px-4 text-sm font-medium text-neutral-900"
                    :style="{ borderRadius: `${form.radius}rem` }"
                >
                    <span
                        class="inline-flex h-7 items-center px-3"
                        :style="{
                            background: form.primary_color_dark,
                            borderRadius: `${form.radius}rem`,
                        }"
                        >{{ t('Dark') }}</span
                    >
                </span>
            </div>

            <div class="grid gap-2 sm:w-64">
                <Label for="radius"
                    >{{ t('Border radius') }} ({{ form.radius }} rem)</Label
                >
                <input
                    id="radius"
                    v-model="form.radius"
                    type="range"
                    min="0"
                    max="1.5"
                    step="0.125"
                    class="accent-primary"
                />
                <InputError :message="form.errors.radius" />
            </div>
        </div>

        <div class="space-y-6">
            <Heading
                variant="small"
                :title="t('Typography')"
                :description="
                    t(
                        'Fonts are loaded from Bunny Fonts, a privacy friendly CDN',
                    )
                "
            />

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label>{{ t('Body font') }}</Label>
                    <Select v-model="form.font_sans">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="font in fonts"
                                :key="font"
                                :value="font"
                                >{{ font }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.font_sans" />
                </div>
                <div class="grid gap-2">
                    <Label>{{ t('Heading font') }}</Label>
                    <Select v-model="form.font_heading">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="same">{{
                                t('Same as body font')
                            }}</SelectItem>
                            <SelectItem
                                v-for="font in fonts"
                                :key="font"
                                :value="font"
                                >{{ font }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.font_heading" />
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <Heading
                variant="small"
                :title="t('Layout')"
                :description="t('Login screens and default color scheme')"
            />

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-3">
                    <Label>{{ t('Login layout') }}</Label>
                    <RadioGroup v-model="form.auth_layout" class="grid gap-2">
                        <Label
                            v-for="option in layouts"
                            :key="option.value"
                            class="flex items-center gap-2 font-normal"
                        >
                            <RadioGroupItem :value="option.value" />
                            {{ t(option.label) }}
                        </Label>
                    </RadioGroup>
                </div>
                <div class="grid gap-3">
                    <Label>{{ t('Default appearance') }}</Label>
                    <RadioGroup
                        v-model="form.default_appearance"
                        class="grid gap-2"
                    >
                        <Label
                            v-for="option in appearances"
                            :key="option.value"
                            class="flex items-center gap-2 font-normal"
                        >
                            <RadioGroupItem :value="option.value" />
                            {{ t(option.label) }}
                        </Label>
                    </RadioGroup>
                </div>
            </div>
        </div>

        <Button type="submit" :disabled="form.processing">
            <Spinner v-if="form.processing" />
            {{ t('Save branding') }}
        </Button>
    </form>
</template>
