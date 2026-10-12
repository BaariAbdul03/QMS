/* ==========================================================================
   Staff panel client
   Polls /api/staff/overview every few seconds and drives the token actions.

   Rules kept from the plan:
     - every POST carries the CSRF header
     - buttons are disabled while a request is in flight
     - DOM is written with textContent, never innerHTML
     - polling pauses while the tab is hidden
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
    const totalsEl = document.getElementById('qms-totals');
    const statusEl = document.getElementById('qms-status');
    const toastEl = document.getElementById('qms-toast');
    const deskSelect = document.getElementById('qms-counter-select');

    const STORAGE_KEY = 'qms.counter_id';

    const ACTION_PATHS = {
        start: 'start',
        complete: 'complete',
        noShow: 'no-show',
        recall: 'recall',
    };

    let selectedCounterId = Number(localStorage.getItem(STORAGE_KEY) || 0) || null;
    let inFlight = false;
    let timer = null;
    let latest = null;
    let toastTimer = null;

    /** @type {Map<number, {root: HTMLElement, refs: Object, buttons: Object}>} */
    const cards = new Map();

    // ------------------------------------------------------------- network --

    async function api(path, options = {}) {
        const response = await fetch(path, {
            method: options.method || 'GET',
            headers: Object.assign(
                { Accept: 'application/json', 'X-CSRF-Token': config.csrf },
                options.body ? { 'Content-Type': 'application/json' } : {}
            ),
            body: options.body ? JSON.stringify(options.body) : undefined,
        });

        let payload = null;

        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (response.status === 401) {
            window.location.assign(config.login);
            throw new Error('Session expired.');
        }

        if (!response.ok || !payload || payload.ok !== true) {
            const message = (payload && payload.error) || ('Request failed (' + response.status + ')');
            const error = new Error(message);
            error.status = response.status;
            error.details = (payload && payload.details) || null;
            throw error;
        }

        return payload.data;
    }

    // ------------------------------------------------------------- toast ---

    function toast(message, kind = 'error') {
        toastEl.textContent = message;
        toastEl.className = 'qms-toast qms-toast--' + kind;
        toastEl.hidden = false;

        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => {
            toastEl.hidden = true;
        }, kind === 'error' ? 6000 : 3000);
    }

    function setStatus(text, isError = false) {
        statusEl.textContent = text;
        statusEl.classList.toggle('qms-status--error', isError);
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

    const STATUS_LABEL = {
        called: 'called',
        serving: 'serving',
        completed: 'completed',
        no_show: 'no show',
        cancelled: 'cancelled',
        waiting: 'waiting',
    };

    function ensureCard(counter) {
        let card = cards.get(counter.id);

        if (card) {
            return card;
        }

        // Drop the server-rendered placeholder the first time a real card lands.
        if (cards.size === 0) {
            countersEl.replaceChildren();
        }

        const root = el('article', 'qms-card');
        root.dataset.counterId = String(counter.id);

        const head = el('header', 'qms-card__head');
        const name = el('h2', 'qms-card__name', counter.name);
        const staff = el('span', 'qms-card__staff', counter.staff_name || '');
        name.appendChild(staff);

        const pill = el('span', 'qms-pill', 'idle');
        head.append(name, pill);

        const code = el('p', 'qms-token', '—');
        const meta = el('p', 'qms-token__meta', '');

        const actions = el('div', 'qms-card__actions');
        const buttons = {};

        [
            ['call', 'Call next', 'btn-primary'],
            ['start', 'Start', 'btn-success'],
            ['complete', 'Complete', 'btn-dark'],
            ['noShow', 'No-show', 'btn-outline-secondary'],
            ['recall', 'Recall', 'btn-outline-warning'],
        ].forEach(([key, label, variant]) => {
            const button = el('button', 'btn btn-sm ' + variant, label);
            button.type = 'button';
            button.dataset.action = key;
            button.addEventListener('click', () => run(key, counter.id));
            buttons[key] = button;
            actions.appendChild(button);
        });

        root.append(head, code, meta, actions);
        countersEl.appendChild(root);

        card = { root: root, refs: { name, staff, pill, code, meta }, buttons: buttons };
        cards.set(counter.id, card);

        return card;
    }

    function paintCounter(counter) {
        const card = ensureCard(counter);
        const current = counter.current;
        const mine = counter.id === selectedCounterId;

        card.root.classList.toggle('qms-card--mine', mine);
        card.root.classList.toggle('qms-card--serving', !!current && current.status === 'serving');
        card.root.classList.toggle('qms-card--closed', !counter.is_open);

        const status = !counter.is_open ? 'closed' : (current ? current.status : 'idle');

        card.refs.pill.textContent = STATUS_LABEL[status] || status;
        card.refs.pill.className = 'qms-pill' + (status === 'idle' || status === 'closed' ? ' qms-pill--idle' : ' qms-pill--' + status);

        card.refs.code.textContent = current ? current.display_code : '—';
        card.refs.code.classList.toggle('qms-token--empty', !current);

        if (current) {
            const since = current.serving_at || current.called_at || '';
            card.refs.meta.textContent = since ? 'since ' + since.slice(11, 19) : '';
        } else {
            card.refs.meta.textContent = counter.is_open ? 'free' : 'counter closed';
        }

        // Only the desk the operator is signed in as can act, and only in the
        // states the state machine allows.
        const canAct = mine && counter.is_open;
        const buttons = card.buttons;

        buttons.call.disabled = !canAct || inFlight || !!current;
        buttons.start.disabled = !canAct || inFlight || !current || current.status !== 'called';
        buttons.complete.disabled = !canAct || inFlight || !current || current.status !== 'serving';
        buttons.noShow.disabled = !canAct || inFlight || !current || current.status !== 'called';
        buttons.recall.disabled = !canAct || inFlight || !current || current.status !== 'called';

        buttons.call.title = !mine
            ? 'Switch "My desk" to this counter to use it'
            : (current ? 'Finish ' + current.display_code + ' first' : 'Call the next visitor');
    }

    function paintServices(services) {
        servicesEl.replaceChildren();

        if (!services.length) {
            servicesEl.appendChild(el('p', 'qms-empty', 'No active services.'));
            return;
        }

        services.forEach((service) => {
            const row = el('div', 'qms-service');
            row.append(
                el('span', 'qms-service__name', service.name),
                el('span', 'qms-service__waiting', service.waiting)
            );

            const shared = service.shared_counters || 0;
            const dedicated = service.open_counters - shared;

            // A shared desk drains every queue, so say so instead of reporting
            // a total that looks larger than the number of physical desks.
            let capacity;

            if (shared > 0 && dedicated > 0) {
                capacity = dedicated + ' dedicated + ' + shared + ' shared';
            } else if (shared > 0) {
                capacity = shared + (shared === 1 ? ' shared counter' : ' shared counters');
            } else {
                capacity = service.open_counters +
                    (service.open_counters === 1 ? ' counter' : ' counters');
            }

            row.appendChild(el('span', 'qms-service__meta', capacity + ' · ' + service.prefix + ' series'));
            servicesEl.appendChild(row);
        });
    }

    function paintTotals(totals) {
        totalsEl.replaceChildren();

        [
            ['In queue', totals.in_queue],
            ['Being served', totals.serving],
            ['Called', totals.called],
            ['Completed', totals.completed],
            ['No show', totals.no_show],
        ].forEach(([label, value]) => {
            totalsEl.append(el('dt', null, label), el('dd', null, value));
        });
    }

    function paint(overview) {
        latest = overview;

        const byId = new Set();

        overview.counters.forEach((counter) => {
            byId.add(counter.id);
            paintCounter(counter);
        });

        // A counter removed from the database must not linger on screen.
        cards.forEach((card, id) => {
            if (!byId.has(id)) {
                card.root.remove();
                cards.delete(id);
            }
        });

        paintServices(overview.services);
        paintTotals(overview.totals);
    }

    function syncDeskOptions(counters) {
        const ids = counters.map((counter) => String(counter.id));

        if (deskSelect.dataset.ready !== 'true') {
            // Rebuild once against the live list, then keep the operator's choice.
            deskSelect.replaceChildren();

            counters.forEach((counter) => {
                const option = el('option', null, counter.staff_name
                    ? counter.name + ' — ' + counter.staff_name
                    : counter.name);
                option.value = String(counter.id);
                deskSelect.appendChild(option);
            });

            deskSelect.dataset.ready = 'true';
        }

        if (selectedCounterId === null || !ids.includes(String(selectedCounterId))) {
            selectedCounterId = counters.length ? counters[0].id : null;
        }

        if (selectedCounterId !== null) {
            deskSelect.value = String(selectedCounterId);
        }
    }

    // -------------------------------------------------------------- actions --

    async function run(action, counterId) {
        if (inFlight) {
            return;
        }

        inFlight = true;
        repaint();

        try {
            let data;

            if (action === 'call') {
                data = await api(config.api + '/counters/' + counterId + '/call-next', { method: 'POST' });

                if (!data.token) {
                    toast(data.message || 'Nobody is waiting.', 'info');
                }
            } else {
                const current = latest && latest.counters.find((counter) => counter.id === counterId);
                const token = current && current.current;

                if (!token) {
                    throw new Error('That counter is not holding a token.');
                }

                data = await api(config.api + '/tokens/' + token.id + '/' + ACTION_PATHS[action], {
                    method: 'POST',
                    body: { counter_id: counterId },
                });
            }

            setStatus('Updated ' + new Date().toLocaleTimeString());
        } catch (error) {
            const detail = error.details && error.details.actual
                ? ' (currently ' + error.details.actual + ')'
                : '';
            toast(error.message + detail, 'error');
            setStatus(error.message, true);
        } finally {
            inFlight = false;
            await refresh().catch(() => {});
        }
    }

    function repaint() {
        if (latest) {
            paint(latest);
        }
    }

    // --------------------------------------------------------------- polling --

    async function refresh() {
        const overview = await api(config.api + '/overview');

        syncDeskOptions(overview.counters);
        paint(overview);

        setStatus('Live · last update ' + new Date().toLocaleTimeString());
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
            setStatus('Cannot reach the server: ' + error.message, true);
        }

        schedule();
    }

    // ----------------------------------------------------------------- wiring --

    deskSelect.addEventListener('change', () => {
        selectedCounterId = Number(deskSelect.value) || null;

        if (selectedCounterId !== null) {
            localStorage.setItem(STORAGE_KEY, String(selectedCounterId));
        }

        repaint();
    });

    document.addEventListener('visibilitychange', () => {
        // Stop hammering the API while nobody is looking, and refresh
        // immediately on return so the panel is never stale on screen.
        if (document.hidden) {
            window.clearTimeout(timer);
        } else {
            loop();
        }
    });

    window.addEventListener('beforeunload', () => window.clearTimeout(timer));

    loop();
})();
