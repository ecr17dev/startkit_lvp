<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { FlaskConical } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@starter/ui/components/alert';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import { Textarea } from '@starter/ui/components/textarea';
import { costStatusLabels, formatCost } from '@/lib/ai';
import { formatNumber, t } from '@/lib/i18n';
import agentRoutes from '@/routes/admin/ai/agents';
import type { AgentTestResult } from '@/types';

const props = defineProps<{ agentId: number; testingDraft: boolean }>();

const form = useForm({ message: '' });
const result = ref<AgentTestResult | null>(null);

const run = () =>
    form.post(agentRoutes.test(props.agentId).url, {
        preserveScroll: true,
        preserveState: true,
        onFlash: (flash) => {
            result.value =
                ((flash as Record<string, unknown>).agentTest as
                    | AgentTestResult
                    | undefined) ?? null;
        },
    });
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2">
                <FlaskConical class="size-4" />
                {{ t('Test') }}
            </CardTitle>
            <CardDescription>
                {{
                    testingDraft
                        ? t(
                              'Runs the saved draft. Save your changes first. Only read-only tools are offered and the call is recorded as a test.',
                          )
                        : t(
                              'Runs the published version. Only read-only tools are offered and the call is recorded as a test.',
                          )
                }}
            </CardDescription>
        </CardHeader>
        <CardContent class="grid gap-3">
            <form class="grid gap-2" @submit.prevent="run">
                <Textarea
                    v-model="form.message"
                    rows="3"
                    :placeholder="t('Write a test message…')"
                />
                <InputError :message="form.errors.message" />
                <Button
                    type="submit"
                    variant="secondary"
                    :disabled="form.processing || !form.message.trim()"
                    >{{
                        form.processing ? t('Running…') : t('Run test')
                    }}</Button
                >
            </form>

            <Alert v-if="result && !result.ok" variant="destructive">
                <AlertDescription>{{ result.error }}</AlertDescription>
            </Alert>

            <div v-else-if="result && result.ok" class="grid gap-2">
                <div
                    class="max-h-72 overflow-y-auto rounded-md border bg-muted/40 p-3 text-sm whitespace-pre-wrap"
                >
                    {{ result.text }}
                </div>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                    <dt class="text-muted-foreground">{{ t('Version') }}</dt>
                    <dd>v{{ result.version }}</dd>
                    <dt class="text-muted-foreground">{{ t('Model') }}</dt>
                    <dd class="truncate">{{ result.model }}</dd>
                    <dt class="text-muted-foreground">{{ t('Latency') }}</dt>
                    <dd>{{ formatNumber(result.latency_ms) }} ms</dd>
                    <dt class="text-muted-foreground">{{ t('Tokens') }}</dt>
                    <dd>
                        {{ formatNumber(result.input_tokens) }} /
                        {{ formatNumber(result.output_tokens) }}
                    </dd>
                    <dt class="text-muted-foreground">{{ t('Cost') }}</dt>
                    <dd>
                        {{ formatCost(result.cost) }}
                        <span class="text-muted-foreground"
                            >({{
                                t(
                                    costStatusLabels[result.cost_status] ??
                                        result.cost_status,
                                )
                            }})</span
                        >
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('Tools offered') }}
                    </dt>
                    <dd>
                        {{
                            result.tools.length ? result.tools.join(', ') : '—'
                        }}
                    </dd>
                </dl>
            </div>
        </CardContent>
    </Card>
</template>
