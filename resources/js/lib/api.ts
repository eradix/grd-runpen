/**
 * Two POSTs, both to our own origin, both CSRF-protected.
 *
 * Inertia's router isn't used here because neither call navigates: running a
 * snippet and saving one both just return JSON that updates part of the page.
 */
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
