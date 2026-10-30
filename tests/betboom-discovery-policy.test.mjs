import test from 'node:test';
import assert from 'node:assert/strict';
import { shouldSleep, QUIET_INTERVAL_MS } from '../resources/js/betboom/discovery-policy.mjs';
const now = Date.parse('2026-10-30T12:00:00Z');
const match = (minutes, type = 2, id = '1') => ({id, type, start:new Date(now+minutes*60000).toISOString()});
test('quiet mode closes connections for five minutes when no match is near',()=>{
 assert.equal(QUIET_INTERVAL_MS,300000);
 assert.equal(shouldSleep([],[],now),true);
 assert.equal(shouldSleep([match(21)],[],now),true);
 assert.equal(shouldSleep([match(20)],[],now),false);
 assert.equal(shouldSleep([match(-30,1)],[],now),false);
 assert.equal(shouldSleep([match(-30,1)],['1'],now),true);
 assert.equal(shouldSleep([match(-721,1)],[],now),true);
 assert.equal(shouldSleep([{id:'1',type:2,start:'bad'}],[],now),false);
});
