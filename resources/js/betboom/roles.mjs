export async function loadRoles(statisticsUrl, fetcher = fetch, warn = () => {}) {
    const params = new URL(statisticsUrl).searchParams;
    const home = params.get('homeTeamId'), away = params.get('awayTeamId');
    const roles = new Map();
    if (!home || !away || !params.get('brandToken')) return roles;
    async function query(homeTeamId, awayTeamId) {
        const response = await fetcher('https://oddin-api-disir.sporthub.bet:470/main/disir/query', {
            method: 'POST',
            headers: { 'content-type': 'application/json', 'x-api-key': params.get('brandToken'), 'x-locale': 'ru' },
            body: JSON.stringify({ variables: { homeTeamId, awayTeamId }, query: 'query ($homeTeamId: ID!, $awayTeamId: ID!) { dota2TeamsHistoricalStatistics(timeframe: THREE_MONTHS, homeTeamId: $homeTeamId, awayTeamId: $awayTeamId) { teamStatistics { team { id name } players { player { id nickname } primaryRole } } } }' }),
            signal: AbortSignal.timeout(10000),
        });
        if (!response.ok) throw new Error('roles_unavailable');
        const body = await response.json();
        if (body.errors?.length || !Object.hasOwn(body.data ?? {}, 'dota2TeamsHistoricalStatistics')) throw new Error('roles_unavailable');
        return body.data.dota2TeamsHistoricalStatistics;
    }
    function collect(statistics) {
        for (const team of statistics?.teamStatistics ?? []) {
            for (const entry of team.players ?? []) {
                if (entry.player?.id && entry.primaryRole) roles.set(Buffer.from(entry.player.id, 'base64').toString(), entry.primaryRole);
            }
        }
    }
    const combined = await query(home, away);
    if (combined !== null) {
        collect(combined);
        return roles;
    }
    for (const teamId of new Set([home, away])) {
        try { collect(await query(teamId, teamId)); }
        catch { warn(); }
    }
    return roles;
}
