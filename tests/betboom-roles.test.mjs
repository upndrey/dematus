import test from 'node:test';
import assert from 'node:assert/strict';
import { loadRoles } from '../resources/js/betboom/roles.mjs';

const url = 'https://example.test/?homeTeamId=home&awayTeamId=away&brandToken=test';
const statistics = { teamStatistics: [{ players: [{ player: { id: Buffer.from('od:player:1').toString('base64') }, primaryRole: 'MID_LANER' }] }] };
function fake(results) {
    const calls = [];
    return { calls, fetcher: async (_, options) => {
        calls.push(JSON.parse(options.body).variables);
        const next = results.shift();
        if (next instanceof Error) throw next;
        return { ok: next?.ok ?? true, json: async () => next?.errors ? next : { data: { dota2TeamsHistoricalStatistics: next } } };
    } };
}
test('successful combined response needs only one request', async () => {
    const f = fake([statistics]);
    assert.equal((await loadRoles(url, f.fetcher)).get('od:player:1'), 'MID_LANER');
    assert.deepEqual(f.calls, [{ homeTeamId: 'home', awayTeamId: 'away' }]);
});
test('null combined response retrieves each team independently', async () => {
    const f = fake([null, null, statistics]);
    assert.equal((await loadRoles(url, f.fetcher)).get('od:player:1'), 'MID_LANER');
    assert.deepEqual(f.calls, [{ homeTeamId: 'home', awayTeamId: 'away' }, { homeTeamId: 'home', awayTeamId: 'home' }, { homeTeamId: 'away', awayTeamId: 'away' }]);
});
test('one failed team does not discard the other teams roles', async () => {
    const f = fake([null, new Error('timeout'), statistics]);
    let warnings = 0;
    assert.equal((await loadRoles(url, f.fetcher, () => warnings++)).size, 1);
    assert.equal(warnings, 1);
});
test('empty but non-null statistics and rejected queries do not cause extra requests', async () => {
    for (const result of [{ teamStatistics: [] }, { ok: false }, { errors: [{ message: 'unavailable' }] }]) {
        const f = fake([result]);
        if (result.teamStatistics) assert.equal((await loadRoles(url, f.fetcher)).size, 0);
        else await assert.rejects(loadRoles(url, f.fetcher));
        assert.equal(f.calls.length, 1);
    }
});
test('missing roles remain empty and duplicate teams are requested once', async () => {
    const f = fake([null, null]);
    assert.equal((await loadRoles(url.replace('awayTeamId=away', 'awayTeamId=home'), f.fetcher)).size, 0);
    assert.equal(f.calls.length, 2);
});
