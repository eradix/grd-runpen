export type Mode = 'script' | 'frontend';

export type TailwindVersion = '3' | '4' | null;

export interface Language {
    key: string;
    label: string;
    version: string;
    editor: string;
    sample: string;
}

/** Mirrors App\Services\Runner\RunResult. */
export interface RunResult {
    stdout: string;
    stderr: string;
    exitCode: number | null;
    durationMs: number;
    timedOut: boolean;
    truncated: boolean;
    outOfMemory: boolean;
}

export interface Snippet {
    slug: string;
    mode: Mode;
    language: string | null;
    source: string | null;
    stdin: string | null;
    html: string | null;
    css: string | null;
    js: string | null;
    tailwind: TailwindVersion;
}

/** One line in the frontend pen's console, forwarded from the preview iframe. */
export interface ConsoleEntry {
    level: 'log' | 'warn' | 'error' | 'info';
    text: string;
    id: number;
}
