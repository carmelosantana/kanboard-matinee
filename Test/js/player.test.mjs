// player.js's behaviour (plan P3 Task 19; C2g, C6b, C6c), run with `node --test Test/js/` and no dependencies: the
// real Assets/js/player.js runs in a vm context against stubbed DOM, fetch, timers, sendBeacon, sessionStorage and Hls.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';

const SOURCE = fs.readFileSync(new URL('../../Assets/js/player.js', import.meta.url), 'utf8');
const SRC = '/k/?controller=MatineePlayController&action=hlsStart&plugin=Matinee&ref=R.s&session=S-uuid';
const RELOAD_KEY = 'matinee-ping-reload';

/**
 * Runs player.js once. Options: kind ('hls' or 'audio'), hls (window.Hls present and supported), native
 * (canPlayType's answer), pingStatus, storage (a Map, or 'throws'). Returns what it did.
 */
function load({ kind = 'hls', hls = true, native = '', pingStatus = 204, storage = new Map() } = {}) {
    const calls = [];
    const timers = new Map();
    const cleared = [];
    const win = {};
    const mediaOn = {};
    let nextId = 0;
    let player = null;
    const attrs = {
        kind, src: SRC, stop: '/stop', ping: '/ping', keepalive: '/keepalive', session: 'S-uuid', csrf: 'TOK',
        error: 'ERR', unsupported: 'UNS',
    };
    const media = {
        srcSet: [],
        getAttribute: (n) => (n.startsWith('data-matinee-') ? attrs[n.slice(13)] ?? null : null),
        addEventListener: (e, f) => { mediaOn[e] = f; },
        canPlayType: (type) => { calls.push(['canPlayType', type]); return native; },
        set src(v) { this.srcSet.push(v); },
    };
    const message = { hidden: true, text: null, set textContent(v) { this.text = v; } };
    class FormData {
        constructor() { this.entries = []; }
        append(k, v) { this.entries.push([k, v]); }
    }
    class Hls {
        static isSupported() { return hls; }
        static Events = { ERROR: 'hlsError' };
        constructor() { player = this; this.on_ = {}; this.loaded = []; this.attached = []; this.destroyed = 0; }
        on(e, f) { this.on_[e] = f; }
        loadSource(u) { this.loaded.push(u); }
        attachMedia(m) { this.attached.push(m); }
        destroy() { this.destroyed++; }
    }
    const fetches = [];
    const beacons = [];
    let reloads = 0;
    const sessionStorage = storage === 'throws'
        ? { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); } }
        : { getItem: (k) => (storage.has(k) ? storage.get(k) : null), setItem: (k, v) => { storage.set(k, String(v)); } };
    const ctx = {
        document: { querySelector: (s) => (s === '.matinee-player[data-matinee-kind]' ? media : s === '.matinee-player-message' ? message : null) },
        window: { addEventListener: (e, f) => { win[e] = f; }, Hls },
        Hls,
        FormData,
        fetch: (url, opts) => { fetches.push({ url, ...opts }); return Promise.resolve({ status: pingStatus }); },
        setInterval: (f, ms) => { timers.set(++nextId, { f, ms }); return nextId; },
        clearInterval: (id) => { if (timers.has(id)) { cleared.push(timers.get(id).ms); } timers.delete(id); },
        navigator: { sendBeacon: (url, body) => { beacons.push({ url, body }); return true; } },
        location: { reload: () => { reloads++; } },
        sessionStorage,
    };
    vm.runInNewContext(SOURCE, ctx);
    const every = (ms) => [...timers.values()].filter((t) => t.ms === ms);
    return {
        media, message, win, mediaOn, fetches, beacons, cleared, storage,
        get reloads() { return reloads; },
        get player() { return player; },
        intervals: () => [...timers.values()].map((t) => t.ms).sort((a, b) => a - b),
        /** Fires every live timer of that period once, then lets the fetch promises settle. */
        async fire(ms) {
            for (const t of every(ms)) t.f();
            await new Promise((r) => setTimeout(r, 0));
        },
    };
}

