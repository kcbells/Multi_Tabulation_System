/* ==========================================================================
   App core — API client, session/role guard, page shell and UI helpers.
   Every page loads this first, then its own module from assets/js/pages/.
   ========================================================================== */
(function () {
  'use strict';

  const BASE = (document.currentScript && document.currentScript.src || location.href)
    .replace(/assets\/js\/app\.js.*$/, '');

  const App = (window.App = {
    base: BASE,
    session: null,
    user: null,
  });

  let csrfToken = '';

  /* ------------------------------------------------------------ API */

  class ApiError extends Error {
    constructor(message, status) {
      super(message);
      this.status = status;
    }
  }
  App.ApiError = ApiError;

  App.url = (path) => BASE + String(path).replace(/^\//, '');
  /** URL of an app page inside pages/ (only index.html lives in the project root). */
  App.page = (path) => BASE + 'pages/' + String(path).replace(/^\//, '');
  App.apiUrl = (route, params = {}) => {
    const q = new URLSearchParams({ r: route, ...params });
    return BASE + 'api/index.php?' + q.toString();
  };

  async function request(route, { method = 'GET', params = null, body = null } = {}) {
    const init = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    let url = App.apiUrl(route, method === 'GET' && params ? params : {});
    if (method === 'POST') {
      init.headers['Content-Type'] = 'application/json';
      init.headers['X-CSRF-Token'] = csrfToken;
      init.body = JSON.stringify(body || {});
    }
    let res;
    try {
      res = await fetch(url, init);
    } catch (e) {
      throw new ApiError('Cannot reach the server. Check your connection.', 0);
    }
    let json = null;
    try {
      json = await res.json();
    } catch (e) {
      throw new ApiError('Unexpected response from the server (' + res.status + ').', res.status);
    }
    if (res.status === 401 && route !== 'auth.staff_login' && route !== 'auth.code_login') {
      App.toast('Your session ended. Please sign in again.', 'error');
      setTimeout(() => location.replace(App.url('index.html')), 900);
    }
    if (!json.ok) throw new ApiError(json.error || 'Request failed.', res.status);
    return json;
  }

  App.get = (route, params) => request(route, { method: 'GET', params });
  App.post = async (route, body) => {
    const r = await request(route, { method: 'POST', body });
    App.live.ownChange(); // this page already shows its own change: don't reload it again
    return r;
  };

  const UPLOAD_RETRIES = 5;
  const waitForSignal = () =>
    navigator.onLine === false
      ? new Promise((resolve) => window.addEventListener('online', resolve, { once: true }))
      : Promise.resolve();

  /**
   * Multipart upload with progress callback (0..100).
   * No time limit: a slow signal can take as long as it needs. If the connection drops,
   * the upload waits for the signal to come back and starts again (up to 5 times).
   */
  App.upload = async (route, formData, onProgress) => {
    for (let attempt = 0; ; attempt++) {
      try {
        return await sendUpload(route, formData, onProgress);
      } catch (err) {
        if (err.status !== 0 || attempt >= UPLOAD_RETRIES) throw err;
        const wait = Math.min(30, 2 ** attempt * 2);
        App.toast(navigator.onLine === false
          ? 'No signal. The upload will continue when you are back online…'
          : `Connection is slow or dropped. Trying the upload again in ${wait}s (${attempt + 1} of ${UPLOAD_RETRIES})…`, 'info', wait * 1000);
        await waitForSignal();
        await new Promise((r) => setTimeout(r, wait * 1000));
        onProgress && onProgress(0);
      }
    }
  };

  const sendUpload = (route, formData, onProgress) =>
    new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', App.apiUrl(route));
      xhr.timeout = 0; // never time out: slow signal is expected
      xhr.setRequestHeader('X-CSRF-Token', csrfToken);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = (e) => e.lengthComputable && onProgress && onProgress(Math.round((e.loaded / e.total) * 100));
      xhr.upload.onload = () => onProgress && onProgress(100);
      xhr.onerror = () => reject(new ApiError('Upload failed. Check your connection.', 0));
      xhr.onabort = () => reject(new ApiError('Upload was interrupted.', 0));
      xhr.onload = () => {
        let json = null;
        try { json = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
        if (!json) {
          // 413 = file larger than the server allows; 502/504 = a proxy gave up waiting
          const hint = xhr.status === 413 ? 'The file is too large for the server.' : `Unexpected response from the server (${xhr.status}).`;
          return reject(new ApiError(hint, xhr.status));
        }
        if (!json.ok) return reject(new ApiError(json.error || 'Upload failed.', xhr.status));
        resolve(json);
      };
      xhr.send(formData);
    });

  /* ------------------------------------------------------------ DOM helpers */

  App.esc = (v) =>
    String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /** Creates an element from an HTML string (first element) */
  App.h = (html) => {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  };

  App.$ = (sel, root = document) => root.querySelector(sel);
  App.$$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  App.param = (name) => new URLSearchParams(location.search).get(name);

  App.debounce = (fn, ms = 300) => {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  };

  App.num = (n, digits = 2) => {
    if (n === null || n === undefined || n === '') return '—';
    const v = Number(n);
    return Number.isInteger(v) && digits <= 2 ? String(v) : v.toFixed(digits).replace(/\.?0+$/, '');
  };

  App.fmtDate = (s) => {
    if (!s) return '';
    const d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? s : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  };
  App.fmtDateTime = (s) => {
    if (!s) return '';
    const d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? s : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  };
  /** "Nov 10, 2026 · 7:30 AM – 5:00 PM" or "Nov 10, 7:30 AM – Nov 12, 2026, 5:00 PM" */
  App.dateTimeRange = (a, b) => {
    const parse = (s) => (s ? new Date(String(s).replace(' ', 'T')) : null);
    const da = parse(a), db = parse(b);
    if (!da || isNaN(da)) return '';
    const time = (d) => d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    const day = (d, year) => d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', ...(year ? { year: 'numeric' } : {}) });
    if (!db || isNaN(db)) return day(da, true) + ' · ' + time(da);
    if (da.toDateString() === db.toDateString()) return day(da, true) + ' · ' + time(da) + ' – ' + time(db);
    return day(da, da.getFullYear() !== db.getFullYear()) + ', ' + time(da) + ' – ' + day(db, true) + ', ' + time(db);
  };

  App.dateRange = (a, b) => {
    if (!a && !b) return '';
    if (a && b && a !== b) return App.fmtDate(a) + ' – ' + App.fmtDate(b);
    return App.fmtDate(a || b);
  };

  const STATUS_LABEL = { open: 'Scoring open', pending: 'Pending', closed: 'Closed', draft: 'Draft', upcoming: 'Upcoming', ongoing: 'Ongoing', completed: 'Completed', cancelled: 'Cancelled' };
  App.badge = (status, label = null) => `<span class="badge badge-${App.esc(status)}">${App.esc(label || STATUS_LABEL[status] || status)}</span>`;
  /** Status wording for bracket / round robin / ranking activities. */
  App.MATCH_STATUS = { pending: 'Not started', open: 'In progress', closed: 'Final' };
  App.pts = (n) => App.num(n) + (Number(n) === 1 ? ' pt' : ' pts');
  App.roleLabel = (role) => ({ admin: 'Administrator', program_head: 'Program Head', facilitator: 'Facilitator', judge: 'Judge' }[role] || role);

  App.setLoading = (btn, loading) => {
    if (!btn) return;
    btn.classList.toggle('is-loading', !!loading);
    btn.disabled = !!loading;
  };

  App.copy = async (text) => {
    try {
      await navigator.clipboard.writeText(text);
    } catch (e) {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
    App.toast('Copied: ' + text, 'success');
  };

  /** Repeats fn every ms while the tab is visible. Returns a stop function. */
  App.poll = (fn, ms) => {
    let timer = null;
    const tick = async () => {
      if (!document.hidden) {
        try { await fn(); } catch (e) { /* keep polling */ }
      }
      timer = setTimeout(tick, ms);
    };
    timer = setTimeout(tick, ms);
    return () => clearTimeout(timer);
  };

  /**
   * Live updates without refreshing: checks every few seconds whether anything changed
   * (scores, results, matches, activities, teams, codes…) and calls onChange() when it did.
   * It waits while someone is typing, a dialog is open or the bracket is being arranged.
   * params: { event_id } for one event, {} for the whole system. Returns a stop() function.
   */
  const liveWatchers = new Set();
  App.live = (params, onChange, ms = 1000 * (App.session?.app?.live_seconds || 4)) => {
    const w = { last: null, quietUntil: 0, running: false, timer: null, stopped: false };
    const busy = () => {
      if (document.querySelector('dialog[open], .is-arranging, [data-live-pause]')) return true;
      const el = document.activeElement;
      if (!el || el === document.body) return false;
      const typing = el.matches('textarea, select, input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit])');
      return typing && Boolean(el.closest('#view, .page-content, .judge-main'));
    };
    const check = async () => {
      if (w.stopped || w.running) return;
      if (document.hidden) return schedule();
      w.running = true;
      try {
        const { v } = await App.get('sync.version', params || {});
        if (w.last === null || Date.now() < w.quietUntil) {
          w.last = v;
        } else if (v !== w.last && !busy()) {
          w.last = v;
          await onChange();
        }
      } catch (e) { /* offline for a moment: try again next time */ }
      w.running = false;
      schedule();
    };
    const schedule = () => {
      clearTimeout(w.timer);
      if (!w.stopped) w.timer = setTimeout(check, ms);
    };
    w.check = check;
    liveWatchers.add(w);
    check();
    return () => { w.stopped = true; clearTimeout(w.timer); liveWatchers.delete(w); };
  };
  /** After this page saves something, take the new version as seen (it reloads on its own). */
  App.live.ownChange = () => {
    liveWatchers.forEach((w) => { w.quietUntil = Date.now() + 2500; });
  };
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) liveWatchers.forEach((w) => w.check && w.check());
  });

  App.loading = (text = 'Loading…') => `<div class="loading"><div class="spinner"></div><div>${App.esc(text)}</div></div>`;
  App.empty = (title, text = '', icon = 'inbox') =>
    `<div class="empty">${App.icon(icon)}<h3>${App.esc(title)}</h3>${text ? `<p>${text}</p>` : ''}</div>`;

  /* ------------------------------------------------------------ entry look (colour + picture) */

  /** Preset team / contestant colours — deep, solid tones that carry white text. */
  App.COLORS = [
    ['#00461B', 'Deep Green'], ['#14532D', 'Dark Green'], ['#166534', 'Forest'],
    ['#1E3A8A', 'Dark Blue'], ['#172554', 'Navy'], ['#1D4ED8', 'Royal Blue'],
    ['#7F1D1D', 'Maroon'], ['#B91C1C', 'Crimson'], ['#5B21B6', 'Purple'],
    ['#A16207', 'Gold'], ['#1A1A1A', 'Black'], ['#334155', 'Slate'],
  ];

  /** photo: { r, id, v } from the API -> image URL. */
  App.photoUrl = (photo) => (photo ? App.apiUrl(photo.r, { id: photo.id, v: photo.v }) : '');

  /** Readable text colour (white or near-black) on a hex background. */
  App.textOn = (hex) => {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
    if (!m) return '#1A1A1A';
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16) / 255)
      .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.36 ? '#1A1A1A' : '#FFFFFF';
  };

  App.initials = (name) => {
    const words = String(name || '').replace(/[^\p{L}\p{N}\s]/gu, ' ').trim().split(/\s+/).filter(Boolean);
    if (!words.length) return '?';
    return (words.length === 1 ? words[0].slice(0, 2) : words[0][0] + words[words.length - 1][0]).toUpperCase();
  };

  /**
   * Round picture of a team / contestant: the uploaded photo, else initials on its colour.
   * entity: { name, color?, photo? }   size: xs | sm | md | lg | xl
   */
  App.avatar = (entity, size = 'sm') => {
    const e = entity || {};
    const color = e.color || '';
    const ring = color ? ` style="--entry:${App.esc(color)}"` : '';
    if (e.photo) {
      return `<span class="avatar avatar-${size} has-photo ${color ? 'has-color' : ''}"${ring}><img src="${App.esc(App.photoUrl(e.photo))}" alt="" loading="lazy" decoding="async"></span>`;
    }
    const style = color ? ` style="--entry:${App.esc(color)};background:${App.esc(color)};color:${App.textOn(color)}"` : '';
    return `<span class="avatar avatar-${size} ${color ? 'has-color' : ''}"${style} aria-hidden="true">${App.esc(App.initials(e.name))}</span>`;
  };

  /** Avatar + name (+ optional second line). */
  App.entry = (e, sub = '', size = 'sm') =>
    `<span class="entry">${App.avatar(e, size)}<span class="entry-text"><strong>${App.esc(e.name)}</strong>${sub ? `<small>${App.esc(sub)}</small>` : ''}</span></span>`;

  /** Downscales large photos in the browser before upload (faster on mobile data, fits OCR limits). */
  App.compressImage = (file) =>
    new Promise((resolve) => {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 1.2 * 1024 * 1024) return resolve(file);
      const img = new Image();
      const url = URL.createObjectURL(file);
      img.onload = () => {
        const scale = Math.min(1, 2400 / Math.max(img.width, img.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);
        canvas.toBlob((blob) => {
          if (!blob || blob.size >= file.size) return resolve(file);
          resolve(new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
        }, 'image/jpeg', 0.9);
      };
      img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });

  /** Accepted document types for scanned uploads. */
  App.DOC_ACCEPT = '.jpg,.jpeg,.png,.webp,.bmp,.tif,.tiff,.gif,.pdf,.docx,.txt,image/*';

  /* ------------------------------------------------------------ icons */

  const ICONS = {
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    trash: '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
    file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
    print: '<path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
    copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    refresh: '<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
    key: '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
    move: '<path d="M5 9l-3 3 3 3M9 5l3-3 3 3M15 19l-3 3-3-3M19 9l3 3-3 3M2 12h20M12 2v20"/>',
    expand: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
    shrink: '<path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/>',
    trophy: '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>',
    check: '<path d="M20 6L9 17l-5-5"/>',
    lock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    unlock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
    eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    chevronLeft: '<path d="M15 18l-6-6 6-6"/>',
    chevronRight: '<path d="M9 18l6-6-6-6"/>',
    up: '<path d="M18 15l-6-6-6 6"/>',
    down: '<path d="M6 9l6 6 6-6"/>',
    x: '<path d="M18 6L6 18M6 6l12 12"/>',
    scan: '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 12h10"/>',
    clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    history: '<path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/>',
    flow: '<rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="15" width="6" height="6" rx="1"/><path d="M6 9v3a3 3 0 0 0 3 3h6"/>',
    shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
    image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
    archive: '<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8M10 12h4"/>',
    restore: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
    alert: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
    moon: '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
    grid: '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
  };
  App.icon = (name, cls = 'icon') =>
    `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name] || ''}</svg>`;

  /* ------------------------------------------------------------ activity details (rules & scoring) */

  /** Activity description as stored by the document scan → { facts: [[label, value]], sections: [{ title, items }] , text } */
  App.parseActivityInfo = (description) => {
    const info = { facts: [], sections: [], text: [] };
    let section = null;
    String(description || '').split(/\r?\n/).map((l) => l.trim()).filter(Boolean).forEach((line) => {
      const heading = /^(rules|rules & scoring|scoring & rules|how to score|mechanics|rules and regulations)\s*:?$/i.exec(line);
      if (heading) {
        section = { title: line.replace(/:$/, ''), items: [] };
        info.sections.push(section);
        return;
      }
      if (section && /^[•\-–*]\s*/.test(line)) {
        section.items.push(line.replace(/^[•\-–*]\s*/, ''));
        return;
      }
      const kv = /^([A-Za-z][A-Za-z &/]{1,30}):\s*(.+)$/.exec(line);
      if (kv && !section) info.facts.push([kv[1], kv[2]]);
      else if (section) section.items.push(line);
      else info.text.push(line);
    });
    return info;
  };

  /** "Rules & scoring" card for an activity (empty string when there is nothing to show). */
  App.activityInfoCard = (activity) => {
    const info = App.parseActivityInfo(activity.description);
    if (!info.facts.length && !info.sections.length && !info.text.length) return '';
    const esc = App.esc;
    const rule = (item) => {
      const m = /^([^:]{2,40}):\s*(.+)$/.exec(item);
      return m ? `<li><strong>${esc(m[1])}:</strong> ${esc(m[2])}</li>` : `<li>${esc(item)}</li>`;
    };
    return `
      <details class="card info-card" open>
        <summary class="card-head"><h3>${App.icon('list')} Rules &amp; scoring</h3><span class="muted small">From the event document</span></summary>
        <div class="card-body">
          ${info.text.length ? `<p>${info.text.map(esc).join('<br>')}</p>` : ''}
          ${info.facts.length ? `<dl class="info-facts">${info.facts.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('')}</dl>` : ''}
          ${info.sections.map((s) => `<div class="info-rules"><h4>${esc(s.title)}</h4><ul>${s.items.map(rule).join('')}</ul></div>`).join('')}
        </div>
      </details>`;
  };


  /* ------------------------------------------------------------ toasts */

  App.toast = (message, type = 'info', ms = 3800) => {
    let box = document.querySelector('.toasts');
    if (!box) {
      box = document.createElement('div');
      box.className = 'toasts';
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      document.body.appendChild(box);
    }
    const t = App.h(`<div class="toast ${type}">${App.esc(message)}</div>`);
    box.appendChild(t);
    setTimeout(() => t.remove(), ms);
  };
  App.fail = (err) => App.toast(err && err.message ? err.message : String(err), 'error', 5500);

  /* ------------------------------------------------------------ dialogs */

  /**
   * Opens a modal form.
   * opts: { title, body (HTML), submitText, wide, danger, onSubmit(data, form, dialog) -> Promise<bool|void>, onOpen(form) }
   * Resolves when closed. If onSubmit returns false the dialog stays open.
   */
  App.modal = (opts) =>
    new Promise((resolve) => {
      const dlg = App.h(`
        <dialog class="modal ${opts.wide ? 'wide' : ''}">
          <form class="modal-form" novalidate>
            <div class="modal-head">
              <h2>${App.esc(opts.title || '')}</h2>
              <button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Close">${App.icon('x')}</button>
            </div>
            <div class="modal-body">${opts.body || ''}</div>
            ${opts.hideFooter ? '' : `<div class="modal-foot">
              <button type="button" class="btn" data-close>${App.esc(opts.cancelText || 'Cancel')}</button>
              ${opts.submitText === null ? '' : `<button type="submit" class="btn ${opts.danger ? 'btn-dark' : 'btn-primary'}">${App.esc(opts.submitText || 'Save')}</button>`}
            </div>`}
          </form>
        </dialog>`);
      document.body.appendChild(dlg);
      const form = dlg.querySelector('form');
      let result;
      const close = (value) => {
        result = value;
        dlg.close();
      };
      dlg.addEventListener('close', () => {
        dlg.remove();
        resolve(result);
      });
      dlg.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => close(undefined)));
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!form.checkValidity()) {
          form.reportValidity();
          return;
        }
        const btn = form.querySelector('[type=submit]');
        if (!opts.onSubmit) return close(App.formData(form));
        App.setLoading(btn, true);
        try {
          const r = await opts.onSubmit(App.formData(form), form, dlg);
          if (r !== false) close(r === undefined ? true : r);
        } catch (err) {
          App.fail(err);
        } finally {
          App.setLoading(btn, false);
        }
      });
      dlg.showModal();
      if (opts.onOpen) opts.onOpen(form, dlg, close);
      const first = form.querySelector('.modal-body input:not([type=hidden]):not([type=checkbox]), .modal-body select, .modal-body textarea');
      if (first && window.matchMedia('(min-width: 641px)').matches) first.focus();
    });

  App.confirm = ({ title = 'Are you sure?', message = '', confirmText = 'Confirm', danger = false } = {}) =>
    App.modal({ title, body: `<p>${message}</p>`, submitText: confirmText, danger, onSubmit: () => true }).then((r) => r === true);

  /** Form fields -> object (checkboxes -> booleans, multi checkboxes named x[] -> arrays). */
  App.formData = (form) => {
    const data = {};
    form.querySelectorAll('input, select, textarea').forEach((el) => {
      if (!el.name || el.disabled || el.type === 'file') return;
      if (el.name.endsWith('[]')) {
        const key = el.name.slice(0, -2);
        data[key] = data[key] || [];
        if (el.type === 'checkbox' ? el.checked : el.value !== '') data[key].push(el.value);
      } else if (el.type === 'checkbox') {
        data[el.name] = el.checked;
      } else if (el.type === 'radio') {
        if (el.checked) data[el.name] = el.value;
      } else {
        data[el.name] = el.value;
      }
    });
    return data;
  };

  /* ------------------------------------------------------------ tabs */

  /** Wires .tabs buttons (data-tab) to panels (data-panel). Remembers the tab in the URL hash. */
  App.tabs = (root, onChange) => {
    const buttons = App.$$('.tabs [data-tab]', root);
    const panels = App.$$('[data-panel]', root);
    const show = (name, push = true) => {
      if (!buttons.some((b) => b.dataset.tab === name && !b.hidden)) name = buttons.find((b) => !b.hidden)?.dataset.tab;
      buttons.forEach((b) => b.setAttribute('aria-selected', String(b.dataset.tab === name)));
      panels.forEach((p) => (p.hidden = p.dataset.panel !== name));
      if (push) history.replaceState(null, '', '#' + name);
      onChange && onChange(name);
    };
    buttons.forEach((b) => b.addEventListener('click', () => show(b.dataset.tab)));
    // Follow in-page links such as href="#codes" (only while this tab set is still on the page)
    const onHash = () => {
      if (!root.isConnected || !buttons[0]?.isConnected) return window.removeEventListener('hashchange', onHash);
      const name = location.hash.slice(1);
      if (buttons.some((b) => b.dataset.tab === name)) show(name, false);
    };
    window.addEventListener('hashchange', onHash);
    show(location.hash.slice(1) || buttons[0]?.dataset.tab, false);
    return show;
  };

  /* ------------------------------------------------------------ session & shell */

  const NAV = {
    admin: [
      { href: 'dashboard.html', icon: 'calendar', label: 'Events', key: 'events' },
      { href: 'users.html', icon: 'users', label: 'Staff accounts', key: 'users' },
      { href: 'logs.html', icon: 'history', label: 'Activity logs', key: 'logs' },
      { href: 'account.html', icon: 'user', label: 'My account', key: 'account' },
    ],
    program_head: [
      { href: 'dashboard.html', icon: 'calendar', label: 'My events', key: 'events' },
      { href: 'account.html', icon: 'user', label: 'My account', key: 'account' },
    ],
    facilitator: [{ href: 'event.html?id={event}', icon: 'calendar', label: 'Event console', key: 'events' }],
  };

  /**
   * Loads the session, enforces roles and renders the app shell around #view.
   * opts: { roles: [...]|'public', nav: 'events'|'users'|..., layout: 'app'|'judge'|'bare' }
   */
  App.boot = async (opts = {}) => {
    const notice = (title, html) => {
      document.body.className = 'auth-body';
      document.body.innerHTML = `<div class="auth-bg"></div><div class="auth-wrap"><div class="auth-card">
        <div class="auth-head"><img src="${App.url('assets/images/app-icon.png')}" alt="">
          <div><span class="auth-kicker">Tabulation System</span><h1 class="auth-title">${title}</h1></div></div>${html}</div></div>`;
    };
    if (location.protocol === 'file:') {
      notice('Open through the web server', `
        <p>This page was opened as a file, so the PHP server cannot run. Open the system through the web server instead.</p>
        <p><a class="btn btn-primary btn-block" href="http://localhost/Scoring/">Go to the homepage</a></p>
        <p class="hint">Make sure Apache and MySQL are running (Laragon or XAMPP).</p>`);
      return halt();
    }
    // Draw the sidebar and top bar at once from the last session (no blank page between menu clicks);
    // the server check below still decides who may see the page.
    const SHELL_KEY = 'coc-shell';
    let drawn = null;
    if ((opts.layout !== 'bare' || opts.nav) && opts.roles !== 'public') {
      try {
        const cached = JSON.parse(sessionStorage.getItem(SHELL_KEY) || 'null');
        const allowed = cached && cached.user && (!opts.roles || opts.roles.includes(cached.user.role));
        // the dashboard (bare) only draws early for staff; judges and facilitators are sent elsewhere
        const staffOnly = opts.layout !== 'bare' || (cached && cached.user && cached.user.kind === 'staff');
        if (allowed && staffOnly) {
          ensureShell(cached, opts);
          drawn = cached.user;
        }
      } catch (e) { /* no cache: draw after the check */ }
    }
    let me;
    try {
      me = await App.get('auth.me');
      try {
        if (me.user) sessionStorage.setItem(SHELL_KEY, JSON.stringify({ ...me, csrf: '' }));
        else sessionStorage.removeItem(SHELL_KEY);
      } catch (e) { /* private mode */ }
    } catch (e) {
      notice('Server unavailable', `<p class="auth-sub" style="margin-bottom:18px">${App.esc(e.message)}</p>
        <button class="btn btn-primary btn-block" onclick="location.reload()">Try again</button>`);
      throw e;
    }
    csrfToken = me.csrf;
    App.session = me;
    App.user = me.user;
    if (!me.installed) {
      location.replace(App.url('install/setup.php'));
      return halt();
    }
    if (opts.roles === 'public') return me;
    if (!me.user) {
      location.replace(App.url('index.html'));
      return halt();
    }
    if (opts.roles && !opts.roles.includes(me.user.role)) {
      location.replace(App.page('dashboard.html'));
      return halt();
    }
    if (opts.layout !== 'bare') ensureShell(me, opts);
    else if (drawn && !sameUser(drawn, me.user)) removeShell();
    return me;
  };

  /** A promise that never settles: stops the page script while the browser redirects. */
  const halt = () => new Promise(() => {});

  /** Renders the shell later (pages that booted with layout: 'bare'). */
  App.shell = (opts = {}) => {
    ensureShell(App.session, opts);
    return App.session;
  };

  const sameUser = (a, b) => Boolean(a && b && a.id === b.id && a.role === b.role && a.name === b.name && a.kind === b.kind);
  let shellUser = null;
  /** Draws the sidebar/top bar once per page; redraws only when the signed-in user differs. */
  function ensureShell(me, opts) {
    if (document.querySelector('.app-container') && sameUser(shellUser, me.user)) return;
    removeShell();
    shellUser = me.user;
    renderShell(me, opts);
  }
  function removeShell() {
    const box = document.querySelector('.app-container');
    if (!box) return;
    const view = document.getElementById('view');
    if (view) document.body.appendChild(view); // keep the page content element
    box.remove();
    shellUser = null;
  }

  App.logout = async () => {
    try {
      await App.post('auth.logout');
    } finally {
      try { sessionStorage.removeItem('coc-shell'); } catch (e) { /* ignore */ }
      location.replace(App.url('index.html'));
    }
  };

  /** "Logging out?" confirmation, same as the COC LMS. */
  App.confirmLogout = () =>
    new Promise((resolve) => {
      const dlg = App.h(`
        <dialog class="modal small">
          <div class="logout-card">
            <div class="lm-icon">${App.icon('logout')}</div>
            <h3>Logging out?</h3>
            <p>You'll need to sign in again to access the tabulation system.</p>
            <div class="lm-actions">
              <button type="button" class="btn" data-stay>Stay</button>
              <button type="button" class="btn btn-primary" data-go>Yes, Logout</button>
            </div>
          </div>
        </dialog>`);
      document.body.appendChild(dlg);
      let go = false;
      dlg.addEventListener('close', () => { dlg.remove(); resolve(go); });
      dlg.addEventListener('click', (e) => e.target === dlg && dlg.close());
      dlg.querySelector('[data-stay]').addEventListener('click', () => dlg.close());
      dlg.querySelector('[data-go]').addEventListener('click', () => { go = true; dlg.close(); });
      dlg.showModal();
    }).then((go) => go && App.logout());

  const initials = (name) => String(name || '?').trim().split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();

  function renderShell(me, opts) {
    const u = me.user;
    const view = document.getElementById('view');
    const judgeLayout = opts.layout === 'judge' || u.role === 'judge';
    const items = (NAV[u.role] || []).map((i) => ({ ...i, href: i.href.replace('{event}', u.event_id) }));
    const withSidebar = !judgeLayout && items.length > 0;
    const logo = App.url('assets/images/app-icon.png');

    const container = App.h(`
      <div class="app-container">
        ${withSidebar ? `
        <aside class="sidebar" id="sidebar" aria-label="Main menu">
          <div class="sidebar-header">
            <a class="logo" href="${App.page('dashboard.html')}">
              <img class="logo-img" src="${logo}" alt="PHINMA Education">
              <span class="logo-school">${App.esc(me.app.school)}</span>
              <span class="logo-system">Tabulation</span>
            </a>
          </div>
          <nav class="sidebar-nav">
            <span class="nav-section-title">Menu</span>
            ${items.map((i) => `
              <a href="${App.page(i.href)}" class="nav-item ${i.key === opts.nav ? 'active' : ''}">
                <span class="nav-icon">${App.icon(i.icon)}</span><span class="nav-text">${App.esc(i.label)}</span>
              </a>`).join('')}
          </nav>
          <div class="sidebar-foot">
            <span class="sidebar-foot-mark"></span>
            <span>PHINMA COC · Scoring</span>
          </div>
        </aside>` : ''}
        <main class="main-content ${withSidebar ? 'with-sidebar' : ''}">
          <header class="topbar">
            <div class="topbar-left">
              ${withSidebar
                ? `<button type="button" class="topbar-btn mobile-menu-btn" aria-label="Toggle menu" aria-expanded="false">${App.icon('menu')}</button>
                   <a class="topbar-brand mobile-brand" href="${App.page('dashboard.html')}"><img src="${logo}" alt=""><span><strong>Tabulation</strong><small>${App.esc(me.app.school)}</small></span></a>`
                : `<a class="topbar-brand" href="${App.page('dashboard.html')}"><img src="${logo}" alt=""><span><strong>Tabulation</strong><small>${App.esc(me.app.school)}</small></span></a>`}
              ${withSidebar ? `<div class="topbar-date"><span data-today-day></span><strong data-today-date></strong></div>` : ''}
            </div>
            <div class="topbar-right">
              <span class="topbar-clock" data-clock aria-label="Current time"></span>
              <div class="dropdown" data-user-menu>
                <button type="button" class="topbar-user" aria-haspopup="true" aria-expanded="false">
                  <span class="topbar-user-avatar">${App.esc(initials(u.name))}</span>
                  <span class="topbar-user-info">
                    <span class="topbar-user-name">${App.esc(u.name)}</span>
                    <span class="topbar-user-role">${App.esc(App.roleLabel(u.role))}${u.program ? ' · ' + App.esc(u.program) : ''}</span>
                  </span>
                  <span class="dropdown-arrow">${App.icon('down')}</span>
                </button>
                <div class="dropdown-menu" role="menu">
                  <div class="dropdown-header"><strong>${App.esc(u.name)}</strong><span>${App.esc(App.roleLabel(u.role))}</span></div>
                  ${u.kind === 'staff' ? `<a class="dropdown-item" href="${App.page('account.html')}" role="menuitem">${App.icon('user')}<span>My account</span></a>` : ''}
                  <div class="dropdown-divider"></div>
                  <button type="button" class="dropdown-item danger" data-logout role="menuitem">${App.icon('logout')}<span>Logout</span></button>
                </div>
              </div>
            </div>
          </header>
          <div class="${judgeLayout ? 'judge-main' : 'page-content'}"></div>
          <footer class="app-footer">© ${new Date().getFullYear()} ${App.esc(me.app.school)} · Multi-Event Tabulation System</footer>
        </main>
      </div>`);
    container.querySelector('.judge-main, .page-content').appendChild(view);
    document.body.prepend(container);

    container.querySelectorAll('[data-logout]').forEach((b) => b.addEventListener('click', App.confirmLogout));

    // Date and live clock in the top bar
    const tick = () => {
      const now = new Date();
      const day = container.querySelector('[data-today-day]');
      const date = container.querySelector('[data-today-date]');
      if (day) day.textContent = now.toLocaleDateString(undefined, { weekday: 'long' });
      if (date) date.textContent = now.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
      container.querySelector('[data-clock]').textContent = now.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    };
    tick();
    setInterval(tick, 15000);

    // User menu
    const menu = container.querySelector('[data-user-menu]');
    if (menu) {
      const menuBtn = menu.querySelector('.topbar-user');
      menuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = !menu.classList.contains('active');
        menu.classList.toggle('active', open);
        menuBtn.setAttribute('aria-expanded', String(open));
      });
      document.addEventListener('click', () => {
        menu.classList.remove('active');
        menuBtn.setAttribute('aria-expanded', 'false');
      });
    }

    if (!withSidebar) return;

    // Mobile sidebar
    const sidebar = container.querySelector('.sidebar');
    const toggle = container.querySelector('.mobile-menu-btn');
    let backdrop = null;
    const setOpen = (open) => {
      sidebar.classList.toggle('active', open);
      document.body.classList.toggle('sidebar-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      if (open && !backdrop) {
        backdrop = App.h('<div class="sidebar-backdrop"></div>');
        backdrop.addEventListener('click', () => setOpen(false));
        document.body.appendChild(backdrop);
      } else if (!open && backdrop) {
        backdrop.remove();
        backdrop = null;
      }
    };
    toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('active')));
    window.addEventListener('keydown', (e) => e.key === 'Escape' && setOpen(false));
  }
})();
