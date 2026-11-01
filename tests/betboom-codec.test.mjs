import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { decode, request, responseSchema } from '../resources/js/betboom/grid-codec.mjs';

test('recorded BetBoom protobuf frames preserve match, picks and bans', () => {
    const frames = JSON.parse(readFileSync(new URL('./Fixtures/betboom/frames.json', import.meta.url)));
    const matches = frames.map(f => decode(Buffer.from(f, 'base64'), responseSchema))
        .map(m => (m.subscribe_match ?? m.match)?.match).filter(Boolean);
    assert.equal(matches.length, 34);
    assert.ok(matches.every(m => m.id === '6344550'));
    assert.ok(matches.some(m => {
        const road = m.widget.dota.games.find(g => g.current)?.pick_ban_road ?? [];
        return road.filter(p => p.type === 'pick' && p.character).length >= 8 &&
            road.filter(p => p.type === 'ban' && p.character).length >= 10;
    }));
});

test('subscription encoding and truncated packet rejection', () => {
    const schema = JSON.parse(readFileSync(new URL('../resources/js/betboom/grid-schema.json', import.meta.url)));
    assert.deepEqual(decode(request(2, 'test', '6344550'), schema.find(s => s.name === 'bb.grid_widget.MainRequest')),
        { subscribe_match: { uid: 'test', match_id: '6344550' } });
    assert.throws(() => decode(Buffer.from([26, 255]), responseSchema));
});
