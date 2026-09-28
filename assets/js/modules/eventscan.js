/* Scan an event document: upload → animated scan with percentage → confirm details and activities → create. */
(function () {
  'use strict';
  const { esc } = App;
  const EventScan = (window.EventScan = {});

  const toInput = (s) => (s ? String(s).replace(' ', 'T').slice(0, 16) : '');
  const reduceMotion = () => window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /**
   * opts: { event?: existing event (adds activities to it), activities?: its current activities }
   * Resolves { eventId } when something was saved, otherwise undefined.
   */
  EventScan.open = (opts = {}) => {
    const existing = opts.event || null;
    let dialogApi = null;
    let scan = null;
    let saved = null;

    return App.modal({
      title: existing ? 'Scan a document into this event' : 'Scan an event document',
      wide: true,
      hideFooter: true,
      body: '<div class="es-root" data-es></div>',
      onOpen: (form, dlg, close) => {
        const heading = form.querySelector('.modal-head h2');
        const title = heading.textContent;
        dialogApi = {
          form, dlg, root: form.querySelector('[data-es]'), close: (v) => close(v),
          // after scanning, the review takes the whole screen like a page
          page(on, fileName = '') {
            dlg.classList.toggle('es-page', on);
            heading.innerHTML = on
              ? `${App.icon('scan')}<span class="es-page-title">${App.esc(title)}</span>${fileName ? `<span class="es-page-file">${App.icon('file')} ${App.esc(fileName)}</span>` : ''}`
              : App.esc(title);
          },
          // leaving the review loses the edits: ask first
          async leave() {
            if (!dlg.classList.contains('es-page')) return close(undefined);
            const ok = await App.confirm({ title: 'Leave without saving?', message: 'The scanned event and the changes you made here will not be saved.', confirmText: 'Leave', danger: true });
            if (ok) close(undefined);
          },
        };
        dlg.addEventListener('click', (e) => {
          if (dlg.classList.contains('es-page') && e.target.closest('.modal-head [data-close]')) {
            e.stopImmediatePropagation();
            dialogApi.leave();
          }
        }, true);
        dlg.addEventListener('cancel', (e) => {
          if (dlg.classList.contains('es-page')) {
            e.preventDefault();
            dialogApi.leave();
          }
        });
        // Enter inside a field must not submit (and close) the dialog
        form.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.tagName === 'INPUT') e.preventDefault(); });
        pickStep();
      },
    }).then(() => saved || undefined);

    /* ------------------------------------------------ step 1: choose the file */
    function pickStep() {
      const { root } = dialogApi;
      dialogApi.page(false);
      root.innerHTML = `
        <ol class="es-progress-steps" aria-label="Steps">
          <li class="active"><span>1</span>Upload</li><li><span>2</span>Scan</li><li><span>3</span>Confirm</li>
        </ol>
        <label class="dropzone es-drop" data-drop>
          ${App.icon('scan')}
          <strong>Upload the event memo, program or proposal</strong>
          <span class="muted small">Photo, PDF or Word (.docx), up to ${Forms.CRITERIA_MAX_MB} MB. Drag &amp; drop or tap to choose.</span>
          <input type="file" accept="${App.DOC_ACCEPT}" data-file>
        </label>
        <div class="es-detects">
          <span>The system reads it and fills in:</span>
          <b>${App.icon('edit')} Event title</b><b>${App.icon('calendar')} Dates &amp; time</b><b>${App.icon('grid')} Venue</b><b>${App.icon('list')} Activities</b><b>${App.icon('trophy')} Category</b><b>${App.icon('flow')} Segments &amp; scoring</b><b>${App.icon('check')} Criteria (tables or lists)</b>
        </div>
        <div class="es-foot"><button type="button" class="btn" data-cancel>Cancel</button></div>`;
      const drop = root.querySelector('[data-drop]');
      const input = root.querySelector('[data-file]');
      const go = (file) => {
        if (!file) return;
        if (file.size > Forms.CRITERIA_MAX_MB * 1024 * 1024) return App.toast(`The file is larger than ${Forms.CRITERIA_MAX_MB} MB.`, 'error');
        scanStep(file);
      };
      input.addEventListener('change', () => go(input.files[0]));
      ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('drag'); }));
      ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('drag'); }));
      drop.addEventListener('drop', (e) => go(e.dataTransfer.files[0]));
      root.querySelector('[data-cancel]').addEventListener('click', () => dialogApi.close(undefined));
    }

    /* ------------------------------------------------ step 2: scanning animation + percentage */
    async function scanStep(file) {
      const { root } = dialogApi;
      const isImage = /^image\//.test(file.type);
      const ext = (file.name.split('.').pop() || 'file').toUpperCase().slice(0, 4);
      root.innerHTML = `
        <ol class="es-progress-steps" aria-label="Steps">
          <li class="done"><span>${App.icon('check')}</span>Upload</li><li class="active"><span>2</span>Scan</li><li><span>3</span>Confirm</li>
        </ol>
        <div class="es-scan ${reduceMotion() ? 'still' : ''}" role="status" aria-live="polite">
          <div class="es-doc" aria-hidden="true">
            <div class="es-page">
              ${isImage ? '<img data-thumb alt="">' : `<span class="es-ext">${esc(ext)}</span>`}
              <i></i><i></i><i class="short"></i><i></i><i></i><i class="short"></i><i></i><i class="short"></i>
              <div class="es-beam"></div>
            </div>
            <div class="es-corners"><span></span><span></span><span></span><span></span></div>
          </div>
          <div class="es-info">
            <div class="es-file">${App.icon('file')} <span>${esc(file.name)}</span></div>
            <div class="es-pct"><strong data-pct>0</strong><span>%</span></div>
            <div class="es-bar"><span data-bar></span></div>
            <ul class="es-steps">
              <li data-step="upload" class="active"><i></i>Uploading to the file server</li>
              <li data-step="read"><i></i>Reading the document</li>
              <li data-step="details"><i></i>Detecting title, venue and dates</li>
              <li data-step="activities"><i></i>Finding activities, categories, segments and criteria</li>
            </ul>
          </div>
        </div>`;

      if (isImage) {
        const url = URL.createObjectURL(file);
        const img = root.querySelector('[data-thumb]');
        img.onload = () => URL.revokeObjectURL(url);
        img.src = url;
      }

      const pctEl = root.querySelector('[data-pct]');
      const barEl = root.querySelector('[data-bar]');
      let shown = 0;
      let target = 0;
      const setStep = (name, state) => {
        const li = root.querySelector(`[data-step="${name}"]`);
        if (!li) return;
        li.classList.remove('active', 'done');
        if (state) li.classList.add(state);
      };
      // smooth counter: eases the shown number toward the target
      const timer = setInterval(() => {
        if (shown < target) {
          shown = Math.min(target, shown + Math.max(1, Math.round((target - shown) / 6)));
          pctEl.textContent = Math.floor(shown);
          barEl.style.width = shown + '%';
        }
      }, 60);
      // while the server reads the file, creep toward 95% and move through the steps
      let creep = null;
      const startCreep = () => {
        setStep('upload', 'done');
        setStep('read', 'active');
        creep = setInterval(() => {
          target = Math.min(95, target + (target < 70 ? 2 : target < 85 ? 1 : 0.34));
          target = Math.round(target * 100) / 100;
          if (target >= 62) { setStep('read', 'done'); setStep('details', 'active'); }
          if (target >= 80) { setStep('details', 'done'); setStep('activities', 'active'); }
        }, 180);
      };

      try {
        const startedAt = Date.now();
        const prepared = await App.compressImage(file);
        const fd = new FormData();
        fd.append('file', prepared, prepared.name || file.name);
        if (existing) fd.append('event_id', existing.id);
        let creeping = false;
        scan = await App.upload('events.scan', fd, (p) => {
          target = Math.max(target, Math.round(p * 0.45));
          if (p >= 100 && !creeping) { creeping = true; startCreep(); }
        });
        if (!creeping) startCreep();
        const minimum = reduceMotion() ? 0 : 2600;
        while (Date.now() - startedAt < minimum) await new Promise((r) => setTimeout(r, 120));
        clearInterval(creep);
        ['upload', 'read', 'details', 'activities'].forEach((s) => setStep(s, 'done'));
        target = 100;
        await new Promise((r) => setTimeout(r, reduceMotion() ? 100 : 700));
        clearInterval(timer);
        pctEl.textContent = 100;
        barEl.style.width = '100%';
        root.querySelector('.es-scan').classList.add('complete');
        await new Promise((r) => setTimeout(r, reduceMotion() ? 100 : 650));
        confirmStep();
      } catch (err) {
        clearInterval(timer);
        clearInterval(creep);
        App.fail(err);
        pickStep();
      }
    }

    /* ------------------------------------------------ step 3: confirm (wizard: event → each activity → review) */
    function confirmStep() {
      const { root } = dialogApi;
      dialogApi.page(true, scan.file_name);
      const ev = scan.event;
      const found = scan.found;
      const base = existing || {};
      const existingByKey = Object.fromEntries((opts.activities || []).map((a) => [a.title.trim().toLowerCase(), a]));
      const rows = scan.activities.map((a) => {
        const dup = existingByKey[a.title.trim().toLowerCase()] || null;
        const criteria = (a.criteria || []).map((c) => ({ name: c.name, description: c.description || '', max_score: c.max_score }));
        const rules = a.rules || [];
        // an activity that already exists is ticked when the document has its criteria or rules: they are filled in
        const fills = criteria.length > 0 || rules.length > 0;
        return { ...a, criteria, rules, segments: a.segments || [], details: { ...(a.details || {}) }, score_label: a.score_label || '', scoring: a.scoring || '', category: a.category || '', include: !dup || fills, duplicate: dup };
      });

      /* project head: detected name matched against staff accounts */
      const owners = opts.owners || [];
      const me = App.user || {};
      const person = (s) => String(s || '').toLowerCase().replace(/^(dr|mr|mrs|ms|engr|atty|prof|sir|ma'?am)\.?\s+/g, '').replace(/[^a-z]/g, '');
      const detectedHead = scan.head || '';
      const matchedOwner = detectedHead ? owners.find((o) => person(o.name) === person(detectedHead) || person(o.name).includes(person(detectedHead)) || person(detectedHead).includes(person(o.name))) : null;

      const state = {
        step: 0,
        updateDetails: false,
        makeCodes: true,
        event: {
          title: ev.title || base.title || '',
          venue: ev.venue || base.venue || '',
          start_at: toInput(ev.start_at || base.start_at),
          end_at: toInput(ev.end_at || base.end_at),
          owner_id: String(matchedOwner ? matchedOwner.id : (base.owner_id || me.id || '')),
        },
      };

      /* judges & facilitators: from the document, merged by name; each remembers its activities */
      const existingCodes = (opts.codes || []).map((c) => ({ id: c.id, role: c.role, name: c.name, key: person(c.name), activity_ids: (c.activity_ids || []).map(Number) }));
      const codeOf = (role, name) => existingCodes.find((c) => c.role === role && c.key === person(name)) || null;
      const hasCode = (role, name) => Boolean(codeOf(role, name));
      const judges = [];
      const facilitators = [];
      const addPerson = (list, role, name, rowList) => {
        const k = person(name);
        if (!k) return null;
        let p = list.find((x) => person(x.name) === k);
        if (!p) list.push((p = { name: name.trim(), rows: new Set(), exists: hasCode(role, name), code: codeOf(role, name) }));
        rowList.forEach((r) => p.rows.add(r));
        return p;
      };
      // judges who already score an activity of this event are shown on it
      existingCodes.filter((c) => c.role === 'judge').forEach((c) => {
        const theirs = rows.filter((r) => r.duplicate && c.activity_ids.includes(Number(r.duplicate.id)));
        if (theirs.length) addPerson(judges, 'judge', c.name, theirs);
      });
      rows.forEach((r) => (r.judges || []).forEach((n) => addPerson(judges, 'judge', n, [r])));
      (scan.judges || []).forEach((n) => addPerson(judges, 'judge', n, rows.filter((r) => r.format === 'score' && !r.duplicate)));
      rows.forEach((r) => (r.facilitators || []).forEach((n) => addPerson(facilitators, 'facilitator', n, [r])));
      (scan.facilitators || []).forEach((n) => addPerson(facilitators, 'facilitator', n, []));

      const formatOptions = (sel) => Object.entries(Forms.FORMATS).map(([k, f]) => `<option value="${k}" ${sel === k ? 'selected' : ''}>${f.label}</option>`).join('');
      const natureOptions = (sel) => `<option value="">Nature…</option>${Forms.NATURES.map((n) => `<option ${sel === n ? 'selected' : ''}>${esc(n)}</option>`).join('')}${sel && !Forms.NATURES.includes(sel) ? `<option selected>${esc(sel)}</option>` : ''}`;
      const totalOf = (r) => r.criteria.reduce((s, c) => s + (Number(c.max_score) || 0), 0);
      const lastStep = () => rows.length + 1;
      const rowOfStep = (step) => (step >= 1 && step <= rows.length ? rows[step - 1] : null);
      const needsWork = (r) => r.include && r.format === 'score' && !r.criteria.filter((c) => String(c.name).trim()).length;
      // score-based criteria must total exactly 100 (the server refuses anything else)
      const badTotal = (r) => r.include && r.format === 'score' && r.criteria.some((c) => String(c.name).trim()) && !Criteria.totalOk(totalOf(r));
      const judgesOf = (r) => judges.filter((j) => j.rows.has(r));
      const facsOf = (r) => facilitators.filter((f) => f.rows.has(r));

      /* ---------- layout pieces ---------- */
      const header = () => {
        const step = state.step;
        const last = lastStep();
        const r = rowOfStep(step);
        // counting starts at the first activity: event details and review are not numbered
        const node = (i, inner, label, extra = '') => {
          const cls = [i === step ? 'active' : '', i < step ? 'passed' : '', extra].filter(Boolean).join(' ');
          return `<li class="${cls}"><button type="button" data-go="${i}" title="${esc(label)}" ${i === step ? 'aria-current="step"' : ''}><span class="wz-circle">${inner}</span><span class="wz-cap">${esc(label)}</span></button></li>`;
        };
        const track = [
          node(0, App.icon('calendar'), 'Event details', 'wz-end'),
          ...rows.map((x, i) => node(i + 1, step > i + 1 && x.include && !needsWork(x) && !badTotal(x) ? App.icon('check') : String(i + 1), x.title || `Activity ${i + 1}`, [!x.include ? 'off' : '', needsWork(x) || badTotal(x) ? 'warn' : ''].filter(Boolean).join(' '))),
          node(last, App.icon('list'), 'Review & create', 'wz-end'),
        ].join('');
        const title = step === 0
          ? `<span class="wz-step">Before the activities</span><strong class="wz-label">Event details</strong>`
          : r
            ? `<span class="wz-step">Activity ${step} of ${rows.length}</span><strong class="wz-label">${esc(r.title || 'Untitled activity')}</strong>`
            : `<span class="wz-step">All ${rows.length} activities checked</span><strong class="wz-label">Review &amp; create</strong>`;
        return `
          <div class="wz-head">
            <div class="wz-top">${title}</div>
            <ol class="wz-track" aria-label="Steps">${track}</ol>
          </div>`;
      };
      const footer = () => {
        const step = state.step;
        const last = lastStep();
        const next = rowOfStep(step + 1);
        const creating = rows.filter((r) => r.include && r.title.trim() && !r.duplicate).length;
        const filling = rows.filter((r) => r.include && r.duplicate).length;
        const finalLabel = existing
          ? ([creating ? `Add ${creating} activit${creating === 1 ? 'y' : 'ies'}` : '', filling ? `fill in ${filling}${creating ? '' : ` activit${filling === 1 ? 'y' : 'ies'}`}` : ''].filter(Boolean).join(' & ').replace(/^./, (c) => c.toUpperCase()) || 'Confirm')
          : `Create event${creating ? ` & ${creating} activit${creating === 1 ? 'y' : 'ies'}` : ''}`;
        return `
          <div class="es-foot wz-foot">
            ${step === 0 ? `<button type="button" class="btn btn-ghost" data-cancel>Cancel</button>` : `<button type="button" class="btn" data-back>${App.icon('chevronLeft')} Back</button>`}
            <span class="grow"></span>
            ${step < last
              ? `<button type="button" class="btn btn-primary" data-next>${step === last - 1 ? 'Review' : next ? `Next: Activity ${step + 1}` : 'Next'} ${App.icon('chevronRight')}</button>`
              : `<button type="button" class="btn btn-primary btn-lg" data-confirm>${App.icon('check')} ${esc(finalLabel)}</button>`}
          </div>`;
      };

      const peopleBlock = (r, role) => {
        const list = role === 'judge' ? judges : facilitators;
        const mine = list.filter((p) => p.rows.has(r));
        const others = list.filter((p) => !p.rows.has(r) && p.name.trim());
        const label = role === 'judge' ? 'Judges' : 'Facilitators';
        const note = role === 'judge'
          ? (r.format === 'score' ? 'Judges score this activity with the criteria.' : 'Optional: bracket, round robin and ranking results are usually recorded by facilitators.')
          : 'They run this activity. A facilitator’s access code works for the whole event.';
        return `
          <div class="wz-people-col" data-role="${role}">
            <h4>${App.icon(role === 'judge' ? 'key' : 'users')} ${label} <span class="muted small">(${mine.length})</span></h4>
            <p class="hint" style="margin:-4px 0 8px">${note}</p>
            <div class="wz-people-list">
              ${mine.length ? mine.map((p) => {
                // an existing judge already assigned to this activity stays (change it in Access codes)
                const saved = p.exists && p.code && r.duplicate && p.code.activity_ids.includes(Number(r.duplicate.id));
                return `
                <div class="wz-person ${p.exists ? 'exists' : ''}" data-person="${list.indexOf(p)}">
                  <span class="wz-avatar">${esc((p.name.trim()[0] || '?').toUpperCase())}</span>
                  <input data-person-name value="${esc(p.name)}" maxlength="150" aria-label="${role} name" ${p.exists ? 'readonly' : ''}>
                  ${p.rows.size > 1 ? `<span class="wz-also" title="${esc([...p.rows].filter((x) => x !== r).map((x) => x.title).join(', '))}">+${p.rows.size - 1} more</span>` : ''}
                  ${p.exists ? `<span class="es-tag">${saved ? 'Already assigned' : 'Has a code'}</span>` : ''}
                  ${saved ? '' : `<button type="button" class="btn btn-sm btn-ghost btn-icon" data-person-remove title="Remove from this activity">${App.icon('x')}</button>`}
                </div>`;
              }).join('') : `<div class="es-people-empty">No ${label.toLowerCase()} for this activity yet.</div>`}
            </div>
            <div class="wz-add">
              <input data-new-person placeholder="${role === 'judge' ? 'Judge' : 'Facilitator'} name" maxlength="150" list="wz-${role}-names" aria-label="New ${role} name">
              <button type="button" class="btn btn-sm btn-primary" data-add-person>${App.icon('plus')} Add ${role === 'judge' ? 'judge' : 'facilitator'}</button>
            </div>
            <datalist id="wz-${role}-names">${list.map((p) => `<option value="${esc(p.name)}">`).join('')}</datalist>
            ${others.length ? `<div class="es-chips" style="margin-top:8px"><span class="muted small">Also add:</span> ${others.map((p) => `<button type="button" class="es-chip" data-add-existing="${list.indexOf(p)}">${esc(p.name)}</button>`).join('')}</div>` : ''}
          </div>`;
      };

      /* ---------- step bodies ---------- */
      const eventBody = () => {
        const withCriteria = rows.filter((r) => r.criteria.length).length;
        const foundLabel = (k) => ({ title: 'title', venue: 'venue', dates: 'dates', activities: `${rows.length} activit${rows.length === 1 ? 'y' : 'ies'}`, criteria: `criteria for ${withCriteria}`, head: 'project head', judges: 'judges', facilitators: 'facilitators' }[k]);
        const heads = owners.filter((o) => o.role === 'program_head');
        const adminsList = owners.filter((o) => o.role !== 'program_head');
        const opt = (o) => `<option value="${o.id}" ${String(state.event.owner_id) === String(o.id) ? 'selected' : ''}>${esc(o.name)}${o.program ? ' — ' + esc(o.program) : ''}</option>`;
        const headNote = detectedHead
          ? `<p class="hint">In the document: <strong>${esc(detectedHead)}</strong>${owners.length ? (matchedOwner ? ' · matched to a staff account' : ' · no staff account with this name, pick the Project Head') : ''}</p>`
          : '<p class="hint">No Project Head was named in the document.</p>';
        const lock = existing && !state.updateDetails ? 'disabled' : '';
        return `
          <div class="es-summary">
            <span class="es-summary-icon">${App.icon('check')}</span>
            <div>
              <strong>Scan complete</strong>
              <div class="muted small">Read ${esc(scan.file_name)} (${esc(scan.engine || 'document reader')}). ${found.length ? 'Found: ' + found.map(foundLabel).join(', ') + '.' : 'Nothing was recognised automatically.'} Go through each step and press Next.</div>
            </div>
          </div>
          ${scan.warnings.map((w) => `<div class="alert alert-warn" style="margin-top:10px">${esc(w)}</div>`).join('')}
          <section class="es-section">
            <div class="es-section-head">
              <h3>Event details</h3>
              ${existing ? `<label class="check"><input type="checkbox" data-update-details ${state.updateDetails ? 'checked' : ''}><span>Replace this event's details with the scanned ones</span></label>` : ''}
            </div>
            <div class="form-grid two">
              <label class="field span-2"><span>Event title ${found.includes('title') ? '<em class="es-found">detected</em>' : ''}</span>
                <input data-f="title" maxlength="200" value="${esc(state.event.title)}" placeholder="e.g. COC Foundation Week 2026" ${lock}></label>
              <label class="field span-2"><span>Venue ${found.includes('venue') ? '<em class="es-found">detected</em>' : ''}</span>
                <input data-f="venue" maxlength="200" value="${esc(state.event.venue)}" placeholder="e.g. COC Gymnasium" ${lock}></label>
              <label class="field"><span>Starting date &amp; time ${found.includes('dates') ? '<em class="es-found">detected</em>' : ''}</span>
                <input type="datetime-local" data-f="start_at" value="${esc(state.event.start_at)}" ${lock}></label>
              <label class="field"><span>End date &amp; time</span>
                <input type="datetime-local" data-f="end_at" value="${esc(state.event.end_at)}" ${lock}></label>
              ${owners.length
                ? `<label class="field span-2"><span>Project Head ${matchedOwner ? '<em class="es-found">detected</em>' : ''}</span>
                    <select data-f="owner_id" ${lock}>${heads.length ? `<optgroup label="Program heads">${heads.map(opt).join('')}</optgroup>` : ''}${adminsList.length ? `<optgroup label="Administrators">${adminsList.map(opt).join('')}</optgroup>` : ''}</select>${headNote}</label>`
                : `<div class="field span-2"><span class="field-label">Project Head</span><input value="${esc(base.owner_name || me.name || '')}" readonly>${headNote}</div>`}
            </div>
          </section>
          <section class="es-section">
            <div class="es-section-head"><h3>Activities found <span class="muted small">(${rows.length})</span></h3><button type="button" class="btn btn-sm" data-add-activity>${App.icon('plus')} Add activity</button></div>
            ${rows.length ? `<ol class="wz-overview">${rows.map((r, i) => `<li><button type="button" data-go="${i + 1}"><span class="wz-ov-no">${i + 1}</span><span class="wz-ov-title">${esc(r.title || 'Untitled activity')}</span><span class="wz-ov-meta">${esc(Forms.FORMATS[r.format]?.label || '')}${r.criteria.length ? ` · ${r.criteria.length} criteria` : ''}</span></button></li>`).join('')}</ol>`
              : `<div class="es-empty">${App.icon('list')}<span>No activities detected. Add them with “Add activity”.</span></div>`}
          </section>
          <details class="es-text"><summary>Text read from the document</summary><pre>${esc(scan.text || '(no text)')}</pre></details>
          <p style="margin-top:12px"><button type="button" class="btn btn-sm btn-ghost" data-again>${App.icon('refresh')} Scan another file</button></p>`;
      };

      const activityBody = (r) => {
        const i = rows.indexOf(r);
        const total = totalOf(r);
        return `
          <div class="wz-act ${r.include ? '' : 'off'}">
            <div class="wz-act-head">
              <span class="wz-act-no">${i + 1}</span>
              <div class="wz-act-title">
                <input data-r="title" value="${esc(r.title)}" maxlength="200" placeholder="Activity title" aria-label="Activity title" ${r.duplicate ? 'readonly' : ''}>
                <div class="muted small">${r.category ? esc(r.category) + ' · ' : ''}${esc(Forms.FORMATS[r.format]?.label || '')}${r.duplicate ? ' · <strong>Already in this event</strong>: its criteria, rules and people are filled in' : ''}</div>
              </div>
              <label class="wz-include"><input type="checkbox" data-r="include" ${r.include ? 'checked' : ''}><span>${r.include ? 'Included' : 'Skipped'}</span></label>
            </div>
            ${r.include ? '' : '<div class="alert alert-info" style="margin-top:10px">This activity will be skipped. Tick “Included” to keep it.</div>'}

            <div class="wz-cols">
            <div class="wz-col">
            <div class="es-detail-grid wz-grid">
              <label class="field"><span>How it is decided</span><select data-r="format">${formatOptions(r.format)}</select></label>
              <label class="field"><span>Nature of Activity</span><select data-r="nature">${natureOptions(r.nature)}</select></label>
              <label class="field"><span>Category</span><input data-r="category" value="${esc(r.category)}" maxlength="80" placeholder="e.g. Esports, Pageantry"></label>
              <label class="field"><span>Type</span><input data-d="Type" value="${esc(r.details.Type || '')}" maxlength="120" placeholder="e.g. Solo, Group (3–5 members)"></label>
              <label class="field"><span>Who can join</span><input data-d="Target" value="${esc(r.details.Target || '')}" maxlength="120" placeholder="e.g. All Students"></label>
              <label class="field"><span>Segments <em>(commas)</em></span><input data-r="segments" value="${esc(r.segments.join(', '))}" maxlength="500"></label>
              <label class="field es-span"><span>Scoring</span><input data-r="scoring" value="${esc(r.scoring)}" maxlength="500" placeholder="e.g. Single elimination, best of 3"></label>
              <label class="field es-span"><span>Rules &amp; how to score <em>(one per line)</em></span>
                <textarea data-r="rules" rows="${Math.min(7, Math.max(2, r.rules.length + 1))}">${esc(r.rules.join('\n'))}</textarea></label>
            </div>
            </div>
            <div class="wz-col">

            <div class="es-crit">
              <div class="es-crit-head">
                <strong>Criteria</strong>
                <span class="es-crit-total ${r.criteria.length && Math.abs(total - 100) < 0.01 ? 'ok' : ''}" data-total>${r.criteria.length ? `Total ${App.num(total)}` : 'None'}</span>
                <span class="grow"></span>
                <button type="button" class="btn btn-sm" data-crit-add>${App.icon('plus')} Criterion</button>
              </div>
              <div class="es-crit-rows">${r.criteria.map((c, j) => `
                <div class="es-crit-row" data-c="${j}">
                  <span class="es-crit-no">${j + 1}</span>
                  <input data-cr="name" value="${esc(c.name)}" maxlength="200" placeholder="Criterion" aria-label="Criterion ${j + 1} name">
                  <input data-cr="description" value="${esc(c.description)}" maxlength="1000" placeholder="Description (optional)" aria-label="Criterion ${j + 1} description">
                  <input data-cr="max_score" type="number" min="0.01" max="1000" step="0.01" value="${esc(c.max_score)}" aria-label="Criterion ${j + 1} points">
                  <button type="button" class="btn btn-sm btn-ghost btn-icon" data-crit-remove title="Remove criterion">${App.icon('x')}</button>
                </div>`).join('')}</div>
              ${!r.criteria.length && r.format === 'score' ? '<p class="hint">No criteria were found for this activity. Add them here, or upload the criteria later in the activity’s Criteria tab.</p>' : ''}
              ${r.criteria.length && r.format !== 'score' ? '<p class="hint">Criteria are used by <strong>score-based</strong> activities; they are saved only if this activity is score-based.</p>' : ''}
            </div>

            <div class="wz-people">
              ${peopleBlock(r, 'judge')}
              ${peopleBlock(r, 'facilitator')}
            </div>
            </div>
            </div>
          </div>`;
      };

      const reviewBody = () => {
        const picked = rows.filter((r) => r.include && r.title.trim());
        const owner = owners.find((o) => String(o.id) === String(state.event.owner_id));
        const missingCriteria = picked.filter((r) => needsWork(r));
        const noJudges = picked.filter((r) => r.format === 'score' && !judgesOf(r).length);
        const odd = picked.filter(badTotal);
        const names = (list) => list.filter((p) => p.name.trim());
        return `
          <section class="es-section" style="margin-top:0">
            <div class="es-section-head"><h3>Event</h3><button type="button" class="btn btn-sm btn-ghost" data-go="0">${App.icon('edit')} Edit</button></div>
            <dl class="info-facts">
              <div><dt>Title</dt><dd>${esc(existing && !state.updateDetails ? existing.title : state.event.title) || '—'}</dd></div>
              <div><dt>Venue</dt><dd>${esc(existing && !state.updateDetails ? existing.venue || '' : state.event.venue) || '—'}</dd></div>
              <div><dt>Dates</dt><dd>${esc(App.dateTimeRange(existing && !state.updateDetails ? existing.start_at : state.event.start_at, existing && !state.updateDetails ? existing.end_at : state.event.end_at)) || '—'}</dd></div>
              <div><dt>Project Head</dt><dd>${esc(owner ? owner.name : (base.owner_name || me.name || ''))}</dd></div>
            </dl>
          </section>
          ${missingCriteria.length ? `<div class="alert alert-warn">${App.icon('alert')}<span><strong>${missingCriteria.length}</strong> score-based activit${missingCriteria.length === 1 ? 'y has' : 'ies have'} no criteria: ${missingCriteria.map((r) => `<a href="#" data-go="${rows.indexOf(r) + 1}">${esc(r.title)}</a>`).join(', ')}. You can still add them later.</span></div>` : ''}
          ${odd.length ? `<div class="alert alert-warn" style="margin-top:8px">${App.icon('alert')}<span>Criteria must total exactly 100. Fix these before creating: ${odd.map((r) => `<a href="#" data-go="${rows.indexOf(r) + 1}">${esc(r.title)}</a> (${App.num(totalOf(r))})`).join(', ')}</span></div>` : ''}
          ${noJudges.length ? `<div class="alert alert-info" style="margin-top:8px">${App.icon('key')}<span>No judges yet for: ${noJudges.map((r) => `<a href="#" data-go="${rows.indexOf(r) + 1}">${esc(r.title)}</a>`).join(', ')}. Add them now or later in Access codes.</span></div>` : ''}
          <section class="es-section">
            <div class="es-section-head"><h3>Activities <span class="muted small">(${picked.length} of ${rows.length})</span></h3><button type="button" class="btn btn-sm" data-add-activity>${App.icon('plus')} Add activity</button></div>
            <div class="table-wrap"><table class="table wz-review">
              <thead><tr><th>#</th><th>Activity</th><th>Format</th><th class="num">Criteria</th><th class="num">Judges</th><th class="num">Facilitators</th><th></th></tr></thead>
              <tbody>${rows.map((r, i) => `<tr class="${r.include ? '' : 'wz-skipped'}">
                <td><span class="rank-pill">${i + 1}</span></td>
                <td><strong>${esc(r.title || 'Untitled')}</strong>${r.duplicate ? ' <span class="badge no-dot">Fill existing</span>' : ''}${r.include ? '' : ' <span class="badge no-dot">Skipped</span>'}<div class="muted small">${esc(r.category || '')}</div></td>
                <td>${esc(Forms.FORMATS[r.format]?.label || '')}</td>
                <td class="num">${r.criteria.length ? `${r.criteria.length} · ${App.num(totalOf(r))}` : (r.format === 'score' ? '<span class="wz-missing">none</span>' : '—')}</td>
                <td class="num">${judgesOf(r).length || '—'}</td>
                <td class="num">${facsOf(r).length || '—'}</td>
                <td class="actions"><button type="button" class="btn btn-sm" data-go="${i + 1}">${App.icon('edit')} Edit</button></td>
              </tr>`).join('')}</tbody>
            </table></div>
          </section>
          <section class="es-section">
            <div class="es-section-head"><h3>Access codes</h3></div>
            <div class="wz-people">
              <div class="wz-people-col"><h4>${App.icon('key')} Judges <span class="muted small">(${names(judges).length})</span></h4>
                ${names(judges).length ? `<ul class="wz-names">${names(judges).map((j) => `<li><strong>${esc(j.name)}</strong> <span class="muted small">${[...j.rows].filter((x) => x.include).map((x) => esc(x.title)).join(', ') || 'no activity'}</span>${j.exists ? ' <span class="es-tag">Has a code</span>' : ''}</li>`).join('')}</ul>` : '<p class="muted small">No judges added.</p>'}</div>
              <div class="wz-people-col"><h4>${App.icon('users')} Facilitators <span class="muted small">(${names(facilitators).length})</span></h4>
                ${names(facilitators).length ? `<ul class="wz-names">${names(facilitators).map((f) => `<li><strong>${esc(f.name)}</strong> <span class="muted small">${[...f.rows].filter((x) => x.include).map((x) => esc(x.title)).join(', ') || 'whole event'}</span>${f.exists ? ' <span class="es-tag">Has a code</span>' : ''}</li>`).join('')}</ul>` : '<p class="muted small">No facilitators added.</p>'}</div>
            </div>
            <label class="check" style="margin-top:10px"><input type="checkbox" data-make-codes ${state.makeCodes ? 'checked' : ''}><span>Generate their access codes when I confirm</span></label>
          </section>`;
      };

      /* ---------- render + events ---------- */
      const render = () => {
        const r = rowOfStep(state.step);
        root.innerHTML = `${header()}<div class="wz-body">${state.step === 0 ? eventBody() : r ? activityBody(r) : reviewBody()}</div>${footer()}`;
        const body = dialogApi.form.querySelector('.modal-body'); if (body) body.scrollTop = 0;
        dialogApi.form.closest('dialog, .modal')?.scrollTo?.(0, 0);
        const cur = root.querySelector('.wz-track .active');
        const track = root.querySelector('.wz-track');
        if (cur && track) track.scrollLeft = cur.offsetLeft - track.clientWidth / 2 + cur.clientWidth / 2;
      };
      const go = (step) => {
        state.step = Math.max(0, Math.min(lastStep(), step));
        render();
      };
      const validateStep = () => {
        const r = rowOfStep(state.step);
        if (state.step === 0 && (!existing || state.updateDetails)) {
          if (!state.event.title.trim()) return App.toast('Enter the event title.', 'error'), false;
          if (state.event.start_at && state.event.end_at && state.event.end_at < state.event.start_at) return App.toast('The event cannot end before it starts.', 'error'), false;
        }
        if (r && r.include) {
          if (!r.title.trim()) return App.toast('Give this activity a title, or untick “Included”.', 'error'), false;
          if (r.criteria.some((c) => (String(c.name).trim() && !(Number(c.max_score) > 0)) || (!String(c.name).trim() && Number(c.max_score) > 0))) {
            return App.toast('Every criterion needs a name and points.', 'error'), false;
          }
          if (badTotal(r)) return App.toast(Criteria.totalProblem(totalOf(r)), 'error', 6000), false;
        }
        return true;
      };
      const addActivity = () => {
        rows.push({ title: '', format: existing?.default_format || 'score', nature: '', category: '', segments: [], rules: [], details: {}, scoring: '', score_label: '', criteria: [], include: true, duplicate: null });
        go(rows.length);
        root.querySelector('[data-r="title"]')?.focus();
      };

      root.addEventListener('input', (e) => {
        const t = e.target;
        const r = rowOfStep(state.step);
        if (t.dataset.f) {
          state.event[t.dataset.f] = t.value;
        } else if (t.dataset.cr && r) {
          r.criteria[Number(t.closest('[data-c]').dataset.c)][t.dataset.cr] = t.value;
          const total = root.querySelector('[data-total]');
          if (total) {
            total.textContent = `Total ${App.num(totalOf(r))}`;
            total.classList.toggle('ok', Criteria.totalOk(totalOf(r)));
          }
        } else if (t.dataset.d && r) {
          r.details[t.dataset.d] = t.value;
        } else if (t.dataset.r && r) {
          const key = t.dataset.r;
          if (key === 'rules') r.rules = t.value.split('\n').map((s) => s.trim()).filter(Boolean);
          else if (key === 'segments') r.segments = t.value.split(',').map((s) => s.trim()).filter(Boolean);
          else if (key !== 'include') r[key] = t.value;
          if (key === 'title') {
            const dot = root.querySelector(`.wz-track [data-go="${state.step}"]`);
            if (dot) { dot.title = t.value; dot.querySelector('.wz-cap').textContent = t.value; }
            const head = root.querySelector('.wz-label');
            if (head) head.textContent = t.value || 'Untitled activity';
          }
        } else if (t.hasAttribute('data-person-name') && r) {
          const role = t.closest('[data-role]').dataset.role;
          (role === 'judge' ? judges : facilitators)[Number(t.closest('[data-person]').dataset.person)].name = t.value;
        }
      });
      root.addEventListener('change', (e) => {
        const t = e.target;
        const r = rowOfStep(state.step);
        if (t.dataset.r === 'include' && r) { r.include = t.checked; render(); }
        else if (t.dataset.r === 'format' && r) { r.format = t.value; render(); }
        else if (t.hasAttribute('data-update-details')) { state.updateDetails = t.checked; render(); }
        else if (t.hasAttribute('data-make-codes')) { state.makeCodes = t.checked; }
      });
      root.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.hasAttribute('data-new-person')) {
          e.preventDefault();
          e.target.closest('[data-role]').querySelector('[data-add-person]').click();
        }
      });
      root.addEventListener('click', (e) => {
        const t = e.target;
        const r = rowOfStep(state.step);
        const goBtn = t.closest('[data-go]');
        if (goBtn) {
          e.preventDefault();
          const target = Number(goBtn.dataset.go);
          if (target > state.step && !validateStep()) return;
          return go(target);
        }
        if (t.closest('[data-next]')) return validateStep() && go(state.step + 1);
        if (t.closest('[data-back]')) return go(state.step - 1);
        if (t.closest('[data-cancel]')) return dialogApi.leave();
        if (t.closest('[data-again]')) return pickStep();
        if (t.closest('[data-add-activity]')) return addActivity();
        if (t.closest('[data-confirm]')) {
          return confirm(t.closest('[data-confirm]'), rows, state, { judges, facilitators });
        }
        if (!r) return;
        if (t.closest('[data-crit-add]')) {
          r.criteria.push({ name: '', description: '', max_score: '' });
          render();
          root.querySelector('.es-crit-row:last-child [data-cr="name"]')?.focus();
        } else if (t.closest('[data-crit-remove]')) {
          r.criteria.splice(Number(t.closest('[data-c]').dataset.c), 1);
          render();
        } else if (t.closest('[data-add-person]')) {
          const col = t.closest('[data-role]');
          const role = col.dataset.role;
          const input = col.querySelector('[data-new-person]');
          const name = input.value.trim();
          if (!name) return input.focus();
          addPerson(role === 'judge' ? judges : facilitators, role, name, [r]);
          render();
          root.querySelector(`[data-role="${role}"] [data-new-person]`)?.focus();
        } else if (t.closest('[data-add-existing]')) {
          const role = t.closest('[data-role]').dataset.role;
          (role === 'judge' ? judges : facilitators)[Number(t.closest('[data-add-existing]').dataset.addExisting)].rows.add(r);
          render();
        } else if (t.closest('[data-person-remove]')) {
          const role = t.closest('[data-role]').dataset.role;
          const list = role === 'judge' ? judges : facilitators;
          const p = list[Number(t.closest('[data-person]').dataset.person)];
          p.rows.delete(r);
          if (!p.rows.size && role === 'judge') list.splice(list.indexOf(p), 1); // a judge with no activity is gone
          render();
        }
      });

      render();
    }

    /* ------------------------------------------------ save what was confirmed */
    async function confirm(btn, rows, state, people) {
      const val = (k) => String(state.event[k] || '').trim();
      const updateBox = { checked: state.updateDetails };
      const makeCodes = state.makeCodes;
      const judgesToMake = makeCodes ? people.judges.filter((j) => j.name.trim() && !j.exists) : [];
      const facsToMake = makeCodes ? people.facilitators.filter((f) => f.name.trim() && !f.exists) : [];
      const picked = rows.filter((r) => r.include && r.title.trim());
      const cleanCriteria = (r) => r.criteria
        .map((c) => ({ name: String(c.name).trim(), description: String(c.description || '').trim(), max_score: Number(c.max_score) }))
        .filter((c) => c.name && c.max_score > 0);

      if (!existing || updateBox?.checked) {
        if (!val('title')) return App.toast('Enter the event title (step 1).', 'error');
        if (val('start_at') && val('end_at') && val('end_at') < val('start_at')) return App.toast('The event cannot end before it starts.', 'error');
      }
      for (const r of picked) {
        const bad = r.criteria.find((c) => (String(c.name).trim() && !(Number(c.max_score) > 0)) || (!String(c.name).trim() && Number(c.max_score) > 0));
        if (bad) {
          return App.toast(`Check the criteria of “${r.title}”: every criterion needs a name and points.`, 'error', 6000);
        }
        if (r.format === 'score' && cleanCriteria(r).length && !Criteria.totalOk(cleanCriteria(r).reduce((s, c) => s + c.max_score, 0))) {
          return App.toast(`“${r.title}”: ${Criteria.totalProblem(cleanCriteria(r).reduce((s, c) => s + c.max_score, 0))}`, 'error', 6000);
        }
      }
      const newRows = picked.filter((r) => !r.duplicate);
      const critCount = picked.filter((r) => r.format === 'score' && cleanCriteria(r).length).length;
      const ok = await App.confirm({
        title: existing ? 'Add to this event?' : 'Create this event?',
        message: `${existing ? (updateBox?.checked ? `Update the details of <strong>${esc(val('title'))}</strong>` : `Attach <strong>${esc(scan.file_name)}</strong> to <strong>${esc(existing.title)}</strong>`) : `Create <strong>${esc(val('title'))}</strong>`}${newRows.length ? `, add <strong>${newRows.length}</strong> activit${newRows.length === 1 ? 'y' : 'ies'} (${newRows.slice(0, 5).map((r) => esc(r.title)).join(', ')}${newRows.length > 5 ? '…' : ''})` : ''}${critCount ? ` and save the criteria of <strong>${critCount}</strong> activit${critCount === 1 ? 'y' : 'ies'}` : ''}${judgesToMake.length || facsToMake.length ? `, then give access codes to <strong>${judgesToMake.length}</strong> judge${judgesToMake.length === 1 ? '' : 's'} and <strong>${facsToMake.length}</strong> facilitator${facsToMake.length === 1 ? '' : 's'}` : ''}?`,
        confirmText: 'Yes, confirm',
      });
      if (!ok) return;

      App.setLoading(btn, true);
      const status = document.createElement('div');
      status.className = 'es-saving';
      btn.closest('.es-foot').before(status);
      const say = (t) => (status.textContent = t);
      try {
        // most common format among the activities becomes the event's main format
        const counts = picked.reduce((m, r) => ((m[r.format] = (m[r.format] || 0) + 1), m), {});
        const mainFormat = Object.keys(counts).sort((a, b) => counts[b] - counts[a])[0] || existing?.default_format || 'score';

        let eventId;
        say(existing ? 'Saving the event…' : 'Creating the event…');
        if (existing) {
          const useScan = updateBox?.checked;
          const r = await App.post('events.save', {
            id: existing.id,
            title: useScan ? val('title') : existing.title,
            venue: useScan ? val('venue') : existing.venue,
            start_at: useScan ? val('start_at') : toInput(existing.start_at),
            end_at: useScan ? val('end_at') : toInput(existing.end_at),
            status: existing.status, owner_id: useScan && val('owner_id') ? val('owner_id') : existing.owner_id, description: existing.description || '',
            default_format: existing.default_format, structure: existing.structure,
            document_token: scan.token,
          });
          eventId = r.id;
        } else {
          const r = await App.post('events.save', {
            title: val('title'), venue: val('venue'), start_at: val('start_at'), end_at: val('end_at'),
            status: 'upcoming', structure: 'multi', default_format: mainFormat, document_token: scan.token, owner_id: val('owner_id') || '',
          });
          eventId = r.id;
        }

        const failed = [];
        const flat = (x) => String(x).toLowerCase().replace(/[^a-z0-9]+/g, '');
        const inRules = (row, text) => row.rules.some((rule) => flat(rule).includes(flat(text)));
        const idOf = new Map();
        let n = 0;
        for (const row of picked) {
          n++;
          say(`${row.duplicate ? 'Updating' : 'Adding'} activities… ${n} of ${picked.length}: ${row.title}`);
          try {
            let activityId = row.duplicate ? row.duplicate.id : null;
            const description = [
              row.category ? `Category: ${row.category}` : '',
              row.details.Type ? `Type: ${row.details.Type}` : '',
              row.details.Target ? `Who can join: ${row.details.Target}` : '',
              // each piece once: rounds/scoring that are already listed as a rule are not repeated
              row.segments.length && !inRules(row, row.segments.join(', ')) ? `Segments: ${row.segments.join(', ')}` : '',
              row.scoring && !inRules(row, row.scoring) ? `Scoring: ${row.scoring}` : '',
              row.rules.length ? `Rules & scoring:\n${row.rules.map((x) => `• ${x}`).join('\n')}` : '',
            ].filter(Boolean).join('\n').slice(0, 5000);
            if (!activityId) {
              const r = await App.post('activities.save', {
                event_id: eventId, title: row.title.trim(), format: row.format, nature: row.nature || row.category || '',
                description, score_label: row.score_label || '', counts_to_overall: true, third_place: true,
              });
              activityId = r.id;
            } else if (description) {
              // fill the existing activity: keep its settings, add the document's details
              const d = row.duplicate;
              await App.post('activities.save', {
                id: d.id, title: d.title, format: d.format, nature: d.nature || row.nature || row.category || '',
                description, venue: d.venue || '', schedule_at: d.schedule_at || '', score_label: d.score_label || row.score_label || '',
                rank_direction: d.rank_direction || 'desc', third_place: Number(d.third_place ?? 1) === 1,
                counts_to_overall: Number(d.counts_to_overall ?? 1) === 1, sort_order: d.sort_order || 0,
              });
            }
            idOf.set(row, activityId);
            const criteria = cleanCriteria(row);
            if (criteria.length && (row.duplicate ? row.duplicate.format : row.format) === 'score') {
              await App.post('criteria.save', { activity_id: activityId, criteria });
            }
          } catch (err) {
            failed.push(`${row.title}: ${err.message}`);
          }
        }
        // access codes for the people from the document
        const codes = [];
        let k = 0;
        const total = judgesToMake.length + facsToMake.length;
        for (const j of judgesToMake) {
          say(`Giving access codes… ${++k} of ${total}: ${j.name}`);
          try {
            const activityIds = [...j.rows].filter((r) => idOf.has(r)).map((r) => idOf.get(r));
            const r = await App.post('codes.save', { event_id: eventId, role: 'judge', name: j.name.trim(), activity_ids: activityIds });
            codes.push({ name: j.name.trim(), role: 'Judge', code: r.code, activities: [...j.rows].filter((x) => idOf.has(x)).map((x) => x.title) });
          } catch (err) {
            failed.push(`${j.name}: ${err.message}`);
          }
        }
        for (const j of people.judges.filter((x) => x.exists && x.code && x.name.trim())) {
          const added = [...j.rows].filter((r) => idOf.has(r)).map((r) => Number(idOf.get(r))).filter((id) => !j.code.activity_ids.includes(id));
          if (!added.length) continue;
          say(`Assigning ${j.code.name} to ${added.length} more activit${added.length === 1 ? 'y' : 'ies'}…`);
          try {
            await App.post('codes.save', { id: j.code.id, name: j.code.name, activity_ids: [...j.code.activity_ids, ...added] });
          } catch (err) {
            failed.push(`${j.code.name}: ${err.message}`);
          }
        }
        for (const f of facsToMake) {
          say(`Giving access codes… ${++k} of ${total}: ${f.name}`);
          try {
            const r = await App.post('codes.save', { event_id: eventId, role: 'facilitator', name: f.name.trim() });
            codes.push({ name: f.name.trim(), role: 'Facilitator', code: r.code, activities: [...(f.rows || [])].filter((x) => idOf.has(x)).map((x) => x.title) });
          } catch (err) {
            failed.push(`${f.name}: ${err.message}`);
          }
        }

        saved = { eventId };
        if (codes.length) {
          showCodes(eventId, codes, failed);
          return;
        }
        App.toast(failed.length
          ? `Saved, but ${failed.length} activit${failed.length === 1 ? 'y had' : 'ies had'} a problem: ${failed[0]}`
          : `${existing ? 'Event updated' : 'Event created'}${newRows.length ? ` with ${newRows.length} activit${newRows.length === 1 ? 'y' : 'ies'}` : ''}${critCount ? ` and criteria for ${critCount}` : ''}.`, failed.length ? 'error' : 'success', failed.length ? 9000 : 4500);
        dialogApi.close(true);
      } catch (err) {
        App.fail(err);
        status.remove();
        App.setLoading(btn, false);
      }
    }

    /* ------------------------------------------------ done: the new access codes */
    function showCodes(eventId, codes, failed) {
      const { root } = dialogApi;
      dialogApi.page(false);
      root.innerHTML = `
        <div class="es-summary">
          <span class="es-summary-icon">${App.icon('check')}</span>
          <div>
            <strong>${existing ? 'Event updated' : 'Event created'}</strong>
            <div class="muted small">Give each person their access code. They sign in on the homepage; no account needed.</div>
          </div>
        </div>
        ${failed.map((f) => `<div class="alert alert-warn" style="margin-top:10px">${esc(f)}</div>`).join('')}
        <div class="table-wrap" style="margin-top:16px"><table class="table">
          <thead><tr><th>Name</th><th>Role</th><th>Access code</th><th>Activities</th></tr></thead>
          <tbody>${codes.map((c) => `<tr>
            <td><strong>${esc(c.name)}</strong></td>
            <td>${esc(c.role)}</td>
            <td class="code-value" style="font-size:20px">${esc(c.code)}</td>
            <td class="small">${c.activities.length ? c.activities.map(esc).join(', ') : (c.role === 'Judge' ? '<span class="muted">No activity yet</span>' : '<span class="muted">Whole event</span>')}</td>
          </tr>`).join('')}</tbody>
        </table></div>
        <div class="es-foot">
          <a class="btn" href="${App.page('print.html?type=codes&id=' + eventId)}" target="_blank" rel="noopener">${App.icon('print')} Print code slips</a>
          <span class="grow"></span>
          <button type="button" class="btn btn-primary" data-done>${App.icon('check')} Open the event</button>
        </div>`;
      root.querySelector('[data-done]').addEventListener('click', () => dialogApi.close(true));
    }
  };
})();
