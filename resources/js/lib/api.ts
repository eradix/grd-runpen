/**
 * Two POSTs, both to our own origin, both CSRF-protected.
 *
 * Inertia's router isn't used here because neither call navigates: running a
 * snippet and saving one both just return JSON that updates part of the page.
 */
import type { RunResult } from '@/types/playground';

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function post<T>(url: string, body: unknown): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(
            payload.message ?? `Request failed (${response.status}).`,
        );
    }

    return payload as T;
}

export { post };

/**
 * Runs a snippet, streaming its output back as it is produced.
 *
 * The endpoint answers with server-sent events, but EventSource cannot POST, so
 * the stream is read off the fetch body and the frames are parsed by hand —
 * which is little more than splitting on a blank line. Anything that goes wrong
 * *before* the stream starts (validation, no free slot) comes back as ordinary
 * JSON with a real status code, and is thrown.
 */
export async function runStream(
    body: unknown,
    onOutput: (stream: 'out' | 'err', text: string) => void,
): Promise<RunResult> {
    const response = await fetch('/run', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'text/event-stream',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok || !response.body) {
        const payload = await response.json().catch(() => ({}));

        throw new Error(
            payload.message ?? `Request failed (${response.status}).`,
        );
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let result: RunResult | null = null;

    for (;;) {
        const { value, done } = await reader.read();

        if (done) {
            break;
        }

        buffer += decoder.decode(value, { stream: true });

        // A frame ends at a blank line; whatever follows the last one is a
        // partial frame and stays in the buffer until the next chunk.
        const frames = buffer.split('\n\n');
        buffer = frames.pop() ?? '';

        for (const frame of frames) {
            const event = /^event: (.+)$/m.exec(frame)?.[1];
            const data = /^data: (.+)$/m.exec(frame)?.[1];

            if (!event || !data) {
                continue;
            }

            if (event === 'output') {
                const chunk = JSON.parse(data) as {
                    stream: 'out' | 'err';
                    text: string;
                };
                onOutput(chunk.stream, chunk.text);
            }

            if (event === 'done') {
                result = JSON.parse(data) as RunResult;
            }
        }
    }

    if (!result) {
        throw new Error('The run ended without a result.');
    }

    return result;
}
