import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Script, createContext } from 'node:vm';
import { shouldSleep } from '../resources/js/betboom/discovery-policy.mjs';

function harness() {
    const sockets = [], events = [], intervals = new Set(), timeouts = [];
    class Socket {
        readyState = 1;
        sent = [];
        closed = false;
        constructor() { sockets.push(this); }
        send(value) { this.sent.push(value); }
        close() { this.closed = true; this.readyState = 3; this.onclose?.({code:1000}); }
        receive(value) { this.onmessage({data:Buffer.from(JSON.stringify(value))}); }
    }
    const context = createContext({
        Buffer, URL, Date, Map, Set, JSON, Math, WebSocket:Socket, shouldSleep,
        readFileSync:() => JSON.stringify([{symbol:'root',name:'bb.sport_ws.v1.MainResponse',fields:[]}]),
        decode:bytes => JSON.parse(bytes.toString()),
        setInterval:fn => { intervals.add(fn); return fn; },
        clearInterval:fn => intervals.delete(fn),
        setTimeout:fn => { timeouts.push(fn); return fn; }, clearTimeout:() => {},
        process:{argv:[],stdout:{write:s=>events.push(JSON.parse(s))},on:()=>{},exit:()=>{}},
    });
    const path = new URL('../resources/js/betboom/discover.mjs',import.meta.url);
    const code = readFileSync(path,'utf8').replace(/^import .*;\r?\n/gm,'').replaceAll('import.meta.url',JSON.stringify(path.href));
    new Script(code).runInContext(context);
    const socket = sockets[0];
    socket.onopen();
    socket.receive({newsletters_state_ready:{code:200}});
    return {socket,events,sockets,tick:()=>[...intervals].forEach(fn=>fn())};
}

test('complete empty schedule closes the socket and stops all pings until parent wakes it',()=>{
    const h=harness();
    h.socket.receive({state_subscribe_categories:{categories:[{code:200,type:1,category:{tournaments:[]}},{code:200,type:2,category:{tournaments:[]}}]}});
    h.tick();
    assert.equal(h.socket.closed,true);
    assert.equal(h.events.filter(e=>e.event==='idle').length,1);
    const sent=h.socket.sent.length;
    for(let n=0;n<20;n++)h.tick();
    assert.equal(h.socket.sent.length,sent);
    assert.equal(h.sockets.length,1);
});

test('missing tournament response cannot be mistaken for an empty schedule',()=>{
    const h=harness();
    h.socket.receive({state_subscribe_categories:{categories:[{code:200,type:1,category:{tournaments:[]}},{code:200,type:2,category:{tournaments:[{info:{id:10,name:'Cup'}}]}}]}});
    h.tick();assert.equal(h.socket.closed,false);
    h.socket.receive({state_subscribe_tournaments:{tournaments:[{code:200,type:2,tournament:{info:{id:10,name:'Cup'},matches:[]}}]}});
    h.tick();assert.equal(h.socket.closed,true);
});

test('live match keeps the schedule connected',()=>{
    const h=harness();
    const tournament={info:{id:10,name:'Cup'},matches:[{info:{id:1,type:1,start_dttm:new Date().toISOString(),teams:{home_team:{name:'A'},away_team:{name:'B'}}}}]};
    h.socket.receive({state_subscribe_categories:{categories:[{code:200,type:1,category:{tournaments:[tournament]}},{code:200,type:2,category:{tournaments:[]}}]}});
    h.socket.receive({state_subscribe_tournaments:{tournaments:[{code:200,type:1,tournament}]}});
    h.tick();assert.equal(h.socket.closed,false);assert.equal(h.events.some(e=>e.event==='idle'),false);
});
