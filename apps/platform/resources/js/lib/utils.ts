import type { InertiaLinkProps } from '@inertiajs/vue3';

export { cn } from '@starter/ui/lib/utils';

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}
