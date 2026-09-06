import type { Mode, TailwindVersion } from '@/types/playground';

const KEY = 'runpen:draft';

export interface Draft {
    mode: Mode;
    language: string;
    source: string;
    stdin: string;
    html: string;
    css: string;
    js: string;
    tailwind: TailwindVersion;
}

/**
 * Keeps whatever is in the editors across a reload.
 *
 * Snippets are only saved when you press Share, so without this an accidental
 * refresh loses your work. It is deliberately per-browser and never sent
 * anywhere: this is a scratch buffer, not storage. Reads and writes are wrapped
 * because localStorage throws outright in some privacy modes.
 */
export function readDraft(): Draft | null {
    try {
        const stored = window.localStorage.getItem(KEY);

        return stored ? (JSON.parse(stored) as Draft) : null;
    } catch {
        return null;
    }
}

export function writeDraft(draft: Draft): void {
    try {
        window.localStorage.setItem(KEY, JSON.stringify(draft));
    } catch {
        // Full, disabled, or blocked — losing the draft is not worth an error.
    }
}

export function clearDraft(): void {
    try {
        window.localStorage.removeItem(KEY);
    } catch {
        // As above.
    }
}
