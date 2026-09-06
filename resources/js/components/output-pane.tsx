import type { RunResult } from '@/types/playground';

interface Props {
    result: RunResult | null;
    error: string | null;
    running: boolean;
}

/**
 * Shows what came back from a run.
 *
 * The badges matter as much as the text: a container killed for running too
 * long, running out of memory, or printing too much produces little or no
 * output, and "exit code 137" on its own tells the reader nothing.
 */
export default function OutputPane({ result, error, running }: Props) {
    if (running) {
        return <p className="p-4 font-mono text-xs text-slate-500">Running…</p>;
    }

    if (error) {
        return <p className="p-4 font-mono text-xs text-rose-400">{error}</p>;
    }

    if (!result) {
        return (
            <p className="p-4 font-mono text-xs text-slate-500">
                Press Run, or{' '}
                <kbd className="rounded bg-slate-800 px-1">⌘/Ctrl</kbd> +{' '}
                <kbd className="rounded bg-slate-800 px-1">Enter</kbd>.
            </p>
        );
    }

    const badges = [
        result.timedOut && {
            text: `killed after ${(result.durationMs / 1000).toFixed(1)}s`,
            tone: 'bg-amber-500/15 text-amber-300',
        },
        result.outOfMemory && {
            text: 'out of memory',
            tone: 'bg-amber-500/15 text-amber-300',
        },
        result.truncated && {
            text: 'output truncated',
            tone: 'bg-amber-500/15 text-amber-300',
        },
    ].filter(Boolean) as { text: string; tone: string }[];

    return (
        <div className="flex h-full flex-col">
            <div className="flex flex-wrap items-center gap-2 border-b border-slate-800 px-4 py-2 text-xs">
                <span
                    className={
                        result.exitCode === 0
                            ? 'text-emerald-400'
                            : 'text-rose-400'
                    }
                >
                    exit {result.exitCode ?? '—'}
                </span>
                <span className="text-slate-500">{result.durationMs} ms</span>
                {badges.map((badge) => (
                    <span
                        key={badge.text}
                        className={`rounded px-1.5 py-0.5 ${badge.tone}`}
                    >
                        {badge.text}
                    </span>
                ))}
            </div>

            <div className="flex-1 overflow-auto p-4 font-mono text-xs leading-relaxed">
                {result.stdout && (
                    <pre className="whitespace-pre-wrap text-slate-200">
                        {result.stdout}
                    </pre>
                )}
                {result.stderr && (
                    <pre className="mt-2 whitespace-pre-wrap text-rose-400">
                        {result.stderr}
                    </pre>
                )}
                {!result.stdout && !result.stderr && (
                    <p className="text-slate-500">No output.</p>
                )}
            </div>
        </div>
    );
}
