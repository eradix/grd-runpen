import FrontendMode from '@/components/frontend-mode';
import ScriptMode from '@/components/script-mode';
import { post } from '@/lib/api';
import { clearDraft, readDraft, writeDraft } from '@/lib/draft';
import type {
    Language,
    Mode,
    RunResult,
    Snippet,
    TailwindVersion,
} from '@/types/playground';
import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface Props {
    languages: Language[];
    snippet: Snippet | null;
}

const DEFAULT_HTML = `<div class="p-8 font-sans">
  <h1 class="text-2xl font-semibold">Runpen</h1>
  <p class="text-slate-600">Edit the panes; this preview reloads as you type.</p>
  <button id="ping" class="mt-4 rounded bg-slate-900 px-3 py-1.5 text-white">Log something</button>
</div>`;

const DEFAULT_JS = `document.getElementById('ping').addEventListener('click', () => {
  console.log('clicked at', new Date().toISOString());
});`;

export default function Playground({ languages, snippet }: Props) {
    const [mode, setMode] = useState<Mode>(snippet?.mode ?? 'script');

    // Script mode.
    const [language, setLanguage] = useState(
        snippet?.language ?? languages[0].key,
    );
    const [source, setSource] = useState(
        snippet?.source ??
            languages.find(
                (item) => item.key === (snippet?.language ?? languages[0].key),
            )?.sample ??
            '',
    );
    const [stdin, setStdin] = useState(snippet?.stdin ?? '');
    const [showStdin, setShowStdin] = useState(Boolean(snippet?.stdin));
    const [result, setResult] = useState<RunResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [running, setRunning] = useState(false);

    // Frontend mode.
    const [html, setHtml] = useState(snippet?.html ?? DEFAULT_HTML);
    const [css, setCss] = useState(snippet?.css ?? '');
    const [js, setJs] = useState(snippet?.js ?? DEFAULT_JS);
    const [tailwind, setTailwind] = useState<TailwindVersion>(
        snippet?.tailwind ?? '4',
    );

    const [sharing, setSharing] = useState(false);
    const [shared, setShared] = useState<string | null>(null);

    // Restoring happens after mount rather than in the initial state: this page
    // is server-rendered, and reading localStorage during render would make the
    // first client render disagree with the server's.
    const restored = useRef(false);

    useEffect(() => {
        if (restored.current) {
            return;
        }

        restored.current = true;

        // A shared link must show the snippet it points at, never a local draft.
        if (snippet) {
            return;
        }

        const draft = readDraft();

        if (!draft) {
            return;
        }

        setMode(draft.mode);
        setLanguage(draft.language);
        setSource(draft.source);
        setStdin(draft.stdin);
        setShowStdin(Boolean(draft.stdin));
        setHtml(draft.html);
        setCss(draft.css);
        setJs(draft.js);
        setTailwind(draft.tailwind);
    }, [snippet]);

    // Saved on a delay so typing does not hit localStorage on every keystroke.
    useEffect(() => {
        if (!restored.current) {
            return;
        }

        const timer = window.setTimeout(
            () =>
                writeDraft({
                    mode,
                    language,
                    source,
                    stdin,
                    html,
                    css,
                    js,
                    tailwind,
                }),
            500,
        );

        return () => window.clearTimeout(timer);
    }, [mode, language, source, stdin, html, css, js, tailwind]);

    /**
     * Switching language swaps in that language's sample, but only when the
     * editor still holds another sample — never when it holds something the
     * visitor typed or a snippet they opened.
     */
    const changeLanguage = (next: string) => {
        const samples = languages.map((item) => item.sample);

        setLanguage(next);

        if (samples.includes(source) || source.trim() === '') {
            setSource(
                languages.find((item) => item.key === next)?.sample ?? '',
            );
        }
    };

    const run = useCallback(async () => {
        setRunning(true);
        setError(null);

        try {
            setResult(
                await post<RunResult>('/run', {
                    language,
                    code: source,
                    stdin,
                }),
            );
        } catch (exception) {
            setResult(null);
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'Something went wrong.',
            );
        } finally {
            setRunning(false);
        }
    }, [language, source, stdin]);

    /** Throws away the local draft and starts from the language's sample. */
    const reset = () => {
        clearDraft();

        setSource(
            languages.find((item) => item.key === language)?.sample ?? '',
        );
        setStdin('');
        setResult(null);
        setError(null);
        setHtml(DEFAULT_HTML);
        setCss('');
        setJs(DEFAULT_JS);
        setTailwind('4');

        window.history.replaceState({}, '', '/');
    };

    const share = async () => {
        setSharing(true);

        try {
            const payload =
                mode === 'script'
                    ? { mode, language, source, stdin }
                    : { mode, html, css, js, tailwind };

            const { url } = await post<{ slug: string; url: string }>(
                '/snippets',
                payload,
            );

            // The address bar is updated rather than navigated: the editor keeps
            // its state, and the link is now shareable.
            window.history.replaceState({}, '', new URL(url).pathname);

            await navigator.clipboard?.writeText(url).catch(() => undefined);

            setShared(url);
            window.setTimeout(() => setShared(null), 4000);
        } catch (exception) {
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'Could not save.',
            );
        } finally {
            setSharing(false);
        }
    };

    const active = languages.find((item) => item.key === language);

    return (
        <div className="flex h-screen flex-col bg-slate-950 text-slate-100">
            <Head
                title={
                    mode === 'script'
                        ? `${active?.label} playground`
                        : 'Frontend playground'
                }
            />

            <header className="flex flex-wrap items-center gap-3 border-b border-slate-800 px-4 py-2">
                <span className="font-semibold tracking-tight">Runpen</span>

                <div className="flex rounded border border-slate-800 p-0.5 text-xs">
                    {(['script', 'frontend'] as const).map((item) => (
                        <button
                            key={item}
                            type="button"
                            onClick={() => setMode(item)}
                            className={`rounded px-2.5 py-1 capitalize ${
                                mode === item
                                    ? 'bg-slate-800 text-slate-100'
                                    : 'text-slate-400 hover:text-slate-200'
                            }`}
                        >
                            {item}
                        </button>
                    ))}
                </div>

                {mode === 'script' && (
                    <>
                        <select
                            value={language}
                            onChange={(event) =>
                                changeLanguage(event.target.value)
                            }
                            className="rounded border border-slate-700 bg-slate-900 px-2 py-1 text-xs"
                        >
                            {languages.map((item) => (
                                <option key={item.key} value={item.key}>
                                    {item.label} {item.version}
                                </option>
                            ))}
                        </select>

                        <button
                            type="button"
                            onClick={run}
                            disabled={running}
                            className="rounded bg-emerald-500 px-3 py-1 text-xs font-medium text-slate-950 hover:bg-emerald-400 disabled:opacity-50"
                        >
                            {running ? 'Running…' : 'Run'}
                        </button>
                    </>
                )}

                <div className="ml-auto flex items-center gap-3">
                    {shared && (
                        <span className="text-xs text-emerald-400">
                            Link copied
                        </span>
                    )}

                    <button
                        type="button"
                        onClick={reset}
                        title="Discard the local draft and start from the sample"
                        className="rounded border border-slate-700 px-3 py-1 text-xs hover:border-slate-500"
                    >
                        Reset
                    </button>

                    <button
                        type="button"
                        onClick={share}
                        disabled={sharing}
                        className="rounded border border-slate-700 px-3 py-1 text-xs hover:border-slate-500 disabled:opacity-50"
                    >
                        {sharing ? 'Saving…' : 'Share'}
                    </button>
                </div>
            </header>

            {mode === 'script' ? (
                <ScriptMode
                    languages={languages}
                    language={language}
                    source={source}
                    stdin={stdin}
                    showStdin={showStdin}
                    result={result}
                    error={error}
                    running={running}
                    onSourceChange={setSource}
                    onStdinChange={setStdin}
                    onToggleStdin={() => setShowStdin((value) => !value)}
                    onRun={run}
                />
            ) : (
                <FrontendMode
                    html={html}
                    css={css}
                    js={js}
                    tailwind={tailwind}
                    onHtmlChange={setHtml}
                    onCssChange={setCss}
                    onJsChange={setJs}
                    onTailwindChange={setTailwind}
                />
            )}
        </div>
    );
}
