/* Admin: staff accounts (administrators and program heads). */
(async function () {
  'use strict';
  await App.boot({ roles: ['admin'], nav: 'users' });
  const { esc } = App;
  const view = App.$('#view');
  let users = [];
  let myId = 0;

  let liveStarted = false;
  async function load() {
    if (!liveStarted) { liveStarted = true; App.live({}, () => load()); }
    const r = await App.get('users.list');
    users = r.users;
    myId = r.me;
    render();
  }

  function render() {
    view.innerHTML = `
      ${App.pathBar({ back: { href: App.page('dashboard.html'), label: 'events' }, trail: [{ label: 'Events', href: App.page('dashboard.html') }, { label: 'Staff accounts' }] })}
      <div class="page-head">
        <div><div class="eyebrow">Administration</div><h1>Staff accounts</h1>
          <div class="meta">Administrators and program heads sign in with a username and password. Judges and facilitators use access codes instead.</div></div>
        <button class="btn btn-primary" data-add>${App.icon('plus')} New account</button>
      </div>
      <div class="card">
        <div class="table-wrap"><table class="table stackable">
          <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Program</th><th class="num">Events</th><th>Last sign-in</th><th>Status</th><th class="actions"></th></tr></thead>
          <tbody>${users.map((u) => `<tr>
            <td class="primary" data-label="Name">${esc(u.name)}${Number(u.id) === Number(myId) ? ' <span class="badge badge-green no-dot">You</span>' : ''}</td>
            <td data-label="Username" class="mono">${esc(u.username)}</td>
            <td data-label="Role">${esc(App.roleLabel(u.role))}</td>
            <td data-label="Program">${u.program ? esc(u.program) : '<span class="muted">—</span>'}</td>
            <td data-label="Events" class="num">${u.events}</td>
            <td data-label="Last sign-in">${u.last_login_at ? esc(App.fmtDateTime(u.last_login_at)) : '<span class="muted">Never</span>'}</td>
            <td data-label="Status">${Number(u.is_active) ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-pending">Disabled</span>'}</td>
            <td class="actions">
              <button class="btn btn-sm" data-edit="${u.id}">${App.icon('edit')} Edit</button>
              ${Number(u.id) === Number(myId) ? '' : `<button class="btn btn-sm btn-danger" data-delete="${u.id}" title="Delete">${App.icon('trash')}</button>`}
            </td></tr>`).join('')}
          </tbody></table></div>
      </div>`;

    App.$('[data-add]', view).addEventListener('click', () => edit(null));
    App.$$('[data-edit]', view).forEach((b) => b.addEventListener('click', () => edit(users.find((u) => u.id == b.dataset.edit))));
    App.$$('[data-delete]', view).forEach((b) => b.addEventListener('click', async () => {
      const u = users.find((x) => x.id == b.dataset.delete);
      if (!(await App.confirm({ title: 'Delete account?', message: `Delete <strong>${esc(u.name)}</strong>? Their events are kept and can be reassigned to another owner.`, confirmText: 'Delete', danger: true }))) return;
      try { App.toast((await App.post('users.delete', { id: u.id })).message, 'success'); load(); } catch (err) { App.fail(err); }
    }));
  }

  async function edit(user) {
    const u = user || { role: 'program_head', is_active: 1 };
    const saved = await App.modal({
      title: user ? 'Edit account' : 'New staff account',
      submitText: user ? 'Save' : 'Create account',
      body: `
        <div class="form-grid two">
          <label class="field span-2"><span>Full name</span><input name="name" required maxlength="120" value="${esc(u.name)}"></label>
          <label class="field"><span>Username</span><input name="username" required maxlength="60" pattern="[a-zA-Z0-9._\\-]{3,60}" autocapitalize="none" value="${esc(u.username)}"></label>
          <label class="field"><span>Role</span><select name="role">
            <option value="program_head" ${u.role === 'program_head' ? 'selected' : ''}>Program Head</option>
            <option value="admin" ${u.role === 'admin' ? 'selected' : ''}>Administrator</option>
          </select></label>
          <label class="field span-2"><span>Program / department <em>(optional)</em></span><input name="program" maxlength="120" value="${esc(u.program)}" placeholder="e.g. College of Information Technology"></label>
          <label class="field span-2"><span>${user ? 'New password <em>(leave blank to keep current)</em>' : 'Password'}</span>
            <input name="password" type="password" minlength="8" autocomplete="new-password" ${user ? '' : 'required'}></label>
          <label class="check span-2"><input type="checkbox" name="is_active" ${Number(u.is_active) ? 'checked' : ''}><span>Account is active</span></label>
        </div>`,
      onSubmit: async (data) => {
        const r = await App.post('users.save', { ...data, id: user ? user.id : 0 });
        App.toast(r.message, 'success');
      },
    });
    if (saved) load();
  }

  try { await load(); } catch (err) { view.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`; }
})();
