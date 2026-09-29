/* Dashboard: routes judges/facilitators; lists events for staff. */
(async function () {
  'use strict';
  const me = await App.boot({ roles: ['admin', 'program_head', 'facilitator', 'judge'], nav: 'events', layout: 'bare' });
  const role = me.user.role;
  if (role === 'judge') return location.replace(App.page('judge.html'));
  if (role === 'facilitator') return location.replace(App.page('event.html?id=' + me.user.event_id));

  App.shell({ nav: 'events' });
  App.live({}, () => load());
  const { esc } = App;
  const view = App.$('#view');
  let owners = [];
  let listView = App.param('view') === 'archived' ? 'archived' : 'active';

  async function load() {
    let { events, owners: o } = await App.get('events.list');
    owners = o;
    const archived = events.filter((e) => e.archived_at);
    const allEvents = events;
    events = events.filter((e) => !e.archived_at);
    const ongoing = events.filter((e) => e.status === 'ongoing').length;
    const openActs = events.reduce((s, e) => s + Number(e.open_activities), 0);
    const judges = events.reduce((s, e) => s + Number(e.judges), 0);

    const stat = (icon, value, label, dark = false) =>
      `<div class="stat ${dark ? 'dark' : ''}"><span class="stat-icon">${App.icon(icon)}</span><div><span class="value">${value}</span><span class="label">${label}</span></div></div>`;

    view.innerHTML = `
      <section class="welcome-section">
        <div>
          <div class="eyebrow">${role === 'admin' ? 'Administrator' : 'Program Head'}</div>
          <h1>Welcome back, ${esc(me.user.name.split(' ')[0])}!</h1>
          <p>Create events, add activities, upload the criteria and hand out access codes to your judges and facilitators.</p>
        </div>
        <div class="row hero-actions">
          <button class="btn btn-lg btn-hero-outline" data-scan>${App.icon('scan')} Scan document</button>
          <button class="btn btn-light btn-lg" data-new>${App.icon('plus')} New event</button>
        </div>
      </section>

      <div class="stats">
        ${stat('calendar', events.length, 'Total events', true)}
        ${stat('trophy', ongoing, 'Ongoing events')}
        ${stat('scan', openActs, 'Live now')}
        ${stat('users', judges, 'Judges')}
      </div>

      <div class="row-between" style="margin-bottom:12px">
        <h2>${role === 'admin' ? 'All events' : 'My events'}</h2>
        <div class="tabs" role="tablist" style="margin:0">
          <button type="button" data-view="active" aria-selected="${listView === 'active'}">${App.icon('calendar')} Events <span class="count">${events.length}</span></button>
          <button type="button" data-view="archived" aria-selected="${listView === 'archived'}">${App.icon('archive')} Archived <span class="count">${archived.length}</span></button>
        </div>
      </div>

      <div data-list="active" ${listView === 'active' ? '' : 'hidden'}>
        ${events.length ? `<div class="grid-cards">${events.map(card).join('')}</div>`
          : `<div class="card">${App.empty(allEvents.length ? 'No active events' : 'No events yet', allEvents.length ? 'Every event is archived. Create a new one, or restore one from the Archived tab.' : 'Create your first event, then add its activities.', 'calendar')}
              <div class="text-center" style="padding-bottom:28px"><button class="btn btn-primary" data-new>${App.icon('plus')} New event</button></div></div>`}
      </div>
      <div data-list="archived" ${listView === 'archived' ? '' : 'hidden'}>
        ${archived.length ? `<p class="muted small" style="margin:-4px 0 12px">Archived events are hidden from the list above and their access codes don't work. Open one to restore or delete it.</p><div class="grid-cards">${archived.map(card).join('')}</div>`
          : `<div class="card">${App.empty('Nothing archived', 'Archive finished events from their event page to tidy up this list. Nothing is deleted.', 'archive')}</div>`}
      </div>
    `;
    App.$$('[data-view]', view).forEach((b) => b.addEventListener('click', () => {
      listView = b.dataset.view;
      App.$$('[data-view]', view).forEach((x) => x.setAttribute('aria-selected', String(x === b)));
      App.$$('[data-list]', view).forEach((l) => (l.hidden = l.dataset.list !== listView));
      history.replaceState(null, '', listView === 'archived' ? '?view=archived' : location.pathname);
    }));
    App.$('[data-scan]', view)?.addEventListener('click', async () => {
      const r = await EventScan.open({ owners });
      if (r && r.eventId) location.href = App.page('event.html?id=' + r.eventId + '#activities');
    });
    App.$$('[data-new]', view).forEach((b) => b.addEventListener('click', async () => {
      const r = await Forms.event(null, owners);
      if (r && r.activity_id) location.href = App.page('activity.html?id=' + r.activity_id + (r.criteriaScanned ? '#criteria' : ''));
      else if (r && r.id) location.href = App.page('event.html?id=' + r.id + '&new=1');
    }));
  }

  function card(e) {
    const dates = e.start_at ? App.dateTimeRange(e.start_at, e.end_at) : App.dateRange(e.start_date, e.end_date);
    return `
      <a class="card event-card ${esc(e.status)} ${e.archived_at ? 'archived' : ''}" href="${App.page('event.html?id=' + e.id)}">
        <div class="band">
          <div class="row" style="gap:6px">${e.archived_at ? `<span class="badge badge-archived no-dot">${App.icon('archive')} Archived</span>` : ''}${App.badge(e.status)}${!e.archived_at && Number(e.open_activities) ? `<span class="badge">${e.open_activities} live</span>` : ''}</div>
          <h3>${esc(e.title)}</h3>
          ${e.archived_at ? `<div class="band-sub">Archived ${esc(App.fmtDate(e.archived_at))}${e.archived_by_name ? ' by ' + esc(e.archived_by_name) : ''}</div>` : ''}
          <div class="band-sub">${[dates, e.venue].filter(Boolean).map(esc).join(' · ') || '&nbsp;'}</div>
        </div>
        <div class="card-body">
          ${role === 'admin' && e.owner_name ? `<div class="small muted">Project Head: <b style="color:var(--gray-800)">${esc(e.owner_name)}</b></div>` : ''}
          ${Forms.FORMATS[e.default_format] ? `<div class="small muted">${e.structure === 'single' ? 'Single competition' : 'Main format'}: <b style="color:var(--gray-800)">${Forms.FORMATS[e.default_format].label}</b></div>` : ''}
          <div class="facts">
            <div class="fact"><b>${e.activities}</b><span>Activities</span></div>
            <div class="fact"><b>${e.teams}</b><span>Teams</span></div>
            <div class="fact"><b>${e.judges}</b><span>Judges</span></div>
          </div>
          <span class="card-link">Open event</span>
        </div>
      </a>`;
  }

  const deleted = App.param('deleted');
  if (deleted) {
    history.replaceState(null, '', location.pathname);
    setTimeout(() => App.toast(`“${deleted}” was deleted.`, 'success', 5000), 300);
  }

  try {
    await load();
  } catch (err) {
    view.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
  }
})();
