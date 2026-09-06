import type { RecentSnippet } from '@/types/playground';
import { Head, Link } from '@inertiajs/react';

interface Props {
    snippets: RecentSnippet[];
}

/**
 * The last fifty snippets anyone shared.
 *
 * Saving is what mints a link, so everything here is public by construction —
 * there is no visibility flag and nothing to filter. Rows show a first line
 * rather than the source: enough to recognise something you saved, not enough
 * to read it here instead of opening it.
 */
export default function Recent({ snippets }: Props) {
    return (
        <div className="min-h-screen bg-slate-950 text-slate-100">
            <Head title="Recent snippets" />

            <header className="flex items-center gap-3 border-b border-slate-800 px-4 py-2">
                <Link href="/" className="font-semibold tracking-tight">
                    Runpen
                </Link>
                <span className="text-xs text-slate-500">Recent snippets</span>

                <Link
                    href="/"
                    className="ml-auto rounded border border-slate-700 px-3 py-1 text-xs hover:border-slate-500"
                >
                    New snippet
                </Link>
            </header>

            <main className="mx-auto max-w-3xl p-4">
                {snippets.length === 0 ? (
                    <p className="py-16 text-center text-sm text-slate-500">
                        Nothing shared yet. Press{' '}
                        <span className="text-slate-300">Share</span> on a
                        snippet and it will show up here.
                    </p>
                ) : (
                    <ul className="divide-y divide-slate-800">
                        {snippets.map((snippet) => (
                            <li key={snippet.slug}>
                                <Link
                                    href={`/p/${snippet.slug}`}
                                    className="flex items-baseline gap-3 py-3 hover:bg-slate-900/60"
                                >
                                    <span className="w-20 shrink-0 text-xs text-slate-400">
                                        {snippet.label}
                                    </span>

                                    <span className="min-w-0 flex-1 truncate font-mono text-xs text-slate-300">
                                        {snippet.preview}
                                    </span>

                                    <span className="shrink-0 text-xs text-slate-600">
                                        {snippet.createdAt}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </main>
        </div>
    );
}
