import DOMPurify from 'dompurify';
import { marked } from 'marked';

marked.setOptions({ gfm: true, breaks: true });

/**
 * Render untrusted Markdown (e.g. AI answers) into sanitized HTML.
 */
export function renderMarkdown(source: string): string {
    const html = marked.parse(source, { async: false });

    return DOMPurify.sanitize(html, { USE_PROFILES: { html: true } });
}
