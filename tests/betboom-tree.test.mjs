import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { decode } from '../resources/js/betboom/grid-codec.mjs';
test('recorded schedule includes Dota live and prematch categories with usable IDs and UTC times',()=>{
 const schemas=JSON.parse(readFileSync(new URL('../resources/js/betboom/tree-schema.json',import.meta.url)));
 const registry=new Map(schemas.map(s=>[s.symbol,s]));
 const root=schemas.find(s=>s.name==='bb.sport_ws.v1.MainResponse');
 const frames=JSON.parse(readFileSync(new URL('./Fixtures/betboom/tree-frames.json',import.meta.url)));
 const categories=frames.slice(0,4).flatMap(f=>decode(Buffer.from(f,'base64'),root,registry).state_subscribe_categories?.categories??[]);
 assert.deepEqual(categories.map(c=>c.type).sort(),[1,2]);
 assert.equal(categories.find(c=>c.type===2).category.info.matches_count,12);
 const matches=categories.find(c=>c.type===1).category.tournaments.flatMap(t=>t.matches??[]).map(m=>m.info);
 assert.equal(matches.length,2);
 assert.ok(matches.every(m=>m.id>0&&m.category_id===2&&m.teams.home_team.name&&m.start_dttm.endsWith('Z')));
});
