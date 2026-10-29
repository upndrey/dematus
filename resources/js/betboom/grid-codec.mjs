import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const base = new URL('./', import.meta.url);
const schemas = JSON.parse(readFileSync(new URL('grid-schema.json', base), 'utf8'));
const bySymbol = new Map(schemas.map(s => [s.symbol, s]));
const responseSchema = schemas.find(s => s.name === 'bb.grid_widget.MainResponse');

function decode(buffer, schema) {
    assert.ok(schema, 'Message schema must exist');
    let offset = 0;
    const result = {};
    function varint() {
        let value = 0n;
        for (let shift = 0n; shift < 70n; shift += 7n) {
            assert.ok(offset < buffer.length, 'Truncated varint');
            const byte = buffer[offset++];
            value |= BigInt(byte & 127) << shift;
            if (!(byte & 128)) return value;
        }
        throw new Error('Invalid varint');
    }
    while (offset < buffer.length) {
        const tag = Number(varint());
        const no = tag >>> 3;
        const wire = tag & 7;
        const field = schema.fields.find(f => f.no === no);
        let value;
        if (wire === 0) {
            const raw = varint();
            value = field?.scalar === '8' ? raw !== 0n : Number(BigInt.asIntN(64, raw));
            if (field?.scalar === '5') value = Number(BigInt.asIntN(32, raw));
        } else if (wire === 2) {
            const size = Number(varint());
            assert.ok(offset + size <= buffer.length, 'Truncated message');
            const bytes = buffer.subarray(offset, offset + size);
            offset += size;
            value = field?.kind === 'message' ? decode(bytes, bySymbol.get(field.message)) : bytes.toString('utf8');
        } else if (wire === 1 || wire === 5) {
            const size = wire === 1 ? 8 : 4;
            assert.ok(offset + size <= buffer.length, 'Truncated fixed field');
            value = buffer.subarray(offset, offset + size).toString('hex');
            offset += size;
        } else {
            throw new Error(`Unsupported wire type ${wire}`);
        }
        if (!field) continue;
        if (field.repeated) (result[field.name] ??= []).push(value);
        else result[field.name] = value;
    }
    return result;
}

function encodeVarint(value) {
    const bytes = [];
    do { const next = value & 127; value >>>= 7; bytes.push(next | (value ? 128 : 0)); } while (value);
    return Buffer.from(bytes);
}
function field(no, bytes) {
    return Buffer.concat([encodeVarint((no << 3) | 2), encodeVarint(bytes.length), bytes]);
}
function request(kind, uid, matchId) {
    const parts = [field(1, Buffer.from(uid))];
    if (matchId) parts.push(field(2, Buffer.from(matchId)));
    return field(kind, Buffer.concat(parts));
}
export { decode, responseSchema, request };
