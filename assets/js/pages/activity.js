/* Activity console: criteria, contestants, judge panel & progress, live results, rounds, certification. */
(async function () {
  'use strict';
  const me = await App.boot({ roles: ['admin', 'program_head', 'facilitator'], nav: 'events' });
  const { esc, num } = App;
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
    const certified = !!a.certified_at;
    const boardTab = { bracket: 'Bracket', round_robin: 'Standings & fixtures', ranking: 'Leaderboard' }[format];

    view.innerHTML = `
      ${event.archived ? `<div class="archive-banner compact" role="status"><span class="ab-icon">${App.icon('archive')}</span><div class="ab-text"><strong>Archived event</strong><span>This activity is read-only. Restore the event from its event page to make changes.</span></div></div>` : ''}
      ${App.pathBar({
        back: { href: App.page('event.html?id=' + event.id + (single ? '' : '#activities')), label: event.title },
        trail: [
          me.user.kind === 'staff' ? { label: 'Events', href: App.page('dashboard.html') } : null,
          { label: event.title, href: App.page('event.html?id=' + event.id) },
          { label: single ? 'Competition' : a.title },
        ].filter(Boolean),
        tab: true,
      })}
      <div class="page-head">
        <div>
          <div class="eyebrow">${single ? 'Competition' : 'Activity'}</div>
          <h1>${esc(a.title)}</h1>
          <div class="meta">
            ${App.activityBadge(a)}
            <span class="badge badge-format no-dot">${App.icon(fmt.icon)} ${esc(fmt.label)}</span>
            ${a.nature ? `<span class="badge badge-format no-dot">${esc(a.nature)}</span>` : ''}
            ${a.schedule_at ? `<span>${App.icon('clock')} ${esc(App.fmtDateTime(a.schedule_at))}</span>` : ''}
            ${a.venue ? `<span>${esc(a.venue)}</span>` : ''}
          </div>
        </div>
        <div class="row">
          ${certified ? '' : `<div class="status-switch" role="group" aria-label="Activity status" title="Not started → Live (judges can score) → Final (locked)">
            ${['pending', 'open', 'closed'].map((s) => `<button data-set-status="${s}" data-status="${s}" class="${a.status === s ? 'active' : ''}" aria-pressed="${a.status === s}">${App.MATCH_STATUS[s]}</button>`).join('')}
          </div>`}
          ${canConfigure && a.status === 'closed' && !certified ? `<button class="btn btn-primary" data-certify title="Sign off the final results: they are locked and get a certificate code">${App.icon('shield')} Certify results</button>` : ''}
          ${App.moreMenu([
            { label: 'Print results', icon: 'print', href: App.page(isScore ? `print.html?type=activity&id=${activityId}&drafts=0` : 'print.html?type=board&id=' + activityId), target: '_blank' },
            { label: 'Live Ops', icon: 'flow', href: App.page('ops.html?event_id=' + event.id) },
            canConfigure ? 'divider' : null,
            canConfigure && !certified ? { label: single ? 'Competition settings' : 'Edit activity', icon: 'edit', attrs: 'data-edit' } : null,
            canConfigure ? { label: 'Access codes', icon: 'key', href: App.page('event.html?id=' + event.id + '#codes') } : null,
            canConfigure && certified ? { label: 'Remove certification…', icon: 'unlock', attrs: 'data-uncertify' } : null,
            canConfigure && isScore && data.has_scores && !certified ? { label: 'Clear all scores…', icon: 'refresh', attrs: 'data-reset', danger: true } : null,
            canConfigure && !single && !certified ? { label: 'Delete activity…', icon: 'trash', attrs: 'data-delete', danger: true } : null,
          ])}
        </div>
      </div>
      ${certified ? `<div class="certified-banner" role="status">${App.icon('shield')}<div><strong>Certified results</strong>
        <span>Signed off${a.certified_by ? ' by ' + esc(a.certified_by) : ''} on ${esc(App.fmtDateTime(a.certified_at))}. Certificate code <b class="mono">${esc(a.certificate)}</b> — printed on every result sheet. Nothing can be changed until the certification is removed.</span></div></div>` : ''}
      ${renderRounds()}
      ${App.activityInfoCard(a)}

      <div class="tabs" role="tablist">
        ${isScore ? `<button data-tab="criteria">Criteria<span class="count">${criteria.length}</span></button>`
          : `<button data-tab="board">${App.icon(fmt.icon)} ${boardTab}</button>`}
        <button data-tab="contestants">Participants<span class="count">${contestants.length}</span></button>
        ${isScore ? `<button data-tab="judges">Judges<span class="count">${data.assigned_judges.length}</span></button>
        <button data-tab="results">Results</button>` : ''}
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
        canConfigure: canConfigure && !certified,
        hasScores: data.has_scores,
        hasFile: a.has_file,
        fileName: a.criteria_file_name,
        onSaved: reload,
      });
    }

    App.bindMenus(view);
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

  /* ---------------------------------------------------------------- rounds */

  function renderRounds() {
    const { rounds, activity: a } = data;
    if (!rounds.source && !rounds.later.length) return '';
    const certified = !!a.certified_at;
    const carry = Number(a.carry_weight);
    return `
      <div class="card rounds-card">
        <div class="card-body row-between">
          <div class="row" style="gap:10px;align-items:flex-start;flex-wrap:nowrap">
            <span class="rounds-icon">${App.icon('flow')}</span>
            <div>
              ${rounds.source ? `<strong>Round after <a href="${App.page('activity.html?id=' + rounds.source.id)}">${esc(rounds.source.title)}</a></strong>
                <div class="muted small">${a.advance_count ? `The top ${a.advance_count} advance` : 'Finalists come from the previous round'}${carry ? ` · final score = ${num(carry)}% previous round + ${num(100 - carry)}% this round` : ' · this round starts fresh'}
                  · ${rounds.advanced ? `${rounds.advanced} finalist${rounds.advanced === 1 ? '' : 's'} added` : 'no finalists added yet'}
                  ${rounds.source.status !== 'closed' ? ' · finalize the previous round first' : ''}</div>` : ''}
              ${rounds.later.map((l) => `<div class="${rounds.source ? 'small' : ''}"><strong>Next round: <a href="${App.page('activity.html?id=' + l.id)}">${esc(l.title)}</a></strong>
                <span class="muted small">${l.advance_count ? `· the top ${l.advance_count} advance` : ''}${Number(l.carry_weight) ? ` · ${num(l.carry_weight)}% of this score carries over` : ''} · this round does not count toward the overall</span></div>`).join('')}
            </div>
          </div>
          ${rounds.source && !certified && !data.event.archived ? `<button class="btn btn-primary" data-advance ${rounds.source.status === 'closed' ? '' : 'disabled title="Finalize the previous round first"'}>
            ${App.icon('up')} Advance ${a.advance_count ? 'top ' + a.advance_count : 'finalists'}</button>` : ''}
        </div>
      </div>`;
  }

  /* ---------------------------------------------------------------- contestants */

  function renderContestants() {
    const { contestants, teams } = data;
    const locked = !!data.activity.certified_at;
    const overall = data.event.has_overall;
    const members = (c) => (c.members ? String(c.members).split('\n').filter(Boolean) : []);
    return `
      <div class="card">
        <div class="card-head">
          <div><h3>Participants</h3><div class="muted small">${data.rounds.source ? 'Finalists of the previous round (use “Advance” above), or add them one by one.'
            : overall ? 'Solo players or teams. Each one plays for a group and earns points for it in the overall standings.' : 'Solo players or teams competing in this activity.'}</div></div>
          ${locked ? '' : `<div class="row">
            ${overall && teams.length ? `<button class="btn btn-sm" data-add-teams title="One entry for each group, named after it">${App.icon('users')} Add one entry per group</button>` : ''}
            <button class="btn btn-sm btn-primary" data-add-contestant>${App.icon('plus')} Add participant</button>
          </div>`}
        </div>
        ${overall && !teams.length ? `<div class="card-body"><div class="alert alert-warn">This event has overall standings but no groups yet. <a href="${App.page('event.html?id=' + data.event.id + '#teams')}">Add the groups</a> (departments, tribes…) first, then the participants.</div></div>` : ''}
        ${contestants.length ? `
          <div class="table-wrap"><table class="table stackable">
            <thead><tr><th class="num">No.</th><th>Name</th>${overall ? '<th>Group</th>' : ''}<th>Members / details</th><th class="actions"></th></tr></thead>
            <tbody>${contestants.map((c) => `<tr>
              <td class="num" data-label="No."><span class="rank-pill">${c.number}</span></td>
              <td class="primary" data-label="Name">${App.entry(c, members(c).length ? `Team · ${members(c).length} member${members(c).length === 1 ? '' : 's'}` : '')}</td>
              ${overall ? `<td data-label="Group">${c.team_name ? esc(c.team_name) : '<span class="badge badge-pending no-dot">No group — earns no points</span>'}</td>` : ''}
              <td data-label="Members / details">${members(c).length ? `<div class="member-list">${members(c).map(esc).join(', ')}</div>` : ''}${c.details ? `<div class="muted small">${esc(c.details)}</div>` : ''}${!members(c).length && !c.details ? '<span class="muted">—</span>' : ''}</td>
              <td class="actions">${locked ? '' : `
                <button class="btn btn-sm" data-edit-contestant="${c.id}">${App.icon('edit')} Edit</button>
                <button class="btn btn-sm btn-danger" data-delete-contestant="${c.id}" title="Remove">${App.icon('trash')}</button>`}
              </td></tr>`).join('')}
            </tbody></table></div>`
          : App.empty('No participants yet', overall && teams.length ? 'Add solo players or teams one by one, or one entry per group at once.' : 'Add the solo players or teams of this activity.', 'users')}
      </div>`;
  }

  /* ---------------------------------------------------------------- judges */

  async function loadJudges() {
    const box = App.$('#judges-root', view);
    if (!box) return;
    const [res, hist] = await Promise.all([
      App.get('results.activity', { id: activityId, drafts: 1 }),
      App.get('scores.history', { activity_id: activityId }),
    ]);
    result = res.result;
    const assigned = new Set(data.assigned_judges);
    const canConfigure = data.can_configure && !data.activity.certified_at;

    box.innerHTML = `
      ${canConfigure ? `
        <div class="card">
          <div class="card-head"><div><h3>Judge panel</h3><div class="muted small">Tick who scores this activity, or add a new judge here — their access code is created at once.</div></div>
            <button class="btn btn-sm btn-primary" type="button" data-new-judge>${App.icon('plus')} New judge</button></div>
          <div class="card-body">
            ${data.judges.length ? `
              <form data-panel-form class="stack">
                <div class="grid-cards" style="gap:8px">
                  ${data.judges.map((j) => `<label class="check card" style="padding:12px;box-shadow:none"><input type="checkbox" name="judge_ids[]" value="${j.id}" ${assigned.has(Number(j.id)) ? 'checked' : ''}>
                    <span>${esc(j.name)}${Number(j.is_active) ? '' : ' <span class="muted small">(disabled)</span>'}</span></label>`).join('')}
                </div>
                <div class="row-between"><a class="small" href="${App.page('event.html?id=' + data.event.id + '#codes')}">${App.icon('key')} Print or manage every access code</a>
                  <button class="btn btn-primary" type="submit">Save judge panel</button></div>
              </form>` : App.empty('No judges in this event yet', 'Use “New judge” to add the first one.', 'key')}
          </div>
        </div>` : ''}
      <div class="card">
        <div class="card-head"><h3>Scoring progress</h3><span class="live-indicator">Live</span></div>
        ${Results.judgeProgress(result, { canUnlock: !data.activity.certified_at })}
      </div>
      <div class="card">
        <div class="card-head"><div><h3>${App.icon('history')} Score changes</h3><div class="muted small">Every correction to a score already entered, and every score entered after an unlock.</div></div></div>
        ${Results.history(hist)}
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
    box.querySelector('[data-new-judge]')?.addEventListener('click', newJudge);
    bindJudgeActions(box);
  }

  /** Creates a judge access code already assigned to this activity. */
  async function newJudge() {
    const r = await App.modal({
      title: 'New judge for ' + data.activity.title,
      submitText: 'Create judge',
      body: `<label class="field"><span>Judge name</span><input name="name" required maxlength="150" placeholder="e.g. Judge 4 or the judge's full name"></label>
        <p class="hint">An access code is created for them and they are added to this activity's panel.</p>`,
      onSubmit: (d) => App.post('codes.save', { event_id: data.event.id, role: 'judge', name: d.name, activity_ids: [activityId] }),
    });
    if (!r || !r.code) return;
    await reload();
    App.modal({
      title: 'Judge added',
      body: `<p>Give this code to the judge. They sign in on the homepage with it:</p><div class="code-value text-center" style="font-size:2rem;padding:12px 0">${esc(r.code)}</div>
        <p><a class="btn" href="${App.page('print.html?type=codes&id=' + data.event.id)}" target="_blank">${App.icon('print')} Print code slips</a></p>`,
      submitText: null, cancelText: 'Done',
    });
  }

  function bindJudgeActions(box) {
    box.querySelectorAll('[data-sheet]').forEach((b) => b.addEventListener('click', () => {
      const judge = result.judges.find((j) => j.id == b.dataset.sheet);
      Results.showJudgeSheet(activityId, judge).catch(App.fail);
    }));
    box.querySelectorAll('[data-unlock]').forEach((b) => b.addEventListener('click', async () => {
      const judge = result.judges.find((j) => j.id == b.dataset.unlock);
      if (!(await App.confirm({ title: 'Unlock submission?', message: `<strong>${esc(judge.name)}</strong> will be able to change their scores again (only while the activity is live). Every change is recorded in the score history.`, confirmText: 'Unlock' }))) return;
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
    const rankSum = result.method.scoring === 'rank_sum';
    const locked = !!data.activity.certified_at || !!data.event.archived;
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
      ${result.activity.status !== 'closed' ? `<div class="alert alert-info" style="margin-bottom:14px">Results are <strong>not final</strong> until the activity is finalized.</div>` : ''}
      ${Results.methodNote(result)}
      ${Charts.podium(result.rows.map((r) => ({ rank: r.rank, name: r.name, sub: r.team_name && r.team_name !== r.name ? r.team_name : '', value: r.average === null ? '' : rankSum ? 'rank sum ' + num(r.rank_sum) : Number(r.average).toFixed(2) })))}
      ${result.rows.some((r) => r.average !== null) ? `<div class="card"><div class="card-body">${Charts.bars(result.rows.map((r) => ({ label: r.name, sublabel: r.team_name && r.team_name !== r.name ? r.team_name : '', value: r.average, display: r.average === null ? '' : Number(r.average).toFixed(2), rank: r.rank })), { title: 'Average score', caption: `Out of ${num(result.max_total)} points`, max: result.max_total || undefined })}</div></div>` : ''}
      <div class="card">${Results.activityTable(result)}</div>
      <div class="two-col">
        <div class="card">
          <div class="card-head"><div><h3>${App.icon('alert')} Deductions</h3><div class="muted small">Penalties taken off a contestant's final score (overtime, rule violations).</div></div>
            ${locked ? '' : `<button class="btn btn-sm" data-add-deduction>${App.icon('plus')} Add deduction</button>`}</div>
          ${Results.deductions(data.deductions, !locked)}
        </div>
        <div class="card">
          <div class="card-head"><div><h3>${App.icon('star')} Special awards</h3><div class="muted small">Best in a criterion, or picked by hand.</div></div>
            ${data.can_configure && !locked ? `<button class="btn btn-sm" data-edit-awards>${App.icon('edit')} ${result.awards.length ? 'Edit' : 'Add awards'}</button>` : ''}</div>
          ${Results.awards(result.awards)}
        </div>
      </div>`;
    Charts.bind(box);
    box.querySelector('[data-drafts]').addEventListener('change', (e) => {
      includeDrafts = e.target.checked;
      loadResults();
    });
    box.querySelector('[data-add-deduction]')?.addEventListener('click', addDeduction);
    box.querySelector('[data-edit-awards]')?.addEventListener('click', editAwards);
    box.querySelectorAll('[data-delete-deduction]').forEach((b) => b.addEventListener('click', async () => {
      const d = data.deductions.find((x) => x.id == b.dataset.deleteDeduction);
      if (!(await App.confirm({ title: 'Remove deduction?', message: `Give back <strong>${num(d.points)}</strong> points to <strong>${esc(d.contestant_name)}</strong> (${esc(d.reason)})?`, confirmText: 'Remove' }))) return;
      try { App.toast((await App.post('deductions.delete', { id: d.id })).message, 'success'); await reload(); } catch (err) { App.fail(err); }
    }));
  }

  async function addDeduction() {
    const r = await App.modal({
      title: 'Add a deduction',
      submitText: 'Deduct points',
      body: `<div class="form-grid two">
        <label class="field span-2"><span>Contestant</span><select name="contestant_id" required>
          <option value="">Choose…</option>
          ${data.contestants.map((c) => `<option value="${c.id}">${c.number}. ${esc(c.name)}</option>`).join('')}
        </select></label>
        <label class="field"><span>Points to deduct</span><input type="number" name="points" min="0.01" max="100" step="0.01" required placeholder="e.g. 2"></label>
        <label class="field"><span>Reason</span><input name="reason" required maxlength="255" placeholder="e.g. Overtime (30 s)"></label>
      </div>
      <p class="hint">Taken off the contestant's final score and shown on the results sheet with its reason.</p>`,
      onSubmit: (d) => App.post('deductions.save', { activity_id: activityId, ...d }),
    });
    if (r) { App.toast(r.message, 'success'); await reload(); }
  }

  async function editAwards() {
    const rows = result.awards.map((a) => ({ name: a.name, criterion_id: a.criterion_id, contestant_id: a.contestant_id }));
    const row = (a = {}) => `
      <div class="award-row">
        <input name="award_name" maxlength="150" placeholder="e.g. Best in Talent" value="${esc(a.name || '')}" aria-label="Award name">
        <select name="award_source" aria-label="Winner">
          <optgroup label="Highest average in">
            ${data.criteria.map((c) => `<option value="c${c.id}" ${Number(a.criterion_id) === Number(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}
          </optgroup>
          <optgroup label="Picked by hand">
            ${data.contestants.map((c) => `<option value="p${c.id}" ${Number(a.contestant_id) === Number(c.id) ? 'selected' : ''}>${c.number}. ${esc(c.name)}</option>`).join('')}
          </optgroup>
        </select>
        <button type="button" class="btn btn-sm btn-ghost" data-remove-award aria-label="Remove">${App.icon('x')}</button>
      </div>`;
    const r = await App.modal({
      title: 'Special awards',
      wide: true,
      submitText: 'Save awards',
      body: `<p class="muted small">A criterion award goes to the contestant with the highest average in that criterion (ties share it). Hand-picked awards, e.g. People's Choice, go to the contestant you choose.</p>
        <div data-award-list>${rows.map(row).join('')}</div>
        <button type="button" class="btn btn-sm" data-add-award style="margin-top:8px">${App.icon('plus')} Add award</button>`,
      onOpen: (form) => {
        const list = form.querySelector('[data-award-list]');
        const add = () => { list.insertAdjacentHTML('beforeend', row()); };
        if (!rows.length) add();
        form.querySelector('[data-add-award]').addEventListener('click', add);
        list.addEventListener('click', (e) => e.target.closest('[data-remove-award]')?.closest('.award-row').remove());
      },
      onSubmit: (d, form) => {
        const awards = App.$$('.award-row', form).map((el) => {
          const source = el.querySelector('[name=award_source]').value;
          return { name: el.querySelector('[name=award_name]').value.trim(), criterion_id: source[0] === 'c' ? Number(source.slice(1)) : null, contestant_id: source[0] === 'p' ? Number(source.slice(1)) : null };
        }).filter((a) => a.name);
        return App.post('activities.awards', { activity_id: activityId, awards });
      },
    });
    if (r) { App.toast(r.message, 'success'); await reload(); }
  }

  /* ---------------------------------------------------------------- actions */

  function bind(canConfigure) {
    const on = (sel, fn) => App.$$(sel, view).forEach((el) => el.addEventListener('click', () => fn(el)));

    on('[data-set-status]', async (btn) => {
      const status = btn.dataset.setStatus;
      if (status === data.activity.status) return;
      if (status === 'closed' && !(await App.confirm({ title: 'Finalize this activity?', message: 'Judges can no longer change scores and the results become final. You can reopen it until the results are certified.', confirmText: 'Finalize', danger: true }))) return;
      if (status === 'pending' && data.activity.status === 'open' && !(await App.confirm({ title: 'Pause scoring?', message: 'Judges will not be able to enter scores until it is live again. Scores already entered are kept.', confirmText: 'Set to not started' }))) return;
      try {
        App.toast((await App.post('activities.status', { id: activityId, status })).message, 'success');
        reload();
      } catch (err) { App.fail(err); }
    });

    on('[data-advance]', async (b) => {
      const a = data.activity;
      const r = await App.modal({
        title: 'Advance finalists',
        submitText: 'Advance',
        body: `<label class="field"><span>How many contestants of ${esc(data.rounds.source.title)} advance?</span>
          <input type="number" name="count" min="1" max="500" required value="${esc(a.advance_count || 5)}"></label>
          <p class="hint">The top contestants by the official (submitted) scores are added with their number, team and pictures. A tie at the cut lets everyone tied advance. Contestants already added are skipped.</p>`,
        onSubmit: (d) => App.post('activities.advance', { id: activityId, count: d.count }),
      });
      if (r) { App.toast(r.message, 'success', 5000); await reload(); App.$('.tabs [data-tab="contestants"]', view)?.click(); }
      void b;
    });

    on('[data-add-contestant]', async () => (await Forms.contestant(activityId, data.teams, null, { hasOverall: data.event.has_overall })) && reload());
    on('[data-edit-contestant]', async (b) => {
      const c = data.contestants.find((x) => x.id == b.dataset.editContestant);
      (await Forms.contestant(activityId, data.teams, c, { hasOverall: data.event.has_overall })) && reload();
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

    on('[data-certify]', async () => {
      const ok = await App.modal({
        title: 'Certify these results?',
        submitText: 'Certify results',
        body: `<div class="danger-summary neutral"><span class="ds-icon">${App.icon('shield')}</span>
          <div><strong>${esc(data.activity.title)}</strong><div class="muted small">You confirm the final results are correct and official.</div></div></div>
          <ul class="effect-list">
            <li>${App.icon('lock')} Scores, deductions, contestants and criteria are locked; the activity cannot be reopened.</li>
            <li>${App.icon('file')} A certificate code (a fingerprint of every rank and result) is printed on the result sheets.</li>
            <li>${App.icon('globe')} The public page and big screen show the results as <strong>Certified</strong>.</li>
            <li>${App.icon('unlock')} A program head or administrator can remove the certification later, with a reason that is logged.</li>
          </ul>`,
        onSubmit: () => App.post('activities.certify', { id: activityId }),
      });
      if (ok) { App.toast(ok.message, 'success', 6000); reload(); }
    });
    on('[data-uncertify]', async () => {
      const ok = await App.modal({
        title: 'Remove the certification?',
        danger: true,
        submitText: 'Remove certification',
        body: `<p>The results of <strong>${esc(data.activity.title)}</strong> can be changed again, and the printed certificate code <b class="mono">${esc(data.activity.certificate)}</b> no longer matches.</p>
          <label class="field"><span>Reason (kept in the activity log)</span><input name="reason" required maxlength="255" placeholder="e.g. Wrong contestant number on the tally sheet"></label>`,
        onSubmit: (d) => App.post('activities.uncertify', { id: activityId, reason: d.reason }),
      });
      if (ok) { App.toast(ok.message, 'success'); reload(); }
    });
    on('[data-edit]', async () => {
      const r = await Forms.activity(data.event.id, data.activity, { single: data.event.structure === 'single', criteria: data.criteria, activities: data.activities });
      if (!r) return;
      await reload();
      if (r.criteriaScanned) App.$('.tabs [data-tab="criteria"]', view)?.click();
    });
    on('[data-reset]', async () => {
      if (!(await App.confirm({ title: 'Clear all scores?', message: 'Every judge score and submission for this activity will be deleted and it goes back to not started. The score history is kept.', confirmText: 'Clear scores', danger: true }))) return;
      try { App.toast((await App.post('activities.reset', { id: activityId })).message, 'success'); reload(); } catch (err) { App.fail(err); }
    });
    on('[data-delete]', async () => {
      const ok = await App.confirm({
        title: 'Delete activity?',
        message: `This permanently deletes <strong>${esc(data.activity.title)}</strong> with its criteria, contestants and all scores.`,
        confirmText: 'Delete activity',
        danger: true,
      });
      if (!ok) return;
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
