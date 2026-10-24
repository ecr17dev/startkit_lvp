<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
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
import { Progress } from '@starter/ui/components/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Switch } from '@starter/ui/components/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import { featureLabels } from '@/lib/ai';
import { formatDate, formatMoney, t } from '@/lib/i18n';
import budgetsRoutes from '@/routes/admin/ai/budgets';
import type { BudgetStatus } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'AI budgets', href: budgetsRoutes.index() }],
    },
});

type BudgetRow = {
    id: number;
    name: string;
    scope: string;
    scope_value: string | null;
    period: string;
    limit_amount: string;
    alert_thresholds: number[];
    hard_limit: boolean;
    block_unknown_cost: boolean;
    is_active: boolean;
    status: BudgetStatus;
};

const props = defineProps<{
    budgets: BudgetRow[];
    alerts: {
        id: number;
        budget: string;
        period: string;
        threshold: number;
        spent: string;
        triggered_at: string;
    }[];
    options: {
        scopes: string[];
        periods: string[];
        features: string[];
        roles: string[];
        connections: { id: number; label: string }[];
        users: { id: number; name: string; email: string }[];
    };
}>();

const scopeLabels: Record<string, string> = {
    global: 'Whole application',
    user: 'One user',
    role: 'One role',
    feature: 'One feature',
    connection: 'One connection',
};

const open = ref(false);
const editing = ref<BudgetRow | null>(null);

const form = useForm({
    name: '',
    scope: 'global',
    scope_value: '' as string,
    period: 'monthly',
    limit_amount: '' as string | number,
    alert_thresholds: '80, 100',
    hard_limit: true,
    block_unknown_cost: false,
    is_active: true,
});

const scopeOptions = computed<{ value: string; label: string }[]>(() => {
    switch (form.scope) {
        case 'user':
            return props.options.users.map((user) => ({
                value: String(user.id),
                label: `${user.name} (${user.email})`,
            }));
        case 'role':
            return props.options.roles.map((role) => ({
                value: role,
                label: role,
            }));
        case 'feature':
            return props.options.features.map((feature) => ({
                value: feature,
                label: t(featureLabels[feature] ?? feature),
            }));
        case 'connection':
            return props.options.connections.map((connection) => ({
                value: String(connection.id),
                label: connection.label,
            }));
        default:
            return [];
    }
});

const scopeName = (budget: BudgetRow) => {
    if (budget.scope === 'global') return t(scopeLabels.global);
    if (budget.scope === 'user')
        return (
            props.options.users.find(
                (user) => String(user.id) === budget.scope_value,
            )?.name ?? budget.scope_value
        );
    if (budget.scope === 'connection')
        return (
            props.options.connections.find(
                (c) => String(c.id) === budget.scope_value,
            )?.label ?? budget.scope_value
        );
    if (budget.scope === 'feature')
        return t(
            featureLabels[budget.scope_value ?? ''] ?? budget.scope_value ?? '',
        );

    return budget.scope_value;
};

const create = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    open.value = true;
};

const edit = (budget: BudgetRow) => {
    editing.value = budget;
    form.clearErrors();
    form.name = budget.name;
    form.scope = budget.scope;
    form.scope_value = budget.scope_value ?? '';
    form.period = budget.period;
    form.limit_amount = budget.limit_amount;
    form.alert_thresholds = budget.alert_thresholds.join(', ');
    form.hard_limit = budget.hard_limit;
    form.block_unknown_cost = budget.block_unknown_cost;
    form.is_active = budget.is_active;
    open.value = true;
};

const submit = () => {
    const request = form.transform((data) => ({
        ...data,
        scope_value: data.scope === 'global' ? null : data.scope_value,
        alert_thresholds: String(data.alert_thresholds)
            .split(',')
            .map((value) => Number(value.trim()))
            .filter((value) => value > 0),
    }));
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (editing.value) {
        request.put(budgetsRoutes.update(editing.value.id).url, options);
    } else {
        request.post(budgetsRoutes.store().url, options);
    }
};

const remove = (budget: BudgetRow) =>
    router.delete(budgetsRoutes.destroy(budget.id).url, {
        preserveScroll: true,
    });
</script>

