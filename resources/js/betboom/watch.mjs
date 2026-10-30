import { decode, responseSchema, request } from './grid-codec.mjs';

const matchId = process.argv[2];
if (!/^\d+$/.test(matchId ?? '')) throw new Error('A numeric match ID is required');
const emit = value => process.stdout.write(JSON.stringify(value) + '\n');
const roles = new Map();
let socket, heartbeat, retry, stopping = false, lastMessage = 0, lastEmit = 0, lastSignature = '';
const roads = new Map();
const scheduled = Date.parse(process.argv[3] ?? '');
let failures = 0, pauseUntil = 0, socketUrl = null;
function retryDelay() {
 const difference = scheduled - Date.now();
 const base = Number.isFinite(scheduled) && (difference > 120000 || difference < -900000) ? 60000 : 30000;
 return Math.max(pauseUntil-Date.now(),Math.min(900000,base*2**Math.min(failures,5))) + Math.random()*3000;
}

async function metadata() {
    const response = await fetch('https://siteapi.betboom.ru/api/site_api/v1/sporthub/tree/get_match_details_info', {
        method: 'POST', headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ match_id: Number(matchId), language: 'LANGUAGES_RU', theme: 'THEMES_BLACK' }),
        signal: AbortSignal.timeout(15000),
    });
    if ([401,403,429].includes(response.status)) {
        const header = response.headers.get('retry-after');
        const seconds = Number(header);
        pauseUntil = Date.now() + Math.max(1800000, Number.isFinite(seconds) ? seconds*1000 : (Date.parse(header)-Date.now() || 0));
    }
    if (!response.ok) { failures++; throw new Error('metadata'); }
    const data = await response.json();
    const url = new URL(data.live_stat_socket?.url);
    if (url.protocol !== 'wss:' || url.hostname !== 'ru-ws.sporthub.bet') throw new Error('unsupported');
    const statistics = data.widget_groups?.flatMap(g => g.widgets ?? []).find(w => w.state === 'WIDGET_STATE_STAT');
    if (statistics?.url) {
        try {
            const params = new URL(statistics.url).searchParams;
            const response = await fetch('https://oddin-api-disir.sporthub.bet:470/main/disir/query', {
                method: 'POST',
                headers: { 'content-type': 'application/json', 'x-api-key': params.get('brandToken'), 'x-locale': 'ru' },
                body: JSON.stringify({
                    variables: { homeTeamId: params.get('homeTeamId'), awayTeamId: params.get('awayTeamId') },
                    query: 'query ($homeTeamId: ID!, $awayTeamId: ID!) { dota2TeamsHistoricalStatistics(timeframe: THREE_MONTHS, homeTeamId: $homeTeamId, awayTeamId: $awayTeamId) { teamStatistics { team { id name } players { player { id nickname } primaryRole } } } }',
                }), signal: AbortSignal.timeout(10000),
            });
            const body = await response.json();
            roles.clear();
            for (const team of body.data?.dota2TeamsHistoricalStatistics?.teamStatistics ?? []) {
                for (const player of team.players ?? []) {
                    roles.set(Buffer.from(player.player.id, 'base64').toString(), player.primaryRole);
                }
            }
        } catch { emit({ event: 'warning', code: 'roles_unavailable' }); }
    }
    socketUrl = url.href;
    return socketUrl;
}

async function connect() {
    try {
        socket = new WebSocket(socketUrl ?? await metadata());
        socket.binaryType = 'arraybuffer';
        socket.addEventListener('open', () => {
            lastMessage = Date.now();
            socket.send(request(2, 'subscribe-' + Date.now(), matchId));
            heartbeat = setInterval(() => {
                if (Date.now() - lastMessage > 45000) { socket.close(); return; }
                if (socket.readyState === 1) {
                    socket.send(request(1, 'ping-' + Date.now()));
                    socket.send(request(2, 'refresh-' + Date.now(), matchId));
                }
            }, 15000);
        });
        socket.addEventListener('message', event => {
            try {
                const message = decode(Buffer.from(event.data), responseSchema);
                const match = (message.subscribe_match ?? message.match)?.match;
                const dota = match?.widget?.dota;
                if (!dota || String(match.id) !== matchId) return;
                lastMessage = Date.now();
                failures = 0;
                emit({ event: 'connected', matchId });
                const game = dota.games?.find(g => g.current);
                if (!game) return;
                if (game.pick_ban_road?.length) roads.set(game.sequence, game.pick_ban_road);
                const players = (game.teams ?? []).flatMap(team => (team.players ?? []).filter(p => p.character).map(p => ({
                    teamId: team.id, teamName: dota.teams?.find(t => t.id === team.id)?.name ?? team.id,
                    side: team.side, playerId: p.id,
                    playerName: dota.teams?.flatMap(t => t.players ?? []).find(x => x.id === p.id)?.name ?? p.id,
                    hero: p.character.id, role: roles.get(p.id) ?? null,
                })));
                const payload = { event: 'draft', matchId, map: game.sequence, status: dota.status, players, draft: roads.get(game.sequence) ?? [] };
                const signature = JSON.stringify(payload);
                if (signature !== lastSignature || Date.now() - lastEmit >= 5000) {
                    lastSignature = signature; lastEmit = Date.now();
                    emit({ ...payload, receivedAt: lastEmit });
                }
            } catch { emit({ event: 'warning', code: 'decode_failed' }); }
        });
        socket.addEventListener('error', () => socket.close());
        socket.addEventListener('close', event => { failures++; if(event.code===3003)pauseUntil=Date.now()+1800000; clearInterval(heartbeat); if (!stopping) retry = setTimeout(connect, retryDelay()); });
    } catch {
        emit({ event: 'warning', code: 'source_unavailable' });
        if (!stopping) retry = setTimeout(connect, retryDelay());
    }
}
for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, () => {
    stopping = true; clearTimeout(retry); clearInterval(heartbeat); socket?.close(); process.exit(0);
});
await connect();
