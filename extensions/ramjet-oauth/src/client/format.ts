/** Shortens an identifier in the middle: 01a12dc9-cd2c-702a-afed-8ee180829693 becomes 01a12dc9…829693. */
export const middleTruncate = (value: string, head = 8, tail = 6): string =>
    value.length <= head + tail + 1 ? value : `${value.slice(0, head)}…${value.slice(-tail)}`;

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['week', 604_800],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
];

/** "3 days ago", "in 2 hours", or "just now" for anything within a minute. `now` is injectable for tests. */
export const relativeTime = (iso: string, now: number = Date.now(), locale?: string): string => {
    const time = Date.parse(iso);

    if (Number.isNaN(time)) {
        return iso;
    }

    const seconds = Math.round((time - now) / 1000);

    if (Math.abs(seconds) < 60) {
        return 'just now';
    }

    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'always' });
    const unit = UNITS.find(([, size]) => Math.abs(seconds) >= size);

    return unit
        ? formatter.format(Math.round(seconds / unit[1]), unit[0])
        : formatter.format(Math.round(seconds / 60), 'minute');
};

/** The absolute time for a tooltip, in the reader's locale. */
export const absoluteTime = (iso: string, locale?: string): string => {
    const time = new Date(iso);

    return Number.isNaN(time.getTime())
        ? iso
        : time.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' });
};