const STOP_BODY = [['session', 'S-uuid'], ['csrf_token', 'TOK']];

test('the ping POSTs session and csrf_token every 30 s with same-origin credentials', async () => {
    const p = load();
    assert.deepEqual(p.intervals(), [30000, 300000]);
    await p.fire(30000);
    assert.equal(p.fetches.length, 1);
    const [f] = p.fetches;
    assert.equal(f.url, '/ping');
    assert.equal(f.method, 'POST');
    assert.equal(f.credentials, 'same-origin');
    assert.deepEqual(f.body.entries, STOP_BODY);
    assert.equal(p.reloads, 0);
});

test('the keepalive POSTs csrf_token alone every 300000 ms', async () => {
    for (const kind of ['hls', 'audio']) {
        const p = load({ kind });
        await p.fire(300000);
        assert.equal(p.fetches.length, 1, kind);
        const [f] = p.fetches;
        assert.equal(f.url, '/keepalive', kind);
        assert.equal(f.method, 'POST', kind);
        assert.equal(f.credentials, 'same-origin', kind);
        assert.deepEqual(f.body.entries, [['csrf_token', 'TOK']], kind);
    }
});

test('the audio page runs the keepalive alone and never pings', () => {
    const p = load({ kind: 'audio' });
    assert.deepEqual(p.intervals(), [300000]);
    assert.equal(p.player, null);
});

test('pagehide clears both timers and sends one FormData beacon with session and csrf_token, however often it fires', async () => {
    const p = load();
    p.win.pagehide({ persisted: true });
    p.win.pagehide({ persisted: false });
    assert.deepEqual(p.intervals(), []);
    assert.deepEqual([...p.cleared].sort((a, b) => a - b), [30000, 300000]);
    assert.equal(p.beacons.length, 1);
    assert.equal(p.beacons[0].url, '/stop');
    assert.equal(p.beacons[0].body.constructor.name, 'FormData');
    assert.deepEqual(p.beacons[0].body.entries, STOP_BODY);
    await p.fire(30000);
    await p.fire(300000);
    assert.equal(p.fetches.length, 0);
});

test('the audio page clears its keepalive on pagehide and sends no beacon', () => {
    const p = load({ kind: 'audio' });
    assert.equal(typeof p.win.pagehide, 'function');
    p.win.pagehide({ persisted: false });
    assert.deepEqual(p.intervals(), []);
    assert.equal(p.beacons.length, 0);
});

test('a ping 403 reloads; any other answer does not', async () => {
    for (const status of [204, 200, 404, 500, 502]) {
        const p = load({ pingStatus: status });
        await p.fire(30000);
        assert.equal(p.reloads, 0, String(status));
    }
    const p = load({ pingStatus: 403 });
    await p.fire(30000);
    assert.equal(p.reloads, 1);
});

test('pageshow reloads only a page restored from the back/forward cache', () => {
    for (const kind of ['hls', 'audio']) {
        const p = load({ kind });
        p.win.pageshow({ persisted: false });
        assert.equal(p.reloads, 0, kind);
        p.win.pageshow({ persisted: true });
        assert.equal(p.reloads, 1, kind);
    }
});

test('hls.js plays data-matinee-src exactly when it is supported', () => {
    const p = load({ hls: true, native: 'maybe' });
    assert.ok(p.player);
    assert.deepEqual(p.player.loaded, [SRC]);
    assert.deepEqual(p.player.attached, [p.media]);
    assert.deepEqual(p.media.srcSet, []);
    assert.equal(p.message.hidden, true);
});

test('native HLS plays data-matinee-src exactly when hls.js is unsupported and canPlayType answers', () => {
    for (const native of ['maybe', 'probably']) {
        const p = load({ hls: false, native });
        assert.equal(p.player, null);
        assert.deepEqual(p.media.srcSet, [SRC]);
        assert.equal(p.message.hidden, true);
        assert.deepEqual(p.beacons, []);
    }
});

