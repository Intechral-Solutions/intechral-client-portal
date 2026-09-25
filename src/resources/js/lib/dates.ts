/**
 * Date-only values (`YYYY-MM-DD`: due dates, entry dates, target dates) are calendar days, not
 * instants. They are formatted in UTC so the browser's timezone can never shift the day.
 */
export function formatDate(date: string): string {
    const value = new Date(`${date}T00:00:00Z`);

    if (Number.isNaN(value.getTime())) return date;

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(value);
}

/** ISO-8601 timestamps are instants: shown in the viewer's locale and timezone. */
export function formatTimestamp(timestamp: string): string {
    const value = new Date(timestamp);

    if (Number.isNaN(value.getTime())) return timestamp;

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(value);
}
