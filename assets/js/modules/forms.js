/* Reusable modal forms (event, activity, team, contestant, access code). */
(function () {
  'use strict';
  const { esc } = App;

  const Forms = (window.Forms = {});

  const toLocalInput = (s) => (s ? String(s).replace(' ', 'T').slice(0, 16) : '');

  Forms.NATURES = ['Academic', 'Sports', 'Cultural', 'Pageant', 'Arts & Literary', 'Music & Dance', 'Technology / IT', 'Leadership', 'Community / Outreach', 'Religious', 'Wellness'];
  Forms.STATUSES = ['draft', 'upcoming', 'ongoing', 'completed', 'cancelled'];

  /** Nature of Activity select with an "Other…" text box. */
  Forms.natureField = (value, label = 'Nature of Activity') => {
    const known = !value || Forms.NATURES.includes(value);
    return `
      <label class="field" data-nature><span>${esc(label)}</span>
        <select name="nature_choice" required>
          <option value="">Select…</option>
          ${Forms.NATURES.map((n) => `<option ${value === n ? 'selected' : ''}>${esc(n)}</option>`).join('')}
          <option value="__other" ${known ? '' : 'selected'}>Other…</option>
        </select>
        <input name="nature_other" maxlength="80" placeholder="Type the nature of activity" value="${known ? '' : esc(value)}" ${known ? 'hidden disabled' : 'required'} style="margin-top:8px">
      </label>`;
  };

  Forms.bindNature = (form, onChange) => {
    const sel = form.querySelector('[name=nature_choice]');
    const other = form.querySelector('[name=nature_other]');
    sel.addEventListener('change', () => {
      other.hidden = sel.value !== '__other';
      other.disabled = other.hidden;
      other.required = !other.hidden;
      if (!other.hidden) other.focus();
      onChange && onChange(sel.value);
    });
  };

  /** Moves nature_choice / nature_other into data.nature. */
  Forms.takeNature = (data) => {
    if ('nature_choice' in data) data.nature = data.nature_choice === '__other' ? (data.nature_other || '') : data.nature_choice;
    delete data.nature_choice;
    delete data.nature_other;
    return data;
  };

  /** Optional criteria sheet dropzone (photo / PDF / DOCX), scanned after the form is saved. */
  Forms.criteriaField = (a = {}, attrs = '') => `
    <div class="field span-2" data-criteria-field ${attrs}>
      <span class="field-label">${a.has_file ? 'Replace the criteria sheet' : 'Criteria sheet'} <em>(optional)</em></span>
      <label class="dropzone compact" data-crit-drop>
        ${App.icon('scan')}
        <strong data-crit-title>Upload the criteria: photo, PDF or Word</strong>
        <span class="muted small" data-crit-sub>Drag &amp; drop or tap to choose. It is scanned automatically when you save, then you review it.</span>
        <input type="file" accept="${App.DOC_ACCEPT}" data-crit-file>
      </label>
      <div class="row" style="margin-top:8px" data-crit-actions hidden>
        <button type="button" class="btn btn-sm btn-ghost" data-crit-clear>${App.icon('x')} Remove file</button>
      </div>
      <p class="hint" data-crit-status>${a.has_file ? 'Current file: ' + esc(a.criteria_file_name || 'uploaded') + '. Leave empty to keep it.' : 'You can also upload or type the criteria later in the Criteria tab.'}</p>
    </div>`;

  /** Wires the criteria dropzone; returns a getter for the picked file. */
  Forms.bindCriteriaField = (form) => {
    let file = null;
    const drop = form.querySelector('[data-crit-drop]');
    const input = form.querySelector('[data-crit-file]');
    const title = form.querySelector('[data-crit-title]');
    const sub = form.querySelector('[data-crit-sub]');
    const actions = form.querySelector('[data-crit-actions]');
    const titleText = title.textContent;
    const subText = sub.textContent;
    const size = (n) => (n < 1048576 ? Math.max(1, Math.round(n / 1024)) + ' KB' : (n / 1048576).toFixed(1) + ' MB');
    const pick = (next) => {
      if (next && next.size > Forms.CRITERIA_MAX_MB * 1024 * 1024) {
        App.toast(`The file is larger than ${Forms.CRITERIA_MAX_MB} MB.`, 'error');
        next = null;
      }
      file = next || null;
      drop.classList.toggle('has-file', !!file);
      title.textContent = file ? file.name : titleText;
      sub.textContent = file ? `${size(file.size)} · scanned when you save` : subText;
      actions.hidden = !file;
      if (!file) input.value = '';
    };
    input.addEventListener('change', () => pick(input.files[0]));
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('drag'); }));
    drop.addEventListener('drop', (e) => e.dataTransfer.files[0] && pick(e.dataTransfer.files[0]));
    form.querySelector('[data-crit-clear]').addEventListener('click', () => pick(null));
    // a hidden field (other format chosen) never uploads
    return () => (form.querySelector('[data-criteria-field]').hidden ? null : file);
  };

  /** One competition vs. several activities. */
  Forms.STRUCTURES = {
    single: { label: 'One competition', icon: 'trophy', text: 'The event itself is the contest, e.g. a pageant, a battle of the bands or a quiz bee. No activities to add.' },
    multi: { label: 'Multiple activities', icon: 'grid', text: 'Several competitions under one event, e.g. Foundation Day or IT Days: Mobile Legends, CODM, chess, a programming contest, dance — each decided its own way. Departments or tribes collect overall points.' },
  };

  /** Create or edit an event. owners: admin-only list of staff who can be Project Head. */
  Forms.event = (event = null, owners = [], opts = {}) => {
    const e = event || { status: 'upcoming' };
    const activityCount = (opts.activities || []).length;
    const lockedToMulti = activityCount > 1;
    const structure = e.structure === 'single' && !lockedToMulti ? 'single' : (event ? 'multi' : (e.structure || 'multi'));
    const competition = (e.structure === 'single' && opts.activities && opts.activities[0]) || {};
    let points = [15, 12, 10, 8, 6, 5, 4, 3, 2, 1];
    try { if (e.placement_points) points = JSON.parse(e.placement_points); } catch (_) { /* standard points */ }
    // a new event starts without overall standings; existing events keep theirs
    const hasOverall = event ? Number(e.has_overall ?? 1) === 1 : false;
    const pointsMode = event ? e.points_mode || 'list' : 'countdown';
    const groupCount = (opts.teams || []).length;
    let getCriteriaFile = () => null;
    const me = App.user || {};
    const heads = owners.filter((o) => o.role === 'program_head');
    const others = owners.filter((o) => o.role !== 'program_head');
    const option = (o) => `<option value="${o.id}" ${Number(e.owner_id || (!event && me.id)) === Number(o.id) ? 'selected' : ''}>${esc(o.name)}${o.program ? ' — ' + esc(o.program) : ''}</option>`;
    const headField = owners.length
      ? `<label class="field"><span>Project Head</span><select name="owner_id" required>
          ${heads.length ? `<optgroup label="Program heads">${heads.map(option).join('')}</optgroup>` : ''}
          ${others.length ? `<optgroup label="Administrators">${others.map(option).join('')}</optgroup>` : ''}
        </select><p class="hint">Manages this event and its activities.</p></label>`
      : `<div class="field"><span class="field-label">Project Head</span>
          <input value="${esc(event ? (e.owner_name || me.name) : me.name)}" readonly></div>`;
    return App.modal({
      title: event ? 'Edit event' : 'New event',
      wide: true,
      submitText: event ? 'Save changes' : 'Create event',
      body: `
        <div class="form-grid two">
          <label class="field span-2"><span>Event title</span><input name="title" required maxlength="200" value="${esc(e.title)}" placeholder="e.g. COC Foundation Week 2026"></label>
          <label class="field span-2"><span>Venue</span><input name="venue" required maxlength="200" value="${esc(e.venue)}" placeholder="e.g. COC Gymnasium"></label>
          <label class="field"><span>Starting date &amp; time</span><input type="datetime-local" name="start_at" required value="${esc(toLocalInput(e.start_at))}"></label>
          <label class="field"><span>End date &amp; time</span><input type="datetime-local" name="end_at" required value="${esc(toLocalInput(e.end_at))}"></label>
          ${headField}
          <label class="field"><span>Status</span><select name="status">
            ${Forms.STATUSES.map((s) => `<option value="${s}" ${e.status === s ? 'selected' : ''}>${s[0].toUpperCase() + s.slice(1)}</option>`).join('')}
          </select></label>
          <div class="field span-2"><span class="field-label">Event type</span>
            <div class="format-cards">
              ${Object.entries(Forms.STRUCTURES).map(([key, s]) => `
                <label class="format-card">
                  <input type="radio" name="structure" value="${key}" ${structure === key ? 'checked' : ''}>
                  <span class="fc-icon">${App.icon(s.icon)}</span>
                  <span class="fc-body"><strong>${s.label}</strong><span>${s.text}</span></span>
                </label>`).join('')}
            </div>
            ${lockedToMulti ? `<p class="hint">This event has ${activityCount} activities, so it stays “Multiple activities”.</p>` : ''}
          </div>
          <div class="field span-2" data-single-format><span class="field-label">How is the competition decided?</span>
            ${Forms.formatCards('default_format', e.default_format || 'score')}
            <p class="hint">Pick the one format for this competition. You set up its criteria, contestants, judges or bracket right after saving.</p>
          </div>
          <div class="field span-2" data-multi-info>
            <div class="multi-explainer">
              <strong>${App.icon('grid')} Each activity is decided its own way</strong>
              <p>You don't choose one format for the whole event. ${event ? 'On the event page, use' : 'Right after saving, you get'} <b>Add activities</b> to list them all at once and pick how each one is decided:</p>
              <ul>${Object.values(Forms.FORMATS).map((f) => `<li>${App.icon(f.icon)}<span><b>${f.label}</b> — ${esc(f.examples)}</span></li>`).join('')}</ul>
            </div>
          </div>
          <div class="span-2 form-grid two" data-single-only style="margin:0">
            ${Forms.natureField(competition.nature)}
          </div>
          ${Forms.criteriaField(competition, 'data-single-score')}
          <label class="field span-2"><span>Description <em>(optional)</em></span><textarea name="description" rows="2">${esc(e.description)}</textarea></label>
          <div class="field span-2 overall-box">
            <label class="check overall-switch"><input type="checkbox" name="has_overall" ${hasOverall ? 'checked' : ''}>
              <span><strong>${App.icon('trophy')} Overall standings</strong>
                <span class="muted">Groups — departments, tribes, colleges — compete, and every placing of their players and teams earns points for them. The group with the most points wins the event.</span></span></label>
            <div data-overall-on>
              ${event ? `<p class="hint">${groupCount} group${groupCount === 1 ? '' : 's'} in this event — add, rename or remove them in the <strong>Groups</strong> tab of the event.</p>`
                : `<label class="field"><span>Groups <em>(one per line — you can add more later)</em></span>
                  <textarea name="groups_text" rows="4" placeholder="Academia&#10;Jujutsu&#10;Titans&#10;Hydra"></textarea></label>`}
              <div class="field"><span class="field-label">Points for each placing</span>
                <label class="check"><input type="radio" name="points_mode" value="countdown" ${pointsMode === 'countdown' ? 'checked' : ''}>
                  <span><strong>Countdown</strong> <span class="muted">— 1st place gets as many points as there are groups, each place one less, and everyone who places gets at least 1 (8 tribes: 8, 7, 6 … 1).</span></span></label>
                <label class="check"><input type="radio" name="points_mode" value="list" ${pointsMode === 'list' ? 'checked' : ''}>
                  <span><strong>My own points per place</strong> <span class="muted">— e.g. 15, 12, 10, 8, 6, 5, 4, 3, 2, 1.</span></span></label>
              </div>
              <div class="form-grid two" data-points-list style="margin:0">
                <label class="field"><span>Points for 1st, 2nd, 3rd… place</span>
                  <input name="placement_points" value="${esc(points.join(', '))}" placeholder="15, 12, 10, 8, 6, 5, 4, 3, 2, 1" pattern="[0-9., /]+" required>
                  <p class="hint">From 1st place down, separated by commas. <button type="button" class="link-btn" data-points="15, 12, 10, 8, 6, 5, 4, 3, 2, 1">Use 15 … 1 (10 places)</button> · <button type="button" class="link-btn" data-points="10, 7, 5">Use 10, 7, 5</button></p></label>
                <label class="field"><span>Points for every place after those</span>
                  <input type="number" name="participation_points" min="0" step="0.5" value="${esc(Number(e.participation_points ?? 1))}">
                  <p class="hint">So even the last place earns something.</p></label>
              </div>
            </div>
            <p class="hint" data-overall-off>No overall: participants don't need a group. Each activity simply ranks its solo players or teams (a team lists its members).</p>
          </div>
        </div>`,
      onOpen: (form) => {
        if (lockedToMulti) form.querySelector('[name=structure][value=single]').disabled = true;
        const singleOnly = form.querySelector('[data-single-only]');
        const criteria = form.querySelector('[data-single-score]');
        const sync = () => {
          const single = form.querySelector('[name=structure]:checked')?.value === 'single';
          const format = form.querySelector('[name=default_format]:checked')?.value || 'score';
          form.querySelectorAll('[name=structure]').forEach((r) => r.closest('.format-card').classList.toggle('active', r.checked));
          // several activities: no event-wide format, every activity picks its own
          form.querySelector('[data-single-format]').hidden = !single;
          form.querySelectorAll('[name=default_format]').forEach((r) => (r.disabled = !single));
          form.querySelector('[data-multi-info]').hidden = single;
          singleOnly.hidden = !single;
          singleOnly.querySelectorAll('select, input').forEach((el) => { el.disabled = !single || (el.name === 'nature_other' && el.hidden); });
          criteria.hidden = !(single && format === 'score');
        };
        form.querySelectorAll('[name=structure]').forEach((r) => r.addEventListener('change', sync));
        Forms.bindFormatCards(form, 'default_format', sync);

        // overall standings: groups and points only when switched on
        const overall = form.querySelector('[name=has_overall]');
        const syncOverall = () => {
          const on = overall.checked;
          const list = form.querySelector('[name=points_mode]:checked')?.value !== 'countdown';
          form.querySelector('[data-overall-on]').hidden = !on;
          form.querySelector('[data-overall-off]').hidden = on;
          form.querySelector('[data-points-list]').hidden = !on || !list;
          form.querySelectorAll('[data-overall-on] input, [data-overall-on] textarea').forEach((el) => {
            el.disabled = !on || (el.closest('[data-points-list]') && !list);
          });
        };
        overall.addEventListener('change', syncOverall);
        form.querySelectorAll('[name=points_mode]').forEach((r) => r.addEventListener('change', syncOverall));
        form.querySelectorAll('[data-points]').forEach((b) => b.addEventListener('click', () => (form.querySelector('[name=placement_points]').value = b.dataset.points)));
        syncOverall();
        Forms.bindNature(form);
        getCriteriaFile = Forms.bindCriteriaField(form);
        const startAt = form.querySelector('[name=start_at]');
        const endAt = form.querySelector('[name=end_at]');
        startAt.addEventListener('change', () => {
          endAt.min = startAt.value;
          if (!endAt.value || endAt.value < startAt.value) endAt.value = startAt.value.slice(0, 10) + 'T17:00';
        });
      },
      onSubmit: async (data, form) => {
        Forms.takeNature(data);
        if ('groups_text' in data) {
          data.groups = String(data.groups_text).split(/\r?\n/).map((g) => g.trim()).filter(Boolean);
          delete data.groups_text;
        }
        if (data.has_overall && !event && !(data.groups || []).length && !(await App.confirm({
          title: 'No groups yet?',
          message: 'The overall standings need groups (departments, tribes…). You can add them later in the Groups tab — participants cannot be added until then.',
          confirmText: 'Continue without groups',
        }))) return false;
        const criteriaFile = data.structure === 'single' && data.default_format === 'score' ? getCriteriaFile() : null;
        const r = await App.post('events.save', { ...data, id: event ? event.id : 0 });
        App.toast(r.message, 'success');
        if (criteriaFile && r.activity_id) {
          // the event is saved: never throw from here, or a retry would create it twice
          r.criteriaScanned = await Forms.scanCriteria(r.activity_id, criteriaFile, form.querySelector('[data-crit-status]'));
        }
        return r;
      },
    });
  };

  /** Archive an event: hidden from the list, codes stop working, changes locked. Resolves true when archived. */
  Forms.archiveEvent = async (event) => {
    const ok = await App.modal({
      title: 'Archive this event?',
      submitText: 'Archive event',
      body: `
        <div class="danger-summary neutral">
          <span class="ds-icon">${App.icon('archive')}</span>
          <div><strong>${esc(event.title)}</strong><div class="muted small">Nothing is deleted. You can restore it at any time.</div></div>
        </div>
        <ul class="effect-list">
          <li>${App.icon('eye')} It moves to the <strong>Archived</strong> tab of the events list.</li>
          <li>${App.icon('key')} Judges' and facilitators' access codes stop working.</li>
          <li>${App.icon('lock')} Activities, scores, teams and codes are locked from changes.</li>
          <li>${App.icon('print')} Results stay viewable and printable.</li>
        </ul>`,
      onSubmit: async () => {
        const r = await App.post('events.archive', { id: event.id });
        App.toast(r.message, 'success', 5000);
        return true;
      },
    });
    return ok === true;
  };

  Forms.restoreEvent = async (event) => {
    const r = await App.post('events.restore', { id: event.id });
    App.toast(r.message, 'success');
    return true;
  };

  /** Permanently delete an event; the user must type its title. Resolves true when deleted. */
  Forms.deleteEvent = async (event) => {
    let preview;
    try {
      preview = await App.get('events.delete_preview', { id: event.id });
    } catch (err) {
      App.fail(err);
      return false;
    }
    const c = preview.counts || {};
    const line = (n, one, many, icon) => `<li>${App.icon(icon)} <strong>${n}</strong> ${n === 1 ? one : many}</li>`;
    const ok = await App.modal({
      title: 'Delete this event?',
      danger: true,
      submitText: 'Delete permanently',
      body: `
        <div class="danger-summary">
          <span class="ds-icon">${App.icon('alert')}</span>
          <div><strong>This cannot be undone.</strong><div class="small">Everything in <strong>${esc(preview.title)}</strong> is removed for good. To keep it but hide it, archive it instead.</div></div>
        </div>
        <ul class="effect-list">
          ${line(Number(c.activities), 'activity with its criteria, brackets and results', 'activities with their criteria, brackets and results', 'list')}
          ${line(Number(c.contestants), 'contestant', 'contestants', 'users')}
          ${line(Number(c.scores), 'judge score', 'judge scores', 'trophy')}
          ${line(Number(c.teams), 'team with its logo', 'teams with their logos', 'grid')}
          ${line(Number(c.codes), 'access code', 'access codes', 'key')}
          <li>${App.icon('file')} All uploaded files (criteria sheets, pictures, the event document)</li>
        </ul>
        <label class="field" style="margin-top:14px"><span>Type <strong class="confirm-title">${esc(preview.title)}</strong> to confirm</span>
          <input name="confirm_title" autocomplete="off" spellcheck="false" placeholder="Event title"></label>
        <p class="hint">The activity log keeps a record that the event was deleted.</p>`,
      onOpen: (form) => {
        const input = form.querySelector('[name=confirm_title]');
        const btn = form.querySelector('[type=submit]');
        const norm = (s) => s.trim().replace(/\s+/g, ' ').toLowerCase();
        const sync = () => (btn.disabled = norm(input.value) !== norm(preview.title));
        input.addEventListener('input', sync);
        sync();
        setTimeout(() => input.focus(), 50);
      },
      onSubmit: async (data) => {
        await App.post('events.delete', { id: event.id, confirm_title: data.confirm_title });
        return true;
      },
    });
    return ok === true;
  };

  /** Generate several access codes at once ("Judge 1" … "Judge N"). */
  Forms.bulkCodes = (eventId, activities, role = 'judge') =>
    App.modal({
      title: 'Give access codes',
      submitText: 'Generate codes',
      body: `
        <div class="form-grid two">
          <label class="field"><span>Who are they for?</span>
            <select name="role"><option value="judge" ${role === 'judge' ? 'selected' : ''}>Judges</option><option value="facilitator" ${role === 'facilitator' ? 'selected' : ''}>Facilitators</option></select></label>
          <label class="field"><span>How many?</span><input type="number" name="count" min="1" max="50" value="${role === 'judge' ? 3 : 1}" required></label>
          <label class="field span-2"><span>Name prefix</span><input name="prefix" maxlength="100" value="${role === 'judge' ? 'Judge' : 'Facilitator'}" data-prefix>
            <p class="hint">Codes are named “Judge 1”, “Judge 2”… continuing after existing ones. You can rename them later.</p></label>
          <div class="field span-2" data-acts ${role === 'judge' ? '' : 'hidden'}>
            <span class="field-label">Activities these judges will score</span>
            ${activities.length ? `<label class="check" style="padding:4px 0 8px"><input type="checkbox" data-all checked><span><strong>All activities</strong></span></label>
              ${activities.map((a) => `<label class="check" style="padding:4px 0"><input type="checkbox" name="activity_ids[]" value="${a.id}" checked><span>${esc(a.title)}</span></label>`).join('')}`
              : '<p class="muted small">No activities yet — assign them later.</p>'}
          </div>
        </div>`,
      onOpen: (form) => {
        const roleSel = form.querySelector('[name=role]');
        roleSel.addEventListener('change', () => {
          const judge = roleSel.value === 'judge';
          form.querySelector('[data-acts]').hidden = !judge;
          const prefix = form.querySelector('[data-prefix]');
          if (['Judge', 'Facilitator'].includes(prefix.value)) prefix.value = judge ? 'Judge' : 'Facilitator';
        });
        const all = form.querySelector('[data-all]');
        all?.addEventListener('change', () => form.querySelectorAll('[name="activity_ids[]"]').forEach((c) => (c.checked = all.checked)));
      },
      onSubmit: async (data) => {
        const r = await App.post('codes.bulk', { event_id: eventId, role: data.role, count: data.count, prefix: data.prefix, activity_ids: data.activity_ids || [] });
        App.toast(r.message, 'success');
        return r;
      },
    }).then((r) => {
      if (!r || !r.codes) return r;
      return App.modal({
        title: 'Access codes generated',
        cancelText: 'Done',
        submitText: null,
        body: `
          <p>Give each person their code. They sign in on the <a href="${App.url('index.html')}" target="_blank" rel="noopener">homepage</a> with it. No account needed.</p>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Name</th><th>Access code</th></tr></thead>
            <tbody>${r.codes.map((c) => `<tr><td><strong>${esc(c.name)}</strong></td><td class="code-value" style="font-size:18px">${esc(c.code)}</td></tr>`).join('')}</tbody>
          </table></div>
          <p style="margin-top:14px"><a class="btn btn-primary" href="${App.page('print.html?type=codes&id=' + eventId)}" target="_blank">${App.icon('print')} Print code slips</a></p>`,
      }).then(() => r);
    });

  /** How an activity is decided. */
  Forms.FORMATS = {
    score: { label: 'Score-based', icon: 'list', text: 'Judges score each contestant using the criteria. Ranked by average score.',
      examples: 'judges score with criteria: singing, dancing, pageant, poster making, cosplay' },
    bracket: { label: 'Bracket', icon: 'trophy', text: 'Single elimination. Winners advance round by round to the championship.',
      examples: 'knock-out matches: Mobile Legends, CODM, Valorant, basketball, volleyball' },
    round_robin: { label: 'Round robin', icon: 'grid', text: 'Everyone plays everyone. Ranked by wins, draws and score difference.',
      examples: 'everyone plays everyone: chess, table tennis, small leagues' },
    ranking: { label: 'Ranking', icon: 'history', text: 'Enter each result — points, time or placement — and it ranks automatically.',
      examples: 'one result each (points or time): quiz bee, battle of the wits, IP subnetting, crimping, programming contest by problems solved' },
  };

  /** The four format choices as selectable cards (radio name = inputName). */
  Forms.formatCards = (inputName, selected) => `
    <div class="format-cards">
      ${Object.entries(Forms.FORMATS).map(([key, f]) => `
        <label class="format-card ${selected === key ? 'active' : ''}">
          <input type="radio" name="${inputName}" value="${key}" ${selected === key ? 'checked' : ''}>
          <span class="fc-icon">${App.icon(f.icon)}</span>
          <span class="fc-body"><strong>${f.label}</strong><span>${f.text}</span><em class="fc-examples">e.g. ${esc(f.examples.replace(/^[^:]*:\s*/, ''))}</em></span>
        </label>`).join('')}
    </div>`;

  /** Keeps the .active highlight in sync with the checked card. */
  Forms.bindFormatCards = (form, inputName, onChange) => {
    const sync = () => {
      form.querySelectorAll(`[name="${inputName}"]`).forEach((r) => r.closest('.format-card').classList.toggle('active', r.checked));
      onChange && onChange(form.querySelector(`[name="${inputName}"]:checked`)?.value);
    };
    form.querySelectorAll(`[name="${inputName}"]`).forEach((r) => r.addEventListener('change', sync));
    sync();
  };

  /**
   * Common competitions with the way they are usually decided. Ranking activities also say
   * what the result is: points (highest wins) or time (fastest wins).
   */
  Forms.ACTIVITY_PRESETS = [
    { group: 'Esports', items: [
      ['Mobile Legends', 'bracket', 'Sports'], ['Call of Duty Mobile (CODM)', 'bracket', 'Sports'], ['Valorant', 'bracket', 'Sports'], ['Tekken', 'bracket', 'Sports'],
    ] },
    { group: 'Sports & mind games', items: [
      ['Chess', 'round_robin', 'Sports'], ['Basketball', 'bracket', 'Sports'], ['Volleyball', 'bracket', 'Sports'], ['Table tennis', 'round_robin', 'Sports'], ['Badminton', 'bracket', 'Sports'],
    ] },
    { group: 'IT & academic contests', items: [
      ['Programming contest', 'ranking', 'Technology / IT', 'points'], ['IP subnetting contest', 'ranking', 'Technology / IT', 'points'], ['Crimping contest', 'ranking', 'Technology / IT', 'time'],
      ['Battle of the Wits', 'ranking', 'Academic', 'points'], ['Quiz bee', 'ranking', 'Academic', 'points'], ['Web design', 'score', 'Technology / IT'],
    ] },
    { group: 'Performing & arts', items: [
      ['Singing contest', 'score', 'Music & Dance'], ['Dance competition', 'score', 'Music & Dance'], ['Mr. & Ms.', 'score', 'Pageant'],
      ['Poster making', 'score', 'Arts & Literary'], ['Cosplay', 'score', 'Cultural'], ['Battle of the bands', 'score', 'Music & Dance'],
    ] },
  ];

  /**
   * Add several activities at once: one row each with its name and how it is decided.
   * Resolves { created: [ids] } when at least one was added.
   * opts: { existing: [activities], firstTime: bool }
   */
  Forms.addActivities = (eventId, opts = {}) => {
    const existing = new Set((opts.existing || []).map((a) => a.title.trim().toLowerCase()));
    const formatOptions = (sel) => Object.entries(Forms.FORMATS).map(([k, f]) => `<option value="${k}" ${sel === k ? 'selected' : ''}>${f.label}</option>`).join('');
    const row = (title = '', format = 'score', nature = '', unit = 'points') => `
      <div class="act-row" data-act-row>
        <input name="act_title" maxlength="200" placeholder="Activity name, e.g. Mobile Legends" value="${esc(title)}" aria-label="Activity name">
        <select name="act_format" aria-label="How it is decided">${formatOptions(format)}</select>
        <select name="act_unit" aria-label="What wins" class="${format === 'ranking' ? '' : 'is-off'}">
          <option value="points" ${unit === 'points' ? 'selected' : ''}>Highest points wins</option>
          <option value="time" ${unit === 'time' ? 'selected' : ''}>Fastest time wins</option>
        </select>
        <select name="act_nature" aria-label="Nature of activity">
          <option value="">Nature…</option>
          ${Forms.NATURES.map((n) => `<option ${n === nature ? 'selected' : ''}>${esc(n)}</option>`).join('')}
        </select>
        <button type="button" class="btn btn-sm btn-ghost" data-remove-row aria-label="Remove">${App.icon('x')}</button>
        <p class="act-row-hint muted small" data-row-hint></p>
      </div>`;
    return App.modal({
      title: opts.firstTime ? 'Event created — now add its activities' : 'Add activities',
      wide: true,
      submitText: 'Add activities',
      cancelText: opts.firstTime ? 'Later' : 'Cancel',
      body: `
        ${opts.firstTime ? `<div class="alert alert-success" style="margin-bottom:14px">${App.icon('check')} <span>The event is saved. List every competition in it below — each one can be decided a different way.</span></div>` : ''}
        <div class="preset-box">
          <div class="field-label">Quick add <em>(tap to add a row; you can rename it)</em></div>
          ${Forms.ACTIVITY_PRESETS.map((g) => `<div class="preset-group"><span>${esc(g.group)}</span>
            ${g.items.map(([t, f, n, u], i) => `<button type="button" class="chip-btn" data-preset="${esc(g.group)}|${i}" title="${esc(Forms.FORMATS[f].label)}">${App.icon(Forms.FORMATS[f].icon)} ${esc(t)}</button>`).join('')}</div>`).join('')}
        </div>
        <div class="act-head" aria-hidden="true"><span>Activity</span><span>How it is decided</span><span></span><span>Nature</span><span></span></div>
        <div data-act-list>${row()}</div>
        <button type="button" class="btn btn-sm" data-add-row style="margin-top:6px">${App.icon('plus')} Add another activity</button>
        <p class="hint" style="margin-top:12px">Venue, schedule, criteria sheet and rounds can be set later by opening each activity. Every activity counts toward the overall standings of the departments / tribes.</p>`,
      onOpen: (form) => {
        const list = form.querySelector('[data-act-list]');
        const syncRow = (r) => {
          const f = r.querySelector('[name=act_format]').value;
          r.querySelector('[name=act_unit]').classList.toggle('is-off', f !== 'ranking');
          r.querySelector('[data-row-hint]').textContent = Forms.FORMATS[f].examples;
        };
        const add = (...args) => {
          // fill the first empty row before adding new ones
          const empty = App.$$('[data-act-row]', list).find((r) => !r.querySelector('[name=act_title]').value.trim());
          if (empty && args.length) empty.remove();
          list.insertAdjacentHTML('beforeend', row(...args));
          syncRow(list.lastElementChild);
          if (!args.length) list.lastElementChild.querySelector('input').focus();
        };
        App.$$('[data-act-row]', list).forEach(syncRow);
        list.addEventListener('change', (e) => e.target.name === 'act_format' && syncRow(e.target.closest('[data-act-row]')));
        list.addEventListener('click', (e) => {
          if (!e.target.closest('[data-remove-row]')) return;
          e.target.closest('[data-act-row]').remove();
          if (!list.children.length) add();
        });
        form.querySelector('[data-add-row]').addEventListener('click', () => add());
        form.querySelectorAll('[data-preset]').forEach((b) => b.addEventListener('click', () => {
          const [group, i] = b.dataset.preset.split('|');
          const [t, f, n, u] = Forms.ACTIVITY_PRESETS.find((g) => g.group === group).items[Number(i)];
          add(t, f, n, u || 'points');
          b.classList.add('used');
        }));
      },
      onSubmit: async (d, form) => {
        const rows = App.$$('[data-act-row]', form).map((r) => ({
          title: r.querySelector('[name=act_title]').value.trim(),
          format: r.querySelector('[name=act_format]').value,
          unit: r.querySelector('[name=act_unit]').value,
          nature: r.querySelector('[name=act_nature]').value,
        })).filter((r) => r.title);
        if (!rows.length) { App.toast('Type at least one activity name, or tap a quick-add chip.', 'error'); return false; }
        const seen = new Set();
        for (const r of rows) {
          const key = r.title.toLowerCase();
          if (existing.has(key) || seen.has(key)) { App.toast(`“${r.title}” is listed twice or already in this event.`, 'error'); return false; }
          seen.add(key);
        }
        const created = [];
        const btn = form.querySelector('[type=submit]');
        for (const r of rows) {
          btn.textContent = `Adding ${created.length + 1} of ${rows.length}…`;
          try {
            const res = await App.post('activities.save', {
              event_id: eventId, title: r.title, format: r.format, nature: r.nature, counts_to_overall: true,
              ...(r.format === 'ranking' ? (r.unit === 'time' ? { score_label: 'Seconds', rank_direction: 'asc' } : { score_label: 'Points', rank_direction: 'desc' }) : {}),
              ...(['bracket', 'round_robin'].includes(r.format) ? { score_label: 'Points' } : {}),
            });
            created.push(res.id);
            existing.add(r.title.toLowerCase());
          } catch (err) {
            // keep what was added; the rest stays in the form to fix and retry
            App.$$('[data-act-row]', form).forEach((el) => {
              if (created.length && existing.has(el.querySelector('[name=act_title]').value.trim().toLowerCase())) el.remove();
            });
            btn.textContent = 'Add activities';
            App.toast(`${created.length ? created.length + ' added. ' : ''}“${r.title}” could not be added: ${err.message}`, 'error', 7000);
            return created.length ? { created, partial: true } : false;
          }
        }
        App.toast(`${created.length} activit${created.length === 1 ? 'y' : 'ies'} added. Open each one to finish its setup.`, 'success', 5000);
        return { created };
      },
    });
  };

  Forms.activity = (eventId, activity = null, opts = {}) => {
    const a = activity || { counts_to_overall: 1, format: Forms.FORMATS[opts.defaultFormat] ? opts.defaultFormat : 'score', third_place: 1, rank_direction: 'desc' };
    const format = a.format || 'score';
    let getCriteriaFile = () => null;
    return App.modal({
      title: opts.single ? 'Competition settings' : activity ? 'Edit activity' : (opts.firstActivity ? 'Event created — add the first activity' : 'New activity'),
      wide: true,
      submitText: activity ? 'Save changes' : 'Add activity',
      cancelText: opts.firstActivity ? 'Later' : 'Cancel',
      body: `
        ${opts.firstActivity ? `<div class="alert alert-success" style="margin-bottom:16px">${App.icon('check')} <span>Your event is saved. Now add its activities — choose <strong>Score-based</strong>, <strong>Bracket</strong>, <strong>Round robin</strong> or <strong>Ranking</strong> for each one.</span></div>` : ''}
        <div class="form-grid two">
          ${opts.single
            ? `<div class="field"><span class="field-label">Competition</span><input value="${esc(a.title)}" readonly><p class="hint">Same as the event title. Change it with Edit event.</p></div>`
            : `<label class="field"><span>Activity title</span><input name="title" required maxlength="200" value="${esc(a.title)}" placeholder="e.g. Mr. & Ms. COC, Basketball, Quiz Bee"></label>`}
          ${Forms.natureField(a.nature)}
          <div class="field span-2"><span class="field-label">How is it decided?</span>
            ${Forms.formatCards('format', format)}
            ${!activity && opts.defaultFormat ? `<p class="hint">Preselected from the event’s main format. Pick another if this activity is decided differently.</p>` : ''}
          </div>
          ${Forms.criteriaField(a, 'data-show="score"')}
          <label class="field" data-show="bracket round_robin ranking"><span>Score / result label</span>
            <input name="score_label" maxlength="40" value="${esc(a.score_label)}" placeholder="e.g. Points, Goals, Sets, Seconds"></label>
          <label class="field" data-show="ranking"><span>Which result is better?</span>
            <select name="rank_direction">
              <option value="desc" ${a.rank_direction !== 'asc' ? 'selected' : ''}>Higher is better (points, score)</option>
              <option value="asc" ${a.rank_direction === 'asc' ? 'selected' : ''}>Lower is better (time, placement)</option>
            </select></label>
          <div class="field" data-show="bracket"><span class="field-label">Bracket</span>
            <label class="check"><input type="checkbox" name="third_place" ${Number(a.third_place ?? 1) ? 'checked' : ''}><span>Play a 3rd-place match between the semifinal losers</span></label></div>
          ${Forms.scoringFields(a, opts)}
          <label class="field span-2"><span>Description <em>(optional)</em></span><textarea name="description" rows="2">${esc(a.description)}</textarea></label>
          <label class="field"><span>Venue</span><input name="venue" maxlength="200" value="${esc(a.venue)}"></label>
          <label class="field"><span>Schedule</span><input type="datetime-local" name="schedule_at" value="${esc(toLocalInput(a.schedule_at))}"></label>
          ${opts.single ? '' : `<label class="field"><span>Display order</span><input type="number" name="sort_order" min="0" value="${esc(a.sort_order || '')}" placeholder="Auto"></label>`}
          <div class="field"><span class="field-label">Overall standings</span>
            <label class="check"><input type="checkbox" name="counts_to_overall" ${Number(a.counts_to_overall) ? 'checked' : ''}>
              <span>Placements in this activity earn points for teams</span></label></div>
        </div>`,
      onOpen: (form) => {
        const sync = () => {
          const f = form.querySelector('[name=format]:checked')?.value || 'score';
          form.querySelectorAll('[data-show]').forEach((el) => (el.hidden = !el.dataset.show.split(' ').includes(f)));
          form.querySelectorAll('.format-card').forEach((c) => c.classList.toggle('active', c.querySelector('input').checked));
        };
        form.querySelectorAll('[name=format]').forEach((r) => r.addEventListener('change', sync));
        sync();
        Forms.bindScoringFields(form);

        // a criteria sheet picked here is scanned right after the activity is saved
        getCriteriaFile = Forms.bindCriteriaField(form);
        Forms.bindNature(form, (value) => {
          // Sports usually run as brackets; suggest it for a brand-new activity
          if (!activity && value === 'Sports' && form.querySelector('[name=format]:checked')?.value === 'score') {
            form.querySelector('[name=format][value=bracket]').checked = true;
            sync();
          }
        });
      },
      onSubmit: async (data, form) => {
        Forms.takeNature(data);
        const criteriaFile = getCriteriaFile();
        const r = await App.post('activities.save', { ...data, event_id: eventId, id: activity ? activity.id : 0 });
        App.toast(r.message, 'success');
        if (criteriaFile && data.format === 'score') {
          // the activity exists now: never throw from here, or a retry would create it twice
          r.criteriaScanned = await Forms.scanCriteria(r.id, criteriaFile, form.querySelector('[data-crit-status]'));
        }
        return r;
      },
    });
  };

  /**
   * Score-based activities: how the judges' scores are combined, ties, the scoring scale, and rounds
   * (a final that takes the top N of an earlier round, optionally carrying part of that score over).
   * opts: { criteria: [...] (tie-break choice), activities: [...] (previous-round choice) }
   */
  Forms.scoringFields = (a, opts = {}) => {
    const criteria = opts.criteria || [];
    const rounds = (opts.activities || []).filter((x) => (x.format || 'score') === 'score' && Number(x.id) !== Number(a.id));
    const method = a.scoring_method || 'average';
    const tie = a.tie_break || 'share';
    const source = Number(a.source_activity_id || 0);
    return `
      <details class="field span-2 scoring-box" data-show="score" ${source || method !== 'average' || tie !== 'share' || Number(a.drop_extremes) || Number(a.score_scale) ? 'open' : ''}>
        <summary class="field-label">${App.icon('list')} Scoring &amp; rounds <em>(optional)</em></summary>
        <div class="form-grid two" style="margin-top:10px">
          <label class="field"><span>Combine the judges' scores by</span>
            <select name="scoring_method">
              <option value="average" ${method === 'average' ? 'selected' : ''}>Average of the judges' totals</option>
              <option value="rank_sum" ${method === 'rank_sum' ? 'selected' : ''}>Rank sum (each judge ranks; lowest sum wins)</option>
            </select>
            <p class="hint" data-method-hint></p></label>
          <label class="field"><span>Judges enter</span>
            <select name="score_scale">
              <option value="0" ${Number(a.score_scale) ? '' : 'selected'}>Points, up to each criterion's maximum</option>
              <option value="10" ${Number(a.score_scale) ? 'selected' : ''}>A score from 0 to 10 per criterion (weighted)</option>
            </select>
            <p class="hint">With 0–10, a criterion worth 40 points turns an 8 into 32.</p></label>
          <label class="field"><span>When two contestants tie</span>
            <select name="tie_break">
              <option value="share" ${tie === 'share' ? 'selected' : ''}>They share the rank</option>
              <option value="criterion" ${tie === 'criterion' ? 'selected' : ''} ${criteria.length ? '' : 'disabled'}>Higher score in one criterion wins${criteria.length ? '' : ' (add criteria first)'}</option>
              <option value="rank_sum" ${tie === 'rank_sum' ? 'selected' : ''} data-for="average">Lower rank sum wins</option>
              <option value="average" ${tie === 'average' ? 'selected' : ''} data-for="rank_sum">Higher average wins</option>
            </select></label>
          <label class="field" data-tie-criterion><span>Tie-break criterion</span>
            <select name="tie_criterion_id">
              ${criteria.map((c) => `<option value="${c.id}" ${Number(a.tie_criterion_id) === Number(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}
            </select></label>
          <div class="field span-2"><label class="check"><input type="checkbox" name="drop_extremes" ${Number(a.drop_extremes) ? 'checked' : ''}>
            <span>Drop the highest and the lowest judge for each contestant <span class="muted">(needs 3 or more judges)</span></span></label></div>
          <label class="field"><span>Previous round</span>
            <select name="source_activity_id" ${rounds.length ? '' : 'disabled'}>
              <option value="0">None — this is a first round or a single round</option>
              ${rounds.map((r) => `<option value="${r.id}" ${source === Number(r.id) ? 'selected' : ''}>${esc(r.title)}</option>`).join('')}
            </select>
            <p class="hint">${rounds.length ? 'For finals: the top contestants of that round advance here, and it stops counting toward the overall standings.' : 'Add the first round (another score-based activity) to use rounds.'}</p></label>
          <div class="form-grid two" data-round-only style="margin:0">
            <label class="field"><span>Contestants who advance</span>
              <input type="number" name="advance_count" min="1" max="500" value="${esc(a.advance_count || '')}" placeholder="e.g. 5"></label>
            <label class="field"><span>Carry over from the previous round</span>
              <div class="input-suffix"><input type="number" name="carry_weight" min="0" max="100" step="1" value="${esc(Number(a.carry_weight) ? Number(a.carry_weight) : '')}" placeholder="0"><span>%</span></div>
              <p class="hint">e.g. 30% prelims + 70% finals. 0 = the finals start fresh.</p></label>
          </div>
        </div>
      </details>`;
  };

  Forms.bindScoringFields = (form) => {
    const method = form.querySelector('[name=scoring_method]');
    if (!method) return;
    const tie = form.querySelector('[name=tie_break]');
    const tieCriterion = form.querySelector('[data-tie-criterion]');
    const source = form.querySelector('[name=source_activity_id]');
    const roundOnly = form.querySelector('[data-round-only]');
    const carry = form.querySelector('[name=carry_weight]');
    const hint = form.querySelector('[data-method-hint]');
    const sync = () => {
      const m = method.value;
      hint.textContent = m === 'rank_sum'
        ? 'Each judge’s totals become ranks (1st, 2nd…); the lowest total of ranks wins. One very harsh or generous judge cannot swing the result.'
        : 'The usual way: the final score is the average of the judges’ totals.';
      tie.querySelectorAll('[data-for]').forEach((o) => { o.hidden = o.dataset.for !== m; o.disabled = o.hidden; });
      if (tie.selectedOptions[0]?.disabled) tie.value = 'share';
      tieCriterion.hidden = tie.value !== 'criterion';
      tieCriterion.querySelector('select').disabled = tieCriterion.hidden;
      const round = Number(source.value) > 0;
      roundOnly.hidden = !round;
      roundOnly.querySelectorAll('input').forEach((i) => (i.disabled = !round));
      // rank sum cannot carry a score over: send an empty value (0) rather than keeping the old one
      carry.readOnly = m === 'rank_sum';
      if (m === 'rank_sum') carry.value = '';
    };
    [method, tie, source].forEach((el) => el.addEventListener('change', sync));
    sync();
  };

  Forms.CRITERIA_MAX_MB = 15;

  /**
   * Uploads a criteria sheet for an activity and keeps the scan result for the Criteria tab to review.
   * Resolves true when the sheet was scanned.
   */
  Forms.scanCriteria = async (activityId, file, statusEl) => {
    const say = (text) => statusEl && (statusEl.textContent = text);
    try {
      const prepared = await App.compressImage(file);
      const fd = new FormData();
      fd.append('activity_id', activityId);
      fd.append('file', prepared, prepared.name || file.name);
      say('Uploading the criteria sheet… 0%');
      const r = await App.upload('criteria.scan', fd, (pct) => say(pct < 100 ? `Uploading the criteria sheet… ${pct}%` : 'Scanning and reading the criteria…'));
      if (window.Criteria) Criteria.stashReview(activityId, r);
      else try { sessionStorage.setItem('criteria-review-' + activityId, JSON.stringify(r)); } catch (e) { /* review can still be redone in the Criteria tab */ }
      App.toast(r.criteria.length ? `Found ${r.criteria.length} criteria. Review and save them.` : 'Criteria sheet uploaded. Check the text that was read.', r.criteria.length ? 'success' : 'info', 5000);
      return true;
    } catch (err) {
      App.toast('Activity saved, but the criteria sheet could not be read: ' + err.message + ' Upload it again in the Criteria tab.', 'error', 8000);
      return false;
    }
  };

  /* ---------------------------------------------------------------- colour + picture */

  const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

  /** Shrinks a picture to at most 640px — avatars never need more, and uploads stay light on mobile data. */
  const shrinkPhoto = (file) =>
    new Promise((resolve) => {
      const img = new Image();
      const url = URL.createObjectURL(file);
      img.onload = () => {
        URL.revokeObjectURL(url);
        const scale = Math.min(1, 640 / Math.max(img.width, img.height));
        if (scale === 1 && file.size < 400 * 1024) return resolve(file);
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        const png = file.type === 'image/png'; // keep transparent logos transparent
        canvas.toBlob((blob) => {
          if (!blob || blob.size >= file.size) return resolve(file);
          resolve(new File([blob], file.name.replace(/\.\w+$/, '') + (png ? '.png' : '.jpg'), { type: png ? 'image/png' : 'image/jpeg' }));
        }, png ? 'image/png' : 'image/jpeg', 0.88);
      };
      img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });

  /**
   * Colour swatches + picture field for teams and contestants.
   * entity: { name, color, own_color?, photo, has_photo? }   opts: { photoLabel, inherited }
   */
  function lookFields(entity, opts = {}) {
    const e = entity || {};
    const own = (e.own_color !== undefined ? e.own_color : e.color) || '';
    const preset = App.COLORS.some(([hex]) => hex === own);
    const custom = own && !preset ? own : '#00461B';
    const swatch = (hex, name) => `
      <label class="swatch" title="${esc(name)}" style="--swatch:${hex}">
        <input type="radio" name="color" value="${hex}" ${own === hex ? 'checked' : ''} aria-label="${esc(name)}">
        <span>${App.icon('check')}</span></label>`;
    return `
      <div class="look-field span-2">
        <div class="look-preview" data-look-preview></div>
        <div class="look-controls">
          <div class="field">
            <span class="field-label">${esc(opts.photoLabel || 'Picture')} <em>(optional)</em></span>
            <div class="row">
              <label class="btn btn-sm">${App.icon('image')} <span data-look-pick>${e.photo ? 'Change' : 'Upload'}</span>
                <input type="file" accept="${PHOTO_TYPES.join(',')}" hidden data-look-file></label>
              <button type="button" class="btn btn-sm btn-ghost" data-look-remove ${e.photo ? '' : 'hidden'}>${App.icon('x')} Remove</button>
            </div>
            <p class="hint">JPG, PNG or WEBP, up to 5 MB. Shown in brackets, standings and podiums.</p>
          </div>
          <fieldset class="field swatches">
            <legend class="field-label">Colour</legend>
            <div class="swatch-row">
              <label class="swatch none" title="${opts.inherited ? 'Use the team colour' : 'No colour'}">
                <input type="radio" name="color" value="" ${own ? '' : 'checked'} aria-label="${opts.inherited ? 'Use the team colour' : 'No colour'}">
                <span>${App.icon('x')}</span></label>
              ${App.COLORS.map(([hex, name]) => swatch(hex, name)).join('')}
              <label class="swatch custom" title="Custom colour" style="--swatch:${esc(custom)}">
                <input type="radio" name="color" value="${esc(custom)}" ${own && !preset ? 'checked' : ''} aria-label="Custom colour" data-look-custom-radio>
                <span>${App.icon('check')}</span><b class="custom-plus">${App.icon('plus')}</b>
                <input type="color" value="${esc(custom)}" data-look-custom aria-label="Pick a custom colour"></label>
            </div>
            <p class="hint" data-look-name></p>
          </fieldset>
        </div>
      </div>`;
  }

  /** Live preview + pending picture change. Returns a function that saves the picture once the record has an id. */
  function wireLook(form, entity, opts) {
    const e = entity || {};
    const state = { file: null, remove: false, objectUrl: '' };
    const preview = form.querySelector('[data-look-preview]');
    const fileInput = form.querySelector('[data-look-file]');
    const removeBtn = form.querySelector('[data-look-remove]');
    const pickText = form.querySelector('[data-look-pick]');
    const customRadio = form.querySelector('[data-look-custom-radio]');
    const customInput = form.querySelector('[data-look-custom]');
    const nameHint = form.querySelector('[data-look-name]');
    const nameInput = form.querySelector('[name=name]');

    const draw = () => {
      const picked = form.querySelector('[name=color]:checked')?.value || '';
      const inherited = opts.inheritedColor ? opts.inheritedColor() : '';
      const color = picked || inherited;
      const photoPreview = state.file ? { objectUrl: state.objectUrl } : state.remove ? null : e.photo;
      const name = nameInput?.value || e.name || '';
      if (photoPreview && photoPreview.objectUrl) {
        preview.innerHTML = `<span class="avatar avatar-xl has-photo ${color ? 'has-color' : ''}" style="--entry:${esc(color)}"><img src="${esc(photoPreview.objectUrl)}" alt=""></span>`;
      } else {
        preview.innerHTML = App.avatar({ name, color, photo: photoPreview }, 'xl');
      }
      const preset = App.COLORS.find(([hex]) => hex === picked);
      nameHint.textContent = picked ? `${preset ? preset[1] : 'Custom'} · ${picked}` : (inherited ? `Using the team colour · ${inherited}` : 'No colour');
    };

    form.querySelectorAll('[name=color]').forEach((r) => r.addEventListener('change', draw));
    customInput.addEventListener('input', () => {
      const hex = customInput.value.toUpperCase();
      customRadio.value = hex;
      customRadio.checked = true;
      customInput.closest('.swatch').style.setProperty('--swatch', hex);
      draw();
    });
    // clicking the custom swatch opens the native picker
    customRadio.addEventListener('click', () => customInput.click());
    nameInput?.addEventListener('input', App.debounce(draw, 150));
    opts.onInheritChange && opts.onInheritChange(draw);

    fileInput.addEventListener('change', async () => {
      const file = fileInput.files[0];
      fileInput.value = '';
      if (!file) return;
      if (!PHOTO_TYPES.includes(file.type)) return App.toast('Choose a JPG, PNG or WEBP picture.', 'error');
      const small = await shrinkPhoto(file);
      if (small.size > 5 * 1024 * 1024) return App.toast('The picture is larger than 5 MB.', 'error');
      if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
      Object.assign(state, { file: small, remove: false, objectUrl: URL.createObjectURL(small) });
      removeBtn.hidden = false;
      pickText.textContent = 'Change';
      draw();
    });
    removeBtn.addEventListener('click', () => {
      if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
      Object.assign(state, { file: null, remove: !!e.photo, objectUrl: '' });
      removeBtn.hidden = true;
      pickText.textContent = 'Upload';
      draw();
    });
    draw();

    /** Called after the record is saved. */
    return async (id) => {
      if (state.file) {
        const fd = new FormData();
        fd.append(opts.idField, id);
        fd.append('file', state.file);
        try {
          await App.upload(opts.uploadRoute, fd);
        } catch (err) {
          App.toast('Saved, but the picture was not uploaded: ' + err.message, 'error', 6000);
        }
      } else if (state.remove) {
        await App.post(opts.removeRoute, { [opts.idField]: id });
      }
      if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
    };
  }

  /**
   * Quick pictures for a bracket: each entry uploads right away.
   * Entries linked to a team can save the picture as the team's logo (staff), which shows in every activity.
   * opts: { staff, refresh() -> fresh entries }. Resolves true when something changed.
   */
  Forms.entryPictures = (entries, opts = {}) => {
    let list = entries.slice();
    let changed = false;
    let targetBox = null;
    const anyTeam = opts.staff && list.some((c) => c.team_id);
    const asTeam = () => Boolean(anyTeam && targetBox && targetBox.checked);
    const row = (c) => {
      const teamLogo = Boolean(c.photo && c.photo.r === 'media.team');
      const removable = asTeam() && c.team_id ? teamLogo : c.has_photo;
      const sameName = !c.team_name || c.team_name.toLowerCase() === c.name.toLowerCase();
      return `
        <div class="pic-row" data-entry="${c.id}">
          ${App.avatar(c, 'lg')}
          <div class="pic-name"><strong>${esc(c.name)}</strong>
            <small>${c.photo ? (teamLogo ? 'Team logo' : 'Own picture') : 'No picture yet'}${sameName ? '' : ' · ' + esc(c.team_name)}</small></div>
          <label class="btn btn-sm ${c.photo ? '' : 'btn-primary'}">${App.icon('image')} ${c.photo ? 'Change' : 'Upload'}
            <input type="file" accept="${PHOTO_TYPES.join(',')}" hidden data-pic-file></label>
          <button type="button" class="btn btn-sm btn-ghost btn-icon" data-pic-remove title="Remove picture" ${removable ? '' : 'hidden'}>${App.icon('x')}</button>
        </div>`;
    };
    return App.modal({
      title: 'Pictures & logos',
      submitText: 'Done',
      cancelText: 'Close',
      body: `
        <p class="hint" style="margin-top:0">JPG, PNG or WEBP, up to 5 MB. Each picture is saved as soon as you choose it.</p>
        ${anyTeam ? `<label class="check pic-target"><input type="checkbox" data-as-team checked><span><strong>Save as the team logo</strong>, shown in every activity and the overall standings. Untick to use the picture in this activity only.</span></label>` : ''}
        <div class="pic-list" data-pic-list>${list.map(row).join('')}</div>`,
      onOpen: (form) => {
        const box = form.querySelector('[data-pic-list]');
        targetBox = form.querySelector('[data-as-team]');
        const redraw = () => (box.innerHTML = list.map(row).join(''));
        const reload = async () => {
          list = await opts.refresh();
          changed = true;
          redraw();
        };
        targetBox?.addEventListener('change', redraw);
        box.addEventListener('change', async (e) => {
          const input = e.target.closest('[data-pic-file]');
          if (!input || !input.files[0]) return;
          const rowEl = input.closest('[data-entry]');
          const c = list.find((x) => x.id === Number(rowEl.dataset.entry));
          const file = input.files[0];
          input.value = '';
          if (!PHOTO_TYPES.includes(file.type)) return App.toast('Choose a JPG, PNG or WEBP picture.', 'error');
          rowEl.classList.add('is-saving');
          try {
            const small = await shrinkPhoto(file);
            if (small.size > 5 * 1024 * 1024) throw new Error('The picture is larger than 5 MB.');
            const team = asTeam() && c.team_id;
            const fd = new FormData();
            fd.append(team ? 'team_id' : 'contestant_id', team ? c.team_id : c.id);
            fd.append('file', small);
            const r = await App.upload(team ? 'teams.logo' : 'contestants.photo', fd);
            App.toast((r && r.message) || 'Picture saved.', 'success');
            await reload();
          } catch (err) {
            App.fail(err);
            rowEl.classList.remove('is-saving');
          }
        });
        box.addEventListener('click', async (e) => {
          const btn = e.target.closest('[data-pic-remove]');
          if (!btn) return;
          const c = list.find((x) => x.id === Number(btn.closest('[data-entry]').dataset.entry));
          App.setLoading(btn, true);
          try {
            if (asTeam() && c.team_id) await App.post('teams.logo_remove', { team_id: c.team_id });
            else await App.post('contestants.photo_remove', { contestant_id: c.id });
            await reload();
          } catch (err) {
            App.fail(err);
            App.setLoading(btn, false);
          }
        });
      },
      onSubmit: () => true,
    }).then(() => changed);
  };

  Forms.team = (eventId, team = null) => {
    let saveLook = null;
    return App.modal({
      title: team ? 'Edit group' : 'Add group',
      submitText: team ? 'Save' : 'Add group',
      wide: true,
      body: `
        <div class="form-grid two">
          <label class="field span-2"><span>Group name <em>(department, tribe, college…)</em></span>
            <input name="name" required maxlength="150" value="${esc(team ? team.name : '')}" placeholder="e.g. Academia, Jujutsu, Titans or College of Engineering"></label>
          ${lookFields(team, { photoLabel: 'Logo' })}
        </div>
        <p class="hint">Groups collect points from every placing of their players and teams, for the overall standings. Their entries use this colour and logo unless an entry has its own.</p>`,
      onOpen: (form) => {
        saveLook = wireLook(form, team, { idField: 'team_id', uploadRoute: 'teams.logo', removeRoute: 'teams.logo_remove' });
      },
      onSubmit: async (data) => {
        const r = await App.post('teams.save', { ...data, event_id: eventId, id: team ? team.id : 0 });
        await saveLook(r.id);
        App.toast(r.message, 'success');
        return r;
      },
    });
  };

  /**
   * A participant of an activity: a solo player, or a team with its members.
   * opts.hasOverall: the event has overall standings, so every entry must play for a group.
   */
  Forms.contestant = (activityId, teams, contestant = null, opts = {}) => {
    const c = contestant || {};
    const hasOverall = opts.hasOverall !== false;
    const isTeam = !!(c.members && String(c.members).trim());
    // only the contestant's own picture can be removed here; a group logo shows through when it has none
    const own = contestant ? { ...c, photo: c.has_photo ? c.photo : null } : null;
    let saveLook = null;
    return App.modal({
      title: contestant ? 'Edit participant' : 'Add participant',
      submitText: contestant ? 'Save' : 'Add participant',
      wide: true,
      body: `
        <div class="form-grid two">
          <div class="field span-2"><span class="field-label">Who is competing?</span>
            <div class="segmented entry-type">
              <label><input type="radio" name="entry_type" value="solo" ${isTeam ? '' : 'checked'}><span>${App.icon('user')} Solo player</span></label>
              <label><input type="radio" name="entry_type" value="team" ${isTeam ? 'checked' : ''}><span>${App.icon('users')} Team</span></label>
            </div></div>
          ${hasOverall ? `<label class="field span-2"><span>Group <em>(department, tribe…) — earns the points of this entry</em></span>
            <select name="team_id" required>
              <option value="">Choose the group…</option>
              ${teams.map((t) => `<option value="${t.id}" ${Number(c.team_id) === Number(t.id) ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}
            </select>
            ${teams.length ? '' : '<p class="hint">This event has no groups yet. Add them on the event page (Groups tab) first.</p>'}</label>` : ''}
          <label class="field" data-name-field><span data-name-label>${isTeam ? 'Team name' : 'Player name'}</span><input name="name" required maxlength="200" value="${esc(c.name)}"></label>
          <label class="field"><span>No. <em>(optional)</em></span><input type="number" name="number" min="1" value="${esc(c.number || '')}" placeholder="Auto"></label>
          <label class="field span-2" data-members ${isTeam ? '' : 'hidden'}><span>Members <em>(one per line)</em></span>
            <textarea name="members" rows="4" placeholder="Juan Dela Cruz&#10;Maria Santos&#10;…">${esc(c.members || '')}</textarea></label>
          <label class="field span-2"><span>Details <em>(optional — section, piece title, IGN…)</em></span><input name="details" maxlength="255" value="${esc(c.details)}"></label>
          ${lookFields(own, { photoLabel: 'Picture', inherited: hasOverall && teams.length > 0 })}
        </div>`,
      onOpen: (form) => {
        const teamSelect = form.querySelector('[name=team_id]');
        const teamOf = () => (teamSelect ? teams.find((t) => Number(t.id) === Number(teamSelect.value)) : null);
        const syncType = () => {
          const team = form.querySelector('[name=entry_type]:checked').value === 'team';
          form.querySelector('[data-members]').hidden = !team;
          form.querySelector('[data-name-label]').textContent = team ? 'Team name' : 'Player name';
          form.querySelectorAll('.entry-type label').forEach((l) => l.classList.toggle('active', l.querySelector('input').checked));
        };
        form.querySelectorAll('[name=entry_type]').forEach((r) => r.addEventListener('change', syncType));
        syncType();
        saveLook = wireLook(form, own, {
          idField: 'contestant_id', uploadRoute: 'contestants.photo', removeRoute: 'contestants.photo_remove',
          inheritedColor: () => teamOf()?.color || '',
          onInheritChange: (redraw) => teamSelect && teamSelect.addEventListener('change', redraw),
        });
      },
      onSubmit: async (data) => {
        if (data.entry_type !== 'team') data.members = '';
        delete data.entry_type;
        const r = await App.post('contestants.save', { ...data, activity_id: activityId, id: contestant ? contestant.id : 0 });
        await saveLook(r.id);
        App.toast(r.message, 'success');
        return r;
      },
    });
  };

  /** Judge or facilitator access code. activities: event activities (for judge assignment). */
  const CODE_ROLES = {
    judge: { title: 'judge', name: 'Judge name', placeholder: 'e.g. Judge 1 — Ms. Dela Cruz' },
    facilitator: { title: 'facilitator', name: 'Facilitator name', placeholder: 'e.g. Stage facilitator' },
  };

  Forms.accessCode = (eventId, role, activities, code = null) => {
    const isJudge = role === 'judge';
    // a new judge scores every activity; a new facilitator handles the whole event until activities are ticked
    const assigned = new Set((code ? code.activity_ids : isJudge ? activities.map((a) => a.id) : []).map(Number));
    const r = CODE_ROLES[role] || CODE_ROLES.facilitator;
    return App.modal({
      title: (code ? 'Edit ' : 'New ') + r.title,
      submitText: code ? 'Save' : 'Generate access code',
      body: `
        <div class="stack">
          <label class="field"><span>${r.name}</span>
            <input name="name" required maxlength="150" value="${esc(code ? code.name : '')}" placeholder="${r.placeholder}"></label>
          ${isJudge ? `
            <div class="field"><span class="field-label">Activities this judge will score</span>
              ${activities.length ? activities.map((a) => `
                <label class="check" style="padding:6px 0"><input type="checkbox" name="activity_ids[]" value="${a.id}" ${assigned.has(Number(a.id)) ? 'checked' : ''}>
                  <span>${esc(a.title)}</span></label>`).join('') : '<p class="muted small">No activities yet — you can assign them later.</p>'}
            </div>` : `
            <div class="field"><span class="field-label">Activities this facilitator handles <em>(their big screens)</em></span>
              ${activities.length ? activities.map((a) => `
                <label class="check" style="padding:6px 0"><input type="checkbox" name="activity_ids[]" value="${a.id}" ${assigned.has(Number(a.id)) ? 'checked' : ''}>
                  <span>${esc(a.title)}${a.venue ? ` <span class="muted small">· ${esc(a.venue)}</span>` : ''}</span></label>`).join('') : '<p class="muted small">No activities yet — you can assign them later.</p>'}
              <p class="hint">Tick the activities in this facilitator's venue: they run only those screens. Leave all unticked to run every screen of the event, including the main screen.</p>
            </div>
            <p class="hint">Facilitators can manage contestants, take activities live and finalize them, monitor judges in Live Ops, add deductions, view live results and run the big screen. They cannot edit criteria or access codes.</p>`}
          ${code ? '' : '<p class="hint">An 8-character access code is generated automatically.</p>'}
        </div>`,
      onSubmit: async (data) => {
        const r = await App.post('codes.save', { ...data, activity_ids: data.activity_ids || [], event_id: eventId, role, id: code ? code.id : 0 });
        App.toast(r.message, 'success');
        return r;
      },
    });
  };
})();
