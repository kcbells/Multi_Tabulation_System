/* Staff: profile summary and password change. */
(async function () {
  'use strict';
  const me = await App.boot({ roles: ['admin', 'program_head'], nav: 'account' });
  const { esc } = App;
  const view = App.$('#view');
  const u = me.user;

  view.innerHTML = `
    <div class="page-head"><div><div class="eyebrow">Account</div><h1>My account</h1></div></div>
    <div class="card"><div class="card-body">
      <div class="row" style="gap:16px">
        <span class="brand-mark" style="width:56px;height:56px;flex-basis:56px;font-size:1rem">${esc(u.name.split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase())}</span>
        <div><h2>${esc(u.name)}</h2><div class="muted">${esc(App.roleLabel(u.role))}${u.program ? ' · ' + esc(u.program) : ''}</div></div>
      </div>
    </div></div>
    <div class="card">
      <div class="card-head"><h3>Change password</h3></div>
      <div class="card-body">
        <form class="stack" id="pw-form" novalidate>
          <label class="field"><span>Current password</span><input type="password" name="current_password" required autocomplete="current-password"></label>
          <label class="field"><span>New password <em>(at least 8 characters)</em></span><input type="password" name="new_password" required minlength="8" autocomplete="new-password"></label>
          <label class="field"><span>Confirm new password</span><input type="password" name="confirm" required minlength="8" autocomplete="new-password"></label>
          <div class="row" style="justify-content:flex-end"><button class="btn btn-primary" type="submit">Update password</button></div>
        </form>
      </div>
    </div>`;

  const form = App.$('#pw-form');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!form.checkValidity()) return form.reportValidity();
    const data = App.formData(form);
    if (data.new_password !== data.confirm) return App.toast('The new passwords do not match.', 'error');
    const btn = form.querySelector('[type=submit]');
    App.setLoading(btn, true);
    try {
      App.toast((await App.post('auth.change_password', data)).message, 'success');
      form.reset();
    } catch (err) {
      App.fail(err);
    } finally {
      App.setLoading(btn, false);
    }
  });
})();
