/*
 * Score outbox: keeps a score on this device until the server has confirmed it.
 *
 * Every score is written to localStorage first, then sent through the Livewire component.
 * If the request fails (Wi-Fi drop, server restart, timeout) the score stays queued, the
 * cell turns amber, and the queue is sent again when the connection returns, on a timer,
 * and when the scoring page is opened again. The server is the final authority: a reply
 * of {ok:false} (scoring locked, not your column) removes the item and shows the error.
 *
 * Usage inside a Livewire @script block:
 *
 *   ScoreOutbox.bind({
 *       root: $wire.$el,
 *       wire: $wire,
 *       scope: 'higalaay:streetdance',   // only this component replays these items
 *       method: 'saveScore',             // default; per-input override with data-score-method
 *       beforeSave(el) {},               // optional: clamp/validate el.value before saving
 *       toast(msg, kind) {},             // optional: page's own toast
 *   });
 *
 * Inputs opt in with data-score-args='[participantId, criteriaId, judgeId]'. The input's
 * value is appended as the last argument: wire[method](...args, value).
 *
 * Note: a failed Livewire request never settles the $wire promise, so failures are
 * detected through Livewire's request "fail" hook plus a timeout.
 */
(function () {
    'use strict';
    if (window.ScoreOutbox) return;

    const TIMEOUT_MS = 20000;
    const RETRY_STEPS_MS = [3000, 6000, 12000, 30000];
    const CHUNK = 20; // calls sent together are pooled by Livewire into one request
    const STATE_CLASSES = ['is-saving', 'is-saved', 'is-error', 'is-pending'];

    const userMeta = document.querySelector('meta[name="user-id"]');
    // One queue per logged-in user, so a shared tablet never replays someone else's scores
    const STORE_KEY = 'tabulation.outbox.v1:' + (userMeta ? userMeta.content : 'guest');

    /* ------------------------------ storage ------------------------------ */
    let storageOk = true;
    let memoryItems = {};

    function loadAll() {
        if (storageOk) {
            try {
                const raw = localStorage.getItem(STORE_KEY);
                memoryItems = raw ? (JSON.parse(raw) || {}) : {};
            } catch (e) {
                storageOk = false;
            }
        }
        return memoryItems;
    }

    function saveAll(items) {
        memoryItems = items;
        if (!storageOk) return;
        try {
            if (Object.keys(items).length) localStorage.setItem(STORE_KEY, JSON.stringify(items));
            else localStorage.removeItem(STORE_KEY);
        } catch (e) {
            storageOk = false; // private mode / quota: keep working from memory
        }
    }

    function putItem(item) {
        const items = loadAll();
        items[item.key] = item;
        saveAll(items);
    }

    // Remove only if it is still the same entry (the judge may have typed a newer value meanwhile)
    function removeItem(key, ts) {
        const items = loadAll();
        if (items[key] && items[key].ts === ts) {
            delete items[key];
            saveAll(items);
        }
    }

    function pendingCount() {
        return Object.keys(loadAll()).length;
    }

    // Another tab of the same judge changed the queue
    window.addEventListener('storage', (e) => {
        if (e.key === STORE_KEY) {
            loadAll();
            controllers.forEach((c) => c.paint());
            updateBadge();
        }
    });

    /* ------------------------- Livewire call wrapper ------------------------- */
    const inflight = new Set();
    let livewireHooked = false;

    function hookLivewire() {
        if (livewireHooked || !window.Livewire) return;
        livewireHooked = true;

        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (!inflight.size) return;
                // Connection / proxy errors: the score is safe in the outbox, so skip Livewire's
                // error popup. Other statuses (419 expired session, 500) keep the default
                // behaviour; the queue survives the reload Livewire offers.
                if ([0, 502, 503, 504].includes(status)) preventDefault();
                const pending = [...inflight];
                inflight.clear();
                pending.forEach((entry) => entry.fail(new Error('Request failed (' + status + ')')));
            });
        });

        // Livewire re-renders replace the server-side values; put queued values back
        Livewire.hook('morphed', () => {
            controllers.forEach((c) => c.paint());
        });
    }

    function callWire(wire, method, args) {
        return new Promise((resolve, reject) => {
            let done = false;
            const entry = {
                fail(err) {
                    if (done) return;
                    done = true;
                    clearTimeout(timer);
                    reject(err);
                },
            };
            const timer = setTimeout(() => {
                inflight.delete(entry);
                entry.fail(new Error('Request timed out'));
            }, TIMEOUT_MS);
            inflight.add(entry);

            let call;
            try {
                call = wire[method](...args);
            } catch (err) {
                inflight.delete(entry);
                entry.fail(err);
                return;
            }
            Promise.resolve(call).then(
                (res) => {
                    inflight.delete(entry);
                    if (done) return;
                    done = true;
                    clearTimeout(timer);
                    resolve(res);
                },
                (err) => {
                    inflight.delete(entry);
                    entry.fail(err);
                }
            );
        });
    }

    /* ------------------------------ UI helpers ------------------------------ */
    let badgeEl = null;
    let toastEl = null;
    let toastTimer = null;

    function defaultToast(msg, kind) {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'outbox-toast';
            toastEl.setAttribute('role', 'status');
            toastEl.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastEl);
        }
        toastEl.textContent = msg;
        toastEl.className = 'outbox-toast show ' + (kind || 'warning');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toastEl.classList.remove('show'), 3200);
    }

    function updateBadge() {
        const count = pendingCount();
        const offline = navigator.onLine === false;
        if (!badgeEl) {
            if (!count && !offline) return;
            badgeEl = document.createElement('div');
            badgeEl.className = 'outbox-badge';
            badgeEl.setAttribute('role', 'status');
            badgeEl.setAttribute('aria-live', 'polite');
            badgeEl.addEventListener('click', () => {
                if (!badgeEl.dataset.otherPage) controllers.forEach((c) => c.flush(true));
            });
            document.body.appendChild(badgeEl);
        }

        // Items queued on a page that is not open here can only be sent from that page
        const items = Object.values(loadAll());
        const openScopes = new Set(controllers.map((c) => c.scope));
        const elsewhere = items.filter((i) => !openScopes.has(i.scope));
        const scoresWord = (n) => n + (n === 1 ? ' score' : ' scores');

        let html;
        if (!count) {
            html = '<i class="bi bi-wifi-off"></i> Offline';
        } else if (offline) {
            html = '<i class="bi bi-wifi-off"></i> Offline · ' + scoresWord(count) + ' saved on this device';
        } else {
            html = '<i class="bi bi-arrow-repeat"></i> ' + scoresWord(count) + ' waiting to sync';
        }
        delete badgeEl.dataset.otherPage;
        if (elsewhere.length && elsewhere[0].page) {
            badgeEl.dataset.otherPage = '1';
            html += ' · <a href="' + encodeURI(elsewhere[0].page) + '">open the page where ' +
                (elsewhere.length === 1 ? 'it was' : 'they were') + ' entered</a>';
        }
        badgeEl.innerHTML = html;
        badgeEl.classList.toggle('offline', offline);
        badgeEl.hidden = !count && !offline;
    }

    window.addEventListener('online', () => {
        updateBadge();
        controllers.forEach((c) => c.flush(true));
    });
    window.addEventListener('offline', updateBadge);

    // Unsent scores are kept on the device, but the admin can't see them yet
    window.addEventListener('beforeunload', (e) => {
        if (pendingCount() > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    /* ------------------------------ controller ------------------------------ */
    const controllers = [];

    function setState(el, cls, ms) {
        if (!el) return;
        el.classList.remove(...STATE_CLASSES);
        if (!cls) return;
        el.classList.add(cls);
        if (ms) setTimeout(() => el.classList.remove(cls), ms);
    }

    function parseArgs(el) {
        try {
            const args = JSON.parse(el.dataset.scoreArgs);
            return Array.isArray(args) ? args : null;
        } catch (e) {
            return null;
        }
    }

    function bind(opts) {
        hookLivewire();

        const root = opts.root;
        const wire = opts.wire;
        const scope = String(opts.scope);
        const defaultMethod = opts.method || 'saveScore';
        const toast = opts.toast || defaultToast;
        const beforeSave = opts.beforeSave || null;

        // Livewire can re-run @script for the same component; keep one controller per root
        const existing = controllers.find((c) => c.root === root);
        if (existing) return existing;

        let flushing = false;
        let retryTimer = null;
        let retryStep = 0;

        const keyFor = (method, args) => scope + '|' + method + '|' + args.join(',');

        function inputsByKey() {
            const map = new Map();
            if (!root.isConnected) return map;
            root.querySelectorAll('[data-score-args]').forEach((el) => {
                const args = parseArgs(el);
                if (args) map.set(keyFor(el.dataset.scoreMethod || defaultMethod, args), el);
            });
            return map;
        }

        function scopeItems() {
            return Object.values(loadAll()).filter((i) => i.scope === scope);
        }

        // Show queued values (server HTML may be older) and mark them amber
        function paint() {
            const pending = new Map(scopeItems().map((i) => [i.key, i]));
            inputsByKey().forEach((el, key) => {
                const item = pending.get(key);
                if (item) {
                    if (document.activeElement !== el) el.value = item.value;
                    if (!el.classList.contains('is-saving')) setState(el, 'is-pending');
                } else {
                    el.classList.remove('is-pending');
                }
            });
        }

        function scheduleRetry() {
            clearTimeout(retryTimer);
            if (!scopeItems().length) {
                retryStep = 0;
                return;
            }
            const delay = RETRY_STEPS_MS[Math.min(retryStep, RETRY_STEPS_MS.length - 1)];
            retryStep++;
            retryTimer = setTimeout(() => flush(), delay);
        }

        async function send(item) {
            let res;
            try {
                res = await callWire(wire, item.method, [...item.args, item.value]);
            } catch (err) {
                return { status: 'pending' };
            }
            removeItem(item.key, item.ts);
            if (res && res.ok === false) return { status: 'rejected', error: res.error };
            return { status: 'saved', res };
        }

        // Save one score: queue first, then try the server
        async function save(method, args, value, el) {
            const item = {
                key: keyFor(method, args),
                scope,
                method,
                args,
                value,
                ts: Date.now() + Math.random(), // unique even for two saves in the same ms
                page: location.pathname + location.search,
            };
            putItem(item);
            updateBadge();

            if (navigator.onLine === false) {
                setState(el, 'is-pending');
                scheduleRetry();
                return { status: 'pending' };
            }

            setState(el, 'is-saving');
            const result = await send(item);
            const current = inputsByKey().get(item.key) || el;
            if (result.status === 'saved') {
                // A newer value for this cell may still be queued
                const still = loadAll()[item.key];
                setState(current, still ? 'is-pending' : 'is-saved', still ? 0 : 1200);
            } else if (result.status === 'rejected') {
                setState(current, 'is-error', 4000);
                toast(result.error || 'Score was not saved.', 'danger');
            } else {
                setState(current, 'is-pending');
                toast('No connection. The score is saved on this device and will be sent automatically.', 'warning');
                scheduleRetry();
            }
            updateBadge();
            return result;
        }

        async function flush(resetBackoff) {
            if (resetBackoff) retryStep = 0;
            if (flushing || navigator.onLine === false || !root.isConnected) return;
            const items = scopeItems().sort((a, b) => a.ts - b.ts);
            if (!items.length) return;

            flushing = true;
            clearTimeout(retryTimer);
            let sent = 0;
            let failed = false;
            try {
                for (let i = 0; i < items.length && !failed; i += CHUNK) {
                    const chunk = items.slice(i, i + CHUNK);
                    const map = inputsByKey();
                    chunk.forEach((item) => {
                        const el = map.get(item.key);
                        if (el) setState(el, 'is-saving');
                    });
                    const results = await Promise.all(chunk.map(send));
                    const after = inputsByKey();
                    results.forEach((r, idx) => {
                        const item = chunk[idx];
                        const el = after.get(item.key);
                        if (r.status === 'saved') {
                            sent++;
                            const still = loadAll()[item.key];
                            setState(el, still ? 'is-pending' : 'is-saved', still ? 0 : 1200);
                        } else if (r.status === 'rejected') {
                            setState(el, 'is-error', 4000);
                            toast(r.error || 'A queued score was rejected by the server.', 'danger');
                        } else {
                            failed = true;
                            setState(el, 'is-pending');
                        }
                    });
                }
            } finally {
                flushing = false;
            }

            if (sent && !failed) {
                retryStep = 0;
                toast(sent === 1 ? 'Queued score sent.' : sent + ' queued scores sent.', 'success');
            }
            updateBadge();
            paint();
            scheduleRetry();
        }

        // Save on change (blur / Enter after editing)
        root.addEventListener('change', (e) => {
            const el = e.target;
            if (!el || !el.dataset || el.dataset.scoreArgs === undefined) return;
            const args = parseArgs(el);
            if (!args) return;
            if (beforeSave) beforeSave(el);
            save(el.dataset.scoreMethod || defaultMethod, args, el.value, el);
        });

        const controller = { root, scope, save, flush, paint };
        controllers.push(controller);

        paint();
        updateBadge();
        // Send whatever was left over from earlier (page reloaded after a drop)
        setTimeout(() => flush(true), 500);
        return controller;
    }

    window.ScoreOutbox = { bind, pendingCount, toast: defaultToast };
})();
