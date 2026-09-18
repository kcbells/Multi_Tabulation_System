/* Event console: activities, teams, access codes and overall standings. */
(async function () {
  'use strict';
  const me = await App.boot({ roles: ['admin', 'program_head', 'facilitator'], nav: 'events' });
  const { esc, num } = App;
  const view = App.$('#view');
  const eventId = Number(App.param('id') || me.user.event_id || 0);
  const role = me.user.role;

  let data = null;
  let owners = [];
  let stopPolling = null;
  let currentTab = null;

  async function load() {
    data = await App.get('events.get', { id: eventId });
    startLive();
    if (role === 'admin' && !owners.length) {
      try { owners = (await App.get('events.list')).owners; } catch (_) { /* optional */ }
    }
    render();
  }

  function render() {
    const { event, activities, teams, codes, can_configure: canOwn } = data;
    const archived = !!event.archived_at;
    const canConfigure = canOwn && !archived; // archived events are read-only
    view.classList.toggle('is-archived', archived);
    document.title = event.title + ' · PHINMA COC Tabulation';
    const judges = codes.filter((c) => c.role === 'judge');
    const facilitators = codes.filter((c) => c.role === 'facilitator');
    const single = event.structure === 'single';
    const competition = single ? activities[0] : null;

    view.innerHTML = `
      ${me.user.kind === 'staff' ? `<div class="crumbs"><a href="${App.page('dashboard.html')}">Events</a> / ${esc(event.title)}</div>` : ''}
      ${archived ? `
      <div class="archive-banner" role="status">
        <span class="ab-icon">${App.icon('archive')}</span>
        <div class="ab-text">
          <strong>Archived event</strong>
          <span>Archived ${esc(App.fmtDateTime(event.archived_at))}${event.archived_by_name ? ' by ' + esc(event.archived_by_name) : ''}. It is hidden from the events list, access codes don't work and changes are locked. Results can still be viewed and printed.</span>
        </div>
        ${canOwn ? `<div class="row">
          <button class="btn btn-primary" data-restore-event>${App.icon('restore')} Restore</button>
          <button class="btn btn-danger" data-delete-event>${App.icon('trash')} Delete</button>
        </div>` : ''}
      </div>` : ''}
      <div class="page-head">
        <div>
          <div class="eyebrow">${role === 'facilitator' ? 'Facilitator console' : 'Event'}</div>
          <h1>${esc(event.title)}</h1>
          <div class="meta">
            ${App.badge(event.status)}
            ${event.start_at || event.start_date ? `<span>${App.icon('calendar')} ${esc(event.start_at ? App.dateTimeRange(event.start_at, event.end_at) : App.dateRange(event.start_date, event.end_date))}</span>` : ''}
            ${event.venue ? `<span>${esc(event.venue)}</span>` : ''}
            ${event.owner_name ? `<span>${App.icon('user')} Project Head: ${esc(event.owner_name)}</span>` : ''}
            ${event.has_document ? `<a href="${App.apiUrl('events.document', { id: event.id })}" target="_blank" rel="noopener">${App.icon('file')} ${esc(event.document_name || 'Event document')}</a>` : ''}
            ${Forms.FORMATS[event.default_format] ? `<span title="${single ? 'Single competition' : 'Main format of this event’s activities'}">${App.icon(Forms.FORMATS[event.default_format].icon)} ${single ? 'Single competition · ' : ''}${Forms.FORMATS[event.default_format].label}</span>` : ''}
          </div>
        </div>
        <div class="row">
          <label class="status-select"><span class="sr-only">Event status</span>
            <select data-event-status aria-label="Event status" ${archived ? 'disabled' : ''}>
              ${Forms.STATUSES.map((s) => `<option value="${s}" ${event.status === s ? 'selected' : ''}>${s[0].toUpperCase() + s.slice(1)}</option>`).join('')}
            </select></label>
          <a class="btn" href="${App.page('print.html?type=overall&id=' + event.id)}" target="_blank">${App.icon('print')} Print standings</a>
          ${competition ? `<a class="btn btn-primary" href="${App.page('activity.html?id=' + competition.id + (competition.format === 'score' ? '' : '#board'))}">${App.icon(Forms.FORMATS[competition.format].icon)} Open competition</a>` : ''}
          ${canConfigure && !single ? `<button class="btn" data-scan-doc>${App.icon('scan')} Scan document</button>` : ''}
          ${canConfigure ? `<button class="btn" data-edit-event>${App.icon('edit')} Edit event</button>` : ''}
          ${canConfigure ? `<button class="btn" data-archive-event title="Archive event">${App.icon('archive')} Archive</button>
          <button class="btn btn-icon btn-danger" data-delete-event title="Delete event" aria-label="Delete event">${App.icon('trash')}</button>` : ''}
        </div>
      </div>
      ${event.description ? `<p class="muted" style="margin-top:-8px">${esc(event.description)}</p>` : ''}

      <div class="tabs" role="tablist">
        <button data-tab="overview">Overview</button>
        <button data-tab="activities">${single ? 'Competition' : `Activities<span class="count">${activities.length}</span>`}</button>
        <button data-tab="teams">Teams<span class="count">${teams.length}</span></button>
        <button data-tab="codes" ${canConfigure ? '' : 'hidden'}>Access codes<span class="count">${codes.length}</span></button>
        <button data-tab="standings">${single ? 'Team standings' : 'Overall standings'}</button>
        ${seeLogs() ? '<button data-tab="logs">Activity logs</button>' : ''}
      </div>

      <section data-panel="overview">${renderOverview(canConfigure)}</section>
      <section data-panel="activities" hidden>${renderActivities(activities, canConfigure)}</section>
      <section data-panel="teams" hidden>${renderTeams(teams, canConfigure)}</section>
      <section data-panel="codes" hidden>${canConfigure ? renderCodes(judges, facilitators, activities) : ''}</section>
      <section data-panel="standings" hidden><div id="standings">${App.loading('Computing standings…')}</div></section>
      ${seeLogs() ? '<section data-panel="logs" hidden><div id="logs-root"></div></section>' : ''}
    `;

    if (seeLogs()) Logs.mount(App.$('#recent-logs', view), { eventId, compact: true, limit: 6 });

    bind(canConfigure, canOwn);
    let logsMounted = false;
    App.tabs(view, (tab) => {
      currentTab = tab;
      if (stopPolling) { stopPolling(); stopPolling = null; }
      if (tab === 'standings') {
        loadStandings();
        stopPolling = App.poll(loadStandings, 15000);
      }
      if (tab === 'logs' && seeLogs() && !logsMounted) {
        logsMounted = true;
        Logs.mount(App.$('#logs-root', view), { eventId });
      }
    });
  }

  let liveStarted = false;
  function startLive() {
    if (liveStarted) return;
    liveStarted = true;
    App.live({ event_id: eventId }, () => reloadTo(currentTab || 'overview'));
  }

  async function reloadTo(tab) {
    await load();
    history.replaceState(null, '', '#' + tab);
    App.$(`.tabs [data-tab="${tab}"]`, view)?.click();
  }

  /* ---------------------------------------------------------------- overview & setup checklist */

  function renderOverview(canConfigure) {
    const { activities, teams, codes } = data;
    const judges = codes.filter((c) => c.role === 'judge');
    const scored = activities.filter((a) => (a.format || 'score') === 'score');
    const withCriteria = scored.filter((a) => Number(a.criteria) > 0).length;
    const withJudges = scored.filter((a) => Number(a.judges) > 0).length;
    const matchBased = activities.filter((a) => ['bracket', 'round_robin'].includes(a.format));
    const withMatches = matchBased.filter((a) => Number(a.matches) > 0).length;

    const single = data.event.structure === 'single';
    const open = activities[0] ? `<a class="btn btn-sm" href="${App.page('activity.html?id=' + activities[0].id + ((activities[0].format || 'score') === 'score' ? '' : '#board'))}">${App.icon('trophy')} Open competition</a>` : '';
    const steps = [
      single
        ? { done: activities.length > 0, title: 'Competition', text: activities[0] ? `One competition, decided by ${Forms.FORMATS[activities[0].format || 'score'].label.toLowerCase()}.` : 'Save the event again to create its competition.', action: open }
        : { done: activities.length > 0, title: 'Add the activities', text: activities.length ? `${activities.length} activit${activities.length === 1 ? 'y' : 'ies'} in this event.` : 'Add each activity and choose how it is decided.', action: `<button class="btn btn-sm" data-add-activity>${App.icon('plus')} Add activity</button>` },
      { done: teams.length > 0, title: single ? 'Add the teams (optional)' : 'Add the teams', text: teams.length ? `${teams.length} team${teams.length === 1 ? '' : 's'} competing.` : (single ? 'Only needed if contestants represent colleges or departments.' : 'Colleges or departments that earn overall points.'), action: `<button class="btn btn-sm" data-add-team>${App.icon('plus')} Add team</button>` },
      { done: activities.length > 0 && withCriteria === scored.length && withMatches === matchBased.length, title: single ? 'Criteria, contestants & brackets' : 'Criteria & brackets', text: activities.length ? [scored.length ? `${withCriteria} of ${scored.length} score-based ${single ? 'competition has' : 'activities have'} criteria` : '', matchBased.length ? `${withMatches} of ${matchBased.length} brackets / fixtures created` : ''].filter(Boolean).join(' · ') || 'Ranking only needs contestants.' : 'Add activities first.', action: single ? open : `<button class="btn btn-sm" data-goto="activities">${App.icon('list')} Open activities</button>` },
      { done: scored.length === 0 ? true : judges.length > 0 && withJudges === scored.length, title: 'Give access codes', text: scored.length ? (judges.length ? `${judges.length} judge code${judges.length === 1 ? '' : 's'} · ${withJudges} of ${scored.length} score-based activities have judges.` : 'Generate codes for judges and facilitators — no accounts needed.') : 'No judges needed — brackets and rankings are recorded by facilitators.', action: `<button class="btn btn-sm btn-primary" data-bulk-codes>${App.icon('key')} Give access codes</button>` },
    ];
    const done = steps.filter((s) => s.done).length;

    return `
      <div class="overview-grid ${seeLogs() ? '' : 'one-col'}">
        <div class="stack">
          ${canConfigure ? `
          <div class="card">
            <div class="card-head">
              <div><h3>Event setup</h3><div class="muted small">${done === steps.length ? 'Everything is ready — open scoring on each activity when it starts.' : `${done} of ${steps.length} steps complete`}</div></div>
              <div class="progress" style="width:160px"><span style="width:${Math.round((done / steps.length) * 100)}%"></span></div>
            </div>
            <ol class="setup-steps">
              ${steps.map((s, i) => `
                <li class="${s.done ? 'done' : ''}">
                  <span class="step-no">${s.done ? App.icon('check') : i + 1}</span>
                  <div class="step-body"><strong>${s.title}</strong><div class="muted small">${s.text}</div></div>
                  <div class="step-action">${s.action}</div>
                </li>`).join('')}
            </ol>
          </div>` : ''}
        </div>
        ${seeLogs() ? `<div class="card">
          <div class="card-head"><h3>${App.icon('history')} Recent activity</h3><button class="btn btn-sm btn-ghost" data-goto="logs">View all</button></div>
          <div class="card-body" id="recent-logs"></div>
        </div>` : ''}
      </div>`;
  }

  /** Program heads do not see the activity logs (recent activity). */
  function seeLogs() {
    return App.user?.role !== 'program_head';
  }

  /* ---------------------------------------------------------------- activities */

  function renderActivities(activities, canConfigure) {
    const single = data.event.structure === 'single';
    const add = canConfigure && !single ? `<button class="btn btn-primary" data-add-activity>${App.icon('plus')} Add activity</button>` : '';
    if (!activities.length) {
      return `<div class="card">${App.empty('No activities yet', 'Add the activities of this event — score-based, bracket, round robin or ranking.', 'list')}
        <div class="row" style="justify-content:center;padding-bottom:28px">${add ? `<button class="btn" data-scan-doc>${App.icon('scan')} Scan document</button>` : ''}${add}</div></div>`;
    }
    return `
      <div class="row-between" style="margin-bottom:12px">
        <p class="muted small" style="margin:0">${single ? 'This event is one competition. Open it to manage its criteria, contestants, judges and results. To add more activities, edit the event and choose “Multiple activities”.' : 'Open an activity to manage its criteria, contestants, judges and results.'}</p>
        <div class="row">${add ? `<button class="btn" data-scan-doc>${App.icon('scan')} Scan document</button>` : ''}${add}</div>
      </div>
      <div class="activity-list">
        ${activities.map((a, i) => {
          const total = Number(a.criteria_total);
          const format = a.format || 'score';
          const fmt = Forms.FORMATS[format];
          const ready = format === 'score' ? Number(a.criteria) && Number(a.contestants) && Number(a.judges)
            : ['bracket', 'round_robin'].includes(format) ? Number(a.matches) > 0 : Number(a.contestants) > 0;
          const missing = format === 'score'
            ? [!Number(a.criteria) && 'criteria', !Number(a.contestants) && 'contestants', !Number(a.judges) && 'judges']
            : ['bracket', 'round_robin'].includes(format) ? [Number(a.contestants) < 2 && 'contestants', !Number(a.matches) && (format === 'bracket' ? 'bracket' : 'fixtures')] : [!Number(a.contestants) && 'contestants'];
          const facts = format === 'score'
            ? `<span><b>${a.criteria}</b> criteria${Number(a.criteria) ? ` (${num(total)} pts)` : ''}</span><span><b>${a.contestants}</b> contestants</span><span><b>${a.submitted}/${a.judges}</b> judges submitted</span>`
            : ['bracket', 'round_robin'].includes(format)
              ? `<span><b>${a.contestants}</b> contestants</span><span><b>${a.matches_done}/${a.matches}</b> ${format === 'bracket' ? 'matches' : 'games'} done</span>`
              : `<span><b>${a.contestants}</b> contestants</span><span><b>${a.results}</b> results entered</span>`;
          const openLabel = format === 'score' ? (a.status === 'closed' ? 'Reopen scoring' : 'Open scoring') : (a.status === 'closed' ? 'Reopen' : 'Start');
          const closeLabel = format === 'score' ? 'Close scoring' : 'Finalize';
          return `
          <article class="card activity-item">
            <div class="row" style="align-items:flex-start;flex-wrap:nowrap">
              <span class="order">${i + 1}</span>
              <div class="grow" style="min-width:0">
                <div class="row" style="gap:8px">${App.badge(a.status, format === 'score' ? null : App.MATCH_STATUS[a.status])}<span class="badge badge-format no-dot">${App.icon(fmt.icon)} ${esc(fmt.label)}</span>${a.nature ? `<span class="badge badge-format no-dot">${esc(a.nature)}</span>` : ''}${Number(a.counts_to_overall) ? '' : '<span class="badge no-dot">Not in overall</span>'}</div>
                <h3 style="margin-top:6px"><a href="${App.page('activity.html?id=' + a.id)}">${esc(a.title)}</a></h3>
                <div class="facts">
                  ${a.schedule_at ? `<span>${esc(App.fmtDateTime(a.schedule_at))}</span>` : ''}
                  ${a.venue ? `<span>${esc(a.venue)}</span>` : ''}
                  ${facts}
                </div>
                ${!ready && a.status === 'pending' ? `<div class="small" style="margin-top:6px">Setup needed: ${missing.filter(Boolean).join(', ')}</div>` : ''}
              </div>
            </div>
            <div class="row" style="justify-content:flex-end">
              ${a.status !== 'open' ? `<button class="btn btn-sm" data-status="open" data-id="${a.id}" ${ready ? '' : 'disabled'}>${openLabel}</button>` : `<button class="btn btn-sm btn-dark" data-status="closed" data-id="${a.id}">${App.icon('lock')} ${closeLabel}</button>`}
              <a class="btn btn-sm btn-primary" href="${App.page('activity.html?id=' + a.id + (format === 'score' ? '' : '#board'))}">${format === 'bracket' ? 'Open bracket' : format === 'round_robin' ? 'Open standings' : format === 'ranking' ? 'Open leaderboard' : 'Manage'}</a>
            </div>
          </article>`;
        }).join('')}
      </div>`;
  }

  /* ---------------------------------------------------------------- teams */

  function renderTeams(teams, canConfigure) {
    const add = canConfigure ? `<button class="btn btn-primary" data-add-team>${App.icon('plus')} Add team</button>` : '';
    return `
      <div class="card">
        <div class="card-head"><div><h3>Teams</h3><div class="muted small">Colleges, departments or groups competing for the overall title.</div></div>${add}</div>
        ${teams.length ? `<div class="table-wrap"><table class="table stackable">
          <thead><tr><th>Team</th><th>Colour</th><th class="num">Entries</th>${canConfigure ? '<th class="actions"></th>' : ''}</tr></thead>
          <tbody>${teams.map((t) => `<tr>
            <td class="primary" data-label="Team">${App.entry(t, '', 'md')}</td>
            <td data-label="Colour">${t.color ? `<span class="color-chip"><span style="background:${esc(t.color)}"></span>${esc(t.color)}</span>` : '<span class="muted">—</span>'}</td>
            <td class="num" data-label="Entries">${t.contestants}</td>
            ${canConfigure ? `<td class="actions">
              <button class="btn btn-sm" data-edit-team="${t.id}">${App.icon('edit')} Edit</button>
              <button class="btn btn-sm btn-danger" data-delete-team="${t.id}">${App.icon('trash')}</button></td>` : ''}
          </tr>`).join('')}</tbody></table></div>` : App.empty('No teams yet', 'Add teams to track overall standings.', 'users')}
      </div>`;
  }

  /* ---------------------------------------------------------------- access codes */

  function renderCodes(judges, facilitators, activities) {
    const titleOf = Object.fromEntries(activities.map((a) => [a.id, a.title]));
    const codeCard = (c) => `
      <div class="card code-card ${Number(c.is_active) ? '' : 'inactive'}">
        <div class="row-between">
          <strong>${esc(c.name)}</strong>
          ${Number(c.is_active) ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-pending">Disabled</span>'}
        </div>
        <div class="row-between">
          <span class="code-value">${esc(c.display_code)}</span>
          <button class="btn btn-sm btn-ghost" data-copy="${esc(c.display_code)}" title="Copy code">${App.icon('copy')}</button>
        </div>
        ${c.role === 'judge' ? `<div>${c.activity_ids.length ? c.activity_ids.map((id) => `<span class="chip">${esc(titleOf[id] || 'Activity')}</span>`).join('') : '<span class="muted small">No activities assigned</span>'}</div>` : ''}
        <div class="muted small">${c.last_used_at ? 'Last signed in ' + esc(App.fmtDateTime(c.last_used_at)) : 'Not used yet'}</div>
        <div class="row" style="justify-content:flex-end">
          <button class="btn btn-sm" data-edit-code="${c.id}">${App.icon('edit')} Edit</button>
          <button class="btn btn-sm" data-regen="${c.id}" title="Generate a new code">${App.icon('refresh')} New code</button>
          <button class="btn btn-sm" data-toggle="${c.id}">${Number(c.is_active) ? 'Disable' : 'Enable'}</button>
          <button class="btn btn-sm btn-danger" data-delete-code="${c.id}" title="Delete">${App.icon('trash')}</button>
        </div>
      </div>`;

    return `
      <div class="alert alert-info" style="margin-bottom:16px">
        Judges and facilitators do not need accounts. Give each person their code — they sign in on the <a href="${App.url('index.html')}" target="_blank" rel="noopener">homepage</a>.
        <a href="${App.page('print.html?type=codes&id=' + eventId)}" target="_blank">Print code slips</a>
      </div>
      <div class="row-between" style="margin-bottom:10px"><h2>Judges <span class="muted small">(${judges.length})</span></h2>
        <div class="row"><button class="btn" data-bulk-codes>${App.icon('key')} Generate several</button>
        <button class="btn btn-primary" data-add-code="judge">${App.icon('plus')} Add judge</button></div></div>
      ${judges.length ? `<div class="grid-cards">${judges.map(codeCard).join('')}</div>` : `<div class="card">${App.empty('No judges yet', 'Add a judge to generate their access code.', 'key')}</div>`}

      <div class="row-between" style="margin:26px 0 10px"><h2>Facilitators <span class="muted small">(${facilitators.length})</span></h2>
        <button class="btn btn-dark" data-add-code="facilitator">${App.icon('plus')} Add facilitator</button></div>
      ${facilitators.length ? `<div class="grid-cards">${facilitators.map(codeCard).join('')}</div>` : `<div class="card">${App.empty('No facilitators yet', 'Facilitators run the event on the ground: contestants, scoring status and live results.', 'key')}</div>`}
    `;
  }

  /* ---------------------------------------------------------------- standings */

  let finalOnly = false;
  async function loadStandings() {
    const box = App.$('#standings', view);
    if (!box) return;
    const { result } = await App.get('results.overall', { id: eventId, final_only: finalOnly ? 1 : 0 });
    box.innerHTML = `
      <div class="row-between" style="margin-bottom:14px">
        <label class="check"><input type="checkbox" id="final-only" ${finalOnly ? 'checked' : ''}><span>Only count closed (final) activities</span></label>
        <span class="live-indicator">Live · updated ${new Date().toLocaleTimeString()}</span>
      </div>
      ${Results.overall(result)}`;
    App.$('#final-only', box).addEventListener('change', (e) => {
      finalOnly = e.target.checked;
      loadStandings();
    });
  }

  /* ---------------------------------------------------------------- events */

  function bind(canConfigure, canOwn) {
    const reload = async () => {
      const tab = currentTab;
      await load();
      if (tab) App.$(`.tabs [data-tab="${tab}"]`, view)?.click();
    };
    const on = (sel, fn) => App.$$(sel, view).forEach((el) => el.addEventListener('click', (e) => fn(el, e)));

    on('[data-status]', async (btn) => {
      const status = btn.dataset.status;
      if (status === 'closed' && !(await App.confirm({ title: 'Close scoring?', message: 'Judges will no longer be able to change scores. Results become final.', confirmText: 'Close scoring', danger: true }))) return;
      App.setLoading(btn, true);
      try {
        const r = await App.post('activities.status', { id: btn.dataset.id, status });
        App.toast(r.message, 'success');
        await reload();
      } catch (err) {
        App.fail(err);
        App.setLoading(btn, false);
      }
    });

    view.querySelector('[data-event-status]')?.addEventListener('change', async (e) => {
      try {
        const r = await App.post('events.status', { id: eventId, status: e.target.value });
        App.toast(r.message, 'success');
        reload();
      } catch (err) { App.fail(err); e.target.value = data.event.status; }
    });
    on('[data-goto]', (b) => App.$(`.tabs [data-tab="${b.dataset.goto}"]`, view)?.click());

    if (canOwn) {
      on('[data-archive-event]', async () => {
        if (await Forms.archiveEvent(data.event)) reload();
      });
      on('[data-restore-event]', async (b) => {
        App.setLoading(b, true);
        try {
          await Forms.restoreEvent(data.event);
          reload();
        } catch (err) {
          App.fail(err);
          App.setLoading(b, false);
        }
      });
      on('[data-delete-event]', async () => {
        const title = data.event.title;
        if (await Forms.deleteEvent(data.event)) {
          location.replace(App.page('dashboard.html?deleted=' + encodeURIComponent(title)));
        }
      });
    }

    if (!canConfigure) return;

    on('[data-bulk-codes]', async () => {
      const r = await Forms.bulkCodes(eventId, data.activities);
      if (r) reloadTo('codes');
    });

    on('[data-edit-event]', async () => {
      const r = await Forms.event(data.event, owners, { activities: data.activities });
      if (r && r.activity_id && (r.criteriaScanned || !data.activities.length)) location.href = App.page('activity.html?id=' + r.activity_id + (r.criteriaScanned ? '#criteria' : ''));
      else if (r) reload();
    });
    on('[data-scan-doc]', async () => {
      const r = await EventScan.open({ event: data.event, activities: data.activities, owners, codes: data.codes });
      if (r) reloadTo('activities');
    });
    on('[data-add-activity]', async () => {
      const r = await Forms.activity(eventId, null, { defaultFormat: data.event.default_format });
      if (r && r.id) location.href = App.page('activity.html?id=' + r.id + (r.criteriaScanned ? '#criteria' : ''));
    });
    on('[data-add-team]', async () => (await Forms.team(eventId)) && reload());
    on('[data-edit-team]', async (b) => (await Forms.team(eventId, data.teams.find((t) => t.id == b.dataset.editTeam))) && reload());
    on('[data-delete-team]', async (b) => {
      const t = data.teams.find((x) => x.id == b.dataset.deleteTeam);
      if (!(await App.confirm({ title: 'Remove team?', message: `Remove <strong>${esc(t.name)}</strong>? Its contestants stay but will no longer earn team points.`, confirmText: 'Remove', danger: true }))) return;
      try { App.toast((await App.post('teams.delete', { id: t.id })).message, 'success'); reload(); } catch (err) { App.fail(err); }
    });

    on('[data-add-code]', async (b) => {
      const r = await Forms.accessCode(eventId, b.dataset.addCode, data.activities);
      if (r) {
        await reload();
        App.modal({
          title: 'Access code created',
          body: `<p>Share this code with the ${b.dataset.addCode}:</p><div class="code-value text-center" style="font-size:2rem;padding:12px 0">${esc(r.code)}</div>`,
          submitText: null, cancelText: 'Done',
        });
      }
    });
    on('[data-edit-code]', async (b) => {
      const c = data.codes.find((x) => x.id == b.dataset.editCode);
      (await Forms.accessCode(eventId, c.role, data.activities, c)) && reload();
    });
    on('[data-copy]', (b) => App.copy(b.dataset.copy));
    on('[data-regen]', async (b) => {
      const c = data.codes.find((x) => x.id == b.dataset.regen);
      if (!(await App.confirm({ title: 'Generate a new code?', message: `The current code for <strong>${esc(c.name)}</strong> will stop working immediately.`, confirmText: 'Generate new code' }))) return;
      try { const r = await App.post('codes.regenerate', { id: c.id }); App.toast(r.message + ' New code: ' + r.code, 'success', 7000); reload(); } catch (err) { App.fail(err); }
    });
    on('[data-toggle]', async (b) => {
      try { App.toast((await App.post('codes.toggle', { id: b.dataset.toggle })).message, 'success'); reload(); } catch (err) { App.fail(err); }
    });
    on('[data-delete-code]', async (b) => {
      const c = data.codes.find((x) => x.id == b.dataset.deleteCode);
      if (!(await App.confirm({ title: 'Delete access code?', message: `Delete <strong>${esc(c.name)}</strong>? ${c.role === 'judge' ? 'All scores entered by this judge will be permanently deleted.' : ''}`, confirmText: 'Delete', danger: true }))) return;
      try { App.toast((await App.post('codes.delete', { id: c.id })).message, 'success'); reload(); } catch (err) { App.fail(err); }
    });
  }

  try {
    if (!eventId) throw new Error('No event selected.');
    await load();

    // Just created: go straight to adding the first activity (with its format choices).
    if (App.param('new') === '1' && data.can_configure && data.event.structure !== 'single') {
      history.replaceState(null, '', location.pathname + '?id=' + eventId + '#activities');
      App.$('.tabs [data-tab="activities"]', view)?.click();
      const r = await Forms.activity(eventId, null, { firstActivity: true, defaultFormat: data.event.default_format });
      if (r && r.id) location.href = App.page('activity.html?id=' + r.id + (r.criteriaScanned ? '#criteria' : ''));
    }
  } catch (err) {
    view.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
  }
})();
