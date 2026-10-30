export const QUIET_INTERVAL_MS = 300_000;
export const NEAR_MATCH_MS = 20 * 60_000;
export function shouldSleep(matches, ignored = [], now = Date.now()) {
    const excluded = new Set(ignored.map(String));
    return !matches.some(match => {
        if (excluded.has(String(match.id))) return false;
        const start = Date.parse(match.start_dttm ?? match.start);
        if (!Number.isFinite(start)) return true;
        if (start < now - 12 * 60 * 60_000) return false;
        return match.type === 1 || start <= now + NEAR_MATCH_MS;
    });
}
