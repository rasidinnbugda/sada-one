/* ============================================================
   SADA Dijital — Application JavaScript
   ============================================================ */
(function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf"]')?.content || '';

    /* ---------- Helpers ---------- */
    const $ = (s, k = document) => k.querySelector(s);
    const $$ = (s, k = document) => Array.from(k.querySelectorAll(s));

    window.api = async function (action, data = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('csrf', CSRF);
        let hasFile = false;
        for (const k in data) {
            if (data[k] instanceof File || data[k] instanceof Blob) { fd.append(k, data[k]); hasFile = true; }
            else if (Array.isArray(data[k])) fd.append(k, JSON.stringify(data[k]));
            else fd.append(k, data[k] ?? '');
        }
        // Every call has a deadline: a request that never answers used to leave the
        // button on "İşleniyor..." forever and silently stop the polling chain.
        const ms = hasFile ? 180000 : (action.startsWith('ai_') || action.startsWith('report_mail') || action === 'drive_files_batch' ? 120000 : 25000);
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), ms);
        try {
            const r = await fetch('ajax.php', { method: 'POST', body: fd, signal: ctrl.signal });
            const j = await r.json();
            if (!j.ok && j.error) toast(j.error, 'error');
            return j;
        } catch (e) {
            toast(e.name === 'AbortError' ? 'Sunucu yanıt vermedi (zaman aşımı). Tekrar deneyin.' : 'Bağlantı hatası. Tekrar deneyin.', 'error');
            return { ok: false, error: e.name === 'AbortError' ? 'timeout' : 'network' };
        } finally { clearTimeout(timer); }
    };

    /* ---------- Toast ---------- */
    window.toast = function (message, type = 'info', duration = 3800) {
        const field = $('#toastField');
        if (!field) return;
        const el = document.createElement('div');
        el.className = 'toast ' + type;
        const icons = {
            success: '<path d="M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            error: '<path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/>',
            info: '<path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
        };
        el.innerHTML = `<svg class="toast-icon" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">${icons[type] || icons.info}</svg><span>${message}</span>`;
        field.appendChild(el);
        setTimeout(() => { el.classList.add('leaving'); setTimeout(() => el.remove(), 300); }, duration);
    };

    /* ---------- Modal ---------- */
    window.modalOpen = function (id) {
        const m = document.getElementById(id);
        if (m) { m.classList.add('open'); document.body.style.overflow = 'hidden'; const first = m.querySelector('input,textarea,select'); if (first) setTimeout(() => first.focus(), 120); }
    };
    window.modalClose = function (el) {
        const m = el?.closest ? el.closest('.modal-overlay') : document.getElementById(el);
        if (m) { m.classList.remove('open'); document.body.style.overflow = ''; }
    };
    document.addEventListener('click', e => {
        if (e.target.classList?.contains('modal-overlay')) modalClose(e.target);
        const opener = e.target.closest('[data-modal]');
        if (opener) { e.preventDefault(); modalOpen(opener.dataset.modal); }
        const closer = e.target.closest('[data-modal-close]');
        if (closer) { e.preventDefault(); modalClose(closer); }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { const a = $('.modal-overlay.open'); if (a) modalClose(a); }
    });

    /* ---------- Dropdown menu ---------- */
    document.addEventListener('click', e => {
        const btn = e.target.closest('[data-dropdown-btn]');
        const openOne = $$('.dropdown.open');
        if (btn) {
            const group = btn.closest('.dropdown');
            const alreadyOpen = group.classList.contains('open');
            openOne.forEach(a => a.classList.remove('open'));
            if (!alreadyOpen) group.classList.add('open');
            e.stopPropagation();
        } else if (!e.target.closest('.dropdown-panel')) {
            openOne.forEach(a => a.classList.remove('open'));
        }
    });

    /* ---------- Tabs ---------- */
    document.addEventListener('click', e => {
        const tab = e.target.closest('[data-tab]');
        if (!tab) return;
        const container = tab.closest('.tab-container') || document;
        $$('[data-tab]', container).forEach(s => s.classList.remove('active'));
        $$('.tab-content', container).forEach(s => s.classList.remove('active'));
        tab.classList.add('active');
        const target = $('#tab-' + tab.dataset.tab, container);
        if (target) target.classList.add('active');
        if (history.replaceState) history.replaceState(null, '', '#' + tab.dataset.tab);
    });
    // Open the tab from the URL hash
    if (location.hash) {
        const s = $(`[data-tab="${location.hash.slice(1)}"]`);
        if (s) s.click();
    }

    /* ---------- Nav groups: expand/collapse + remember ---------- */
    $$('.nav-group').forEach(group => {
        const storageKey = 'navGroup_' + group.dataset.navGroup;
        // Saved state (the group containing the active page is always open)
        if (!group.classList.contains('active-group')) {
            const entry = localStorage.getItem(storageKey);
            if (entry === 'open') group.classList.add('open');
        }
        group.querySelector('[data-group-btn]')?.addEventListener('click', () => {
            group.classList.toggle('open');
            localStorage.setItem(storageKey, group.classList.contains('open') ? 'open' : 'closed');
        });
    });

    /* ---------- No autofill inside the panel ----------
       The browser was pouring the PANEL's saved login into unrelated forms
       (SMTP credentials, user editing...). The login page does not load this
       file, so autofill keeps working there. new-password is the only value
       Chrome reliably honours on password fields. */
    $$('form').forEach(f => f.setAttribute('autocomplete', 'off'));
    $$('input[type="password"]').forEach(i => { if (!i.getAttribute('autocomplete')) i.setAttribute('autocomplete', 'new-password'); });
    $$('input[type="email"], input[name*="user"], input[name*="email"]').forEach(i => { if (!i.getAttribute('autocomplete')) i.setAttribute('autocomplete', 'off'); });

    /* ---------- Sidebar (mobile) ---------- */
    const sidebar = $('#sidebar'), backdrop = $('[data-backdrop]');
    $('[data-sidebar-open]')?.addEventListener('click', () => { sidebar.classList.add('open'); backdrop.classList.add('open'); });
    const sidebarClose = () => { sidebar?.classList.remove('open'); backdrop?.classList.remove('open'); };
    $('[data-sidebar-close]')?.addEventListener('click', sidebarClose);
    backdrop?.addEventListener('click', sidebarClose);

    /* ---------- Theme switching ---------- */
    $$('.theme-dot').forEach(dot => {
        dot.addEventListener('click', async () => {
            const theme = dot.dataset.theme;
            document.documentElement.setAttribute('data-theme', theme);
            $$('.theme-dot').forEach(n => n.classList.toggle('selected', n === dot));
            await api('theme_change', { theme });
        });
    });

    /* ---------- Notifications ---------- */
    document.addEventListener('click', async e => {
        const read = e.target.closest('[data-all-read]');
        if (read) {
            e.preventDefault(); e.stopPropagation();
            await api('notification_all_read');
            $$('.notification-item.new').forEach(b => b.classList.remove('new'));
            const badge = $('[data-notification-badge]'); if (badge) badge.style.display = 'none';
            read.remove();
        }
        const notificationDelete = e.target.closest('[data-notification-delete]');
        if (notificationDelete) {
            e.preventDefault(); e.stopPropagation();
            const item = notificationDelete.closest('[data-notification]');
            await api('notification_delete', { id: item.dataset.notification });
            item.remove();
            return;
        }
        const notifEl = e.target.closest('[data-notification]');
        if (notifEl && notifEl.classList.contains('new')) {
            api('notification_read', { id: notifEl.dataset.notification });
        }
    });

    /* ---------- HTML escape helper for template literals (XSS guard) ---------- */
    window.esc = v => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    /* ---------- Navigation progress bar: appears on click, finishes when the page changes ---------- */
    document.addEventListener('click', e => {
        const a = e.target.closest('a[href]');
        if (!a || a.target === '_blank' || a.hasAttribute('download') || e.metaKey || e.ctrlKey) return;
        const href = a.getAttribute('href') || '';
        if (href.startsWith('#') || href.startsWith('javascript') || href.startsWith('http') && !href.includes(location.host)) return;
        const bar = document.getElementById('pageBar');
        if (!bar) return;
        bar.classList.add('active');
        bar.style.width = '30%';
        setTimeout(() => bar.style.width = '75%', 180);
        setTimeout(() => bar.style.width = '92%', 700);
    });
    window.addEventListener('pageshow', () => {
        const bar = document.getElementById('pageBar');
        if (bar) { bar.style.width = '0'; bar.classList.remove('active'); }
    });

    /* ---------- Polling helper ----------
       The next round is scheduled only after the previous answer arrived: with a
       plain setInterval a slow server made every open tab stack requests on top of
       each other (10 s interval, 30 s answers → 3 in flight), which slowed the
       server further — a self-feeding pile-up. Failures back off up to 2 min. */
    window.sadaPoll = function (ms, fn) {
        let wait = ms;
        const tick = async () => {
            if (!document.hidden) {
                try { await fn(); wait = ms; } catch (e) { wait = Math.min(wait * 2, 120000); }
            }
            setTimeout(tick, wait);
        };
        setTimeout(tick, ms);
    };

    /* ---------- Live notification counter: refresh every 45 s ---------- */
    sadaPoll(45000, async () => {
        const j = await api('notification_count', {});
        if (!j.ok) throw new Error('poll');
        const badge = document.querySelector('[data-notification-badge]');
        if (!badge) return;
        badge.textContent = j.count > 99 ? '99+' : j.count;
        badge.style.display = j.count > 0 ? '' : 'none';
    });

    /* ---------- Collapsible sections (My Steps etc.) ---------- */
    $$('[data-collapse]').forEach(box => {
        const storageKey = 'collapse_' + box.dataset.collapse;
        const entry = localStorage.getItem(storageKey);
        // collapsed by default; apply the saved preference if any
        if (entry === 'open') box.classList.remove('closed');
        box.querySelector('[data-collapse-btn]')?.addEventListener('click', () => {
            box.classList.toggle('closed');
            localStorage.setItem(storageKey, box.classList.contains('closed') ? 'closed' : 'open');
        });
    });

    /* ---------- Task kind: internal work has no publish plan ---------- */
    document.addEventListener('change', e => {
        if (!e.target.matches('input[type="radio"][name="kind"]')) return;
        e.target.form?.querySelectorAll('.publish-fields').forEach(b => { b.hidden = e.target.value !== 'client'; });
    });

    /* ---------- AJAX form submission ---------- */
    document.addEventListener('submit', async e => {
        const form = e.target;
        if (!form.matches('[data-ajax]')) return;
        e.preventDefault();
        // Serialize member picker checkboxes to JSON
        const memberJson = form.querySelector('.member-json');
        if (memberJson) memberJson.value = JSON.stringify($$('.member-box:checked', form).map(c => c.value));
        // Serialize task assignees
        const assigneeJson = form.querySelector('.assignees-json');
        if (assigneeJson) assigneeJson.value = JSON.stringify($$('.assigned-box:checked', form).map(c => c.value));
        // Serialize publish platforms
        const platformJson = form.querySelector('.platforms-json');
        if (platformJson) platformJson.value = JSON.stringify($$('.platform-box:checked', form).map(c => c.value));
        const btn = form.querySelector('[type="submit"]');
        const oldText = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = 'İşleniyor...'; }

        const data = {};
        new FormData(form).forEach((v, k) => { data[k] = v; });
        // Add ALL file inputs in the form (e.g. logo + favicon in the same form)
        $$('input[type="file"]', form).forEach(field => {
            if (!field.files.length || !field.name) return;
            if (field.multiple) [...field.files].forEach((d, i) => { data[field.name + '__' + i] = d; });
            else data[field.name] = field.files[0];
        });

        let j;
        try { j = await api(form.dataset.ajax, data); }
        finally { if (btn) { btn.disabled = false; btn.innerHTML = oldText; } }

        if (j.ok) {
            if (j.message) toast(j.message, 'success');
            if (j.redirect) { setTimeout(() => location.href = j.redirect, 500); }
            else if (form.dataset.refresh !== 'no') { setTimeout(() => location.reload(), 550); }
            const m = form.closest('.modal-overlay'); if (m) modalClose(m);
        }
    });

    /* ---------- Generic data-action triggers (confirmation dialog) ---------- */
    document.addEventListener('click', async e => {
        const el = e.target.closest('[data-action]');
        if (!el) return;
        e.preventDefault();
        const question = el.dataset.confirm;
        if (question && !confirm(question)) return;
        const data = {};
        for (const k in el.dataset) {
            if (!['action', 'confirm', 'refresh', 'redirect'].includes(k)) data[k] = el.dataset[k];
        }
        const j = await api(el.dataset.action, data);
        if (j.ok) {
            if (j.message) toast(j.message, 'success');
            if (el.dataset.redirect) location.href = el.dataset.redirect;
            else if (j.redirect) location.href = j.redirect;
            else if (el.dataset.refresh !== 'no') setTimeout(() => location.reload(), 450);
        }
    });

    /* ---------- Kanban drag-and-drop (in-column sorting + persistence) ---------- */
    let dragged = null;
    $$('.kanban-card[draggable]').forEach(bind_card);
    function bind_card(card) {
        card.addEventListener('dragstart', e => {
            dragged = card;
            // Custom clone floating under the cursor: slightly tilted + deep shadow
            if (e.dataTransfer && e.dataTransfer.setDragImage) {
                const r = card.getBoundingClientRect();
                const clone = card.cloneNode(true);
                clone.classList.add('kanban-ghost');
                clone.style.width = r.width + 'px';
                const wrap = document.createElement('div');
                wrap.style.cssText = 'position:fixed;top:-600px;left:-600px;padding:34px;pointer-events:none;background:transparent';
                wrap.appendChild(clone);
                document.body.appendChild(wrap);
                e.dataTransfer.setDragImage(wrap, (e.clientX - r.left) + 34, (e.clientY - r.top) + 34);
                setTimeout(() => wrap.remove(), 0);
            }
            setTimeout(() => card.classList.add('dragging'), 0);
        });
        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
            card.classList.add('dropped');
            setTimeout(() => card.classList.remove('dropped'), 360);
            dragged = null;
        });
    }
    // Insertion point based on mouse position: which card will it be dropped above?
    function insertionPoint(list, y) {
        const cards = Array.from(list.querySelectorAll('.kanban-card:not(.dragging)'));
        let nearest = { distance: Number.NEGATIVE_INFINITY, el: null };
        for (const k of cards) {
            const box = k.getBoundingClientRect();
            const diff = y - box.top - box.height / 2;
            if (diff < 0 && diff > nearest.distance) nearest = { distance: diff, el: k };
        }
        return nearest.el;
    }
    $$('.kanban-list').forEach(list => {
        list.addEventListener('dragover', e => {
            e.preventDefault();
            list.closest('.kanban-column').classList.add('drag-over');
            if (!dragged) return;
            const next = insertionPoint(list, e.clientY);
            if (next) list.insertBefore(dragged, next);
            else list.appendChild(dragged);
        });
        list.addEventListener('dragleave', () => list.closest('.kanban-column').classList.remove('drag-over'));
        list.addEventListener('drop', async e => {
            e.preventDefault();
            const column = list.closest('.kanban-column');
            column.classList.remove('drag-over');
            if (!dragged) return;
            const newStatus = column.dataset.status;
            const taskId = dragged.dataset.task;
            const oldStatus = dragged.dataset.status;
            const card = dragged;
            card.dataset.status = newStatus;
            updateKanbanCounts();
            // Collect the column's current order and save it in a single request
            const ids = Array.from(list.querySelectorAll('.kanban-card')).map(k => k.dataset.task);
            const j = await api('task_sort', { id: taskId, status: newStatus, ids });
            if (j.ok) {
                if (newStatus !== oldStatus) toast('İş "' + column.querySelector('.kanban-title').textContent + '" durumuna taşındı', 'success', 2200);
            } else {
                // Rejected by the lock etc.: put the card back into its old column
                card.dataset.status = oldStatus;
                const oldList = $(`.kanban-column[data-status="${oldStatus}"] .kanban-list`);
                if (oldList) oldList.appendChild(card);
                updateKanbanCounts();
            }
        });
    });
    function updateKanbanCounts() {
        $$('.kanban-column').forEach(s => {
            const say = s.querySelectorAll('.kanban-card').length;
            const el = s.querySelector('.kanban-count'); if (el) el.textContent = say;
        });
    }

    /* ---------- Global search ---------- */
    const searchInput = $('#globalSearch');
    if (searchInput) {
        const panel = $('#searchResult');
        let timer = null;
        searchInput.addEventListener('input', () => {
            clearTimeout(timer);
            const q = searchInput.value.trim();
            if (q.length < 2) { panel.classList.remove('open'); return; }
            timer = setTimeout(async () => {
                const j = await api('search', { q });
                if (!j.ok) return;
                let h = '';
                const icons = { 'Dosyalar': '📁', 'Projeler': '📋', 'İşler': '✅', 'İçerikler': '📅', 'Talepler': '💬' };
                for (const group in j.results) {
                    if (!j.results[group].length) continue;
                    h += `<div class="search-group">${icons[group] || ''} ${group}</div>`;
                    j.results[group].forEach(s => {
                        h += `<a href="${s.link}" class="search-item"><span>${s.name.replace(/</g, '&lt;')}</span><span class="cell-bottom">${s.bottom || ''}</span></a>`;
                    });
                }
                panel.innerHTML = h || '<div class="empty-mini">Sonuç bulunamadı</div>';
                panel.classList.add('open');
            }, 280);
        });
        document.addEventListener('click', e => {
            if (!e.target.closest('.search-global')) panel.classList.remove('open');
        });
        searchInput.addEventListener('keydown', e => { if (e.key === 'Escape') panel.classList.remove('open'); });
    }

    /* ---------- @Mention autocomplete ---------- */
    function trLower(s) { return s.replace(/İ/g, 'i').replace(/I/g, 'ı').toLowerCase(); }
    function mentionSetup(ta) {
        const container = ta.closest('.mention-wrap') || ta.parentElement;
        let dropdown = null, activeIndex = 0, matched = [];
        function close() { if (dropdown) { dropdown.remove(); dropdown = null; } }
        function queryFind() {
            const textBefore = ta.value.slice(0, ta.selectionStart);
            const m = textBefore.match(/@([^\s@]{0,25})$/);
            return m ? m[1] : null;
        }
        function show(query) {
            const people = window.sadaPeople || [];
            matched = people.filter(k => trLower(k.name).includes(trLower(query))).slice(0, 6);
            if (!matched.length) { close(); return; }
            close();
            activeIndex = 0;
            dropdown = document.createElement('div');
            dropdown.className = 'mention-dropdown';
            matched.forEach((k, i) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'mention-item' + (i === 0 ? ' active' : '');
                b.textContent = '@ ' + k.name;
                b.addEventListener('mousedown', e => { e.preventDefault(); pick(k); });
                dropdown.appendChild(b);
            });
            container.appendChild(dropdown);
        }
        function pick(person) {
            const textBefore = ta.value.slice(0, ta.selectionStart);
            const textAfter = ta.value.slice(ta.selectionStart);
            const newTextBefore = textBefore.replace(/@[^\s@]{0,25}$/, '@' + person.name + ' ');
            ta.value = newTextBefore + textAfter;
            ta.selectionStart = ta.selectionEnd = newTextBefore.length;
            // add the id to the hidden field
            const hidden = (ta.closest('form') || container).querySelector('.mention-ids');
            if (hidden) {
                const current = hidden.value ? JSON.parse(hidden.value) : [];
                if (!current.includes(person.id)) current.push(person.id);
                hidden.value = JSON.stringify(current);
            }
            close();
            ta.focus();
        }
        ta.addEventListener('input', () => {
            const query = queryFind();
            if (query === null) { close(); return; }
            show(query);
        });
        ta.addEventListener('keydown', e => {
            if (!dropdown) return;
            const items = dropdown.querySelectorAll('.mention-item');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = (activeIndex + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                items.forEach((o, i) => o.classList.toggle('active', i === activeIndex));
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                pick(matched[activeIndex]);
            } else if (e.key === 'Escape') close();
        });
        ta.addEventListener('blur', () => setTimeout(close, 180));
    }
    $$('textarea[data-mention]').forEach(mentionSetup);
    window.sadaMentionSetup = mentionSetup;

    /* ---------- Task table: column sorting ---------- */
    document.addEventListener('click', e => {
        const th = e.target.closest('th.sortable');
        if (!th) return;
        const table = th.closest('table');
        const tbody = table.querySelector('tbody');
        const index = Array.from(th.parentElement.children).indexOf(th);
        const direction = th.dataset.direction === 'asc' ? 'desc' : 'asc';
        table.querySelectorAll('th.sortable').forEach(t => { delete t.dataset.direction; const i = t.querySelector('.order-mark'); if (i) i.textContent = '↕'; });
        th.dataset.direction = direction;
        const mark = th.querySelector('.order-mark'); if (mark) mark.textContent = direction === 'asc' ? '↑' : '↓';
        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort((a, b) => {
            const av = (a.children[index]?.dataset.sort ?? a.children[index]?.textContent ?? '').trim();
            const bv = (b.children[index]?.dataset.sort ?? b.children[index]?.textContent ?? '').trim();
            const an = parseFloat(av), bn = parseFloat(bv);
            const result = (!isNaN(an) && !isNaN(bn)) ? an - bn : av.localeCompare(bv, 'tr');
            return direction === 'asc' ? result : -result;
        });
        rows.forEach(s => tbody.appendChild(s));
    });

    /* ---------- Task table: in-cell editing ---------- */
    window.cellSave = async function (el, id, field) {
        const j = await api('task_field', { id, field, value: el.value });
        if (j.ok) {
            const cell = el.closest('td');
            cell.classList.remove('cell-saved');
            void cell.offsetWidth; // trigger the animation
            cell.classList.add('cell-saved');
        } else if (el.dataset.old !== undefined) {
            el.value = el.dataset.old; // revert if the lock rejected it
        }
    };

    /* ---------- Row sorting arrows (workflow/form editors) ---------- */
    document.addEventListener('click', e => {
        const ok = e.target.closest('[data-sort-dir]');
        if (!ok) return;
        e.preventDefault();
        const row_item = ok.closest('[data-sortable]');
        if (!row_item) return;
        if (ok.dataset.sortDir === 'up' && row_item.previousElementSibling) {
            row_item.parentElement.insertBefore(row_item, row_item.previousElementSibling);
        } else if (ok.dataset.sortDir === 'down' && row_item.nextElementSibling) {
            row_item.parentElement.insertBefore(row_item.nextElementSibling, row_item);
        }
    });

    /* ---------- Search filtering (client-side) ---------- */
    $$('[data-search]').forEach(input => {
        input.addEventListener('input', () => {
            const q = input.value.toLowerCase().trim();
            $$(input.dataset.search).forEach(item => {
                const text = item.dataset.search || item.textContent;
                item.style.display = text.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    });

    /* ---------- Pill filters ---------- */
    $$('[data-pill-group]').forEach(group => {
        group.addEventListener('click', e => {
            const pill = e.target.closest('.pill');
            if (!pill) return;
            $$('.pill', group).forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            const value = pill.dataset.value;
            const target = group.dataset.pillGroup;
            $$(target).forEach(item => {
                item.style.display = (value === '' || item.dataset.filter === value) ? '' : 'none';
            });
        });
    });

    /* ---------- Progress bar animation ---------- */
    setTimeout(() => {
        $$('.progress-full[data-rate]').forEach(el => { el.style.width = el.dataset.rate + '%'; });
    }, 200);

    /* ---------- Counter animation ---------- */
    $$('[data-counter]').forEach(el => {
        const target = parseFloat(el.dataset.counter);
        if (isNaN(target)) return;
        let current = 0;
        const step = target / 32;
        const timer = setInterval(() => {
            current += step;
            if (current >= target) { current = target; clearInterval(timer); }
            el.textContent = Number.isInteger(target) ? Math.round(current) : current.toFixed(1);
        }, 22);
    });

    /* ---------- Scroll to the bottom in messaging ---------- */
    const chatBody = $('.chat-body');
    if (chatBody) chatBody.scrollTop = chatBody.scrollHeight;

    /* ---------- Live sync: check for changes every 10 s ----------
       Activates if the page defines window.sadaCanli = {baglam, id, hash}.
       Refreshing is deferred while the user is typing or a modal is open. */
    window.liveRefresh = async function () {
        if (!window.sadaLive) return;
        const j = await api('live_status', { context: sadaLive.context, id: sadaLive.id || 0 });
        if (j.ok) sadaLive.hash = j.hash;
    };
    function isBusy() {
        const a = document.activeElement;
        if (a && (a.tagName === 'TEXTAREA' || a.tagName === 'INPUT' || a.tagName === 'SELECT')) return true;
        if ($('.modal-overlay.open') || $('.mention-dropdown') || $('.kanban-card.dragging')) return true;
        return false;
    }
    // Reload guard: the list hash covers every task, so on a busy day one tab could
    // reload every 10 s all day long. At most one auto-reload per 45 s, and after
    // 4 reloads in 10 minutes the tab switches to a "Yenile" notice instead.
    const liveReloadAllowed = () => {
        let stored = {};
        try { stored = JSON.parse(sessionStorage.getItem('sadaLiveReload') || '{}'); } catch (e) { /* ignore */ }
        const nowMs = Date.now();
        const times = (stored.times || []).filter(t => nowMs - t < 600000);
        if (times.length && nowMs - times[times.length - 1] < 45000) return false;
        if (times.length >= 4) return false;
        times.push(nowMs);
        try { sessionStorage.setItem('sadaLiveReload', JSON.stringify({ times })); } catch (e) { /* ignore */ }
        return true;
    };
    let liveWarned = false;
    sadaPoll(10000, async () => {
        if (!window.sadaLive || isBusy()) return;
        const j = await api('live_status', { context: sadaLive.context, id: sadaLive.id || 0 });
        if (!j.ok) throw new Error('poll');
        if (j.hash !== sadaLive.hash) {
            sadaLive.hash = j.hash;
            if (liveReloadAllowed()) location.reload();
            else if (!liveWarned) { liveWarned = true; toast('Sayfa içeriği değişti — güncel hâli için yenileyin.', 'info', 8000); }
        }
    });

    window.sadaBindCard = bind_card; // for dynamic cards

    /* ============================================================
       CUSTOM PICKERS — themed components instead of browser defaults
       ============================================================ */
    const MONTHS_TR = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    const DAYS_SHORT = ['Pt', 'Sa', 'Ça', 'Pe', 'Cu', 'Ct', 'Pz'];
    let openPanel = null;
    function panelClose() { if (openPanel) { openPanel.remove(); openPanel = null; } }
    document.addEventListener('mousedown', e => {
        if (openPanel && !openPanel.contains(e.target) && !e.target.closest('.ui-select-trigger')) panelClose();
    });
    function panelOpen(trigger, panel) {
        panelClose();
        panel.className = 'ui-select-panel ' + (panel.className || '');
        document.body.appendChild(panel);
        const k = trigger.getBoundingClientRect();
        const spaceBelow = window.innerHeight - k.bottom;
        panel.style.left = Math.min(k.left, window.innerWidth - panel.offsetWidth - 10) + 'px';
        panel.style.top = (spaceBelow > panel.offsetHeight + 12 ? k.bottom + 6 : k.top - panel.offsetHeight - 6) + window.scrollY + 'px';
        openPanel = panel;
    }

    /* ---------- Custom SELECT ---------- */
    window.customSelectSetup = function (scope) {
        (scope || document).querySelectorAll('select.select:not([data-ui-select]):not(.native-select)').forEach(sel => {
            sel.dataset.uiSelect = '1';
            const originalStyle = sel.getAttribute('style') || '';
            sel.style.display = 'none';
            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'select ui-select-trigger';
            if (originalStyle) trigger.style.cssText += originalStyle;
            const write = () => { trigger.textContent = sel.selectedOptions[0]?.textContent.trim() || 'Seçin...'; };
            write();
            sel.insertAdjacentElement('afterend', trigger);
            sel.addEventListener('change', write);
            trigger.addEventListener('click', () => {
                const panel = document.createElement('div');
                const ops = Array.from(sel.options);
                if (ops.length > 8) {
                    const search = document.createElement('input');
                    search.className = 'input'; search.placeholder = 'Ara...';
                    search.style.margin = '4px'; search.style.width = 'calc(100% - 8px)';
                    search.addEventListener('input', () => {
                        const q = search.value.toLocaleLowerCase('tr');
                        panel.querySelectorAll('.ui-select-item').forEach(o => o.style.display = o.textContent.toLocaleLowerCase('tr').includes(q) ? '' : 'none');
                    });
                    panel.appendChild(search);
                }
                const list = document.createElement('div');
                list.className = 'ui-select-list';
                ops.forEach(op => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'ui-select-item' + (op.selected ? ' selected' : '');
                    b.textContent = op.textContent.trim() || '—';
                    b.addEventListener('click', () => {
                        sel.value = op.value;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                        write(); panelClose();
                    });
                    list.appendChild(b);
                });
                panel.appendChild(list);
                panelOpen(trigger, panel);
                if (ops.length > 8) panel.querySelector('input')?.focus();
            });
        });
    };

    /* ---------- Custom DATE / DATETIME / TIME ---------- */
    function dateWrite(v) { // "YYYY-MM-DD" → "8 Temmuz 2026"
        if (!v) return '';
        const [y, m, g] = v.split('-').map(Number);
        return g + ' ' + MONTHS_TR[m - 1] + ' ' + y;
    }
    function calendarPanel(selected, minStr, onSelect) {
        const today = new Date();
        let gy = selected ? +selected.slice(0, 4) : today.getFullYear();
        let ga = selected ? +selected.slice(5, 7) - 1 : today.getMonth();
        const panel = document.createElement('div');
        function draw() {
            panel.innerHTML = '';
            const top = document.createElement('div');
            top.className = 'ui-date-top';
            top.innerHTML = `<button type="button" class="order-arrow" data-y="-1">‹</button><b>${MONTHS_TR[ga]} ${gy}</b><button type="button" class="order-arrow" data-y="1">›</button>`;
            top.querySelectorAll('[data-y]').forEach(b => b.addEventListener('click', () => {
                ga += +b.dataset.y; if (ga < 0) { ga = 11; gy--; } if (ga > 11) { ga = 0; gy++; }
                draw();
            }));
            panel.appendChild(top);
            const grid = document.createElement('div');
            grid.className = 'ui-date-grid';
            DAYS_SHORT.forEach(g => { const s = document.createElement('span'); s.className = 'ui-date-day-name'; s.textContent = g; grid.appendChild(s); });
            const firstDay = (new Date(gy, ga, 1).getDay() + 6) % 7; // Mon=0
            const dayCount = new Date(gy, ga + 1, 0).getDate();
            for (let i = 0; i < firstDay; i++) grid.appendChild(document.createElement('span'));
            const todayStr = today.toISOString().slice(0, 10);
            for (let g = 1; g <= dayCount; g++) {
                const v = `${gy}-${String(ga + 1).padStart(2, '0')}-${String(g).padStart(2, '0')}`;
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'ui-date-day' + (v === selected ? ' selected' : '') + (v === todayStr ? ' today' : '');
                if (minStr && v < minStr.slice(0, 10)) b.disabled = true;
                b.textContent = g;
                b.addEventListener('click', () => onSelect(v));
                grid.appendChild(b);
            }
            panel.appendChild(grid);
            const bottom = document.createElement('div');
            bottom.className = 'ui-date-bottom';
            const todayBtn = document.createElement('button');
            todayBtn.type = 'button'; todayBtn.className = 'mini-btn'; todayBtn.textContent = 'Bugün';
            todayBtn.addEventListener('click', () => onSelect(todayStr));
            const clear = document.createElement('button');
            clear.type = 'button'; clear.className = 'mini-btn'; clear.style.color = 'var(--danger)'; clear.textContent = 'Temizle';
            clear.addEventListener('click', () => onSelect(''));
            bottom.append(todayBtn, clear);
            panel.appendChild(bottom);
        }
        draw();
        return panel;
    }
    function timeList(selectedTime, onSelect) {
        const box = document.createElement('div');
        box.className = 'ui-time-list';
        // Free-form time entry: any minute value can be typed
        const freeInput = document.createElement('input');
        freeInput.className = 'input ui-time-free';
        freeInput.placeholder = 'SS:DD yaz';
        freeInput.value = selectedTime || '';
        freeInput.maxLength = 5;
        const apply = () => {
            let v = freeInput.value.trim().replace('.', ':').replace(',', ':');
            if (/^\d{1,2}:?\d{2}$/.test(v)) {
                if (!v.includes(':')) v = v.slice(0, -2) + ':' + v.slice(-2);
                const [s, d] = v.split(':').map(Number);
                if (s < 24 && d < 60) { onSelect(String(s).padStart(2, '0') + ':' + String(d).padStart(2, '0')); return; }
            }
            freeInput.style.borderColor = 'var(--danger)';
        };
        freeInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
        freeInput.addEventListener('input', () => freeInput.style.borderColor = '');
        const applyBtn = document.createElement('button');
        applyBtn.type = 'button'; applyBtn.className = 'btn btn-sm btn-brand'; applyBtn.textContent = '✓';
        applyBtn.addEventListener('click', apply);
        const freeInputWrap = document.createElement('div');
        freeInputWrap.className = 'ui-time-free-wrap';
        freeInputWrap.append(freeInput, applyBtn);
        box.appendChild(freeInputWrap);
        for (let s = 0; s < 24; s++) for (const min of [0, 30]) {
            const v = String(s).padStart(2, '0') + ':' + String(min).padStart(2, '0');
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'ui-select-item' + (v === selectedTime ? ' selected' : '');
            b.textContent = v;
            b.addEventListener('click', () => onSelect(v));
            box.appendChild(b);
        }
        return box;
    }
    window.customDateSetup = function (scope) {
        (scope || document).querySelectorAll('input[type=date]:not([data-ui-select]), input[type=datetime-local]:not([data-ui-select]), input[type=time]:not([data-ui-select])').forEach(inp => {
            if (inp.classList.contains('native-select')) return;
            inp.dataset.uiSelect = '1';
            const type = inp.type;
            inp.type = 'text';
            inp.readOnly = true;
            inp.classList.add('ui-select-trigger');
            inp.style.cursor = 'pointer';
            const realInput = document.createElement('input');
            realInput.type = 'hidden'; realInput.name = inp.name; inp.name = '';
            realInput.value = inp.value;
            if (inp.required) { inp.dataset.is_required = '1'; }
            inp.insertAdjacentElement('afterend', realInput);
            const show = () => {
                const v = realInput.value;
                inp.dataset.value = v;
                if (!v) { inp.value = ''; return; }
                if (type === 'time') inp.value = v.slice(0, 5);
                else if (type === 'date') inp.value = dateWrite(v);
                else inp.value = dateWrite(v.slice(0, 10)) + ', ' + v.slice(11, 16);
            };
            show();
            realInput.pickerShow = show; // lets formFieldSet() refresh the visible trigger
            inp.addEventListener('click', () => {
                const min = inp.getAttribute('min') || '';
                if (type === 'time') {
                    const panel = document.createElement('div');
                    panel.appendChild(timeList(realInput.value.slice(0, 5), v => { realInput.value = v; show(); realInput.dispatchEvent(new Event('change', { bubbles: true })); panelClose(); }));
                    panelOpen(inp, panel);
                    panel.querySelector('.selected')?.scrollIntoView({ block: 'center' });
                    return;
                }
                if (type === 'date') {
                    panelOpen(inp, calendarPanel(realInput.value, min, v => { realInput.value = v; show(); realInput.dispatchEvent(new Event('change', { bubbles: true })); panelClose(); }));
                    return;
                }
                // datetime-local: calendar + time side by side
                const panel = document.createElement('div');
                panel.className = 'ui-date-double';
                let tSelect = realInput.value ? realInput.value.slice(0, 10) : '';
                let sSelect = realInput.value ? realInput.value.slice(11, 16) : '10:00';
                const finish = () => {
                    if (!tSelect) { realInput.value = ''; }
                    else realInput.value = tSelect + 'T' + (sSelect || '10:00');
                    show(); realInput.dispatchEvent(new Event('change', { bubbles: true })); panelClose();
                };
                const calendarEl = calendarPanel(tSelect, min, v => { if (!v) { tSelect = ''; finish(); return; } tSelect = v; calendarEl.querySelectorAll('.ui-date-day').forEach(g => g.classList.remove('selected')); finish(); });
                const time = timeList(sSelect, v => { sSelect = v; if (tSelect) finish(); else { time.querySelectorAll('.selected').forEach(x => x.classList.remove('selected')); } });
                panel.append(calendarEl, time);
                panelOpen(inp, panel);
                time.querySelector('.selected')?.scrollIntoView({ block: 'center' });
            });
        });
    };
    try { customSelectSetup(); customDateSetup(); } catch (e) { console.error('Seçici hatası:', e); }
    window.customPickerRefresh = () => { customSelectSetup(); customDateSetup(); };
    /** Sets a form field by name, including fields behind the custom date/time picker */
    window.formFieldSet = (form, name, value) => {
        const el = form && form.querySelector(`[name="${name}"]`);
        if (!el) return;
        el.value = value;
        if (el.pickerShow) el.pickerShow();
        el.dispatchEvent(new Event('change', { bubbles: true }));
    };

})();

/* ---------- PWA: register the service worker ---------- */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
}
