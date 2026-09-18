/* Activity logs (audit trail) viewer — per event or system-wide. */
(function () {
  'use strict';
  const { esc } = App;
  const Logs = (window.Logs = {});

  const ICON = {
    auth: 'key', event: 'calendar', program: 'flow', activity: 'list', team: 'users', contestant: 'user',
    criteria: 'file', code: 'shield', score: 'check', result: 'download', user: 'users',
  };

  const initials = (name) => String(name || '?').trim().split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();

  const when = (s) => {
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return s;
    const diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return App.fmtDateTime(s);
  };

  const row = (l, showEvent) => {
    const cat = l.action.split('.')[0];
    const who = l.actor_name || (l.actor_kind === 'guest' ? 'Unknown visitor' : 'System');
    const alert = /\.(failed|deleted|disabled|reset)$/.test(l.action);
    return `
      <li class="log-item ${alert ? 'alert' : ''}">
        <span class="log-ico">${App.icon(ICON[cat] || 'clock')}</span>
        <div class="log-body">
          <div class="log-text">${esc(l.description)}</div>
          <div class="log-meta">
            <span class="log-who"><span class="log-avatar">${esc(initials(who))}</span>${esc(who)}</span>
            ${l.actor_role ? `<span class="badge no-dot">${esc(App.roleLabel(l.actor_role))}</span>` : ''}
            ${showEvent && l.event_title ? `<span>${App.icon('calendar')} ${esc(l.event_title)}</span>` : ''}
            <span title="${esc(l.created_at)}">${App.icon('clock')} ${esc(when(l.created_at))}</span>
            ${l.ip ? `<span class="log-ip">${esc(l.ip)}</span>` : ''}
          </div>
        </div>
      </li>`;
  };

  /**
   * opts: { eventId (optional), compact: bool (latest few, no filters), limit, onMore() }
   */
  Logs.mount = (root, opts = {}) => {
    const state = { category: '', q: '', before: null, logs: [], hasMore: false, categories: {} };
    const showEvent = !opts.eventId;

    const params = () => {
      const p = { limit: opts.limit || (opts.compact ? 8 : 40) };
      if (opts.eventId) p.event_id = opts.eventId;
      if (state.category) p.category = state.category;
      if (state.q) p.q = state.q;
      if (state.before) p.before = state.before;
      return p;
    };

    async function fetchPage(reset) {
      if (reset) { state.before = null; state.logs = []; }
      const r = await App.get('logs.list', params());
      state.categories = r.categories;
      state.logs = state.logs.concat(r.logs);
      state.hasMore = r.has_more;
      state.before = r.logs.length ? r.logs[r.logs.length - 1].id : state.before;
    }

    function drawList() {
      const list = root.querySelector('[data-list]');
      list.innerHTML = state.logs.length
        ? `<ul class="log-list">${state.logs.map((l) => row(l, showEvent)).join('')}</ul>`
        : App.empty('No activity yet', state.q || state.category ? 'Nothing matches these filters.' : 'Actions like uploads, access codes and scoring will appear here.', 'history');
      const more = root.querySelector('[data-more]');
      if (more) more.hidden = !state.hasMore;
    }

    async function render() {
      if (opts.compact) {
        root.innerHTML = `<div data-list>${App.loading()}</div>`;
      } else {
        root.innerHTML = `
          <div class="card">
            <div class="card-head">
              <div><h3>${App.icon('history')} Activity logs</h3><div class="muted small">Who did what and when${opts.eventId ? ' in this event' : ' across the system'}.</div></div>
              <div class="row log-filters">
                <select data-category aria-label="Filter by type"><option value="">All activity</option></select>
                <input type="search" data-q placeholder="Search logs…" aria-label="Search logs">
                <button type="button" class="btn btn-sm" data-refresh title="Refresh">${App.icon('refresh')}</button>
              </div>
            </div>
            <div class="card-body" data-list>${App.loading()}</div>
            <div class="text-center" style="padding:0 0 18px"><button type="button" class="btn btn-sm" data-more hidden>Load older activity</button></div>
          </div>`;
      }
      try {
        await fetchPage(true);
      } catch (err) {
        root.querySelector('[data-list]').innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
        return;
      }
      if (!opts.compact) {
        const sel = root.querySelector('[data-category]');
        sel.innerHTML = '<option value="">All activity</option>' + Object.entries(state.categories).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
        sel.addEventListener('change', async () => { state.category = sel.value; await reload(); });
        root.querySelector('[data-q]').addEventListener('input', App.debounce(async (e) => { state.q = e.target.value.trim(); await reload(); }, 350));
        root.querySelector('[data-refresh]').addEventListener('click', reload);
        root.querySelector('[data-more]').addEventListener('click', async (e) => {
          App.setLoading(e.currentTarget, true);
          try { await fetchPage(false); drawList(); } catch (err) { App.fail(err); }
          App.setLoading(e.currentTarget, false);
        });
      }
      drawList();
    }

    async function reload() {
      try { await fetchPage(true); drawList(); } catch (err) { App.fail(err); }
    }

    render();
    return { reload };
  };
})();
