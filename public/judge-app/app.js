/*
 * Offline Judge App (Higalaay scoring).
 *
 * Online, before the event: log in, download the event (participants, criteria, own scores).
 * Offline, at the venue:    score; every score is written to IndexedDB on this device at once.
 * Online, afterwards:       upload. The server applies a conflict check (see HigalaayScoring::applyBatch).
 *
 * Score record (store "scores"):
 *   { key, event, judge_id, category, participant_id, criteria_id,
 *     score,    // value on this device (number)
 *     base,     // server value this device last saw (number|null) - used for the conflict check
 *     dirty,    // true = not uploaded yet
 *     force,    // judge chose "keep mine" after a conflict
 *     conflict, // { server } after a conflicting upload
 *     error,    // message from a rejected upload
 *     updated_at }
 */
(function () {
    'use strict';

    const API = new URL('../api/tab-v1/judge-app/', location.href).href;
    const app = document.getElementById('app');

    /* ============================== IndexedDB ============================== */
    const db = (() => {
        let opening = null;
        function open() {
            if (!opening) {
                opening = new Promise((resolve, reject) => {
                    const r = indexedDB.open('tabulation-judge-app', 1);
                    r.onupgradeneeded = () => {
                        const d = r.result;
                        d.createObjectStore('kv');
                        d.createObjectStore('packages');
                        d.createObjectStore('scores', { keyPath: 'key' }).createIndex('event', 'event');
                    };
                    r.onsuccess = () => resolve(r.result);
                    r.onerror = () => reject(r.error);
                });
            }
            return opening;
        }
        const req = (r) => new Promise((resolve, reject) => {
            r.onsuccess = () => resolve(r.result);
            r.onerror = () => reject(r.error);
        });
        return {
            async get(store, key) {
                const d = await open();
                return req(d.transaction(store).objectStore(store).get(key));
            },
            async all(store, index, value) {
                const d = await open();
                const st = d.transaction(store).objectStore(store);
                return req(index ? st.index(index).getAll(value) : st.getAll());
            },
            // Resolves only when the transaction is committed, so "saved on device" is true
            async write(store, fn) {
                const d = await open();
                return new Promise((resolve, reject) => {
                    const t = d.transaction(store, 'readwrite');
                    fn(t.objectStore(store));
                    t.oncomplete = () => resolve();
                    t.onerror = () => reject(t.error);
                    t.onabort = () => reject(t.error || new Error('Write aborted'));
                });
            },
        };
    })();

    /* ============================== helpers ============================== */
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmt = (n) => (n == null || n === '' ? '' : String(Math.round(Number(n) * 100) / 100));
    const same = (a, b) => (a == null || b == null) ? (a == null && b == null) : Math.abs(Number(a) - Number(b)) < 0.00001;
    const natural = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
    const eventKey = (judgeId, category) => judgeId + '|' + category;
    const scoreKey = (judgeId, category, pid, cid) => judgeId + '|' + category + '|' + pid + '|' + cid;
    const when = (iso) => {
        if (!iso) return '';
        const d = new Date(iso);
        return isNaN(d) ? '' : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    };

    let toastTimer;
    function toast(msg, kind = 'warning', ms = 3200) {
        const el = document.getElementById('toast');
        el.textContent = msg;
        el.className = 'ja-toast show ' + kind;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => el.classList.remove('show'), ms);
    }

    function dialog(html) {
        const root = document.getElementById('dialog-root');
        root.innerHTML = '<div class="ja-dialog-backdrop"><div class="ja-dialog" role="dialog" aria-modal="true">' + html + '</div></div>';
        const box = root.querySelector('.ja-dialog');
        return { box, close: () => (root.innerHTML = '') };
    }

    const online = () => navigator.onLine !== false;
    const netPill = () => '<span class="net-pill ' + (online() ? '' : 'offline') + '">' +
        (online() ? '<i class="bi bi-wifi"></i> Online' : '<i class="bi bi-wifi-off"></i> Offline') + '</span>';

    /* ============================== API ============================== */
    class ApiError extends Error {
        constructor(message, status) { super(message); this.status = status; }
    }

    async function api(path, { method = 'GET', body, token } = {}) {
        let res;
        try {
            res = await fetch(API + path, {
                method,
                credentials: 'omit', // token auth only; never send the main site's session cookie
                headers: Object.assign(
                    { Accept: 'application/json' },
                    body ? { 'Content-Type': 'application/json' } : {},
                    token ? { Authorization: 'Bearer ' + token } : {}
                ),
                body: body ? JSON.stringify(body) : undefined,
            });
        } catch (e) {
            throw new ApiError('No connection to the server.', 0);
        }
        let data = null;
        try { data = await res.json(); } catch (e) { /* not JSON */ }
        if (!res.ok) {
            const msg = (data && (data.message || (data.errors && Object.values(data.errors)[0][0]))) || ('Server error (' + res.status + ').');
            throw new ApiError(msg, res.status);
        }
        return data;
    }

    /* ============================== state ============================== */
    const state = {
        session: null,   // { token, user, judge }
        events: [],      // [{ category, description, locked }] last list from the server
        view: 'home',
        category: null,
    };

    async function loadSession() {
        state.session = (await db.get('kv', 'session')) || null;
        if (state.session) {
            const cached = await db.get('kv', 'events:' + state.session.judge.id);
            state.events = cached ? cached.events : [];
        }
    }

    // The token was revoked or the account changed: keep all data, just ask to log in again
    async function handleAuthError(err) {
        if (err.status === 401 || err.status === 403) {
            toast('Please log in again. Scores on this device are kept.', 'warning', 4500);
            await db.write('kv', (s) => s.delete('session'));
            state.session = null;
            render();
            return true;
        }
        return false;
    }

    async function scoresFor(category) {
        return db.all('scores', 'event', eventKey(state.session.judge.id, category));
    }

    async function packagesForJudge() {
        const all = await db.all('packages');
        return all.filter((p) => p.judge && p.judge.id === state.session.judge.id);
    }

    async function unsyncedCount(category) {
        const rows = category ? await scoresFor(category) : (await db.all('scores')).filter((r) => r.judge_id === state.session.judge.id);
        return rows.filter((r) => r.dirty).length;
    }

    async function refreshEvents() {
        if (!online() || !state.session) return;
        try {
            const data = await api('events', { token: state.session.token });
            state.events = data.events;
            await db.write('kv', (s) => s.put({ events: data.events, at: new Date().toISOString() }, 'events:' + state.session.judge.id));
        } catch (e) {
            if (!(await handleAuthError(e)) && e.status) toast(e.message, 'danger');
        }
    }

    async function requestPersistence() {
        try {
            if (navigator.storage && navigator.storage.persist && !(await navigator.storage.persisted())) {
                await navigator.storage.persist();
            }
        } catch (e) { /* not supported */ }
    }

    /* ============================== download ============================== */
    async function downloadEvent(category) {
        if (!online()) return toast('You are offline. Connect to download.', 'warning');
        let pkg;
        try {
            pkg = await api('package/' + encodeURIComponent(category), { token: state.session.token });
        } catch (e) {
            if (!(await handleAuthError(e))) toast(e.message, 'danger');
            return false;
        }

        const judgeId = state.session.judge.id;
        const evKey = eventKey(judgeId, category);
        const local = new Map((await scoresFor(category)).map((r) => [r.key, r]));
        const server = new Map(pkg.scores.map((s) => [scoreKey(judgeId, category, s.participant_id, s.criteria_id), s]));
        const now = new Date().toISOString();

        await db.write('packages', (st) => st.put(Object.assign({}, pkg, { savedAt: now }), evKey));
        await db.write('scores', (st) => {
            server.forEach((s, key) => {
                const row = local.get(key);
                if (!row) {
                    st.put({ key, event: evKey, judge_id: judgeId, category, participant_id: s.participant_id, criteria_id: s.criteria_id, score: s.score, base: s.score, dirty: false, updated_at: now });
                } else if (!row.dirty || same(row.score, s.score)) {
                    // Not edited here (or already equal): take the server value
                    st.put(Object.assign(row, { score: s.score, base: s.score, dirty: false, force: false, conflict: null, error: null }));
                }
                // Edited here and different: keep it and keep the old base, so the upload detects the conflict
            });
            local.forEach((row, key) => {
                if (!server.has(key) && !row.dirty) st.delete(key); // removed on the server
            });
        });
        await requestPersistence();
        toast('Downloaded ' + pkg.event.description + '.', 'success');
        return true;
    }

    /* ============================== upload ============================== */
    async function uploadEvent(category, { quiet = false } = {}) {
        if (!online()) {
            if (!quiet) toast('You are offline. Scores stay on this device until you upload.', 'warning');
            return null;
        }
        const rows = (await scoresFor(category)).filter((r) => r.dirty);
        if (!rows.length) {
            if (!quiet) toast('Nothing to upload.', 'success');
            return { counts: { saved: 0, same: 0, conflict: 0, rejected: 0 } };
        }

        const sent = new Map(rows.map((r) => [r.participant_id + '-' + r.criteria_id, r]));
        let res;
        try {
            res = await api('upload', {
                method: 'POST',
                token: state.session.token,
                body: {
                    category,
                    scores: rows.map((r) => ({ participant_id: r.participant_id, criteria_id: r.criteria_id, score: r.score, base: r.base, force: !!r.force })),
                },
            });
        } catch (e) {
            if (!(await handleAuthError(e))) toast(e.status ? e.message : 'Upload failed: no connection. Scores are safe on this device.', 'danger', 4500);
            return null;
        }

        // Re-read: the judge may have typed new values while the upload was running
        const current = new Map((await scoresFor(category)).map((r) => [r.participant_id + '-' + r.criteria_id, r]));
        const conflicts = [];
        await db.write('scores', (st) => {
            res.results.forEach((r) => {
                const k = r.participant_id + '-' + r.criteria_id;
                const was = sent.get(k);
                const row = current.get(k);
                if (!row || !was) return;
                const unchanged = row.updated_at === was.updated_at;
                if (r.status === 'saved' || r.status === 'same') {
                    row.base = r.score;
                    if (unchanged) Object.assign(row, { score: r.score, dirty: false, force: false, conflict: null, error: null });
                } else if (r.status === 'conflict') {
                    row.conflict = { server: r.server };
                    row.error = null;
                    conflicts.push(row);
                } else {
                    row.error = r.error || 'Rejected by the server.';
                }
                st.put(row);
            });
        });

        await db.write('kv', (s) => s.put(new Date().toISOString(), 'lastUpload:' + eventKey(state.session.judge.id, category)));

        const c = res.counts;
        const rejected = res.results.filter((r) => r.status === 'rejected');
        if (rejected.length) {
            toast(rejected.length + ' not accepted: ' + rejected[0].error, 'danger', 6000);
        } else if (!quiet || c.saved) {
            toast('Uploaded: ' + (c.saved + c.same) + ' score' + (c.saved + c.same === 1 ? '' : 's') + (c.conflict ? ', ' + c.conflict + ' need a decision' : '') + '.', c.conflict ? 'warning' : 'success', 4000);
        }
        if (conflicts.length) await resolveConflicts(category, conflicts);
        return res;
    }

    // Each conflict: the server changed this cell since the device downloaded it
    function resolveConflicts(category, rows) {
        return new Promise((resolve) => {
            db.get('packages', eventKey(state.session.judge.id, category)).then((pkg) => {
                const pById = new Map((pkg ? pkg.participants : []).map((p) => [p.id, p]));
                const cById = new Map((pkg ? pkg.criteria : []).map((c) => [c.id, c]));
                const choice = new Map(rows.map((r) => [r.key, 'mine']));

                const items = rows.map((r) => {
                    const p = pById.get(r.participant_id) || {};
                    const c = cById.get(r.criteria_id) || {};
                    return '<div class="conflict-row" data-key="' + esc(r.key) + '">' +
                        '<div class="fw-semibold">#' + esc(p.participant_no) + (p.participant ? ' ' + esc(p.participant) : '') + ' · ' + esc(c.criteria) + '</div>' +
                        '<div class="vals"><span>This device: <b>' + fmt(r.score) + '</b></span><span>Server now: <b>' + fmt(r.conflict.server) + '</b></span></div>' +
                        '<div class="btn-group w-100" role="group">' +
                        '<button type="button" class="btn btn-sm btn-primary" data-pick="mine">Keep mine</button>' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-pick="server">Use server value</button>' +
                        '</div></div>';
                }).join('');

                const dlg = dialog(
                    '<h3><i class="bi bi-exclamation-triangle text-warning"></i> Changed on the server</h3>' +
                    '<p class="ja-muted">These scores were changed on the server after you downloaded the event (for example by the admin). Choose which value to keep.</p>' +
                    items +
                    '<div class="ja-actions"><button type="button" class="btn btn-outline-secondary" data-act="later">Decide later</button>' +
                    '<button type="button" class="btn btn-primary" data-act="apply">Apply choices</button></div>'
                );

                dlg.box.addEventListener('click', async (e) => {
                    const pick = e.target.closest('[data-pick]');
                    if (pick) {
                        const row = pick.closest('.conflict-row');
                        choice.set(row.dataset.key, pick.dataset.pick);
                        row.querySelectorAll('[data-pick]').forEach((b) => {
                            b.className = 'btn btn-sm ' + (b === pick ? 'btn-primary' : 'btn-outline-secondary');
                        });
                        return;
                    }
                    const act = e.target.closest('[data-act]');
                    if (!act) return;
                    dlg.close();
                    if (act.dataset.act === 'later') return resolve();

                    let resend = false;
                    await db.write('scores', (st) => {
                        rows.forEach((r) => {
                            if (choice.get(r.key) === 'server') {
                                Object.assign(r, { score: r.conflict.server, base: r.conflict.server, dirty: false, force: false, conflict: null, updated_at: new Date().toISOString() });
                                if (r.score == null) { st.delete(r.key); return; }
                            } else {
                                Object.assign(r, { force: true, conflict: null });
                                resend = true;
                            }
                            st.put(r);
                        });
                    });
                    if (resend) await uploadEvent(category, { quiet: true });
                    if (state.view === 'event' && state.category === category) await renderEvent();
                    resolve();
                });
            });
        });
    }

    /* ============================== backup ============================== */
    async function exportBackup() {
        const judge = state.session.judge;
        const packages = await packagesForJudge();
        const events = [];
        for (const pkg of packages) {
            const rows = await scoresFor(pkg.event.category);
            events.push({
                category: pkg.event.category,
                description: pkg.event.description,
                scores: rows.map((r) => ({ participant_id: r.participant_id, criteria_id: r.criteria_id, score: r.score, base: r.base, uploaded: !r.dirty })),
            });
        }
        const backup = {
            app: 'tabulation-judge-app',
            version: 1,
            exported_at: new Date().toISOString(),
            judge: { id: judge.id, name: judge.name },
            user: state.session.user,
            events,
        };
        const stamp = new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-');
        const name = 'judge-' + judge.id + '-scores-' + stamp + '.json';
        const blob = new Blob([JSON.stringify(backup, null, 1)], { type: 'application/json' });

        try {
            const file = new File([blob], name, { type: 'application/json' });
            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                await navigator.share({ files: [file], title: 'Judge scores backup' });
                return;
            }
        } catch (e) {
            if (e && e.name === 'AbortError') return;
        }
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
        toast('Backup saved: ' + name, 'success', 4500);
    }

    /* ============================== views ============================== */
    function render() {
        if (!state.session) return renderLogin();
        if (state.view === 'event' && state.category) return renderEvent();
        return renderHome();
    }

    function renderLogin() {
        app.innerHTML =
            '<div class="ja-wrap"><div class="ja-login ja-card">' +
            '<img src="../img/pwa/icon-192.png" alt="">' +
            '<h2 class="text-center mb-1">Judge Scoring</h2>' +
            '<p class="ja-muted text-center">Log in while you have internet. After you download your event, scoring works with no connection.</p>' +
            '<form id="login-form" novalidate>' +
            '<div class="mb-2"><label class="form-label" for="email">Email</label><input class="form-control form-control-lg" id="email" type="email" autocomplete="username" required></div>' +
            '<div class="mb-3"><label class="form-label" for="password">Password</label><input class="form-control form-control-lg" id="password" type="password" autocomplete="current-password" required></div>' +
            '<button class="btn btn-primary btn-lg w-100" type="submit">Log in</button>' +
            '<div id="login-msg" class="text-danger small mt-2"></div>' +
            '</form>' +
            '<p class="ja-muted mt-3 mb-0 text-center">' + netPill() + '</p>' +
            '</div></div>';

        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const msg = document.getElementById('login-msg');
            const btn = e.target.querySelector('button');
            if (!online()) { msg.textContent = 'You are offline. Logging in needs internet.'; return; }
            btn.disabled = true;
            msg.textContent = '';
            try {
                const data = await api('login', {
                    method: 'POST',
                    body: { email: document.getElementById('email').value.trim(), password: document.getElementById('password').value, device: navigator.platform || '' },
                });
                state.session = { token: data.token, user: data.user, judge: data.judge, at: new Date().toISOString() };
                await db.write('kv', (s) => s.put(state.session, 'session'));
                await requestPersistence();
                state.view = 'home';
                await refreshEvents();
                render();
            } catch (err) {
                msg.textContent = err.message;
                btn.disabled = false;
            }
        });
    }

    async function renderHome() {
        const judge = state.session.judge;
        const packages = await packagesForJudge();
        const pkgByCat = new Map(packages.map((p) => [p.event.category, p]));

        // Events from the server list plus anything already downloaded (works offline)
        const cats = new Map();
        state.events.forEach((e) => cats.set(e.category, e));
        packages.forEach((p) => { if (!cats.has(p.event.category)) cats.set(p.event.category, p.event); });

        let totalUnsynced = 0;
        const cards = [];
        for (const ev of cats.values()) {
            const pkg = pkgByCat.get(ev.category);
            let body;
            if (!pkg) {
                body = '<div class="ja-muted">Not downloaded yet.</div>' +
                    '<div class="ja-actions"><button class="btn btn-primary" data-act="download" data-cat="' + esc(ev.category) + '"' + (online() ? '' : ' disabled') + '><i class="bi bi-cloud-download"></i> Download</button></div>';
            } else {
                const rows = await scoresFor(ev.category);
                const filled = rows.filter((r) => r.score != null).length;
                const cells = pkg.participants.length * pkg.criteria.length;
                const unsynced = rows.filter((r) => r.dirty).length;
                const conflicts = rows.filter((r) => r.conflict).length;
                totalUnsynced += unsynced;
                const pct = cells ? Math.round((filled / cells) * 100) : 0;
                const lastUp = await db.get('kv', 'lastUpload:' + eventKey(judge.id, ev.category));
                body =
                    '<div class="d-flex justify-content-between ja-muted mb-1"><span>' + filled + ' of ' + cells + ' scored</span><span>' + pct + '%</span></div>' +
                    '<div class="progress mb-2"><div class="progress-bar' + (pct === 100 ? ' bg-success' : '') + '" style="width:' + pct + '%"></div></div>' +
                    '<div class="ja-muted">Downloaded ' + esc(when(pkg.savedAt)) + (lastUp ? ' · Last upload ' + esc(when(lastUp)) : '') + '</div>' +
                    (unsynced ? '<div class="mt-1"><span class="badge badge-pending">' + unsynced + ' not uploaded</span>' + (conflicts ? ' <span class="badge bg-danger">' + conflicts + ' need a decision</span>' : '') + '</div>' : '<div class="mt-1"><span class="badge bg-success">All uploaded</span></div>') +
                    (pkg.event.locked ? '<div class="mt-1"><span class="badge bg-secondary"><i class="bi bi-lock"></i> Scoring locked</span></div>' : '') +
                    '<div class="ja-actions">' +
                    '<button class="btn btn-primary" data-act="open" data-cat="' + esc(ev.category) + '"><i class="bi bi-pencil-square"></i> Score</button>' +
                    '<button class="btn btn-success" data-act="upload" data-cat="' + esc(ev.category) + '"' + (online() && unsynced ? '' : ' disabled') + '><i class="bi bi-cloud-upload"></i> Upload</button>' +
                    '<button class="btn btn-outline-secondary" data-act="download" data-cat="' + esc(ev.category) + '"' + (online() ? '' : ' disabled') + ' title="Get the latest participants and criteria"><i class="bi bi-arrow-clockwise"></i> Refresh</button>' +
                    '</div>';
            }
            cards.push('<div class="ja-card"><h2>' + esc(ev.description) + '</h2>' + body + '</div>');
        }

        app.innerHTML =
            '<header class="ja-top"><h1>Judge Scoring<span class="sub">' + esc(judge.name) + '</span></h1>' + netPill() +
            '<button class="btn-icon" data-act="menu" aria-label="Menu"><i class="bi bi-three-dots-vertical"></i></button></header>' +
            '<main class="ja-wrap">' +
            (totalUnsynced ? '<div class="ja-banner pending"><i class="bi bi-cloud-slash"></i> ' + totalUnsynced + ' score' + (totalUnsynced === 1 ? ' is' : 's are') + ' only on this device. Upload when you have internet.</div>' : '') +
            (!online() ? '<div class="ja-banner info"><i class="bi bi-wifi-off"></i> Offline. You can score downloaded events; upload later.</div>' : '') +
            (installPrompt ? '<div class="ja-banner info d-flex align-items-center gap-2"><span class="flex-grow-1"><i class="bi bi-phone"></i> Install this app so it opens without internet.</span><button class="btn btn-sm btn-primary" data-act="install">Install</button></div>' : '') +
            (cards.length ? cards.join('') : '<div class="ja-card ja-muted">' + (online() ? 'No events are assigned to you. Ask the admin.' : 'Connect to the internet to see your events.') + '</div>') +
            '</main>' +
            (totalUnsynced ? '<div class="ja-bottom"><div class="info pending">' + totalUnsynced + ' not uploaded</div><button class="btn btn-success" data-act="upload-all"' + (online() ? '' : ' disabled') + '><i class="bi bi-cloud-upload"></i> Upload all</button></div>' : '');
    }

    async function openMenu() {
        const unsynced = await unsyncedCount();
        const dlg = dialog(
            '<h3>' + esc(state.session.judge.name) + '</h3>' +
            '<p class="ja-muted mb-3">Logged in as ' + esc(state.session.user.name) + '</p>' +
            '<div class="d-grid gap-2">' +
            '<button class="btn btn-outline-primary" data-m="backup"><i class="bi bi-download"></i> Export backup file</button>' +
            '<div class="ja-muted small">If this device can\'t get online, send the backup file to the admin to import.</div>' +
            '<button class="btn btn-outline-secondary" data-m="logout"><i class="bi bi-box-arrow-right"></i> Log out</button>' +
            '<button class="btn btn-outline-danger" data-m="erase"><i class="bi bi-trash"></i> Erase this judge\'s data from the device</button>' +
            '<button class="btn btn-link" data-m="close">Close</button>' +
            '</div>'
        );
        dlg.box.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-m]');
            if (!b) return;
            const m = b.dataset.m;
            if (m === 'close') return dlg.close();
            if (m === 'backup') { dlg.close(); return exportBackup(); }
            if (m === 'logout') {
                if (unsynced && !confirm(unsynced + ' score(s) are not uploaded. They stay on this device and can be uploaded after you log in again. Log out?')) return;
                dlg.close();
                if (online()) api('logout', { method: 'POST', token: state.session.token }).catch(() => {});
                await db.write('kv', (s) => s.delete('session'));
                state.session = null;
                return render();
            }
            if (m === 'erase') {
                if (unsynced && !confirm('WARNING: ' + unsynced + ' score(s) were never uploaded and will be lost. Export a backup first?\n\nPress Cancel to keep them.')) return;
                if (!confirm('Erase all downloaded events and scores of ' + state.session.judge.name + ' from this device?')) return;
                dlg.close();
                const judgeId = state.session.judge.id;
                const pkgs = await packagesForJudge();
                const rows = (await db.all('scores')).filter((r) => r.judge_id === judgeId);
                await db.write('scores', (st) => rows.forEach((r) => st.delete(r.key)));
                await db.write('packages', (st) => pkgs.forEach((p) => st.delete(eventKey(judgeId, p.event.category))));
                toast('Erased.', 'success');
                return render();
            }
        });
    }

    /* ---------------------------- scoring view ---------------------------- */
    let eventCtx = null; // { category, pkg, rows: Map(pid-cid -> row) }

    async function renderEvent() {
        const category = state.category;
        const judge = state.session.judge;
        const pkg = await db.get('packages', eventKey(judge.id, category));
        if (!pkg) { state.view = 'home'; return render(); }

        const rows = new Map((await scoresFor(category)).map((r) => [r.participant_id + '-' + r.criteria_id, r]));
        eventCtx = { category, pkg, rows };

        const prefs = loadPrefs(category);
        const groups = [...new Set(pkg.participants.map((p) => (p.group_name || '').trim()).filter(Boolean))].sort(natural.compare);
        const participants = [...pkg.participants].sort((a, b) => {
            const ga = (a.group_name || '').trim(), gb = (b.group_name || '').trim();
            if (groups.length && (ga === '') !== (gb === '')) return ga === '' ? 1 : -1;
            return (groups.length ? natural.compare(ga, gb) : 0) || natural.compare(String(a.participant_no ?? ''), String(b.participant_no ?? ''));
        });
        const hasSeg = pkg.criteria.some((c) => c.segment);
        const maxTotal = pkg.criteria.reduce((s, c) => s + (Number(c.perfect_score) || 0), 0);
        const locked = !!pkg.event.locked;

        let html = '';
        let lastGroup = null;
        participants.forEach((p) => {
            const g = (p.group_name || '').trim();
            if (groups.length && g !== lastGroup) {
                html += '<div class="group-head" data-group="' + esc(g.toLowerCase()) + '">' + esc(g || 'No group') + '</div>';
                lastGroup = g;
            }
            let crit = '';
            let lastSeg = null;
            pkg.criteria.forEach((c) => {
                if (hasSeg && (c.segment || '') !== lastSeg) {
                    lastSeg = c.segment || '';
                    if (lastSeg) crit += '<div class="seg-head">' + esc(lastSeg) + (c.segment_weight ? ' · ' + esc(c.segment_weight) + '%' : '') + '</div>';
                }
                const r = rows.get(p.id + '-' + c.id);
                crit +=
                    '<div class="c-row"><div class="label"><div>' + esc(c.criteria) + '</div><small class="ja-muted">Max ' + fmt(c.perfect_score) + '</small></div>' +
                    '<input class="form-control score ' + cellClass(r) + '" type="number" inputmode="decimal" step="any" min="0"' +
                    (c.perfect_score != null ? ' max="' + esc(c.perfect_score) + '"' : '') +
                    ' data-p="' + p.id + '" data-c="' + c.id + '" data-max="' + esc(c.perfect_score ?? '') + '" data-label="' + esc(c.criteria) + '"' +
                    ' value="' + esc(r && r.score != null ? fmt(r.score) : '') + '" placeholder="—" autocomplete="off"' +
                    ' aria-label="' + esc('#' + p.participant_no + ' ' + c.criteria) + '"' + (locked ? ' disabled' : '') + '></div>';
            });
            html +=
                '<section class="p-card" data-pid="' + p.id + '" data-group="' + esc(g.toLowerCase()) + '" data-search="' + esc((String(p.participant_no ?? '') + ' ' + (p.participant || '') + ' ' + g).toLowerCase()) + '">' +
                '<div class="p-head"><span class="no">#' + esc(p.participant_no) + '</span>' +
                '<span class="name">' + esc(p.participant || '') + '</span>' +
                '<span class="p-total"><b data-total>0</b> <span class="ja-muted">/ ' + fmt(maxTotal) + '</span><small data-progress></small></span></div>' +
                crit + '</section>';
        });

        app.innerHTML =
            '<header class="ja-top"><button class="btn-icon" data-act="home" aria-label="Back"><i class="bi bi-arrow-left"></i></button>' +
            '<h1>' + esc(pkg.event.description) + '<span class="sub">' + esc(judge.name) + '</span></h1>' + netPill() + '</header>' +
            '<main class="ja-wrap' + (prefs.unfinished ? ' unfinished-only' : '') + '" id="scoring">' +
            (locked ? '<div class="ja-banner danger"><i class="bi bi-lock"></i> Scoring was locked when you downloaded this event. Refresh it when the admin unlocks scoring.</div>' : '') +
            '<div class="ja-tools">' +
            '<input type="search" class="form-control" id="f-search" placeholder="Search number or name…" value="' + esc(prefs.search || '') + '">' +
            (groups.length ? '<select class="form-select" id="f-group"><option value="">All groups</option>' + groups.map((g) => '<option value="' + esc(g.toLowerCase()) + '"' + (prefs.group === g.toLowerCase() ? ' selected' : '') + '>' + esc(g) + '</option>').join('') + '</select>' : '') +
            '<button type="button" class="btn ' + (prefs.unfinished ? 'btn-warning' : 'btn-outline-secondary') + '" id="f-unfinished"><i class="bi bi-funnel"></i> Unfinished only</button>' +
            '</div>' +
            (participants.length ? html : '<div class="ja-card ja-muted">No participants in this event.</div>') +
            '</main>' +
            '<div class="ja-bottom"><div class="info" id="sync-info"></div>' +
            '<button class="btn btn-success" data-act="upload" data-cat="' + esc(category) + '" id="btn-upload"><i class="bi bi-cloud-upload"></i> Upload</button></div>';

        participants.forEach((p) => updateCard(p.id));
        applyFilters();
        updateSyncInfo();
    }

    function cellClass(r) {
        if (!r || r.score == null) return '';
        if (r.conflict) return 'conflict';
        if (r.dirty) return 'dirty';
        return 'has';
    }

    function updateCard(pid) {
        const card = app.querySelector('.p-card[data-pid="' + pid + '"]');
        if (!card || !eventCtx) return;
        let total = 0, done = 0;
        eventCtx.pkg.criteria.forEach((c) => {
            const r = eventCtx.rows.get(pid + '-' + c.id);
            if (r && r.score != null) { total += Number(r.score); done++; }
        });
        const n = eventCtx.pkg.criteria.length;
        card.querySelector('[data-total]').textContent = fmt(total);
        card.querySelector('[data-progress]').textContent = done + '/' + n + (done === n && n ? ' ✓' : '');
        card.classList.toggle('complete', n > 0 && done === n);
    }

    function updateSyncInfo() {
        const info = document.getElementById('sync-info');
        const btn = document.getElementById('btn-upload');
        if (!info || !eventCtx) return;
        const rows = [...eventCtx.rows.values()];
        const dirty = rows.filter((r) => r.dirty).length;
        const conflicts = rows.filter((r) => r.conflict).length;
        info.className = 'info' + (dirty ? ' pending' : '');
        info.innerHTML = dirty
            ? '<i class="bi bi-phone"></i> ' + dirty + ' saved on this device only' + (conflicts ? ' · ' + conflicts + ' need a decision' : '')
            : '<i class="bi bi-check2-circle text-success"></i> Everything uploaded';
        btn.disabled = !dirty || !online();
    }

    function loadPrefs(category) {
        try { return JSON.parse(sessionStorage.getItem('ja-prefs:' + category)) || {}; } catch (e) { return {}; }
    }
    function savePrefs(category, prefs) {
        try { sessionStorage.setItem('ja-prefs:' + category, JSON.stringify(prefs)); } catch (e) { /* ignore */ }
    }

    function applyFilters() {
        const main = document.getElementById('scoring');
        if (!main) return;
        const q = (document.getElementById('f-search')?.value || '').trim().toLowerCase();
        const g = document.getElementById('f-group')?.value || '';
        main.querySelectorAll('.p-card').forEach((card) => {
            const ok = (!q || card.dataset.search.includes(q)) && (!g || card.dataset.group === g);
            card.hidden = !ok;
        });
        main.querySelectorAll('.group-head').forEach((h) => { h.hidden = !!g && h.dataset.group !== g; });
        savePrefs(state.category, { search: q, group: g, unfinished: main.classList.contains('unfinished-only') });
    }

    async function saveCell(el) {
        const ctx = eventCtx;
        if (!ctx) return;
        const max = parseFloat(el.dataset.max);
        let v = el.value.trim() === '' ? 0 : parseFloat(el.value); // blank saves 0, same as the online page
        if (isNaN(v)) v = 0;
        if (v < 0) { v = 0; toast('Scores cannot be negative. Saved as 0.'); }
        if (!isNaN(max) && v > max) { v = max; toast(el.dataset.label + ': the maximum is ' + fmt(max) + '. Saved as ' + fmt(max) + '.'); }
        el.value = fmt(v);

        const pid = +el.dataset.p, cid = +el.dataset.c;
        const judgeId = state.session.judge.id;
        const k = pid + '-' + cid;
        const prev = ctx.rows.get(k);
        const row = Object.assign({}, prev || {
            key: scoreKey(judgeId, ctx.category, pid, cid), event: eventKey(judgeId, ctx.category),
            judge_id: judgeId, category: ctx.category, participant_id: pid, criteria_id: cid, base: null,
        });
        row.score = v;
        row.dirty = !same(v, row.base);
        if (!row.dirty) { row.force = false; row.conflict = null; }
        row.error = null;
        row.updated_at = new Date().toISOString();

        try {
            await db.write('scores', (st) => st.put(row));
        } catch (e) {
            el.classList.add('conflict');
            toast('Could not save on this device: ' + (e && e.message ? e.message : 'storage error') + '. Do not close the app; export a backup.', 'danger', 8000);
            return;
        }
        ctx.rows.set(k, row);
        el.className = 'form-control score ' + cellClass(row) + ' flash';
        setTimeout(() => el.classList.remove('flash'), 600);
        updateCard(pid);
        updateSyncInfo();
    }

    /* ============================== events ============================== */
    app.addEventListener('click', async (e) => {
        const b = e.target.closest('[data-act]');
        if (!b) return;
        const act = b.dataset.act;
        const cat = b.dataset.cat;
        if (act === 'menu') return openMenu();
        if (act === 'home') { state.view = 'home'; state.category = null; eventCtx = null; return render(); }
        if (act === 'install' && installPrompt) {
            installPrompt.prompt();
            installPrompt = null;
            return render();
        }
        if (act === 'open') { state.view = 'event'; state.category = cat; window.scrollTo(0, 0); return render(); }

        b.disabled = true;
        const label = b.innerHTML;
        b.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        try {
            if (act === 'download') await downloadEvent(cat);
            if (act === 'upload') await uploadEvent(cat);
            if (act === 'upload-all') {
                const pkgs = await packagesForJudge();
                for (const p of pkgs) {
                    if (await unsyncedCount(p.event.category)) await uploadEvent(p.event.category, { quiet: true });
                }
            }
        } finally {
            if (b.isConnected) { b.innerHTML = label; b.disabled = false; }
        }
        if (state.session) render();
    });

    app.addEventListener('change', (e) => {
        if (e.target.classList.contains('score')) return saveCell(e.target);
        if (e.target.id === 'f-group') applyFilters();
    });
    app.addEventListener('input', (e) => {
        if (e.target.id === 'f-search') applyFilters();
    });
    app.addEventListener('click', (e) => {
        if (e.target.closest('#f-unfinished')) {
            const main = document.getElementById('scoring');
            const on = main.classList.toggle('unfinished-only');
            e.target.closest('#f-unfinished').className = 'btn ' + (on ? 'btn-warning' : 'btn-outline-secondary');
            applyFilters();
        }
    });

    // Enter / ArrowDown → next score cell, Shift+Enter / ArrowUp → previous (same as the online page)
    app.addEventListener('keydown', (e) => {
        const el = e.target;
        if (!el.classList || !el.classList.contains('score')) return;
        let dir = 0;
        if (e.key === 'Enter') dir = e.shiftKey ? -1 : 1;
        else if (e.key === 'ArrowDown') dir = 1;
        else if (e.key === 'ArrowUp') dir = -1;
        else return;
        e.preventDefault();
        const list = [...app.querySelectorAll('.score')].filter((i) => !i.disabled && i.offsetParent !== null);
        const next = list[list.indexOf(el) + dir];
        if (next) { next.focus(); next.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } else el.blur();
    });
    app.addEventListener('focusin', (e) => {
        if (e.target.classList && e.target.classList.contains('score')) setTimeout(() => e.target.select(), 0);
    });
    // Scrolling over a focused cell must not change its value
    app.addEventListener('wheel', (e) => {
        if (e.target.classList && e.target.classList.contains('score') && document.activeElement === e.target) e.target.blur();
    }, { passive: true });

    // Connection changes: refresh the header and buttons (not while typing in a cell)
    function onNetChange() {
        if (document.activeElement && document.activeElement.classList.contains('score')) {
            updateSyncInfo();
            const pill = app.querySelector('.net-pill');
            if (pill) pill.outerHTML = netPill();
            return;
        }
        if (online() && state.session && state.view === 'home') refreshEvents().then(render);
        else render();
    }
    window.addEventListener('online', () => { toast('Back online. You can upload now.', 'success'); onNetChange(); });
    window.addEventListener('offline', () => { toast('Offline. Scores are saved on this device.', 'warning'); onNetChange(); });

    /* ============================== install / service worker ============================== */
    let installPrompt = null;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        installPrompt = e;
        if (state.session && state.view === 'home') render();
    });

    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register('sw.js').catch((err) => console.warn('Service worker not registered:', err));
    }

    /* ============================== start ============================== */
    (async () => {
        if (!window.indexedDB) {
            app.innerHTML = '<div class="ja-wrap"><div class="ja-banner danger">This browser cannot store data offline. Use a recent Chrome, Edge, Safari or Firefox.</div></div>';
            return;
        }
        try {
            await loadSession();
        } catch (e) {
            app.innerHTML = '<div class="ja-wrap"><div class="ja-banner danger">Storage is not available (private browsing?). Offline scoring will not work in this mode.</div></div>';
            return;
        }
        render();
        if (state.session && online()) {
            await refreshEvents();
            if (state.session && state.view === 'home') render();
        }
    })();
})();
