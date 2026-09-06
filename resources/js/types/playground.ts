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
    /** Read from the container's cgroup; null where cgroups are not readable. */
    peakMemoryBytes: number | null;
    /** Whether the container came from the warm pool or was started for this run. */
    warmStart: boolean;
}

/** One row on the recent page. */
export interface RecentSnippet {
    slug: string;
    mode: Mode;
    language: string | null;
    label: string;
    preview: string;
    createdAt: string;
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
