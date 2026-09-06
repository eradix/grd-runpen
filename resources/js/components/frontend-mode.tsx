import CodeEditor from '@/components/code-editor';
import { buildPreviewDocument } from '@/lib/preview';
import type { ConsoleEntry, TailwindVersion } from '@/types/playground';
import { useEffect, useMemo, useRef, useState } from 'react';

interface Props {
    html: string;
    css: string;
    js: string;
    tailwind: TailwindVersion;
    onHtmlChange: (value: string) => void;
    onCssChange: (value: string) => void;
    onJsChange: (value: string) => void;
    onTailwindChange: (value: TailwindVersion) => void;
}

const TABS = [
    { key: 'html', label: 'HTML', language: 'html' },
    { key: 'css', label: 'CSS', language: 'css' },
    { key: 'js', label: 'JS', language: 'javascript' },
] as const;

/**
 * Editors on the left, a live preview and its console on the right — the
 * CodePen half. Nothing here touches the server: the pen runs in the visitor's
 * own browser, inside an iframe that is sandboxed with `allow-scripts` but
 * deliberately *not* `allow-same-origin`, so it gets a null origin and cannot
 * read this page's DOM, cookies or storage.
 */
export default function FrontendMode({
    html,
    css,
    js,
    tailwind,
    onHtmlChange,
    onCssChange,
    onJsChange,
    onTailwindChange,
}: Props) {
    const [tab, setTab] = useState<(typeof TABS)[number]['key']>('html');
    const [entries, setEntries] = useState<ConsoleEntry[]>([]);
    const [document_, setDocument] = useState('');
    const frame = useRef<HTMLIFrameElement>(null);
    const nextId = useRef(0);

    const sources = useMemo(
        () => ({ html, css, js, tailwind }),
        [html, css, js, tailwind],
    );

    // Debounced so the preview does not reload on every keystroke; each reload
    // is a fresh document, which is also what clears the console.
    useEffect(() => {
        const timer = window.setTimeout(() => {
            setEntries([]);
            setDocument(buildPreviewDocument(sources));
        }, 400);

        return () => window.clearTimeout(timer);
    }, [sources]);

    // Only messages from our own iframe are trusted. The origin is "null"
    // because of the sandbox, so identity is established by comparing the
    // message's source window against the frame we created.
    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            if (event.source !== frame.current?.contentWindow) {
                return;
            }

            const data = event.data as {
                source?: string;
                level?: ConsoleEntry['level'];
                text?: string;
            };

            if (data?.source !== 'runpen-preview') {
                return;
            }

            setEntries((current) => [
                ...current.slice(-199),
                {
                    level: data.level ?? 'log',
                    text: data.text ?? '',
                    id: nextId.current++,
                },
            ]);
        };

        window.addEventListener('message', onMessage);

        return () => window.removeEventListener('message', onMessage);
    }, []);

    const values: Record<string, string> = { html, css, js };
    const setters: Record<string, (value: string) => void> = {
        html: onHtmlChange,
        css: onCssChange,
        js: onJsChange,
    };

    return (
        <div className="grid flex-1 grid-rows-2 overflow-hidden lg:grid-cols-2 lg:grid-rows-1">
            <section className="flex min-h-0 flex-col border-slate-800 lg:border-r">
                <div className="flex items-center gap-1 border-b border-slate-800 px-2 py-1.5">
                    {TABS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setTab(item.key)}
                            className={`rounded px-2.5 py-1 text-xs ${
                                tab === item.key
                                    ? 'bg-slate-800 text-slate-100'
                                    : 'text-slate-400 hover:text-slate-200'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}

                    <label className="ml-auto flex items-center gap-2 pr-2 text-xs text-slate-400">
                        Tailwind
                        <select
                            value={tailwind ?? 'off'}
                            onChange={(event) =>
                                onTailwindChange(
                                    event.target.value === 'off'
                                        ? null
                                        : (event.target
                                              .value as TailwindVersion),
                                )
                            }
                            className="rounded border border-slate-700 bg-slate-900 px-1.5 py-1 text-xs text-slate-200"
                        >
                            <option value="off">off</option>
                            <option value="4">v4</option>
                            <option value="3">v3</option>
                        </select>
                    </label>
                </div>

                {TABS.map((item) => (
                    <div
                        key={item.key}
                        className={`min-h-0 flex-1 ${tab === item.key ? '' : 'hidden'}`}
                    >
                        <CodeEditor
                            value={values[item.key]}
                            language={item.language}
                            onChange={setters[item.key]}
                        />
                    </div>
                ))}
            </section>

            <section className="grid min-h-0 grid-rows-[1fr_10rem] border-t border-slate-800 lg:border-t-0">
                <iframe
                    ref={frame}
                    title="Preview"
                    sandbox="allow-scripts allow-modals allow-forms"
                    srcDoc={document_}
                    className="h-full w-full bg-white"
                />

                <div className="min-h-0 overflow-auto border-t border-slate-800 bg-slate-950 p-3 font-mono text-xs">
                    {entries.length === 0 ? (
                        <p className="text-slate-600">
                            console output appears here
                        </p>
                    ) : (
                        entries.map((entry) => (
                            <pre
                                key={entry.id}
                                className={`whitespace-pre-wrap ${
                                    entry.level === 'error'
                                        ? 'text-rose-400'
                                        : entry.level === 'warn'
                                          ? 'text-amber-300'
                                          : 'text-slate-300'
                                }`}
                            >
                                {entry.text}
                            </pre>
                        ))
                    )}
                </div>
            </section>
        </div>
    );
}
