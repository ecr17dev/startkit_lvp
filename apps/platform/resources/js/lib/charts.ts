/**
 * Shared helpers of the kit charts (components/kit/charts).
 *
 * Colors: categorical series take the slots --chart-1..8 of app.css in this
 * fixed order (validated for color vision deficiency in light and dark).
 * Never cycle them: a ninth series is folded into "Other" instead.
 */
export const CHART_SLOTS = 8;

export type ChartSeries = {
    /** Property of each datum holding the value. */
    key: string;
    label: string;
    /** CSS color; defaults to the slot of its position (var(--chart-n)). */
    color?: string;
};

export type ChartDatum = Record<string, string | number | null | undefined>;

/** Color of the categorical slot at `index` (0-based); gray past the last. */
export function slotColor(index: number): string {
    return index < CHART_SLOTS
        ? `var(--chart-${index + 1})`
        : 'var(--muted-foreground)';
}

/** Series with their final color, following the fixed slot order. */
export function withColors<T extends { color?: string }>(
    series: T[],
): (T & { color: string })[] {
    return series.map((item, index) => ({
        ...item,
        color: item.color ?? slotColor(index),
    }));
}

/** Numeric value of a datum (null and text become 0). */
export function numeric(datum: ChartDatum, key: string): number {
    const value = Number(datum[key] ?? 0);

    return Number.isFinite(value) ? value : 0;
}

/** Round the top of an axis up to a clean number (1, 2, 2.5, 5 × 10^n). */
export function niceMax(value: number): number {
    if (value <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(value));

    for (const step of [1, 2, 2.5, 5, 10]) {
        if (value <= step * magnitude) {
            return step * magnitude;
        }
    }

    return 10 * magnitude;
}

/** Evenly spaced ticks from `max` down to 0. */
export function axisTicks(max: number, count = 4): number[] {
    return Array.from(
        { length: count + 1 },
        (_, index) => (max / count) * (count - index),
    );
}

/** Up to `count` evenly spread indexes (always the first and the last). */
export function spreadIndexes(length: number, count = 4): number[] {
    if (length <= count) {
        return Array.from({ length }, (_, index) => index);
    }

    return [
        ...new Set(
            Array.from({ length: count }, (_, index) =>
                Math.round((index * (length - 1)) / (count - 1)),
            ),
        ),
    ];
}
