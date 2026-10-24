/**
 * UI kit: ready-made pieces built on @starter/ui (shadcn-vue), Inertia
 * and the app translations. Catalog with live examples: /ui-kit.
 * Guide: docs/ui-kit.md.
 *
 * import { DataTable, FormField, WizardForm } from '@/components/kit';
 */

// Forms
export { default as DatePicker } from './DatePicker.vue';
export { default as FormField } from './FormField.vue';
export { default as SubmitButton } from './SubmitButton.vue';
export { default as WizardForm } from './WizardForm.vue';
export type { WizardStep } from './WizardForm.vue';

// Data
export { default as DataTable } from './DataTable.vue';
export type {
    DataTableColumn,
    DataTableMode,
    DataTableSort,
} from './data-table';
export { default as KpiCard } from './KpiCard.vue';

// Charts
export { default as BarChart } from './charts/BarChart.vue';
export { default as DonutChart } from './charts/DonutChart.vue';
export type { DonutSlice } from './charts/DonutChart.vue';
export { default as LineChart } from './charts/LineChart.vue';
export { default as Sparkline } from './charts/Sparkline.vue';
export type { ChartDatum, ChartSeries } from '@/lib/charts';

// Actions and navigation
export { default as ActionsMenu } from './ActionsMenu.vue';
export type { MenuAction } from './ActionsMenu.vue';
export { default as CommandMenu } from './CommandMenu.vue';
export type { CommandEntry, CommandSection } from './CommandMenu.vue';
export { default as CopyButton } from './CopyButton.vue';
export { default as IconButton } from './IconButton.vue';

// Feedback and overlays
export { default as EmptyState } from './EmptyState.vue';
export { default as Modal } from './Modal.vue';
export { default as StatusAlert } from './StatusAlert.vue';

// Media and icons
export { default as ContentCarousel } from './ContentCarousel.vue';
export { default as Icon } from './Icon.vue';
