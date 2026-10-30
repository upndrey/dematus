import { readFileSync } from 'node:fs';
import { shouldSleep } from './discovery-policy.mjs';
import { decode } from './grid-codec.mjs';
const schemas = JSON.parse(readFileSync(new URL('./tree-schema.json', import.meta.url)));
const registry = new Map(schemas.map(s => [s.symbol, s]));
const root = schemas.find(s => s.name === 'bb.sport_ws.v1.MainResponse');
const emit = x => process.stdout.write(JSON.stringify(x) + '\n');
function vi(n) { const a=[]; do {a.push((n & 127) | (n > 127 ? 128 : 0));n=Math.floor(n/128);} while(n);return Buffer.from(a); }
function msg(n,b) {return Buffer.concat([vi(n*8+2),vi(b.length),b]);}
function val(n,x) {return Buffer.concat([vi(n*8),vi(x)]);}
const ignored = JSON.parse(process.argv[2] ?? '[]');
let ws, timer, retry, stopping=false, failures=0, last=0;
function connect() {
 const matches=new Map(), tournaments=new Map(), subscriptions=new Set();
 const categoriesReady = new Set(), pending = new Set();
 let snapshotValid = true;
 let ready=false, started=Date.now();
 ws=new WebSocket('wss://ru-ws.sporthub.bet/api/tree_ws/v1?uuid=01a0d23a-f305-719c-bb3d-11c5bed388f1', {protocols:['protobuf'],headers:{'User-Agent':'Mozilla/5.0 (compatible; Dematus/1.0)','Origin':'https://betboom.ru'}});
 ws.binaryType='arraybuffer';
 const send=(kind,body)=>ws.send(msg(kind,body));
 const subscribe=(type,id)=>{const key=type+':'+id;if(subscriptions.has(key))return;subscriptions.add(key);pending.add(key);const uid=Buffer.from(key);send(8,Buffer.concat([msg(1,uid),msg(2,Buffer.concat([msg(1,uid),val(2,type),val(3,id)]))]));};
 function collect(t,type) {
  if(!t?.info)return;
  tournaments.set(t.info.id,t.info.name);subscribe(type,t.info.id);
  for(const m of t.matches??[]) {if(m.info?.teams) matches.set(m.info.id,m.info);}
 }
 ws.onopen=()=>{last=Date.now();};
 ws.onmessage=e=>{last=Date.now();try {
  const d=decode(Buffer.from(e.data),root,registry);
  if(d.newsletters_state_ready && !ready){ready=true;send(3,Buffer.concat([msg(1,Buffer.from('settings')),msg(2,Buffer.from('all')),val(3,8)]));for(const type of [1,2])send(7,Buffer.concat([msg(1,Buffer.from('category'+type)),msg(2,Buffer.concat([msg(1,Buffer.from('dota'+type)),val(2,type),val(3,1),val(4,2)]))]));}
  for(const c of d.state_subscribe_categories?.categories??[]) {if(c.code===200){categoriesReady.add(c.type);for(const t of c.category?.tournaments??[])collect(t,c.type);}else snapshotValid=false;}
  for(const t of d.state_subscribe_tournaments?.tournaments??[]) {if(t.code===200){collect(t.tournament,t.type);pending.delete(t.type+':'+t.tournament?.info?.id);}else snapshotValid=false;}
  const update=d.newsletters_match;
  if(update?.action===3 && update.match?.info?.id) {
   const id=update.match.info.id;
   if(matches.get(id)?.type===update.type)matches.delete(id);
   if(update.type===1)emit({event:'ended',matchId:String(id)});
  } else if(update?.match?.info?.teams)matches.set(update.match.info.id,update.match.info);
  const tournament=d.newsletters_tournament;
  if(tournament?.action===3 && tournament.tournament?.info?.id) {
   const id=tournament.tournament.info.id,key=tournament.type+':'+id,uid=Buffer.from(key);
   if(subscriptions.delete(key))send(13,Buffer.concat([msg(1,uid),msg(2,Buffer.concat([msg(1,uid),val(2,tournament.type),val(3,id)]))]));
   for(const [mid,m] of matches)if(m.tournament_id===id && m.type===tournament.type){matches.delete(mid);if(m.type===1)emit({event:'ended',matchId:String(mid)});}
  } else if(tournament?.tournament)collect(tournament.tournament,tournament.type);
  if(Date.now()-started>60000)failures=0;
 }catch {snapshotValid=false;emit({event:'warning',code:'schedule_decode_failed'});}};
 timer=setInterval(()=>{
  if(Date.now()-last>60000){ws.close();return;}
  if(ws.readyState!==1||!ready)return;
  send(1,msg(1,Buffer.from('ping'+Date.now())));
  emit({event:'schedule',matches:[...matches.values()].map(m=>({id:String(m.id),start:m.start_dttm,home:m.teams.home_team?.name,away:m.teams.away_team?.name,tournament:tournaments.get(m.tournament_id)??'',type:m.type}))});
  if(snapshotValid && categoriesReady.has(1) && categoriesReady.has(2) && pending.size===0 && shouldSleep([...matches.values()],ignored)) {
   stopping=true;clearInterval(timer);emit({event:'idle'});ws.close();setTimeout(()=>process.exit(0),1000);
  }
 },15000);
 ws.onerror=()=>ws.close();
 ws.onclose=e=>{clearInterval(timer);if(stopping)return;const delay=e.code===3003?1800000:Math.min(900000,15000*2**Math.min(failures++,6));emit({event:'warning',code:'schedule_disconnected',retryIn:delay});retry=setTimeout(connect,delay+Math.random()*5000);};
}
for(const signal of ['SIGTERM','SIGINT'])process.on(signal,()=>{stopping=true;clearInterval(timer);clearTimeout(retry);ws?.close();process.exit(0);});
connect();
