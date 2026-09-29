/* Big screen (TV / projector / LED wall). Shows whatever the operator puts on air from the
   control panel, and follows result changes by itself. ?preview=1 is the control panel's monitor. */
(async function () {
  'use strict';
  const me = await App.boot({ roles: ['admin', 'program_head', 'facilitator'], layout: 'bare' });
  const { esc, num } = App;
  const preview = App.param('preview') === '1';
  const eventId = Number(App.param('event_id') || me.user.event_id || 0);
  const params = { event_id: eventId };
  const PAGE = 5; // leaderboard rows per page; longer lists turn pages by themselves
  const PAGE_SECONDS = 8;
  const POLL_MS = 1500;

  const stage = App.$('#view');
  document.body.classList.toggle('is-preview', preview);
  stage.innerHTML = `
    <header class="st-bar">
      <div class="st-brand"><img src="${App.url('assets/images/app-icon.png')}" alt=""><span><strong data-ev></strong><small>${esc(me.app.school)}</small></span></div>
      <div class="st-right"><span class="st-live" data-live><i></i>Live</span><span class="st-clock" data-clock></span></div>
    </header>
    <main class="st-scenes" data-scenes></main>
    <canvas class="st-fx" data-fx aria-hidden="true"></canvas>
    ${preview ? '' : `<button type="button" class="st-full" data-full>${App.icon('expand')} Full screen <kbd>F</kbd></button>`}`;
  const scenes = App.$('[data-scenes]', stage);

  const ordinal = (n) => n + (['th', 'st', 'nd', 'rd'][(n % 100 - 20) % 10] || ['th', 'st', 'nd', 'rd'][n % 100] || 'th');
  const sub = (e) => (e.team && e.team.trim().toLowerCase() !== e.name.trim().toLowerCase() ? e.team : '');
  const photo = (e, cls = '') => (e.photo
    ? `<span class="st-photo ${cls}" style="--entry:${esc(e.color || '#C8A02C')}"><img src="${esc(App.photoUrl(e.photo))}" alt=""></span>`
    : `<span class="st-photo is-initials ${cls}" style="--entry:${esc(e.color || '#C8A02C')};background:${esc(e.color || '#00461B')};color:${App.textOn(e.color || '#00461B')}"><b>${esc(App.initials(e.name))}</b></span>`);
  const official = (d) => (d.activity && d.activity.certified
    ? '<span class="sc-tag final">Certified official results</span>'
    : d.activity && d.activity.status === 'closed'
    ? '<span class="sc-tag final">Final results</span>'
    : `<span class="sc-tag live"><i></i>${d.activity && d.activity.format === 'score' ? 'Unofficial · live' : 'Live'}</span>`);
  const head = (kicker, title, extra = '') => `<div class="sc-head"><div><div class="sc-kicker">${kicker}</div><h1>${esc(title)}</h1></div>${extra}</div>`;

  /* ------------------------------------------------------------------ scenes */

  const SCENE = {
    idle: (d) => `
      <div class="sc-idle">
        <img src="${App.url('assets/images/app-icon.png')}" alt="">
        <div class="sc-kicker">${esc(App.session.app.school)}</div>
        <h1>${esc(d.event.title)}</h1>
        <div class="idle-rule"></div>
        <p>${[d.event.venue, d.event.start_at ? App.dateTimeRange(d.event.start_at, d.event.end_at) : ''].filter(Boolean).map(esc).join(' · ')}</p>
      </div>`,

    blank: () => '',

    message: (d) => `
      <div class="sc-message">
        <div class="sc-kicker">${esc(d.event.title)}</div>
        <h1>${esc(d.title || '')}</h1>
        ${d.text ? `<p>${esc(d.text)}</p>` : ''}
      </div>`,

    spotlight: (d) => {
      const c = d.contestant;
      if (!c) return `<div class="sc-message"><div class="sc-kicker">${esc(d.activity.title)}</div><h1>No contestants yet</h1></div>`;
      // every contestant has their own stage background; without one, their colour fills the screen
      const bg = c.background;
      return `
        <div class="sc-spotlight ${bg ? 'has-bg' : ''}" style="--entry:${esc(c.color || '#00461B')};--on:${bg ? '#FFFFFF' : App.textOn(c.color || '#00461B')}">
          ${bg ? `<img class="sp-bg" src="${esc(App.photoUrl(bg))}" alt="">` : ''}
          <div class="sp-photo">${photo(c, 'xxl')}</div>
          <div class="sp-info">
            <div class="sc-kicker">${esc(d.activity.title)} · ${d.position} of ${d.total}</div>
            ${c.number ? `<div class="sp-no">No. ${c.number}</div>` : ''}
            <h1>${esc(c.name)}</h1>
            ${sub(c) ? `<div class="sp-team">${esc(sub(c))}</div>` : ''}
            ${c.details ? `<p class="sp-details">${esc(c.details)}</p>` : ''}
          </div>
        </div>`;
    },

    standings: (d) => {
      const ranked = d.rows.filter((r) => r.rank);
      if (!ranked.length) return `${head('Standings', d.activity.title, official(d))}<div class="sc-empty">Results will appear here as soon as they come in.</div>`;
      const pages = [];
      for (let i = 0; i < ranked.length; i += PAGE) pages.push(ranked.slice(i, i + PAGE));
      return `${head('Standings', d.activity.title, official(d))}
        ${pages.map((rows, p) => `<ol class="lb" data-page="${p}" ${p ? 'hidden' : ''}>${rows.map(lbRow).join('')}</ol>`).join('')}
        ${pages.length > 1 ? `<div class="lb-pages">${pages.map((_, p) => `<i data-dot="${p}" class="${p ? '' : 'on'}"></i>`).join('')}</div>` : ''}`;
    },

    bracket: (d) => {
      if (d.activity.format === 'round_robin') {
        const played = d.table.some((r) => r.played > 0);
        return `${head('Round robin', d.activity.title, official(d))}
          <table class="st-table"><thead><tr><th></th><th>Team</th><th>P</th><th>W</th><th>D</th><th>L</th><th>+/−</th><th>Pts</th></tr></thead>
          <tbody>${d.table.slice(0, 10).map((r) => `<tr class="${played && r.rank <= 3 ? 'r' + r.rank : ''}">
            <td><span class="lb-rank">${played ? r.rank : '–'}</span></td><td><span class="st-entry">${photo(r, 'sm')}<strong>${esc(r.name)}</strong></span></td>
            <td>${r.played}</td><td>${r.won}</td><td>${r.drawn}</td><td>${r.lost}</td><td>${r.diff > 0 ? '+' : ''}${num(r.diff)}</td><td><strong>${r.points}</strong></td></tr>`).join('')}</tbody></table>`;
      }
      if (d.activity.format !== 'bracket') return SCENE.standings(d);
      if (!d.matches.length) return `${head('Bracket', d.activity.title)}<div class="sc-empty">The bracket appears here once the draw is made.</div>`;
      return `${head('Bracket', d.activity.title, official(d))}<div class="sc-fit" data-fit><div class="sc-fit-inner">${Competition.bracketView({ activity: d.activity, matches: d.matches })}</div></div>`;
    },

    overall: (d) => {
      const s = d.overall.standings;
      const anyPoints = s.some((t) => t.total > 0);
      if (!anyPoints) return `${head('Overall standings', d.event.title)}<div class="sc-empty">Team points appear here as soon as activities have winners.</div>`;
      const max = Math.max(...s.map((t) => t.total), 1);
      return `${head('Overall standings', d.event.title, '<span class="sc-tag">Team points</span>')}
        <ol class="lb lb-overall">${s.slice(0, PAGE).map((t) => `
          <li class="lb-row ${t.total > 0 && t.rank <= 3 ? 'r' + t.rank : ''}" style="--entry:${esc(t.color || '#00461B')}">
            <span class="lb-rank">${t.total > 0 ? t.rank : '–'}</span>${photo(t, 'md')}
            <span class="lb-name"><strong>${esc(t.name)}</strong><small>${t.medals.join(' · ')} <em>1st · 2nd · 3rd</em></small></span>
            <span class="lb-bar"><i style="width:${Math.max(2, (t.total / max) * 100)}%"></i></span>
            <span class="lb-val">${num(t.total)}<small>pts</small></span>
          </li>`).join('')}</ol>`;
    },

    reveal: (d) => {
      const latest = d.places[d.places.length - 1];
      const waiting = d.total_places - d.step;
      if (!d.total_places) return `${head('Winners', d.activity.title)}<div class="sc-empty">No results to announce yet.</div>`;
      const heroCards = latest
        ? latest.entries.map((e) => `
            <div class="rv-hero-card">
              ${photo(e, 'xxl')}
              <strong>${esc(e.name)}</strong>${sub(e) ? `<small>${esc(sub(e))}</small>` : ''}${e.display ? `<span class="rv-val">${esc(e.display)}</span>` : ''}
            </div>`).join('')
        : '';
      return `
        <div class="sc-reveal ${latest ? 'm' + Math.min(latest.rank, 3) : ''}">
          <div class="sc-kicker">${esc(d.activity.title)}</div>
          ${latest
            ? `<div class="rv-medal" data-rank="${latest.rank}">${App.icon('trophy')}<span>${latest.rank === 1 ? 'Champion' : ordinal(latest.rank) + ' place'}</span></div>
               <div class="rv-hero" data-key="${latest.rank}">${heroCards}</div>`
            : `<h1 class="rv-teaser">And the winners are…</h1>`}
          <div class="rv-row">
            ${d.places.slice(0, -1).map((p) => `<div class="rv-chip m${Math.min(p.rank, 3)}"><span>${ordinal(p.rank)}</span>${p.entries.map((e) => `<strong>${esc(e.name)}</strong>`).join(' · ')}</div>`).join('')}
            ${Array.from({ length: waiting }, () => '<div class="rv-chip is-hidden"><span>?</span><strong>To be announced</strong></div>').join('')}
          </div>
        </div>`;
    },
  };

  function lbRow(r) {
    return `
      <li class="lb-row ${r.rank <= 3 ? 'r' + r.rank : ''}" style="--entry:${esc(r.color || '#00461B')}">
        <span class="lb-rank">${r.rank}</span>${photo(r, 'md')}
        <span class="lb-name"><strong>${esc(r.name)}</strong>${sub(r) ? `<small>${esc(sub(r))}</small>` : ''}</span>
        ${r.display ? `<span class="lb-val">${esc(r.display)}</span>` : ''}
      </li>`;
  }

  /* ------------------------------------------------------------------ drawing */

  let current = null; // { key, el }
  let pageTimer = null;

  // the same scene/activity/contestant redraws in place; anything else cross-fades
  const keyOf = (d) => [d.scene, d.activity?.id || 0, d.scene === 'spotlight' ? d.contestant?.id || 0 : '', d.scene === 'reveal' ? d.step : ''].join(':');

  function draw(d) {
    App.$('[data-ev]', stage).textContent = d.event.title;
    document.title = d.event.title + ' · Big screen';
    document.body.classList.toggle('is-blank', d.scene === 'blank');
    document.body.dataset.scene = d.scene;
    effects(d);
    const html = `<div class="sc sc-${d.scene}">${SCENE[d.scene](d)}</div>`;
    const key = keyOf(d);
    if (current && current.key === key) {
      // only redraw when something on screen changed (an effect switch alone leaves the scene alone)
      if (current.html === html) return;
      current.html = html;
      current.el.innerHTML = App.h(html).innerHTML;
    } else {
      const el = App.h(html);
      el.classList.add('sc-enter');
      scenes.appendChild(el);
      requestAnimationFrame(() => requestAnimationFrame(() => el.classList.remove('sc-enter')));
      if (current) {
        const old = current.el;
        old.classList.add('sc-leave');
        setTimeout(() => old.remove(), 600);
      }
      current = { key, el, html };
      // the champion's reveal fires the confetti cannons by itself
      if (d.scene === 'reveal' && d.places.length && d.places[d.places.length - 1].rank === 1 && fxReady) fx.burst();
    }
    fit();
    turnPages();
  }

  /* ------------------------------------------------------------------ light effects */

  const fx = StageFx.mount(App.$('[data-fx]', stage));
  let lastBurst = null;
  let fxReady = false; // no confetti for a champion already on screen when the page opens

  function effects(d) {
    fx.set(d.state.effect);
    // each press of "Confetti burst" raises the counter; a screen that just opened only takes note of it
    if (lastBurst !== null && d.state.burst > lastBurst) fx.burst();
    lastBurst = d.state.burst;
    setTimeout(() => (fxReady = true), 0);
  }

  /** Scales the bracket down so the whole draw fits the screen. */
  function fit() {
    const box = current && App.$('[data-fit]', current.el);
    if (!box) return;
    const inner = box.firstElementChild;
    inner.style.transform = 'none';
    const scale = Math.min(1.6, box.clientWidth / inner.scrollWidth, box.clientHeight / inner.scrollHeight);
    inner.style.transform = `scale(${scale})`;
  }
  window.addEventListener('resize', fit);

  function turnPages() {
    clearInterval(pageTimer);
    const pages = current ? App.$$('[data-page]', current.el) : [];
    if (pages.length < 2) return;
    let p = 0;
    pageTimer = setInterval(() => {
      p = (p + 1) % pages.length;
      pages.forEach((el, i) => (el.hidden = i !== p));
      App.$$('[data-dot]', current.el).forEach((el, i) => el.classList.toggle('on', i === p));
    }, PAGE_SECONDS * 1000);
  }

  /* ------------------------------------------------------------------ live link to the control panel */

  let version = null;
  const live = App.$('[data-live]', stage);
  async function refresh() {
    const d = await App.get('display.screen', params);
    version = d.v;
    draw(d);
  }
  async function check() {
    try {
      const { v } = await App.get('display.version', params);
      live.classList.remove('is-offline');
      if (v !== version) await refresh();
    } catch (e) {
      live.classList.add('is-offline'); // keep showing the last picture until the link is back
    }
    setTimeout(check, POLL_MS);
  }

  try {
    await refresh();
  } catch (err) {
    scenes.innerHTML = `<div class="sc"><div class="sc-message"><h1>Screen unavailable</h1><p>${esc(err.message)}</p></div></div>`;
  }
  setTimeout(check, POLL_MS);
  // the control panel's monitor redraws the moment the operator takes a scene
  window.addEventListener('message', (e) => {
    if (e.origin === location.origin && e.data === 'display:refresh') refresh().catch(() => {});
  });

  /* ------------------------------------------------------------------ clock, full screen, idle cursor */

  const clock = App.$('[data-clock]', stage);
  const tick = () => (clock.textContent = new Date().toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }));
  tick();
  setInterval(tick, 10000);

  if (!preview) {
    const toggleFull = () => (document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.()).catch?.(() => {});
    App.$('[data-full]', stage).addEventListener('click', toggleFull);
    document.addEventListener('keydown', (e) => { if (e.key === 'f' || e.key === 'F') toggleFull(); });
    let idle = null;
    const wake = () => {
      document.body.classList.remove('is-idle');
      clearTimeout(idle);
      idle = setTimeout(() => document.body.classList.add('is-idle'), 2500);
    };
    document.addEventListener('mousemove', wake);
    wake();
  }
})();
