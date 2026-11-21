/*
 * Matinee player (plan P3 Task 19; C2g, C6b, C6c). Loaded only on matinee/play, after hls.js. No inline script: the
 * page hands everything over in data-matinee-* attributes. Messages are set with textContent.
 *
 * - Every player page POSTs matinee/keepalive every 5 minutes, so the Kanboard session outlives a long film or album.
 * - Audio: the <audio> plays its src; an error shows data-matinee-error.
 * - Video: hls.js when it is supported, else native HLS, else data-matinee-unsupported. It pings the claimed session
 *   every 30 s (a 403 reloads the page, which claims a fresh one) and sends one stop beacon on pagehide.
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

    media.addEventListener('error', function () {
        show(attr('error'));
    });

    if (attr('kind') !== 'hls') {
        return;
    }

    var session = attr('session');
    var src = attr('src');

    var ping = setInterval(function () {
        post(attr('ping'), form(session)).then(function (response) {
            if (response.status === 403) {
                location.reload();
            }
        }).catch(function () {});
    }, 30000);

    var stopped = false;
    window.addEventListener('pagehide', function () {
        if (stopped) {
            return;
        }
        stopped = true;
        clearInterval(ping);
        clearInterval(keepalive);
        navigator.sendBeacon(attr('stop'), form(session));
    });

    if (window.Hls && Hls.isSupported()) {
        var hls = new Hls();
        hls.on(Hls.Events.ERROR, function (event, data) {
            if (data && data.fatal) {
                show(attr('error'));
                hls.destroy();
            }
        });
        hls.loadSource(src);
        hls.attachMedia(media);
    } else if (media.canPlayType('application/vnd.apple.mpegurl') !== '') {
        media.src = src;
    } else {
        show(attr('unsupported'));
    }
}());
