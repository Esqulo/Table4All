/** "há 5 min", "ontem", "12 de nov." — cached formatters, cheap on long feeds. */
const relative = new Map<string, Intl.RelativeTimeFormat>();
const absolute = new Map<string, Intl.DateTimeFormat>();

function locale(language: string): string {
    return language.replace('_', '-');
}

const STEPS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['second', 60],
    ['minute', 60],
    ['hour', 24],
    ['day', 7],
];

export function timeAgo(
    iso: string,
    language: string,
    now = Date.now(),
): string {
    const tag = locale(language);
    const date = new Date(iso);
    let value = (date.getTime() - now) / 1000;

    for (const [unit, size] of STEPS) {
        if (Math.abs(value) < size) {
            let format = relative.get(tag);

            if (!format) {
                format = new Intl.RelativeTimeFormat(tag, { numeric: 'auto' });
                relative.set(tag, format);
            }

            return format.format(Math.round(value), unit);
        }

        value /= size;
    }

    let format = absolute.get(tag);

    if (!format) {
        format = new Intl.DateTimeFormat(tag, {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
        absolute.set(tag, format);
    }

    return format.format(date);
}
