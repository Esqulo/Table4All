/**
 * Progressive Brazilian phone mask, applied while typing.
 * 10 digits → (31) 3333-4444 (landline), 11 digits → (31) 99999-9999 (mobile).
 * Anything that is not a digit is dropped and input is capped at 11 digits.
 * A pasted +55 country code (e.g. "+55 31 99999-9999", as WhatsApp copies it) is removed.
 * Text already in the mask format "(DD) ..." is never stripped, since 55 is also a real DDD.
 */
export function formatBrPhone(value: string): string {
    const raw = value.trim();
    let digits = raw.replace(/\D/g, '');

    if (
        !raw.startsWith('(') &&
        digits.startsWith('55') &&
        (digits.length === 12 || digits.length === 13)
    ) {
        digits = digits.slice(2);
    }

    digits = digits.slice(0, 11);

    if (digits.length === 0) {
        return '';
    }

    if (digits.length <= 2) {
        return `(${digits}`;
    }

    const ddd = digits.slice(0, 2);
    const rest = digits.slice(2);
    const split = digits.length === 11 ? 5 : 4;

    if (rest.length <= split) {
        return `(${ddd}) ${rest}`;
    }

    return `(${ddd}) ${rest.slice(0, split)}-${rest.slice(split)}`;
}
