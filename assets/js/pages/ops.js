/* Live Ops: the event day at a glance. Every activity in one list with its status, each judge's
   progress and warnings, and the actions needed while it runs: go live, finalize, unlock a judge,
   put the standings on the big screen. Updates by itself. */
(async function () {
  'use strict';
  const me = await App.boot({ roles: ['admin', 'program_head', 'facilitator'], nav: me0() });
  const { esc } = App;
  const view = App.$('#view');
  const eventId = Number(App.param('event_id') || me.user.event_id || 0);
  let data = null;

  // facilitators have "Live Ops" in their menu; staff reach it from the event
  function me0() {
    try { return JSON.parse(sessionStorage.getItem('coc-shell') || 'null')?.user?.role === 'facilitator' ? 'ops' : 'events'; } catch (_) { return 'events'; }
  }

  async function load() {
    data = await App.get('events.ops', { id: eventId });
    render();
  }

  function render() {
    const { event, activities } = data;
    document.title = 'Live Ops · ' + event.title;
    const count = (fn) => activities.filter(fn).length;
    const live = count((a) => a.status === 'open');
    const waiting = activities.reduce((n, a) => n + (a.status === 'open' ? a.judges.filter((j) => !j.submitted && j.is_active).length : 0), 0);
    const alerts = activities.reduce((n, a) => n + a.alerts.length, 0);

    view.innerHTML = `
      ${App.pathBar({
        back: { href: App.page('event.html?id=' + event.id), label: event.title },
        trail: [
          me.user.kind === 'staff' ? { label: 'Events', href: App.page('dashboard.html') } : null,
          { label: event.title, href: App.page('event.html?id=' + event.id) },
          { label: 'Live Ops' },
        ].filter(Boolean),
      })}
      <div class="page-head">
        <div>
          <div class="eyebrow">Live Ops</div>
          <h1>${esc(event.title)}</h1>
          <div class="meta">${App.badge(event.status)}<span class="live-indicator">Updates by itself · ${esc(new Date().toLocaleTimeString())}</span></div>
        </div>
        <div class="row">
          <a class="btn" href="${App.page('control.html?event_id=' + event.id)}">${App.icon('monitor')} Big screen</a>
          <a class="btn" href="${App.page('event.html?id=' + event.id + '#standings')}">${App.icon('trophy')} Standings</a>
          ${event.is_public ? `<a class="btn" href="${App.page('public.html?event=' + event.id)}" target="_blank" rel="noopener">${App.icon('external')} Public page</a>` : ''}
        </div>
      </div>

      <div class="ops-stats">
        <div class="ops-stat ${live ? 'is-live' : ''}"><b>${live}</b><span>live now</span></div>
        <div class="ops-stat"><b>${count((a) => a.status === 'pending')}</b><span>not started</span></div>
        <div class="ops-stat"><b>${count((a) => a.status === 'closed')}</b><span>final</span></div>
        <div class="ops-stat"><b>${count((a) => a.certified)}</b><span>certified</span></div>
        <div class="ops-stat ${waiting ? 'is-warn' : ''}"><b>${waiting}</b><span>judge${waiting === 1 ? '' : 's'} still scoring</span></div>
        <div class="ops-stat ${alerts ? 'is-warn' : ''}"><b>${alerts}</b><span>warning${alerts === 1 ? '' : 's'}</span></div>
      </div>

      ${activities.length ? `<div class="ops-list">${activities.map(row).join('')}</div>`
        : `<div class="card">${App.empty('No activities yet', 'Add activities on the event page first.', 'list')}</div>`}
    `;
    bind();
  }

  function row(a) {
    const fmt = Forms.FORMATS[a.format] || Forms.FORMATS.score;
    const isScore = a.format === 'score';
    const submitted = a.judges.filter((j) => j.submitted).length;
    const progress = isScore
      ? (a.judges.length ? `${submitted}/${a.judges.length} judges submitted` : 'No judges assigned')
      : ['bracket', 'round_robin'].includes(a.format) ? `${a.matches_done}/${a.matches} ${a.format === 'bracket' ? 'matches' : 'games'} played` : `${a.results}/${a.contestants} results entered`;
    const pct = isScore ? (a.judges.length ? submitted / a.judges.length : 0) : a.matches ? a.matches_done / a.matches : a.contestants ? a.results / a.contestants : 0;
    const locked = a.certified || data.event.archived;
    return `
      <article class="card ops-row status-${a.certified ? 'certified' : a.status}">
        <div class="ops-main">
          <div class="row" style="gap:8px">${App.activityBadge(a)}<span class="badge badge-format no-dot">${App.icon(fmt.icon)} ${esc(fmt.label)}</span>${a.schedule_at ? `<span class="muted small">${App.icon('clock')} ${esc(App.fmtDateTime(a.schedule_at))}</span>` : ''}</div>
          <h3><a href="${App.page('activity.html?id=' + a.id + (isScore ? '' : '#board'))}">${esc(a.title)}</a></h3>
          <div class="row" style="flex-wrap:nowrap;gap:10px"><div class="progress grow ${pct >= 1 ? 'done' : ''}"><span style="width:${Math.round(pct * 100)}%"></span></div><span class="small nowrap">${esc(progress)}</span></div>
          ${isScore && a.judges.length ? `<div class="judge-chips">${a.judges.map((j) => {
            const state = j.submitted ? 'submitted' : j.scored ? 'scoring' : 'waiting';
            const label = j.submitted ? 'Submitted' : j.scored ? `${j.scored}/${j.expected} scored` : 'Not started';
            const canUnlock = j.submitted && a.status === 'open' && !locked;
            return `<button type="button" class="judge-chip ${state} ${j.flags.length ? 'flagged' : ''}" ${canUnlock ? `data-unlock="${a.id}:${j.id}"` : 'disabled'} title="${esc(j.name + ' — ' + label + (canUnlock ? ' · click to unlock' : ''))}">
              ${j.submitted ? App.icon('check') : j.flags.length ? App.icon('alert') : ''}<span>${esc(j.name)}</span><small>${esc(label)}</small></button>`;
          }).join('')}</div>` : ''}
          ${a.alerts.map((t) => `<div class="judge-flag">${App.icon('alert')} ${esc(t)}</div>`).join('')}
          ${a.leader ? `<div class="small muted">${App.icon('trophy')} Leading${a.status === 'closed' ? '' : ' (unofficial)'}: <strong>${esc(a.leader)}</strong></div>` : ''}
        </div>
        <div class="ops-actions">
          ${locked ? '' : a.status === 'open'
            ? `<button class="btn btn-dark" data-status="closed" data-id="${a.id}">${App.icon('lock')} Finalize</button>`
            : `<button class="btn btn-primary" data-status="open" data-id="${a.id}">${App.icon('power')} ${a.status === 'closed' ? 'Reopen' : 'Go live'}</button>`}
          <button class="btn" data-screen="${a.id}" title="Show this activity's standings on the big screen">${App.icon('monitor')} On screen</button>
          <a class="btn btn-ghost" href="${App.page('activity.html?id=' + a.id + (isScore ? '#results' : '#board'))}">${App.icon('eye')} Open</a>
        </div>
      </article>`;
  }

  function bind() {
    const on = (sel, fn) => App.$$(sel, view).forEach((el) => el.addEventListener('click', () => fn(el)));
    on('[data-status]', async (b) => {
      const a = data.activities.find((x) => x.id == b.dataset.id);
      const status = b.dataset.status;
      if (status === 'closed' && !(await App.confirm({ title: `Finalize ${a.title}?`, message: 'Judges can no longer change scores and the results become final.', confirmText: 'Finalize', danger: true }))) return;
      App.setLoading(b, true);
      try { App.toast((await App.post('activities.status', { id: a.id, status })).message, 'success'); await load(); } catch (err) { App.fail(err); App.setLoading(b, false); }
    });
    on('[data-unlock]', async (b) => {
      const [aid, jid] = b.dataset.unlock.split(':').map(Number);
      const a = data.activities.find((x) => x.id === aid);
      const j = a.judges.find((x) => x.id === jid);
      if (!(await App.confirm({ title: 'Unlock submission?', message: `<strong>${esc(j.name)}</strong> can change their scores for <strong>${esc(a.title)}</strong> again. Every change is recorded in the score history.`, confirmText: 'Unlock' }))) return;
      try { App.toast((await App.post('scores.unlock', { activity_id: aid, judge_id: jid })).message, 'success'); await load(); } catch (err) { App.fail(err); }
    });
    on('[data-screen]', async (b) => {
      const a = data.activities.find((x) => x.id == b.dataset.screen);
      const scene = ['bracket', 'round_robin'].includes(a.format) ? 'bracket' : 'standings';
      App.setLoading(b, true);
      try {
        await App.post('display.set', { event_id: eventId, state: { scene, activity_id: a.id } });
        App.toast(`${a.title} is on the big screen.`, 'success');
      } catch (err) { App.fail(err); }
      App.setLoading(b, false);
    });
  }

  try {
    if (!eventId) throw new Error('No event selected.');
    await load();
    App.live({ event_id: eventId }, load);
  } catch (err) {
    view.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
  }
})();
