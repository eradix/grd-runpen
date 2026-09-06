import { Compartment, EditorState, type Extension } from '@codemirror/state';
import { oneDark } from '@codemirror/theme-one-dark';
import { EditorView, keymap } from '@codemirror/view';
import { basicSetup } from 'codemirror';
import { useEffect, useRef } from 'react';

/**
 * Language support is imported on demand.
 *
 * The five grammars together are most of this page's JavaScript, and nobody
 * uses all five in one sitting — a PHP snippet has no reason to download the
 * HTML and Python parsers. Each one resolves once and is then cached by the
 * bundler's module registry.
 */
const LANGUAGES: Record<string, () => Promise<Extension>> = {
    php: () => import('@codemirror/lang-php').then((module) => module.php()),
    python: () =>
        import('@codemirror/lang-python').then((module) => module.python()),
    javascript: () =>
        import('@codemirror/lang-javascript').then((module) =>
            module.javascript(),
        ),
    html: () => import('@codemirror/lang-html').then((module) => module.html()),
    css: () => import('@codemirror/lang-css').then((module) => module.css()),
};

interface Props {
    value: string;
    language: keyof typeof LANGUAGES;
    onChange: (value: string) => void;
    onRun?: () => void;
}

/**
 * A CodeMirror 6 editor wrapped for React.
 *
 * CodeMirror owns its own DOM and state, so the view is created once and then
 * *updated* — never re-rendered by React. Two things follow: the language lives
 * in a Compartment so switching languages reconfigures the existing view, and
 * incoming `value` changes are only pushed into the document when they differ
 * from what is already there, which stops the cursor jumping while typing.
 */
export default function CodeEditor({
    value,
    language,
    onChange,
    onRun,
}: Props) {
    const container = useRef<HTMLDivElement>(null);
    const view = useRef<EditorView | null>(null);
    const languageCompartment = useRef(new Compartment());

    // Kept in a ref so the keymap and update listener always call the latest
    // callback without the editor being torn down on every render.
    const handlers = useRef({ onChange, onRun });
    handlers.current = { onChange, onRun };

    useEffect(() => {
        if (!container.current) {
            return;
        }

        const editor = new EditorView({
            parent: container.current,
            state: EditorState.create({
                doc: value,
                extensions: [
                    basicSetup,
                    oneDark,
                    // Starts plain; the grammar is swapped in below once it loads.
                    languageCompartment.current.of([]),
                    keymap.of([
                        {
                            key: 'Mod-Enter',
                            preventDefault: true,
                            run: () => {
                                handlers.current.onRun?.();

                                return true;
                            },
                        },
                    ]),
                    EditorView.updateListener.of((update) => {
                        if (update.docChanged) {
                            handlers.current.onChange(
                                update.state.doc.toString(),
                            );
                        }
                    }),
                    EditorView.theme({
                        '&': { height: '100%', fontSize: '13px' },
                        '.cm-scroller': {
                            fontFamily:
                                'ui-monospace, SFMono-Regular, Menlo, monospace',
                        },
                    }),
                ],
            }),
        });

        view.current = editor;

        return () => {
            editor.destroy();
            view.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        let cancelled = false;

        void (LANGUAGES[language]?.() ?? Promise.resolve([] as Extension)).then(
            (extension) => {
                // The editor may have been unmounted, or the language switched
                // again, while the grammar was still downloading.
                if (!cancelled) {
                    view.current?.dispatch({
                        effects:
                            languageCompartment.current.reconfigure(extension),
                    });
                }
            },
        );

        return () => {
            cancelled = true;
        };
    }, [language]);

    useEffect(() => {
        const editor = view.current;

        if (!editor || editor.state.doc.toString() === value) {
            return;
        }

        editor.dispatch({
            changes: { from: 0, to: editor.state.doc.length, insert: value },
        });
    }, [value]);

    return <div ref={container} className="h-full overflow-hidden" />;
}
