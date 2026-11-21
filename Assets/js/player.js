/*
 * Matinee player (plan P3 Task 19; C2g, C6b, C6c). Loaded only on matinee/play, after hls.js. No inline script: the
 * page hands everything over in data-matinee-* attributes. Messages are set with textContent.
 *
 * - Every player page POSTs matinee/keepalive every 5 minutes, so the Kanboard session outlives a long film or album,
 *   and clears it on pagehide.
 * - Audio: the <audio> plays its src; an error shows data-matinee-error.
 * - Video: hls.js when it is supported, else native HLS, else data-matinee-unsupported. It pings the claimed session
 *   every 30 s (a 403 reloads the page, which claims a fresh one). halt() stops the ping and sends one stop beacon; it
 *   runs on pagehide, on a fatal hls.js error, on a media error and when nothing can play, so a broken player never
 *   holds its slot. A second ping-driven reload within 120 s halts and shows data-matinee-error instead of looping.
 * - A page restored from the back/forward cache reloads: its session was stopped when it was hidden.
 */
(function () {
    'use strict';

    var media = document.querySelector('.matinee-player[data-matinee-kind]');
    if (!media) {
        return;
    }
    var message = document.querySelector('.matinee-player-message');

    function attr(name) {
        return media.getAttribute('data-matinee-' + name) || '';
    }

    function show(text) {
        if (message) {
            message.textContent = text;
            message.hidden = false;
        }
    }

    function form(session) {
        var data = new FormData();
        if (session !== undefined) {
            data.append('session', session);
        }
        data.append('csrf_token', attr('csrf'));
        return data;
    }

    function post(url, data) {
        return fetch(url, { method: 'POST', body: data, credentials: 'same-origin' });
    }

    var keepalive = setInterval(function () {
        post(attr('keepalive'), form()).catch(function () {});
    }, 300000);

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            location.reload();
        }
    });

    var hlsPage = attr('kind') === 'hls';
    var session = attr('session');
    var ping = null;
    // An audio page claims no session, so there is nothing to halt.
    var stopped = !hlsPage;

    function halt() {
        if (stopped) {
            return;
        }
        stopped = true;
        clearInterval(ping);
        navigator.sendBeacon(attr('stop'), form(session));
    }

    window.addEventListener('pagehide', function () {
        clearInterval(keepalive);
        halt();
    });

    media.addEventListener('error', function () {
        show(attr('error'));
        halt();
    });

    if (!hlsPage) {
        return;
    }

    var RELOADED = 'matinee-ping-reload';

    /** A 403 ping reloads to claim a fresh session, unless the last such reload was under 120 s ago. */
    function reloadOrGiveUp() {
        var now = Date.now();
        var last = 0;
        try {
            last = Number(sessionStorage.getItem(RELOADED)) || 0;
        } catch (e) {
            last = 0;
        }
        if (last > 0 && now - last < 120000) {
            halt();
            show(attr('error'));
            return;
        }
        try {
            sessionStorage.setItem(RELOADED, String(now));
        } catch (e) {
            // No storage: reload anyway.
        }
        location.reload();
    }

    var src = attr('src');

    ping = setInterval(function () {
        post(attr('ping'), form(session)).then(function (response) {
            if (response.status === 403) {
                reloadOrGiveUp();
            }
        }).catch(function () {});
    }, 30000);

    if (window.Hls && Hls.isSupported()) {
        var hls = new Hls();
        hls.on(Hls.Events.ERROR, function (event, data) {
            if (data && data.fatal) {
                show(attr('error'));
                hls.destroy();
                halt();
            }
        });
        hls.loadSource(src);
        hls.attachMedia(media);
    } else if (media.canPlayType('application/vnd.apple.mpegurl') !== '') {
        media.src = src;
    } else {
        show(attr('unsupported'));
        halt();
    }
}());
