/**
 * Whole minutes as "3h 20m" ("45m", "2h"): formatting only, never arithmetic over entries. The
 * Overview's time summary and the Project Time tab share it so the same total reads the same on both
 * (EPIC-015 §10: the Overview and the Time workspace never disagree).
 */
export function formatMinutes(totalMinutes: number): string {
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours === 0) return `${minutes}m`;
    if (minutes === 0) return `${hours}h`;

    return `${hours}h ${minutes}m`;
}
