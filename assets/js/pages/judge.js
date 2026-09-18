/* Judge home: assigned activities with status and personal progress. */
(async function () {
  'use strict';
  await App.boot({ roles: ['judge'], layout: 'judge' });
  const { esc } = App;
  const view = App.$('#view');

  async function load() {
    const { judge, event, activities } = await App.get('scores.home');
    const open = activities.filter((a) => a.status === 'open' && !a.submitted_at);

    view.innerHTML = `
      <section class="judge-hero">
        <div>
          <div class="eyebrow">${esc(event.title)}</div>
          <h1>Welcome, ${esc(judge.name)}!</h1>
          <p>${open.length ? `${open.length} activit${open.length === 1 ? 'y is' : 'ies are'} open for your scoring.` : 'No activity is waiting for your scores right now.'}</p>
        </div>
      </section>

      <div class="row-between" style="margin-bottom:10px">
        <h2>Your activities</h2>
        <span class="live-indicator">Auto-updates</span>
      </div>

      ${activities.length ? `<div class="activity-list">${activities.map(card).join('')}</div>`
        : `<div class="card">${App.empty('No activities assigned', 'Please ask the event organizer to assign you to an activity.', 'list')}</div>`}
    `;
  }

  function card(a) {
    const expected = Number(a.criteria) * Number(a.contestants);
    const pct = expected ? Math.round((Number(a.scored) / expected) * 100) : 0;
    let action;
    let state;
    if (a.submitted_at) {
      state = '<span class="badge badge-closed">Submitted</span>';
      action = `<a class="btn" href="${App.page('score.html?activity=' + a.id)}">${App.icon('eye')} View my scores</a>`;
    } else if (a.status === 'open') {
      state = App.badge('open');
      action = `<a class="btn btn-primary btn-lg" href="${App.page('score.html?activity=' + a.id)}">${pct ? 'Continue scoring' : 'Start scoring'}</a>`;
    } else if (a.status === 'closed') {
      state = App.badge('closed');
      action = `<a class="btn" href="${App.page('score.html?activity=' + a.id)}">${App.icon('eye')} View</a>`;
    } else {
      state = '<span class="badge badge-pending">Not open yet</span>';
      action = `<a class="btn" href="${App.page('score.html?activity=' + a.id)}">${App.icon('list')} Preview criteria</a>`;
    }
    return `
      <article class="card activity-item">
        <div>
          <div class="row">${state}</div>
          <h3 style="margin-top:8px">${esc(a.title)}</h3>
          <div class="facts">
            ${a.schedule_at ? `<span>${esc(App.fmtDateTime(a.schedule_at))}</span>` : ''}
            ${a.venue ? `<span>${esc(a.venue)}</span>` : ''}
            <span><b>${a.contestants}</b> contestants</span>
            <span><b>${a.criteria}</b> criteria</span>
          </div>
          ${expected ? `<div class="row" style="margin-top:10px;flex-wrap:nowrap"><div class="progress grow ${pct >= 100 ? 'done' : ''}"><span style="width:${pct}%"></span></div><span class="small nowrap">${a.scored}/${expected} scores</span></div>` : ''}
        </div>
        <div class="row" style="justify-content:flex-end">${action}</div>
      </article>`;
  }

  try {
    await load();
    App.live({}, () => load());
  } catch (err) {
    view.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
  }
})();
