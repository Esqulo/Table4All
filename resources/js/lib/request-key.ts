/**
 * One key per logical write (an order, a payment). The server processes each
 * key once, so re-sending after a timeout or a double tap can't duplicate it.
 * See App\Support\Idempotency.
 *
 * crypto.randomUUID only exists in secure contexts (HTTPS / localhost) and
 * recent browsers; restaurants often reach the app over plain HTTP on the LAN
 * from old devices, so fall back to getRandomValues, then Math.random.
 */
export function newRequestKey(): string {
    const c = typeof crypto !== 'undefined' ? crypto : undefined;

    if (c && typeof c.randomUUID === 'function') {
        try {
            return c.randomUUID();
        } catch {
            // Not a secure context; fall through.
        }
    }

    if (c && typeof c.getRandomValues === 'function') {
        const bytes = c.getRandomValues(new Uint8Array(16));

        return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join(
            '',
        );
    }

    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}
