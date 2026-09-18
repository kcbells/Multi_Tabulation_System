/* Activity console: criteria, contestants, judge panel & progress, live results. */
(async function () {
  'use strict';
  const me = await App.boot({ roles: ['admin', 'program_head', 'facilitator'], nav: 'events' });
  const { esc } = App;
  const view = App.$('#view');
  const activityId = Number(App.param('id') || 0);

  let data = null;
  let result = null;
  let includeDrafts = true;
  let stopPolling = null;
  let currentTab = null;

  async function load() {
    data = await App.get('activities.get', { id: activityId });
    startLive();
    render();
  }

  function render() {
    const { activity: a, event, criteria, contestants, can_configure: canConfigure } = data;
    document.title = a.title + ' · ' + event.title;
    view.classList.toggle('is-archived', !!event.archived);
    const single = event.structure === 'single';
    const format = a.format || 'score';
    const isScore = format === 'score';
    const fmt = Forms.FORMATS[format];
    const boardTab = { bracket: 'Bracket', round_robin: 'Standings & fixtures', ranking: 'Leaderboard' }[format];
    const statusLabels = isScore ? { pending: 'Pending', open: 'Open', closed: 'Closed' } : { pending: 'Not started', open: 'In progress', closed: 'Final' };

    view.innerHTML = `
      ${event.archived ? `<div class="archive-banner compact" role="status"><span class="ab-icon">${App.icon('archive')}</span><div class="ab-text"><strong>Archived event</strong><span>This activity is read-only. Restore the event from its event page to make changes.</span></div></div>` : ''}
      <div class="crumbs">
        ${me.user.kind === 'staff' ? `<a href="${App.page('dashboard.html')}">Events</a> / ` : ''}
        <a href="${App.page('event.html?id=' + event.id)}">${esc(event.title)}</a> / ${single ? 'Competition' : esc(a.title)}
      </div>
      <div class="page-head">
        <div>
          <div class="eyebrow">${single ? 'Competition' : 'Activity'}</div>
          <h1>${esc(a.title)}</h1>
          <div class="meta">
            ${App.badge(a.status, isScore ? null : App.MATCH_STATUS[a.status])}
            <span class="badge badge-format no-dot">${App.icon(fmt.icon)} ${esc(fmt.label)}</span>
            ${a.nature ? `<span class="badge badge-format no-dot">${esc(a.nature)}</span>` : ''}
            ${a.schedule_at ? `<span>${App.icon('clock')} ${esc(App.fmtDateTime(a.schedule_at))}</span>` : ''}
            ${a.venue ? `<span>${esc(a.venue)}</span>` : ''}
          </div>
        </div>
        <div class="row">
          ${isScore ? '' : `<a class="btn" href="${App.page('print.html?type=board&id=' + activityId)}" target="_blank">${App.icon('print')} Print</a>`}
          <div class="status-switch" role="group" aria-label="Scoring status">
            ${['pending', 'open', 'closed'].map((s) => `<button data-set-status="${s}" data-status="${s}" class="${a.status === s ? 'active' : ''}" aria-pressed="${a.status === s}">${statusLabels[s]}</button>`).join('')}
          </div>
          ${canConfigure ? `
            <button class="btn btn-icon" data-edit title="${single ? 'Competition settings' : 'Edit activity'}">${App.icon('edit')}</button>
            ${single ? `<a class="btn" href="${App.page('event.html?id=' + event.id + '#codes')}">${App.icon('key')} Access codes</a>` : `<button class="btn btn-icon btn-danger" data-delete title="Delete activity">${App.icon('trash')}</button>`}` : ''}
        </div>
      </div>
      ${App.activityInfoCard(a)}

      <div class="tabs" role="tablist">
        ${isScore ? `<button data-tab="criteria">Criteria<span class="count">${criteria.length}</span></button>`
          : `<button data-tab="board">${App.icon(fmt.icon)} ${boardTab}</button>`}
        <button data-tab="contestants">Contestants<span class="count">${contestants.length}</span></button>
        ${isScore ? `<button data-tab="judges">Judges<span class="count">${data.assigned_judges.length}</span></button>
        <button data-tab="results">Live results</button>` : ''}
      </div>

      ${isScore ? `<section data-panel="criteria"><div id="criteria-root"></div></section>` : `<section data-panel="board"><div id="board-root"></div></section>`}
      <section data-panel="contestants" hidden>${renderContestants()}</section>
      ${isScore ? `<section data-panel="judges" hidden><div id="judges-root">${App.loading()}</div></section>
      <section data-panel="results" hidden><div id="results-root">${App.loading('Tabulating…')}</div></section>` : ''}
    `;

    if (isScore) {
      Criteria.mount(App.$('#criteria-root', view), {
        activityId,
        criteria,
        canConfigure,
        hasScores: data.has_scores,
        hasFile: a.has_file,
        fileName: a.criteria_file_name,
        onSaved: reload,
      });
    }

    bind(canConfigure);
    let boardMounted = false;
    App.tabs(view, (tab) => {
      currentTab = tab;
      if (stopPolling) { stopPolling(); stopPolling = null; }
      if (tab === 'board' && !boardMounted) {
        boardMounted = true;
        Competition.mount(App.$('#board-root', view), { activityId, canManage: !data.event.archived }).then((b) => { board = b; });
      }
      if (tab === 'judges') {
        loadJudges();
        stopPolling = App.poll(loadJudges, 10000);
      }
      if (tab === 'results') {
        loadResults();
        stopPolling = App.poll(loadResults, 10000);
      }
    });
  }

  let board = null;
  let liveStarted = false;
  function startLive() {
    if (liveStarted) return;
    liveStarted = true;
    App.live({ event_id: data.activity.event_id }, () => {
      // a bracket shown full screen refreshes itself, so the full screen stays on
      if (document.querySelector('.comp-full') && board) return board.reload();
      return reload();
    });
  }

  async function reload() {
    const tab = currentTab;
    await load();
    if (tab) App.$(`.tabs [data-tab="${tab}"]`, view)?.click();
  }

  /* ---------------------------------------------------------------- contestants */

  function renderContestants() {
    const { contestants, teams } = data;
    return `
      <div class="card">
        <div class="card-head">
          <div><h3>Contestants</h3><div class="muted small">Entries judged in this activity.</div></div>
          <div class="row">
            ${teams.length ? `<button class="btn btn-sm" data-add-teams title="Create one entry per team">${App.icon('users')} Add all teams</button>` : ''}
            <button class="btn btn-sm btn-primary" data-add-contestant>${App.icon('plus')} Add contestant</button>
          </div>
        </div>
        ${contestants.length ? `
          <div class="table-wrap"><table class="table stackable">
            <thead><tr><th class="num">No.</th><th>Name</th><th>Team</th><th>Details</th><th class="actions"></th></tr></thead>
            <tbody>${contestants.map((c) => `<tr>
              <td class="num" data-label="No."><span class="rank-pill">${c.number}</span></td>
              <td class="primary" data-label="Name">${App.entry(c)}</td>
              <td data-label="Team">${c.team_name ? esc(c.team_name) : '<span class="muted">—</span>'}</td>
              <td data-label="Details">${c.details ? esc(c.details) : '<span class="muted">—</span>'}</td>
              <td class="actions">
                <button class="btn btn-sm" data-edit-contestant="${c.id}">${App.icon('edit')} Edit</button>
                <button class="btn btn-sm btn-danger" data-delete-contestant="${c.id}" title="Remove">${App.icon('trash')}</button>
              </td></tr>`).join('')}
            </tbody></table></div>`
          : App.empty('No contestants yet', teams.length ? 'Add contestants one by one, or add every team at once.' : 'Add the contestants or entries for this activity.', 'users')}
      </div>`;
  }

  /* ---------------------------------------------------------------- judges */

  async function loadJudges() {
    const box = App.$('#judges-root', view);
    if (!box) return;
    ({ result } = await App.get('results.activity', { id: activityId, drafts: 1 }));
    const assigned = new Set(data.assigned_judges);
    const canConfigure = data.can_configure;

    box.innerHTML = `
      ${canConfigure ? `
        <div class="card">
          <div class="card-head"><div><h3>Judge panel</h3><div class="muted small">Choose which judges score this activity. Judge codes are created on the event page.</div></div>
            <a class="btn btn-sm" href="${App.page('event.html?id=' + data.event.id + '#codes')}">${App.icon('key')} Manage access codes</a></div>
          <div class="card-body">
            ${data.judges.length ? `
              <form data-panel-form class="stack">
                <div class="grid-cards" style="gap:8px">
                  ${data.judges.map((j) => `<label class="check card" style="padding:12px;box-shadow:none"><input type="checkbox" name="judge_ids[]" value="${j.id}" ${assigned.has(Number(j.id)) ? 'checked' : ''}>
                    <span>${esc(j.name)}${Number(j.is_active) ? '' : ' <span class="muted small">(disabled)</span>'}</span></label>`).join('')}
                </div>
                <div class="row" style="justify-content:flex-end"><button class="btn btn-primary" type="submit">Save judge panel</button></div>
              </form>` : App.empty('No judges in this event yet', `<a href="${App.page('event.html?id=' + data.event.id + '#codes')}">Create judge access codes</a> first.`, 'key')}
          </div>
        </div>` : ''}
      <div class="card">
        <div class="card-head"><h3>Scoring progress</h3><span class="live-indicator">Live</span></div>
        ${Results.judgeProgress(result, { canUnlock: true })}
      </div>`;

    box.querySelector('[data-panel-form]')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = e.target.querySelector('[type=submit]');
      App.setLoading(btn, true);
      try {
        const { judge_ids = [] } = App.formData(e.target);
        const r = await App.post('activities.judges', { id: activityId, judge_ids });
        App.toast(r.message, 'success');
        await reload();
      } catch (err) {
        App.fail(err);
        App.setLoading(btn, false);
      }
    });
    bindJudgeActions(box);
  }

  function bindJudgeActions(box) {
    box.querySelectorAll('[data-sheet]').forEach((b) => b.addEventListener('click', () => {
      const judge = result.judges.find((j) => j.id == b.dataset.sheet);
      Results.showJudgeSheet(activityId, judge).catch(App.fail);
    }));
    box.querySelectorAll('[data-unlock]').forEach((b) => b.addEventListener('click', async () => {
      const judge = result.judges.find((j) => j.id == b.dataset.unlock);
      if (!(await App.confirm({ title: 'Unlock submission?', message: `<strong>${esc(judge.name)}</strong> will be able to change their scores again (only while scoring is open).`, confirmText: 'Unlock' }))) return;
      try {
        App.toast((await App.post('scores.unlock', { activity_id: activityId, judge_id: judge.id })).message, 'success');
        loadJudges();
      } catch (err) { App.fail(err); }
    }));
  }

  /* ---------------------------------------------------------------- results */

  async function loadResults() {
    const box = App.$('#results-root', view);
    if (!box) return;
    ({ result } = await App.get('results.activity', { id: activityId, drafts: includeDrafts ? 1 : 0 }));
    const submitted = result.judges.filter((j) => j.submitted).length;
    box.innerHTML = `
      <div class="row-between" style="margin-bottom:14px">
        <div class="row">
          <label class="check"><input type="checkbox" data-drafts ${includeDrafts ? 'checked' : ''}><span>Include unsubmitted scores (live preview)</span></label>
        </div>
        <div class="row">
          <span class="live-indicator">${submitted}/${result.judges.length} submitted · ${new Date().toLocaleTimeString()}</span>
          <a class="btn btn-sm" href="${App.page(`print.html?type=activity&id=${activityId}&drafts=${includeDrafts ? 1 : 0}`)}" target="_blank">${App.icon('print')} Print</a>
          <a class="btn btn-sm" href="${App.apiUrl('results.export', { id: activityId, drafts: includeDrafts ? 1 : 0 })}">${App.icon('download')} CSV</a>
        </div>
      </div>
      ${result.activity.status !== 'closed' ? `<div class="alert alert-info" style="margin-bottom:14px">Results are <strong>not final</strong> until scoring is closed.</div>` : ''}
      ${Charts.podium(result.rows.map((r) => ({ rank: r.rank, name: r.name, sub: r.team_name && r.team_name !== r.name ? r.team_name : '', value: r.average === null ? '' : Number(r.average).toFixed(2) })))}
      ${result.rows.some((r) => r.average !== null) ? `<div class="card"><div class="card-body">${Charts.bars(result.rows.map((r) => ({ label: r.name, sublabel: r.team_name && r.team_name !== r.name ? r.team_name : '', value: r.average, display: r.average === null ? '' : Number(r.average).toFixed(2), rank: r.rank })), { title: 'Average score', caption: `Out of ${App.num(result.max_total)} points`, max: result.max_total || undefined })}</div></div>` : ''}
      <div class="card">${Results.activityTable(result)}</div>`;
    Charts.bind(box);
    box.querySelector('[data-drafts]').addEventListener('change', (e) => {
      includeDrafts = e.target.checked;
      loadResults();
    });
  }

  /* ---------------------------------------------------------------- actions */

  function bind(canConfigure) {
    const on = (sel, fn) => App.$$(sel, view).forEach((el) => el.addEventListener('click', () => fn(el)));

    on('[data-set-status]', async (btn) => {
      const status = btn.dataset.setStatus;
      if (status === data.activity.status) return;
      if (status === 'closed' && !(await App.confirm({ title: 'Close scoring?', message: 'Judges can no longer change scores and results become final.', confirmText: 'Close scoring', danger: true }))) return;
      if (status === 'pending' && data.activity.status === 'open' && !(await App.confirm({ title: 'Pause scoring?', message: 'Judges will not be able to enter scores until you open it again. Scores already entered are kept.', confirmText: 'Set to pending' }))) return;
      try {
        App.toast((await App.post('activities.status', { id: activityId, status })).message, 'success');
        reload();
      } catch (err) { App.fail(err); }
    });

    on('[data-add-contestant]', async () => (await Forms.contestant(activityId, data.teams)) && reload());
    on('[data-edit-contestant]', async (b) => {
      const c = data.contestants.find((x) => x.id == b.dataset.editContestant);
      (await Forms.contestant(activityId, data.teams, c)) && reload();
    });
    on('[data-delete-contestant]', async (b) => {
      const c = data.contestants.find((x) => x.id == b.dataset.deleteContestant);
      if (!(await App.confirm({ title: 'Remove contestant?', message: `Remove <strong>${esc(c.name)}</strong>? Any scores given to this contestant are deleted.`, confirmText: 'Remove', danger: true }))) return;
      try { App.toast((await App.post('contestants.delete', { activity_id: activityId, id: c.id })).message, 'success'); reload(); } catch (err) { App.fail(err); }
    });
    on('[data-add-teams]', async (b) => {
      App.setLoading(b, true);
      try { App.toast((await App.post('contestants.add_teams', { activity_id: activityId })).message, 'success'); reload(); } catch (err) { App.fail(err); App.setLoading(b, false); }
    });

    if (!canConfigure) return;

    on('[data-edit]', async () => {
      const r = await Forms.activity(data.event.id, data.activity, { single: data.event.structure === 'single' });
      if (!r) return;
      await reload();
      if (r.criteriaScanned) App.$('.tabs [data-tab="criteria"]', view)?.click();
    });
    on('[data-delete]', async () => {
      const ok = await App.modal({
        title: 'Delete activity?',
        danger: true,
        submitText: 'Delete activity',
        onSubmit: () => true,
        body: `<p>This permanently deletes <strong>${esc(data.activity.title)}</strong> with its criteria, contestants and all scores.</p>
          ${data.has_scores ? `<p>Or keep the activity and only <button type="button" class="btn btn-sm" data-reset>clear all scores</button></p>` : ''}`,
        onOpen: (form, dlg, close) => form.querySelector('[data-reset]')?.addEventListener('click', async () => {
          close(undefined);
          if (!(await App.confirm({ title: 'Clear all scores?', message: 'Every judge score and submission for this activity will be deleted and scoring set to pending.', confirmText: 'Clear scores', danger: true }))) return;
          try { App.toast((await App.post('activities.reset', { id: activityId })).message, 'success'); reload(); } catch (err) { App.fail(err); }
        }),
      });
      if (ok !== true) return;
      try {
        await App.post('activities.delete', { id: activityId });
        location.replace(App.page('event.html?id=' + data.event.id));
      } catch (err) { App.fail(err); }
    });
  }

  try {
    if (!activityId) throw new Error('No activity selected.');
    await load();
  } catch (err) {
    view.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
  }
})();
