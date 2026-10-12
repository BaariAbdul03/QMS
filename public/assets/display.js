/* ==========================================================================
   Display board client

   Reads the public /api/status feed and renders the waiting-room board. Polls
   every few seconds and pauses while the tab is hidden.

   When a counter announces a token, the tile is highlighted and a
   `qms:called` event is dispatched on document. The sound layer (Phase 6)
   subscribes to that event — the board keeps working with audio unavailable.
   ========================================================================== */

(() => {
    'use strict';

    const configEl = document.getElementById('qms-config');

    if (!configEl) {
        return;
    }

    const config = JSON.parse(configEl.textContent);

    const countersEl = document.getElementById('qms-counters');
    const servicesEl = document.getElementById('qms-services');
    const statusEl = document.getElementById('qms-status');
    const clockEl = document.getElementById('qms-clock');

    /** counterId -> "CODE|called_at" of the announcement we already reacted to. */
    const seen = new Map();

    /** counterId -> {root, refs} */
    const tiles = new Map();

    let firstPaintDone = false;
    let timer = null;
    let clockTimer = null;

    // --------------------------------------------------------------- clock --

    function tickClock() {
        clockEl.textContent = new Date().toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    }

    // ------------------------------------------------------------ rendering --

    function el(tag, className, text) {
        const node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }

        return node;
    }

    function ensureTile(counter) {
        let tile = tiles.get(counter.id);

        if (tile) {
            return tile;
        }

        if (tiles.size === 0) {
            countersEl.replaceChildren();
        }

        const root = el('article', 'qms-tile');
        root.dataset.counterId = String(counter.id);

        const name = el('h2', 'qms-tile__name', counter.name);
        const service = el('p', 'qms-tile__service', counter.service ? counter.service.name : 'All services');
        const code = el('p', 'qms-tile__code', '—');
        const pill = el('p', 'qms-tile__pill', 'ready');

        root.append(name, service, code, pill);
        countersEl.appendChild(root);

        tile = { root: root, refs: { name, service, code, pill } };
        tiles.set(counter.id, tile);

        return tile;
    }

    function paintCounter(counter) {
        const tile = ensureTile(counter);
        const token = counter.token;

        tile.root.classList.toggle('qms-tile--closed', !counter.is_open);
        tile.root.classList.toggle('qms-tile--serving', !!token && token.status === 'serving');
        tile.root.classList.toggle('qms-tile--called', !!token && token.status === 'called');

        tile.refs.code.textContent = token ? token.display_code : '—';
        tile.refs.code.classList.toggle('qms-tile__code--empty', !token);

        if (!counter.is_open) {
            tile.refs.pill.textContent = 'closed';
        } else if (!token) {
            tile.refs.pill.textContent = 'ready';
        } else {
            tile.refs.pill.textContent = token.status === 'serving' ? 'now serving' : 'called';
        }
    }

    function paintServices(services) {
        servicesEl.replaceChildren();

        if (!services.length) {
            servicesEl.appendChild(el('p', 'qms-board__empty', 'No services today.'));
            return;
        }

        services.forEach((service) => {
            const row = el('div', 'qms-board__service');
            row.append(
                el('span', 'qms-board__service-name', service.name),
                el('span', 'qms-board__service-waiting', service.waiting)
            );

            const average = service.average_service_minutes;
            row.appendChild(el('span', 'qms-board__service-meta',
                average ? '~' + average + ' min each' : ''));

            servicesEl.appendChild(row);
        });
    }

    // --------------------------------------------------- announcement hooks --

    /**
     * Runs once per announcement. A recall re-announces the same code, so the
     * key includes called_at rather than just the code.
     */
    function announce(counter) {
        const token = counter.token;

        tileFlash(counter.id);

        document.dispatchEvent(new CustomEvent('qms:called', {
            detail: {
                code: token.display_code,
                counter: counter.name,
                counterId: counter.id,
                status: token.status,
            },
        }));
    }

    function tileFlash(counterId) {
        const tile = tiles.get(counterId);

        if (!tile) {
            return;
        }

        tile.root.classList.remove('qms-tile--announce');
        // Force a reflow so the animation restarts on a repeat announcement.
        void tile.root.offsetWidth;
        tile.root.classList.add('qms-tile--announce');

        window.setTimeout(() => tile.root.classList.remove('qms-tile--announce'), 6000);
    }

    function detectAnnouncements(counters) {
        counters.forEach((counter) => {
            const token = counter.token;
            const key = token && token.status === 'called'
                ? token.display_code + '|' + (token.called_at || '')
                : null;

            const previous = seen.get(counter.id);

            seen.set(counter.id, key);

            // The first paint adopts whatever is already on screen: a board
            // that chimes for every token on startup would be unusable.
            if (!firstPaintDone || key === null || key === previous) {
                return;
            }

            announce(counter);
        });
    }

    // ------------------------------------------------------------- polling --

    function paint(status) {
        detectAnnouncements(status.counters);

        status.counters.forEach(paintCounter);
        paintServices(status.services);

        // A counter removed from the database must not linger on the board.
        const live = new Set(status.counters.map((counter) => counter.id));

        tiles.forEach((tile, id) => {
            if (!live.has(id)) {
                tile.root.remove();
                tiles.delete(id);
                seen.delete(id);
            }
        });

        firstPaintDone = true;
        statusEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
    }

    async function refresh() {
        const response = await fetch(config.api.status, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        const payload = await response.json();

        if (!payload.ok) {
            throw new Error(payload.error || 'Bad response');
        }

        paint(payload.data);
    }

    function schedule() {
        window.clearTimeout(timer);

        if (document.hidden) {
            return;
        }

        timer = window.setTimeout(loop, config.pollMs);
    }

    async function loop() {
        try {
            await refresh();
        } catch (error) {
            statusEl.textContent = 'Cannot reach the server — retrying…';
        }

        schedule();
    }

    // -------------------------------------------------------------- wiring --

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            window.clearTimeout(timer);
            window.clearInterval(clockTimer);
        } else {
            clockTimer = window.setInterval(tickClock, 1000);
            tickClock();
            loop();
        }
    });

    window.addEventListener('beforeunload', () => {
        window.clearTimeout(timer);
        window.clearInterval(clockTimer);
    });

    tickClock();
    clockTimer = window.setInterval(tickClock, 1000);
    loop();
})();
