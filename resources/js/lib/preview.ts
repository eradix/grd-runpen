import type { TailwindVersion } from '@/types/playground';

const TAILWIND_SOURCES: Record<Exclude<TailwindVersion, null>, string> = {
    // v3's Play CDN compiles utilities in the browser; v4 ships a separate
    // browser build instead, and the two take different config, which is why
    // the version is a user-visible choice rather than something we guess.
    '3': 'https://cdn.tailwindcss.com',
    '4': 'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4',
};

/**
 * The shim injected into every preview.
 *
 * The iframe is sandboxed without `allow-same-origin`, so its window is a
 * different origin from ours and cannot reach into this page. postMessage is
 * therefore the only channel back, and this is what makes console output from a
 * pen visible without ever giving the pen access to the parent document.
 */
const CONSOLE_SHIM = `
<script>
(function () {
    var send = function (level, args) {
        var text = Array.prototype.map.call(args, function (value) {
            if (typeof value === 'string') return value;
            try { return JSON.stringify(value, null, 2); } catch (e) { return String(value); }
        }).join(' ');

        parent.postMessage({ source: 'runpen-preview', level: level, text: text }, '*');
    };

    ['log', 'info', 'warn', 'error'].forEach(function (level) {
        var original = console[level].bind(console);
        console[level] = function () { send(level, arguments); original.apply(null, arguments); };
    });

    window.addEventListener('error', function (event) {
        send('error', [event.message + ' (line ' + event.lineno + ')']);
    });

    window.addEventListener('unhandledrejection', function (event) {
        send('error', ['Unhandled promise rejection: ' + event.reason]);
    });
})();
</script>`;

export interface PreviewSources {
    html: string;
    css: string;
    js: string;
    tailwind: TailwindVersion;
}

/**
 * Assembles the document handed to the preview iframe's srcdoc.
 *
 * The shim goes first so it captures console calls made while the rest of the
 * page is still parsing; the pen's own script goes last so it sees a complete
 * DOM without needing a DOMContentLoaded wrapper.
 */
export function buildPreviewDocument({
    html,
    css,
    js,
    tailwind,
}: PreviewSources): string {
    const tailwindTag = tailwind
        ? `<script src="${TAILWIND_SOURCES[tailwind]}"></script>`
        : '';

    return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
${CONSOLE_SHIM}
${tailwindTag}
<style>${css}</style>
</head>
<body>
${html}
<script>
try {
${js}
} catch (error) {
    console.error(error && error.message ? error.message : String(error));
}
</script>
</body>
</html>`;
}
