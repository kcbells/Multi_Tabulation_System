/* Big screen control panel: the technical operator decides what the TV / projector shows.
   Every button goes on air at once; the monitor on the left is the screen itself (display.html). */
(async function () {
  'use strict';
  const me = await App.boot({ roles: ['admin', 'program_head', 'facilitator'], layout: 'judge' });
  const { esc } = App;
  const view = App.$('#view');
  document.body.classList.add('ctl-page');
  const isFacilitator = me.user.role === 'facilitator';
  const eventId = Number(App.param('event_id') || me.user.event_id || 0);
  const params = { event_id: eventId };
  const screenUrl = App.page('display.html?event_id=' + eventId);

  const SCENES = [
    { id: 'idle', icon: 'image', label: 'Title card', hint: 'Event name, between segments' },
    { id: 'spotlight', icon: 'spotlight', label: 'On stage', hint: 'The contestant performing now', activity: true },
    { id: 'standings', icon: 'list', label: 'Standings', hint: 'Leaderboard of the activity', activity: true },
    { id: 'bracket', icon: 'trophy', label: 'Bracket', hint: 'Bracket or round robin table', activity: true },
    { id: 'overall', icon: 'grid', label: 'Overall', hint: 'Team points of the event' },
    { id: 'reveal', icon: 'trophy', label: 'Winners reveal', hint: '3rd, 2nd, then the champion', activity: true },
    { id: 'message', icon: 'message', label: 'Message', hint: 'Announcement or break' },
    { id: 'blank', icon: 'power', label: 'Blackout', hint: 'Black screen' },
  ];
  const sceneOf = (id) => SCENES.find((s) => s.id === id) || SCENES[0];

  // light effects drawn over any scene (assets/js/modules/stagefx.js)
  const EFFECTS = [
    { id: 'none', icon: 'x', label: 'No effect', hint: 'Effects off' },
    { id: 'glitter', icon: 'sparkle', label: 'Glitter', hint: 'Falling gold glitter' },
    { id: 'stars', icon: 'star', label: 'Stars', hint: 'Twinkling and shooting stars' },
    { id: 'confetti', icon: 'sparkle', label: 'Confetti', hint: 'Falling confetti' },
    { id: 'spotlights', icon: 'sun', label: 'Spotlights', hint: 'Sweeping stage beams' },
    { id: 'orbs', icon: 'sun', label: 'Light orbs', hint: 'Soft floating lights' },
    { id: 'fireworks', icon: 'sparkle', label: 'Fireworks', hint: 'Rockets and sparks' },
  ];
  const burst = () => take({ burst: (state.burst || 0) + 1 });

  let data = null; // display.control
  let state = null; // what is on air
  let screen = null; // display.screen (resolved: reveal progress, contestant on stage…)
  let selected = 0; // activity picked in the panel
  let busy = false;
  let lastVersion = null; // screen version this panel already shows

  const activityOf = (id) => data.activities.find((a) => a.id === Number(id));
  const picked = () => activityOf(selected) || data.activities[0] || null;

  async function load() {
    data = await App.get('display.control', params);
    state = data.state;
    if (!activityOf(selected)) selected = state.activity_id || data.activities.find((a) => a.status === 'open')?.id || data.activities[0]?.id || 0;
    screen = await App.get('display.screen', params);
    render();
  }

  /** Puts a change on air. */
  async function take(patch) {
    if (busy) return;
    busy = true;
    view.classList.add('is-sending');
    try {
      const r = await App.post('display.set', { ...params, state: patch });
      state = r.state;
      lastVersion = r.v;
      App.$('.ctl-frame iframe', view)?.contentWindow?.postMessage('display:refresh', location.origin);
      screen = await App.get('display.screen', params);
      render();
    } catch (err) {
      App.fail(err);
    } finally {
      busy = false;
      view.classList.remove('is-sending');
    }
  }

  const takeScene = (id) => {
    const scene = sceneOf(id);
    const a = picked();
    if (scene.activity && !a) return App.toast('Add an activity to this event first.', 'error');
    const patch = { scene: id };
    if (scene.activity) {
      patch.activity_id = a.id;
      if (a.id !== state.activity_id) patch.contestant_id = 0;
    }
    if (id === 'reveal') patch.reveal_step = 0; // every reveal starts from the top
    if (id === 'message') {
      patch.title = App.$('[data-msg-title]', view)?.value || state.title;
      patch.text = App.$('[data-msg-text]', view)?.value || '';
      if (!patch.title.trim()) return App.toast('Type the message title first.', 'error'), App.$('[data-msg-title]', view)?.focus();
    }
    return take(patch);
  };

  const stepContestant = (delta) => {
    const a = picked();
    if (!a || !a.contestants.length) return;
    const onStage = state.scene === 'spotlight' && state.activity_id === a.id ? screen.contestant?.id : null;
    const i = a.contestants.findIndex((c) => c.id === onStage);
    const next = a.contestants[Math.max(0, Math.min(a.contestants.length - 1, i < 0 ? 0 : i + delta))];
    take({ scene: 'spotlight', activity_id: a.id, contestant_id: next.id });
  };

  const revealNext = () => {
    if (state.scene !== 'reveal') return takeScene('reveal');
    if (screen.step >= screen.total_places) return App.toast('Every place has been announced.', 'info');
    take({ reveal_step: state.reveal_step + 1 });
  };

  /* ------------------------------------------------------------------ view */

  function onAirText() {
    const effect = EFFECTS.find((f) => f.id === state.effect && f.id !== 'none');
    return sceneText() + (effect ? ' · Effect: ' + effect.label : '');
  }

  function sceneText() {
    const s = sceneOf(state.scene);
    const a = screen.activity ? ' · ' + screen.activity.title : '';
    if (state.scene === 'spotlight' && screen.contestant) return `${s.label}: ${screen.contestant.name}${a}`;
    if (state.scene === 'reveal') return `${s.label}${a} · ${screen.step} of ${screen.total_places} announced`;
    if (state.scene === 'message') return `${s.label}: ${state.title}`;
    return s.label + a;
  }

  /** Page frame, drawn once so the screen monitor never reloads. */
  function renderFrame() {
    view.innerHTML = `
      <div class="crumbs">${isFacilitator ? '' : `<a href="${App.page('dashboard.html')}">Events</a> / `}<a href="${App.page('event.html?id=' + data.event.id)}">${esc(data.event.title)}</a> / Big screen</div>
      <section class="page-head">
        <div>
          <div class="eyebrow">Big screen control</div>
          <h1>${esc(data.event.title)}</h1>
          <div class="meta"><span class="onair-pill"><i></i>On air</span><span data-onair></span></div>
        </div>
        <div class="row">
          <a class="btn btn-primary" href="${screenUrl}" target="coc-screen" rel="opener">${App.icon('external')} Open screen</a>
          <button class="btn" data-copy-link title="Open this link on the computer connected to the TV">${App.icon('copy')} Copy screen link</button>
        </div>
      </section>

      <div class="ctl-grid">
        <aside class="ctl-monitor">
          <div class="card">
            <div class="card-head"><h3>${App.icon('monitor')} Screen</h3><span class="muted small">What the audience sees</span></div>
            <div class="ctl-frame"><iframe src="${screenUrl}${screenUrl.includes('?') ? '&' : '?'}preview=1" title="Big screen preview" tabindex="-1"></iframe></div>
            <div class="card-body ctl-help">
              <strong>Setup</strong>
              <ol>
                <li>Connect the TV / projector as a second screen.</li>
                <li><strong>Open screen</strong>, drag the window to the TV and press <kbd>F</kbd> for full screen.</li>
                <li>Run the show from here. Keys: <kbd>1</kbd>–<kbd>8</kbd> scenes · <kbd>←</kbd> <kbd>→</kbd> contestants · <kbd>Space</kbd> reveal next · <kbd>C</kbd> confetti · <kbd>B</kbd> blackout.</li>
              </ol>
            </div>
          </div>
        </aside>

        <div class="ctl-main" data-panels></div>
      </div>`;
    App.$('[data-copy-link]', view).addEventListener('click', () => App.copy(screenUrl));
  }

  /** The switcher panels: redrawn after every change. */
  function render() {
    if (!App.$('[data-panels]', view)) renderFrame();
    const a = picked();
    const onStage = state.scene === 'spotlight' && screen.contestant ? screen.contestant.id : null;
    const revealing = state.scene === 'reveal' && a && state.activity_id === a.id;
    App.$('[data-onair]', view).textContent = onAirText();
    App.$('[data-panels]', view).innerHTML = `
          <div class="card">
            <div class="card-head"><h3>Scenes</h3>
              <label class="ctl-activity"><span>Activity</span>
                <select data-activity ${data.activities.length ? '' : 'disabled'}>
                  ${data.activities.length ? data.activities.map((x) => `<option value="${x.id}" ${a && x.id === a.id ? 'selected' : ''}>${esc(x.title)}${x.status === 'open' ? ' (live)' : x.status === 'closed' ? ' (final)' : ''}</option>`).join('') : '<option>No activities yet</option>'}
                </select></label>
            </div>
            <div class="card-body">
              <div class="ctl-scenes">
                ${SCENES.map((s, i) => `
                  <button type="button" class="ctl-scene ${state.scene === s.id ? 'on-air' : ''}" data-scene="${s.id}">
                    <kbd>${i + 1}</kbd>${App.icon(s.icon)}<strong>${s.label}</strong><small>${s.hint}</small>
                  </button>`).join('')}
              </div>
              <label class="check ctl-toggle"><input type="checkbox" data-scores ${state.show_scores ? 'checked' : ''}>
                <span><strong>Show scores on screen</strong> <span class="muted small">— standings and winners show their result line (average, points, time…). Off: names and places only.</span></span></label>
            </div>
          </div>

          <div class="card">
            <div class="card-head"><h3>${App.icon('sparkle')} Effects</h3><span class="muted small">Play over any scene until you switch them off</span></div>
            <div class="card-body">
              <div class="ctl-effects">
                ${EFFECTS.map((f) => `
                  <button type="button" class="ctl-effect ${state.effect === f.id ? 'on-air' : ''}" data-effect="${f.id}">
                    ${App.icon(f.icon)}<strong>${f.label}</strong><small>${f.hint}</small>
                  </button>`).join('')}
              </div>
              <div class="row ctl-burst">
                <button type="button" class="btn btn-primary" data-burst>${App.icon('sparkle')} Confetti burst <kbd>C</kbd></button>
                <span class="muted small">Fires the confetti cannons once. They also fire by themselves when the champion is revealed.</span>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-head"><h3>${App.icon('spotlight')} On stage${a ? ' · ' + esc(a.title) : ''}</h3>
              <div class="row"><button class="btn btn-sm" data-prev>${App.icon('chevronLeft')} Previous</button><button class="btn btn-sm btn-primary" data-next>Next ${App.icon('chevronRight')}</button></div></div>
            <div class="card-body">
              ${a && a.contestants.length ? `<div class="ctl-lineup">${a.contestants.map((c) => `
                <div class="ctl-entry-wrap">
                  <button type="button" class="ctl-entry ${c.id === onStage ? 'on-air' : ''}" data-contestant="${c.id}" style="--entry:${esc(c.color || '#00461B')}">
                    ${App.avatar(c, 'md')}<span><strong>${c.number ? c.number + '. ' : ''}${esc(c.name)}</strong>${c.team && c.team !== c.name ? `<small>${esc(c.team)}</small>` : ''}</span>
                  </button>
                  <button type="button" class="ctl-bg ${c.background ? 'has-bg' : ''}" data-bg="${c.id}" title="${c.background ? 'Change' : 'Add'} the stage background of ${esc(c.name)}" aria-label="Stage background of ${esc(c.name)}">${App.icon('image')}</button>
                </div>`).join('')}</div>` : `<p class="muted">${a ? 'No contestants in this activity yet.' : 'Choose an activity.'}</p>`}
            </div>
          </div>

          <div class="ctl-two">
            <div class="card">
              <div class="card-head"><h3>${App.icon('trophy')} Winners reveal</h3></div>
              <div class="card-body stack">
                <p class="muted small">Announces the top places of <strong>${a ? esc(a.title) : 'the activity'}</strong> one at a time: 3rd, then 2nd, then the champion. The screen never receives a name before you reveal it.${a && a.status !== 'closed' ? ' <strong>This activity is not final yet.</strong>' : ''}</p>
                <div class="ctl-reveal-steps">${revealing
                  ? Array.from({ length: screen.total_places }, (_, i) => `<span class="${i < screen.step ? 'done' : ''}">${i < screen.step ? App.icon('check') : i + 1}</span>`).join('') || '<span class="muted small">No results to announce yet.</span>'
                  : '<span class="muted small">Not on air.</span>'}</div>
                <div class="row">
                  <button class="btn btn-primary" data-reveal ${revealing && screen.step >= screen.total_places ? 'disabled' : ''}>${App.icon('trophy')} ${revealing ? (screen.step >= screen.total_places ? 'All announced' : 'Reveal next') : 'Start reveal'} <kbd>Space</kbd></button>
                  ${revealing ? '<button class="btn" data-reveal-reset>Start over</button>' : ''}
                </div>
              </div>
            </div>
            <div class="card">
              <div class="card-head"><h3>${App.icon('message')} Message</h3></div>
              <div class="card-body stack">
                <label class="field"><span>Title</span><input data-msg-title maxlength="120" value="${esc(state.title)}" placeholder="e.g. Intermission"></label>
                <label class="field"><span>Text <em>(optional)</em></span><input data-msg-text maxlength="400" value="${esc(state.text)}" placeholder="e.g. The next segment starts at 3:00 PM"></label>
                <div class="row"><button class="btn btn-primary" data-scene="message">${App.icon('message')} Show message</button></div>
              </div>
            </div>
          </div>`;
    bind();
  }

  function bind() {
    const on = (sel, fn, ev = 'click') => App.$$(sel, view).forEach((el) => el.addEventListener(ev, (e) => fn(el, e)));
    on('[data-scene]', (b) => takeScene(b.dataset.scene));
    on('[data-contestant]', (b) => take({ scene: 'spotlight', activity_id: picked().id, contestant_id: Number(b.dataset.contestant) }));
    on('[data-bg]', (b) => backgroundDialog(picked().contestants.find((c) => c.id === Number(b.dataset.bg))));
    on('[data-prev]', () => stepContestant(-1));
    on('[data-next]', () => stepContestant(1));
    on('[data-reveal]', revealNext);
    on('[data-reveal-reset]', () => take({ reveal_step: 0 }));
    on('[data-scores]', (el) => take({ show_scores: el.checked }), 'change');
    on('[data-effect]', (b) => take({ effect: b.dataset.effect }));
    on('[data-burst]', burst);
    on('[data-activity]', (el) => {
      selected = Number(el.value);
      // on stage, standings and bracket follow the new choice at once; a reveal waits for "Start reveal"
      if (['spotlight', 'standings', 'bracket'].includes(state.scene)) takeScene(state.scene);
      else render();
    }, 'change');
  }

  /** Upload / remove the On-stage background of one contestant (each contestant has their own). */
  async function backgroundDialog(target) {
    const current = target.background;
    const targetParams = { ...params, contestant_id: target.id };
    await App.modal({
      title: 'Stage background · ' + target.name,
      submitText: null,
      cancelText: 'Close',
      body: `
        <div class="stack">
          <p class="muted small">Shown behind <strong>${esc(target.name)}</strong> only, whenever they are On stage. Each contestant has their own background; without one, their colour fills the screen.</p>
          <div class="ctl-bg-preview" data-preview>${current ? `<img src="${esc(App.photoUrl(current))}" alt="">` : '<span>No background yet</span>'}</div>
          <label class="dropzone">
            ${App.icon('image')}<strong>${current ? 'Replace the picture' : 'Choose a picture'}</strong>
            <span class="muted small">JPG, PNG or WEBP · a wide (16:9) picture fills the screen best</span>
            <input type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-bg-file>
          </label>
          ${current ? `<div class="row"><button type="button" class="btn btn-danger" data-bg-remove>${App.icon('trash')} Remove background</button></div>` : ''}
        </div>`,
      onOpen: (form, dlg, close) => {
        const preview = form.querySelector('[data-preview]');
        form.querySelector('[data-bg-file]').addEventListener('change', async (e) => {
          const file = e.target.files[0];
          if (!file) return;
          preview.innerHTML = '<span>Uploading… <b data-pct>0%</b></span>';
          try {
            const prepared = await App.compressImage(file);
            const fd = new FormData();
            Object.entries(targetParams).forEach(([k, v]) => fd.append(k, v));
            fd.append('file', prepared, prepared.name || file.name);
            await App.upload('display.background', fd, (pct) => { const p = preview.querySelector('[data-pct]'); if (p) p.textContent = pct + '%'; });
            App.toast('Background saved.', 'success');
            close(true);
          } catch (err) {
            App.fail(err);
            preview.innerHTML = current ? `<img src="${esc(App.photoUrl(current))}" alt="">` : '<span>No background yet</span>';
          }
        });
        form.querySelector('[data-bg-remove]')?.addEventListener('click', async (e) => {
          App.setLoading(e.currentTarget, true);
          try {
            App.toast((await App.post('display.background_remove', targetParams)).message, 'success');
            close(true);
          } catch (err) {
            App.fail(err);
            App.setLoading(e.currentTarget, false);
          }
        });
      },
    });
    await load();
    App.$('.ctl-frame iframe', view)?.contentWindow?.postMessage('display:refresh', location.origin);
  }

  // keyboard: the operator's hands stay on the keys during the show
  document.addEventListener('keydown', (e) => {
    if (!data || e.ctrlKey || e.metaKey || e.altKey || e.target.closest('input, textarea, select, dialog')) return;
    const n = Number(e.key);
    if (n >= 1 && n <= SCENES.length) { e.preventDefault(); takeScene(SCENES[n - 1].id); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); stepContestant(1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); stepContestant(-1); }
    else if (e.key === ' ') { e.preventDefault(); revealNext(); }
    else if (e.key === 'b' || e.key === 'B') { e.preventDefault(); takeScene(state.scene === 'blank' ? 'idle' : 'blank'); }
    else if (e.key === 'c' || e.key === 'C') { e.preventDefault(); burst(); }
  });

  try {
    if (!eventId) throw new Error('No event selected.');
    await load();
    // another operator, new contestants or results: refresh the panel
    App.poll(async () => {
      if (busy || document.activeElement?.matches('input, select')) return;
      const { v } = await App.get('display.version', params);
      if (lastVersion !== null && v !== lastVersion) await load();
      lastVersion = v;
    }, 3000);
  } catch (err) {
    view.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
  }
})();
