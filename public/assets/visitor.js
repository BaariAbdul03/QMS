/* ==========================================================================
   Visitor page client

   The ticket lives in localStorage, so a refresh (or a locked phone screen)
   keeps the visitor's place without any server session. Only the random
   public_id is stored — never an internal id.

   One active ticket per browser: while a ticket is waiting, called or being
   served, the service picker stays out of the way.
   ========================================================================== */

(() => {
    'use strict';

    const configEl = document.getElementById('qms-config');

    if (!configEl) {
        return;
    }

    const config = JSON.parse(configEl.textContent);
    const STORAGE_KEY = 'qms.ticket';

    const pickerEl = document.getElementById('qms-picker');
    const ticketEl = document.getElementById('qms-ticket');
    const errorEl = document.getElementById('qms-error');
    const titleEl = document.getElementById('qms-title');
    const hintEl = document.getElementById('qms-hint');

    let ticket = readTicket();
    let inFlight = false;
    let timer = null;

    // ------------------------------------------------------------- storage --

    function readTicket() {
        try {
            const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');

            if (raw && typeof raw.public_id === 'string' && raw.public_id.length === 16) {
                return raw;
            }
        } catch (error) {
            // Corrupt value: start clean rather than fight it.
        }

        return null;
    }

    function writeTicket(value) {
        ticket = value;

        try {
            if (value === null) {
                localStorage.removeItem(STORAGE_KEY);
            } else {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
            }
        } catch (error) {
            // Private mode with storage disabled: the page still works, the
            // ticket just will not survive a refresh.
        }
    }

    // -------------------------------------------------------------- network --

    async function api(path, options = {}) {
        const response = await fetch(path, {
            method: options.method || 'GET',
            headers: Object.assign(
                { Accept: 'application/json' },
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

        if (!response.ok || !payload || payload.ok !== true) {
            const message = (payload && payload.error) || ('Something went wrong (' + response.status + ')');
            const error = new Error(message);
            error.status = response.status;
            throw error;
        }

        return payload.data;
    }

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

    function showError(message) {
        errorEl.textContent = message;
        errorEl.hidden = false;
        window.clearTimeout(showError.timer);
        showError.timer = window.setTimeout(() => {
            errorEl.hidden = true;
        }, 6000);
    }

    // --------------------------------------------------------------- picker --

    function paintPicker(services) {
        services.forEach((service) => {
            const button = pickerEl.querySelector('[data-service-id="' + service.id + '"]');

            if (!button) {
                return;
            }

            const shared = service.shared_counters || 0;
            const dedicated = service.open_counters - shared;

            let capacity;

            if (shared > 0 && dedicated > 0) {
                capacity = dedicated + ' dedicated + ' + shared + ' shared';
            } else {
                capacity = service.open_counters + (service.open_counters === 1 ? ' counter' : ' counters');
            }

            const meta = button.querySelector('[data-role="meta"]');
            meta.textContent = service.waiting + ' waiting · ' + capacity;
        });
    }

    async function refreshServices() {
        paintPicker(await api(config.api.services));
    }

    // --------------------------------------------------------------- ticket --

    const TERMINAL = ['completed', 'cancelled', 'no_show'];

    function renderTicket(token) {
        pickerEl.hidden = true;
        ticketEl.hidden = false;
        ticketEl.replaceChildren();

        const finished = TERMINAL.includes(token.status);

        titleEl.textContent = finished ? 'Ticket closed' : 'Your token';
        hintEl.textContent = finished
            ? 'Keep this code if you need to show it at the desk.'
            : 'Keep this page open — it updates by itself.';

        const code = el('p', 'qms-ticket__code', token.display_code);
        code.dataset.status = token.status;

        const status = el('p', 'qms-ticket__status qms-ticket__status--' + token.status, statusText(token));
        ticketEl.append(code, status);

        ticketEl.appendChild(el('p', 'qms-ticket__service', token.service.name + ' · ' + token.queue_date));

        if (!finished) {
            const facts = el('dl', 'qms-ticket__facts');

            if (token.status === 'waiting') {
                facts.append(
                    el('dt', null, 'Position'),
                    el('dd', null, token.position + (token.position === 1 ? 'st' : (token.position === 2 ? 'nd' : (token.position === 3 ? 'rd' : 'th'))) + ' in line'),
                    el('dt', null, 'Estimated wait'),
                    el('dd', null, token.eta ? token.eta.label : '—')
                );
            } else if (token.counter) {
                facts.append(
                    el('dt', null, 'Counter'),
                    el('dd', null, token.counter.name)
                );
            }

            ticketEl.appendChild(facts);
        }

        const actions = el('div', 'qms-ticket__actions');

        if (token.status === 'waiting') {
            const cancel = el('button', 'qms-btn qms-btn--danger', 'Leave the queue');
            cancel.type = 'button';
            cancel.addEventListener('click', cancelTicket);
            actions.appendChild(cancel);
        }

        if (finished) {
            const again = el('button', 'qms-btn qms-btn--primary', 'Take a new token');
            again.type = 'button';
            again.addEventListener('click', () => {
                writeTicket(null);
                showPicker();
            });
            actions.appendChild(again);
        }

        ticketEl.appendChild(actions);

        if (token.status === 'waiting' && token.eta && token.eta.source !== 'history') {
            ticketEl.appendChild(el('p', 'qms-ticket__note',
                'This estimate is rough — the queue has not collected enough service times yet.'));
        }

        if (token.status === 'called') {
            document.title = 'Go to ' + (token.counter ? token.counter.name : 'the counter') + ' — ' + token.display_code;
        } else {
            document.title = token.display_code + ' — ' + token.service.name;
        }
    }

    function statusText(token) {
        switch (token.status) {
            case 'waiting':
                return 'Waiting in the queue.';
            case 'called':
                return token.counter
                    ? 'It is your turn — please go to ' + token.counter.name + '.'
                    : 'It is your turn — please go to the counter.';
            case 'serving':
                return token.counter
                    ? 'You are being served at ' + token.counter.name + '.'
                    : 'You are being served.';
            case 'completed':
                return 'All done. Thank you.';
            case 'no_show':
                return 'You were marked as a no-show. Please ask at the desk.';
            case 'cancelled':
                return 'You left the queue.';
            default:
                return token.status;
        }
    }

    function showPicker() {
        pickerEl.hidden = false;
        ticketEl.hidden = true;
        titleEl.textContent = 'Take a token';
        hintEl.textContent = 'Choose a service to join the queue.';
        document.title = 'Take a token';

        refreshServices().catch(() => {});
    }

    // -------------------------------------------------------------- actions --

    async function takeToken(serviceId) {
        if (inFlight) {
            return;
        }

        inFlight = true;
        setPickButtonsDisabled(true);

        try {
            const token = await api(config.api.tokens, {
                method: 'POST',
                body: { service_id: serviceId },
            });

            writeTicket({ public_id: token.public_id, service_id: token.service.id });
            renderTicket(token);
        } catch (error) {
            showError(error.message);
        } finally {
            inFlight = false;
            setPickButtonsDisabled(false);
        }
    }

    async function cancelTicket() {
        if (inFlight || !ticket) {
            return;
        }

        inFlight = true;

        try {
            renderTicket(await api(config.api.tokens + '/' + ticket.public_id + '/cancel', { method: 'POST' }));
        } catch (error) {
            showError(error.message);
        } finally {
            inFlight = false;
        }
    }

    async function loadTicket() {
        if (!ticket) {
            showPicker();

            return;
        }

        try {
            renderTicket(await api(config.api.tokens + '/' + ticket.public_id));
        } catch (error) {
            // The token is gone (a demo reset, or a very old link): forget it.
            if (error.status === 404) {
                writeTicket(null);
                showPicker();

                return;
            }

            showError(error.message);
        }
    }

    function setPickButtonsDisabled(disabled) {
        pickerEl.querySelectorAll('button').forEach((button) => {
            button.disabled = disabled;
        });
    }

    // ------------------------------------------------------------- polling --

    function schedule() {
        window.clearTimeout(timer);

        if (document.hidden) {
            return;
        }

        timer = window.setTimeout(loop, config.pollMs);
    }

    async function loop() {
        try {
            if (ticket) {
                await loadTicket();
            } else {
                await refreshServices();
            }
        } catch (error) {
            // Transient network trouble: keep the last good screen and retry.
        }

        schedule();
    }

    // -------------------------------------------------------------- wiring --

    pickerEl.addEventListener('click', (event) => {
        const button = event.target.closest('[data-service-id]');

        if (button) {
            takeToken(Number(button.dataset.serviceId));
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            window.clearTimeout(timer);
        } else {
            loop();
        }
    });

    window.addEventListener('beforeunload', () => window.clearTimeout(timer));

    loadTicket().finally(schedule);
})();