test('with neither, the unsupported message shows and the claimed session is let go', async () => {
    const p = load({ hls: false, native: '' });
    assert.deepEqual(p.media.srcSet, []);
    assert.equal(p.message.text, 'UNS');
    assert.equal(p.message.hidden, false);
    assert.equal(p.beacons.length, 1);
    assert.deepEqual(p.beacons[0].body.entries, STOP_BODY);
    await p.fire(30000);
    assert.equal(p.fetches.length, 0, 'no ping holds the slot');
    p.win.pagehide({ persisted: false });
    assert.equal(p.beacons.length, 1);
    assert.deepEqual(p.intervals(), []);
});

test('a fatal hls.js error shows the error, destroys the player and halts once', async () => {
    const p = load();
    p.player.on_.hlsError('hlsError', { fatal: false });
    assert.equal(p.player.destroyed, 0);
    assert.equal(p.beacons.length, 0);
    assert.equal(p.message.hidden, true);
    p.player.on_.hlsError('hlsError', { fatal: true });
    assert.equal(p.player.destroyed, 1);
    assert.equal(p.message.text, 'ERR');
    assert.equal(p.message.hidden, false);
    assert.equal(p.beacons.length, 1);
    assert.deepEqual(p.beacons[0].body.entries, STOP_BODY);
    await p.fire(30000);
    assert.equal(p.fetches.length, 0, 'no ping holds the slot');
    assert.deepEqual(p.intervals(), [300000], 'the keepalive lives on while the tab is open');
    p.win.pagehide({ persisted: false });
    assert.equal(p.beacons.length, 1);
    assert.deepEqual(p.intervals(), []);
});

test('a media error on a video page shows the error and halts', async () => {
    const p = load();
    p.mediaOn.error();
    assert.equal(p.message.text, 'ERR');
    assert.equal(p.message.hidden, false);
    assert.equal(p.beacons.length, 1);
    assert.deepEqual(p.beacons[0].body.entries, STOP_BODY);
    await p.fire(30000);
    assert.equal(p.fetches.length, 0);
    p.mediaOn.error();
    assert.equal(p.beacons.length, 1);
});

test('a media error on the audio page shows the error and sends no beacon', () => {
    const p = load({ kind: 'audio' });
    p.mediaOn.error();
    assert.equal(p.message.text, 'ERR');
    assert.equal(p.message.hidden, false);
    assert.equal(p.beacons.length, 0);
});

test('a ping 403 records the reload time, and a second 403 within 120 s halts with the error instead', async () => {
    const storage = new Map();
    const first = load({ pingStatus: 403, storage });
    const before = Date.now();
    await first.fire(30000);
    assert.equal(first.reloads, 1);
    const at = Number(storage.get(RELOAD_KEY));
    assert.ok(at >= before && at <= Date.now(), 'a timestamp');

    const second = load({ pingStatus: 403, storage });
    await second.fire(30000);
    assert.equal(second.reloads, 0);
    assert.equal(second.message.text, 'ERR');
    assert.equal(second.message.hidden, false);
    assert.equal(second.beacons.length, 1);
    await second.fire(30000);
    assert.equal(second.fetches.length, 1, 'the ping stopped');
});

test('a reload 119 s ago still holds back the next one', async () => {
    const p = load({ pingStatus: 403, storage: new Map([[RELOAD_KEY, String(Date.now() - 119000)]]) });
    await p.fire(30000);
    assert.equal(p.reloads, 0);
    assert.equal(p.message.text, 'ERR');
});

test('a reload more than 120 s ago does not hold back the next one', async () => {
    const storage = new Map([[RELOAD_KEY, String(Date.now() - 121000)]]);
    const p = load({ pingStatus: 403, storage });
    await p.fire(30000);
    assert.equal(p.reloads, 1);
    assert.equal(p.message.hidden, true);
});

test('a sessionStorage that throws still reloads on a ping 403', async () => {
    const p = load({ pingStatus: 403, storage: 'throws' });
    await p.fire(30000);
    assert.equal(p.reloads, 1);
});

test('the bfcache reload is not counted against the ping reloads', () => {
    const storage = new Map();
    const p = load({ storage });
    p.win.pageshow({ persisted: true });
    assert.equal(storage.has(RELOAD_KEY), false);
});