<template>
    <Head :title="t('AI budgets')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('AI budgets')"
            :description="
                t(
                    'Daily or monthly limits in USD. The estimated cost is reserved atomically before each call and replaced by the real cost when it ends, so the final spend can differ slightly from the estimate.',
                )
            "
        >
            <Button data-test="new-budget" @click="create">
                <Plus class="size-4" />
                {{ t('New budget') }}
            </Button>
        </PageHeader>

        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ t('Budget') }}</TableHead>
                        <TableHead>{{ t('Scope') }}</TableHead>
                        <TableHead class="min-w-48">{{
                            t('Current period')
                        }}</TableHead>
                        <TableHead>{{ t('Mode') }}</TableHead>
                        <TableHead class="w-24 text-right">{{
                            t('Actions')
                        }}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="budget in budgets"
                        :key="budget.id"
                        :class="{ 'opacity-60': !budget.is_active }"
                    >
                        <TableCell>
                            <div class="font-medium">{{ budget.name }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ formatMoney(budget.limit_amount) }} ·
                                {{ t(budget.period) }}
                            </div>
                        </TableCell>
                        <TableCell class="text-sm"
                            >{{ t(scopeLabels[budget.scope] ?? budget.scope) }}
                            <div class="text-xs text-muted-foreground">
                                {{ scopeName(budget) }}
                            </div></TableCell
                        >
                        <TableCell>
                            <Progress
                                :model-value="
                                    Math.min(100, budget.status.percent)
                                "
                                :aria-label="budget.name"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ formatMoney(budget.status.spent) }} ({{
                                    budget.status.percent
                                }}%) · {{ budget.status.period_key }}
                                <template
                                    v-if="budget.status.unknown_cost_requests"
                                >
                                    ·
                                    {{
                                        t(':count with unknown cost', {
                                            count: budget.status
                                                .unknown_cost_requests,
                                        })
                                    }}</template
                                >
                            </p>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    budget.hard_limit ? 'default' : 'outline'
                                "
                                >{{
                                    budget.hard_limit
                                        ? t('Blocks')
                                        : t('Alerts')
                                }}</Badge
                            >
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="t('Edit')"
                                @click="edit(budget)"
                                ><Pencil class="size-4"
                            /></Button>
                            <ConfirmDialog
                                :title="t('Delete budget')"
                                :description="
                                    t(
                                        'Calls will no longer be limited by this budget.',
                                    )
                                "
                                :confirm-text="t('Delete')"
                                @confirm="remove(budget)"
                            >
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive"
                                    :aria-label="t('Delete')"
                                    ><Trash2 class="size-4"
                                /></Button>
                            </ConfirmDialog>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="!budgets.length" :colspan="5">{{
                        t(
                            'No budgets yet. Without budgets AI calls are not limited.',
                        )
                    }}</TableEmpty>
                </TableBody>
            </Table>
        </div>

        <Card>
            <CardHeader
                ><CardTitle>{{ t('Recent alerts') }}</CardTitle></CardHeader
            >
            <CardContent>
                <ul class="grid gap-2 text-sm">
                    <li
                        v-for="alert in alerts"
                        :key="alert.id"
                        class="flex flex-wrap justify-between gap-2"
                    >
                        <span>{{
                            t(':budget reached :threshold% (:period)', {
                                budget: alert.budget,
                                threshold: alert.threshold,
                                period: alert.period,
                            })
                        }}</span>
                        <span class="text-muted-foreground"
                            >{{ formatMoney(alert.spent) }} ·
                            {{ formatDate(alert.triggered_at) }}</span
                        >
                    </li>
                    <li v-if="!alerts.length" class="text-muted-foreground">
                        {{ t('No alerts yet.') }}
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-lg">
                <form class="grid gap-4" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{
                            editing ? t('Edit budget') : t('New budget')
                        }}</DialogTitle>
                        <DialogDescription>{{
                            t(
                                'Hard limits block calls whose estimated cost would exceed the limit. Soft limits only alert.',
                            )
                        }}</DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="budget-name">{{ t('Name') }}</Label>
                        <Input id="budget-name" v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label>{{ t('Scope') }}</Label>
                            <Select v-model="form.scope">
                                <SelectTrigger class="w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="scope in options.scopes"
                                        :key="scope"
                                        :value="scope"
                                        >{{
                                            t(scopeLabels[scope] ?? scope)
                                        }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                        <div v-if="form.scope !== 'global'" class="grid gap-2">
                            <Label>{{ t('Applies to') }}</Label>
                            <Select v-model="form.scope_value">
                                <SelectTrigger class="w-full"
                                    ><SelectValue :placeholder="t('Choose')"
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in scopeOptions"
                                        :key="option.value"
                                        :value="option.value"
                                        >{{ option.label }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.scope_value" />
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label>{{ t('Period') }}</Label>
                            <Select v-model="form.period">
                                <SelectTrigger class="w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="period in options.periods"
                                        :key="period"
                                        :value="period"
                                        >{{ t(period) }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="budget-limit">{{
                                t('Limit (USD)')
                            }}</Label>
                            <Input
                                id="budget-limit"
                                v-model="form.limit_amount"
                                type="number"
                                step="any"
                                min="0"
                                required
                            />
                            <InputError :message="form.errors.limit_amount" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="budget-alerts">{{
                                t('Alerts (%)')
                            }}</Label>
                            <Input
                                id="budget-alerts"
                                v-model="form.alert_thresholds"
                                placeholder="80, 100"
                            />
                            <InputError
                                :message="form.errors.alert_thresholds"
                            />
                        </div>
                    </div>
                    <div class="grid gap-3 rounded-md border p-3">
                        <div class="flex items-center justify-between gap-4">
                            <Label for="hard_limit">{{
                                t('Block calls at the limit')
                            }}</Label>
                            <Switch id="hard_limit" v-model="form.hard_limit" />
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <Label for="block_unknown_cost">{{
                                t('Block calls with unknown cost')
                            }}</Label>
                            <Switch
                                id="block_unknown_cost"
                                v-model="form.block_unknown_cost"
                            />
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <Label for="is_active">{{ t('Active') }}</Label>
                            <Switch id="is_active" v-model="form.is_active" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="form.processing">{{
                            t('Save')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
