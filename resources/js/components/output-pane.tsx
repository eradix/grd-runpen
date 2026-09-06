import type { RunResult } from '@/types/playground';

interface Props {
    result: RunResult | null;
    /** Output received so far on a run that is still going. */
    live: { out: string; err: string } | null;
    error: string | null;
    running: boolean;
}

function formatBytes(bytes: number): string {
    return bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/**
 * Shows what came back from a run, including while it is still arriving.
 *
 * The badges matter as much as the text: a container killed for running too
 * long, running out of memory, or printing too much produces little or no
 * output, and "exit code 137" on its own tells the reader nothing.
 */
export default function OutputPane({ result, live, error, running }: Props) {
    if (error) {
        return <p className="p-4 font-mono text-xs text-rose-400">{error}</p>;
    }

    if (running) {
        return (
            <div className="flex h-full flex-col">
                <div className="border-b border-slate-800 px-4 py-2 text-xs text-slate-500">
                    Running…
                </div>

                <div className="flex-1 overflow-auto p-4 font-mono text-xs leading-relaxed">
                    {live?.out && (
                        <pre className="whitespace-pre-wrap text-slate-200">
                            {live.out}
                        </pre>
                    )}
                    {live?.err && (
                        <pre className="mt-2 whitespace-pre-wrap text-rose-400">
                            {live.err}
                        </pre>
                    )}
                </div>
            </div>
        );
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
        result.timedOut &&
            `killed after ${(result.durationMs / 1000).toFixed(1)}s`,
        result.outOfMemory && 'out of memory',
        result.truncated && 'output truncated',
    ].filter(Boolean) as string[];

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

                {result.peakMemoryBytes !== null && (
                    <span
                        className="text-slate-500"
                        title="Peak memory of the container, read from its cgroup"
                    >
                        {formatBytes(result.peakMemoryBytes)} peak
                    </span>
                )}

                <span
                    className="text-slate-600"
                    title={
                        result.warmStart
                            ? 'A container was already started and waiting'
                            : 'No warm container was free, so one was started for this run'
                    }
                >
                    {result.warmStart ? 'warm' : 'cold'}
                </span>

                {badges.map((badge) => (
                    <span
                        key={badge}
                        className="rounded bg-amber-500/15 px-1.5 py-0.5 text-amber-300"
                    >
                        {badge}
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
