import { toast } from 'vue-sonner';

type Options = {
    description?: string;
    /** Button inside the toast, e.g. "Undo". */
    action?: { label: string; onClick: () => void };
    duration?: number;
};

const options = (extra: Options = {}) => ({
    description: extra.description,
    duration: extra.duration,
    action: extra.action
        ? { label: extra.action.label, onClick: extra.action.onClick }
        : undefined,
});

/**
 * Toasts from the browser (pass texts already translated with t()).
 * After a redirect, prefer the server flash, which arrives the same way:
 *   Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);
 *
 *   notify.success(t('Saved.'));
 *   notify.error(t('Could not save.'), { description: error });
 *   notify.promise(save(), { loading: t('Saving…'), success: t('Saved.'), error: t('Could not save.') });
 */
export const notify = {
    success: (message: string, extra?: Options) =>
        toast.success(message, options(extra)),
    error: (message: string, extra?: Options) =>
        toast.error(message, options(extra)),
    warning: (message: string, extra?: Options) =>
        toast.warning(message, options(extra)),
    info: (message: string, extra?: Options) =>
        toast.info(message, options(extra)),
    message: (message: string, extra?: Options) =>
        toast(message, options(extra)),
    promise: <T>(
        promise: Promise<T>,
        messages: { loading: string; success: string; error: string },
    ) => toast.promise(promise, messages),
    dismiss: (id?: string | number) => toast.dismiss(id),
};
