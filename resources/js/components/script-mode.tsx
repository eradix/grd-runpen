import CodeEditor from '@/components/code-editor';
import OutputPane from '@/components/output-pane';
import type { Language, RunResult } from '@/types/playground';

interface Props {
    languages: Language[];
    language: string;
    source: string;
    stdin: string;
    showStdin: boolean;
    result: RunResult | null;
    error: string | null;
    running: boolean;
    onSourceChange: (value: string) => void;
    onStdinChange: (value: string) => void;
    onToggleStdin: () => void;
    onRun: () => void;
}

/** Editor on the left, program output on the right — the onlinephp.io half. */
export default function ScriptMode({
    languages,
    language,
    source,
    stdin,
    showStdin,
    result,
    error,
    running,
    onSourceChange,
    onStdinChange,
    onToggleStdin,
    onRun,
}: Props) {
    const editorLanguage =
        languages.find((item) => item.key === language)?.editor ?? 'php';

    return (
        <div className="grid flex-1 grid-rows-2 overflow-hidden lg:grid-cols-2 lg:grid-rows-1">
            <section className="flex min-h-0 flex-col border-slate-800 lg:border-r">
                <div className="min-h-0 flex-1">
                    <CodeEditor
                        value={source}
                        language={editorLanguage}
                        onChange={onSourceChange}
                        onRun={onRun}
                    />
                </div>

                <div className="border-t border-slate-800">
                    <button
                        type="button"
                        onClick={onToggleStdin}
                        className="w-full px-4 py-1.5 text-left text-xs text-slate-400 hover:text-slate-200"
                    >
                        {showStdin ? '▾' : '▸'} Standard input
                    </button>

                    {showStdin && (
                        <textarea
                            value={stdin}
                            onChange={(event) =>
                                onStdinChange(event.target.value)
                            }
                            spellCheck={false}
                            placeholder="Piped to the program's stdin"
                            className="h-24 w-full resize-none border-t border-slate-800 bg-slate-950 px-4 py-2 font-mono text-xs text-slate-200 outline-none placeholder:text-slate-600"
                        />
                    )}
                </div>
            </section>

            <section className="min-h-0 overflow-hidden border-t border-slate-800 lg:border-t-0">
                <OutputPane result={result} error={error} running={running} />
            </section>
        </div>
    );
}
