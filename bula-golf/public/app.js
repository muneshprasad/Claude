'use strict';
const API = 'api/index.php';
const $ = (s, r = document) => r.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
let ME = null, CUR = 'FJD', COURSE = 'Bula Golf', timer = null;

async function api(route, { method = 'GET', body, params } = {}) {
  const qs = new URLSearchParams({ r: route, ...(params || {}) });
  let res;
  try {
    res = await fetch(`${API}?${qs}`, {
      method, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'bula', ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch { throw new Error('No connection to the server. Check Wi-Fi and try again.'); }
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && ME) { ME = null; login(); throw new Error('Please sign in again'); }
  if (!res.ok) throw new Error(data.error || 'Something went wrong');
  return data;
}
function toast(msg, err = false) {
  const t = $('#toast'); t.textContent = msg; t.className = 'show' + (err ? ' err' : '');
  clearTimeout(toast.t); toast.t = setTimeout(() => t.className = '', err ? 4500 : 2200);
}
const guard = fn => async (...a) => { try { await fn(...a); } catch (e) { toast(e.message, true); } };
const money = n => `${CUR} ${Number(n || 0).toFixed(2)}`;
const hm = s => s ? s.slice(11, 16) : '';
const fmtDT = s => s ? new Date(s.replace(' ', 'T')).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
const setTimer = (fn, ms = 10000) => { clearInterval(timer); timer = fn ? setInterval(() => document.hidden || fn().catch(() => {}), ms) : null; };
const view = (html, title = '', back = null) => {
  setTimer(null);
  $('#app').innerHTML = `<header><h1>⛳ ${esc(COURSE)}${title ? ' · ' + esc(title) : ''}</h1><div>${back ? '<button id="back">← Back</button> ' : ''}${ME ? `<button id="out">${esc(ME.name)} · Sign out</button>` : ''}</div></header><main>${html}</main>`;
  if (back) $('#back').onclick = back;
  if (ME) $('#out').onclick = guard(async () => { await api('auth/logout', { method: 'POST' }); ME = null; login(); });
  window.scrollTo(0, 0);
};
const isMgr = () => ME?.role === 'manager';

/* ---------------- login ---------------- */
function login() {
  view(`<div class="card" style="max-width:420px;margin:30px auto"><h2>Staff sign in</h2>
    <label>Username</label><input id="u" autocapitalize="none" autocomplete="username">
    <label>Password</label><input id="p" type="password" autocomplete="current-password">
    <p><button class="p" id="go" style="width:100%">Sign in</button></p></div>`);
  const go = guard(async () => {
    const r = await api('auth/login', { method: 'POST', body: { username: $('#u').value, password: $('#p').value } });
    ME = r.user; home();
  });
  $('#go').onclick = go; $('#p').onkeydown = e => e.key === 'Enter' && go();
}

/* ---------------- home ---------------- */
async function home() {
  const [act, ast] = await Promise.all([api('checkins/active'), api('assets')]).catch(() => [{ checkins: [] }, { assets: [] }]);
  const carts = ast.assets.filter(a => a.kind === 'cart'), free = carts.filter(a => a.status === 'available').length;
  view(`
   <button class="big" id="new">➕ New check-in<small>${free} of ${carts.length} carts available</small></button>
   <button class="big alt" id="ret">↩ Return cart / clubs<small>${act.checkins.length} on the course now</small></button>
   <button class="big alt" id="log">📋 Today's check-ins</button>
   ${isMgr() ? '<button class="big alt" id="dash">📊 Dashboard &amp; history</button><button class="big alt" id="set">⚙ Settings (fleet, terms, staff, export)</button>' : ''}`);
  $('#new').onclick = () => startCheckin(); $('#ret').onclick = returns; $('#log').onclick = () => history(new Date().toLocaleDateString('en-CA'));
  if (isMgr()) { $('#dash').onclick = dashboard; $('#set').onclick = settings; }
}

/* ---------------- check-in wizard ---------------- */
let W;
function startCheckin() {
  W = { golfers: [], step: 1, cart: null, clubs: null, holes: 18, fee: 'unpaid', tee: '', notes: '', terms: null, assets: [] };
  api('terms/current').then(t => W.terms = t).catch(() => {});
  golferForm();
}
const stepBar = n => `<div class="steps">${[1, 2, 3].map(i => `<span class="${i <= n ? 'on' : ''}"></span>`).join('')}</div>`;

function golferForm(g = {}) {
  const n = W.golfers.length + 1, main = W.golfers[0];
  view(`${stepBar(1)}<div class="card"><h2>${n === 1 ? 'Guest details' : 'Golfer ' + n}</h2>
   ${W.golfers.length ? `<div class="mut">Already added: ${W.golfers.map(x => esc(x.first_name)).join(', ')}</div>` : ''}
   <label>Mobile number *</label><input id="mobile" type="tel" inputmode="tel" value="${esc(g.mobile ?? main?.mobile ?? '')}">
   <div id="found"></div>
   <div class="row"><div><label>First name *</label><input id="fn" value="${esc(g.first_name)}"></div><div><label>Last name *</label><input id="ln" value="${esc(g.last_name)}"></div></div>
   <div class="row"><div><label>Email</label><input id="em" type="email" value="${esc(g.email)}"></div><div><label>Country</label><input id="co" value="${esc(g.country)}"></div></div>
   <div class="row"><div><label>Hotel / where staying</label><input id="ho" value="${esc(g.hotel ?? main?.hotel ?? '')}"></div><div><label>Room number</label><input id="ro" value="${esc(g.room ?? main?.room ?? '')}"></div></div>
   <div class="row"><div><label>Date of birth (needed for under-18s)</label><input id="dob" type="date" value="${esc(g.dob)}"></div>
   <div><label>Guest type</label><select id="gt"><option value="guest">Visitor</option><option value="member" ${g.guest_type === 'member' ? 'selected' : ''}>Member</option></select></div></div>
   <div id="guard" class="hide"></div>
   <label style="display:flex;gap:8px;align-items:center;color:var(--ink)"><input id="mk" type="checkbox" ${g.marketing_optin ? 'checked' : ''}> Happy to receive offers and news from ${esc(COURSE)}</label>
   <p class="mut">Your details are kept to make future check-ins faster.</p>
   <p style="display:flex;gap:8px;flex-wrap:wrap"><button id="add">+ Add another golfer</button><button class="p" id="next" style="flex:1">Next: terms &amp; signature →</button></p></div>`,
   'Check-in', () => W.golfers.length ? (W.golfers.pop(), golferForm()) : home());

  let list = [];
  const fill = x => { $('#fn').value = x.first_name; $('#ln').value = x.last_name; $('#em').value = x.email || ''; $('#co').value = x.country || ''; $('#ho').value = x.hotel || $('#ho').value; $('#ro').value = x.room || $('#ro').value; $('#dob').value = x.dob || ''; $('#gt').value = x.guest_type; $('#mk').checked = !!+x.marketing_optin; $('#found').innerHTML = ''; };
  const lookup = guard(async () => {
    const m = $('#mobile').value; if (m.replace(/\D/g, '').length < 6) return;
    list = (await api('guests/lookup', { params: { mobile: m } })).guests;
    $('#found').innerHTML = list.map((x, i) => `<div class="found" data-i="${i}"><b>Welcome back, ${esc(x.first_name)} ${esc(x.last_name)}</b> · visit #${+x.visits + 1}${x.last_clubs ? ' · last hired ' + esc(x.last_clubs) : ''}<br><span class="mut">Tap to fill in their details</span>
       ${x.alert_note ? `<div class="alert">⚠ ${esc(x.alert_note)}</div>` : ''}</div>`).join('');
    document.querySelectorAll('.found').forEach(el => el.onclick = () => fill(list[+el.dataset.i]));
    if (list.length === 1 && !$('#fn').value) fill(list[0]);
  });
  $('#mobile').onblur = lookup;
  if (g.mobile || main) lookup();
  const read = () => ({
    mobile: $('#mobile').value.trim(), first_name: $('#fn').value.trim(), last_name: $('#ln').value.trim(), email: $('#em').value.trim(), country: $('#co').value.trim(),
    hotel: $('#ho').value.trim(), room: $('#ro').value.trim(), dob: $('#dob').value, guest_type: $('#gt').value, marketing_optin: $('#mk').checked, guardian_name: $('#gd')?.value.trim() || '',
  });
  const under18 = () => { const d = $('#dob').value; return d && (Date.now() - new Date(d)) / 31557600000 < 18; };
  $('#dob').onchange = () => { $('#guard').innerHTML = under18() ? '<label>Parent / guardian full name (they sign below) *</label><input id="gd">' : ''; $('#guard').className = ''; };
  const validate = () => { const r = read(); if (r.mobile.replace(/\D/g, '').length < 6 || !r.first_name || !r.last_name) throw new Error('Mobile, first name and last name are required'); if (under18() && !r.guardian_name) throw new Error('Under 18: enter the parent/guardian name'); return r; };
  $('#add').onclick = guard(async () => { W.golfers.push(validate()); golferForm({ mobile: W.golfers[0].mobile }); });
  $('#next').onclick = guard(async () => { W.golfers.push(validate()); await signStep(0); });
}

async function signStep(i) {
  if (!W.terms) W.terms = await api('terms/current');
  const g = W.golfers[i];
  view(`${stepBar(2)}<div class="card"><h2>Terms of hire &amp; signature</h2>
    <p class="mut">Golfer ${i + 1} of ${W.golfers.length}: <b>${esc(g.first_name)} ${esc(g.last_name)}</b>${g.guardian_name ? ' · signed by guardian ' + esc(g.guardian_name) : ''}</p>
    <div class="terms">${esc(W.terms.body)}</div>
    <label>Sign here with finger or stylus</label><canvas class="sig" id="sig"></canvas>
    <label style="display:flex;gap:8px;align-items:center;color:var(--ink)"><input type="checkbox" id="agree"> I have read and agree to these terms</label>
    <p style="display:flex;gap:8px"><button id="clr">Clear</button><button class="p" id="ok" style="flex:1">${i + 1 < W.golfers.length ? 'Next golfer →' : 'Next: cart &amp; clubs →'}</button></p></div>`,
    'Sign', () => i ? signStep(i - 1) : (W.golfers.pop(), golferForm(W.golfers[W.golfers.length - 1] && {})));
  const pad = padFor($('#sig'));
  $('#clr').onclick = pad.clear;
  $('#ok').onclick = guard(async () => {
    if (!$('#agree').checked) throw new Error('Please tick the agreement box');
    if (!pad.hasInk()) throw new Error('Please sign in the box');
    g.signature = pad.png();
    i + 1 < W.golfers.length ? signStep(i + 1) : assetStep();
  });
}

function padFor(cv) {
  const r = window.devicePixelRatio || 1, ctx = cv.getContext('2d');
  cv.width = cv.clientWidth * r; cv.height = cv.clientHeight * r; ctx.scale(r, r);
  ctx.lineWidth = 2.5; ctx.lineCap = ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
  let down = false, pts = 0, lx = 0, ly = 0;
  const pos = e => { const b = cv.getBoundingClientRect(); return [e.clientX - b.left, e.clientY - b.top]; };
  cv.onpointerdown = e => { down = true; [lx, ly] = pos(e); cv.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.arc(lx, ly, 1, 0, 7); ctx.stroke(); pts++; };
  cv.onpointermove = e => { if (!down) return; const [x, y] = pos(e); ctx.beginPath(); ctx.moveTo(lx, ly); ctx.lineTo(x, y); ctx.stroke(); lx = x; ly = y; pts++; };
  cv.onpointerup = cv.onpointercancel = () => down = false;
  return { clear() { ctx.clearRect(0, 0, cv.width, cv.height); pts = 0; }, hasInk: () => pts > 12, png() { const o = document.createElement('canvas'); o.width = 600; o.height = Math.round(600 * cv.height / cv.width); const c = o.getContext('2d'); c.fillStyle = '#fff'; c.fillRect(0, 0, o.width, o.height); c.drawImage(cv, 0, 0, o.width, o.height); return o.toDataURL('image/png'); } };
}

async function assetStep() {
  const { assets } = await api('assets'); W.assets = assets;
  const carts = assets.filter(a => a.kind === 'cart'), clubs = assets.filter(a => a.kind === 'clubs');
  const sug = carts.find(a => a.status === 'available');
  if (W.cart === null && sug) W.cart = null; // staff must choose; suggestion is highlighted
  const tiles = (list, sel, key) => list.map(a => {
    const off = a.status !== 'available';
    return `<div class="tile ${off ? 'off' : ''} ${sel === a.id ? 'sel' : ''} ${key === 'cart' && sug?.id === a.id && sel !== a.id ? 'sug' : ''}" data-k="${key}" data-id="${a.id}" ${off ? 'data-off="1"' : ''}>${esc(a.name)}<small>${esc(a.description || '')} ${money(a.fee)}${off ? '<br>' + esc(a.status.replace('_', ' ')) : ''}${a.gps_battery != null && !off ? ' · 🔋' + a.gps_battery + '%' : ''}</small></div>`;
  }).join('') || '<span class="mut">None set up</span>';
  view(`${stepBar(3)}<div class="card"><h2>Cart &amp; club hire</h2>
    <h3>Golf cart ${sug ? `<span class="mut">— suggested: ${esc(sug.name)}</span>` : '<span class="mut">— none free</span>'}</h3>
    <div class="grid"><div class="tile ${W.cart === 0 ? 'sel' : ''}" data-k="cart" data-id="0">Walking<small>no cart</small></div>${tiles(carts, W.cart, 'cart')}</div>
    <h3>Club hire</h3>
    <div class="grid"><div class="tile ${W.clubs === 0 ? 'sel' : ''}" data-k="clubs" data-id="0">Own clubs<small>no hire</small></div>${tiles(clubs, W.clubs, 'clubs')}</div>
    <div class="row"><div><label>Holes</label><select id="holes"><option>18</option><option ${W.holes === 9 ? 'selected' : ''}>9</option></select></div>
    <div><label>Green fee</label><select id="fee"><option value="unpaid">Collect at end</option><option value="paid" ${W.fee === 'paid' ? 'selected' : ''}>Paid</option><option value="room" ${W.fee === 'room' ? 'selected' : ''}>Charge to room</option></select></div></div>
    <div class="row"><div><label>Tee time</label><input id="tee" type="time" value="${esc(W.tee)}"></div><div><label>Notes</label><input id="notes" value="${esc(W.notes)}"></div></div>
    <h3 id="tot"></h3>
    <p><button class="p" id="done" style="width:100%">✔ Complete check-in</button></p></div>`, 'Cart & clubs', () => signStep(W.golfers.length - 1));
  const total = () => { const f = id => W.assets.find(a => a.id === id)?.fee || 0; $('#tot').textContent = `Hire total: ${money(+f(W.cart) + +f(W.clubs))}`; };
  total();
  document.querySelectorAll('.tile').forEach(t => t.onclick = () => {
    if (t.dataset.off) return toast('That one is not available', true);
    W[t.dataset.k] = +t.dataset.id; keep(); assetStepRender();
  });
  const keep = () => { W.holes = +$('#holes').value; W.fee = $('#fee').value; W.tee = $('#tee').value; W.notes = $('#notes').value; };
  const assetStepRender = () => { const s = scrollY; assetStep().then(() => scrollTo(0, s)); };
  $('#done').onclick = guard(async () => {
    keep();
    $('#done').disabled = true;
    try {
      const r = await api('checkins/create', { method: 'POST', body: { golfers: W.golfers, holes: W.holes, fee_status: W.fee, tee_time: W.tee, notes: W.notes, cart_id: W.cart || null, clubs_id: W.clubs || null } });
      const cn = W.assets.find(a => a.id === W.cart)?.name, kn = W.assets.find(a => a.id === W.clubs)?.name;
      view(`<div class="card" style="text-align:center"><h2>✅ Checked in</h2><p style="font-size:24px"><b>${esc(W.golfers[0].first_name)} ${esc(W.golfers[0].last_name)}</b></p>
        <p style="font-size:28px">${cn ? '🛺 ' + esc(cn) : 'Walking'}</p><p style="font-size:20px">${kn ? '🏌 ' + esc(kn) : 'Own clubs'}</p><p>Hire total: ${money(r.hire_fee)}</p>
        <button class="p" id="h" style="width:100%">Done</button></div>`);
      $('#h').onclick = home;
    } catch (e) { $('#done').disabled = false; throw e; }
  });
}

/* ---------------- returns ---------------- */
async function returns() {
  const load = async () => {
    const { checkins } = await api('checkins/active');
    $('#list').innerHTML = checkins.length ? `<table><tr><th>Guest</th><th>Cart</th><th>Clubs</th><th>Out</th></tr>${checkins.map(c => `<tr class="click" data-id="${c.id}"><td><b>${esc(c.guest)}</b>${c.golfers > 1 ? ` +${c.golfers - 1}` : ''}<div class="mut">${esc(c.hotel || '')} ${esc(c.room || '')}</div></td><td>${esc(c.cart_name) || '—'}</td><td>${esc(c.clubs_name) || '—'}</td><td>${hm(c.checked_in_at)}</td></tr>`).join('')}</table>` : '<p class="mut">Nothing is out right now.</p>';
    document.querySelectorAll('#list tr.click').forEach(tr => tr.onclick = () => returnDialog(checkins.find(c => c.id == tr.dataset.id)));
  };
  view('<div class="card"><h2>Tap an item to return it</h2><div id="list"></div></div>', 'Returns', home);
  await load(); setTimer(load);
}
function returnDialog(c) {
  const m = document.createElement('div'); m.className = 'modal';
  m.innerHTML = `<div><h2>Return: ${esc(c.guest)}</h2><p>${esc(c.cart_name || 'No cart')} · ${esc(c.clubs_name || 'No clubs')}</p>
    <button class="p" id="ok">✔ Returned – undamaged</button><button class="d" id="dm">⚠ Damaged</button><button class="d" id="wr">🚫 Raise patron warning</button><button id="cx">Cancel</button></div>`;
  document.body.append(m);
  $('#cx', m).onclick = () => m.remove();
  $('#ok', m).onclick = guard(async () => { await api('checkins/return', { method: 'POST', body: { id: c.id, condition: 'ok' } }); m.remove(); toast('Returned'); returns(); });
  const issue = (cond, title) => {
    m.firstElementChild.innerHTML = `<h2>${title}</h2><label>What happened? *</label><textarea id="nt" rows="3"></textarea>
      ${cond === 'damaged' ? '<label>Photo of damage</label><input id="ph" type="file" accept="image/*" capture="environment">' : ''}
      <button class="d" id="sv">Save</button><button id="cx2">Cancel</button>`;
    $('#cx2', m).onclick = () => m.remove();
    $('#sv', m).onclick = guard(async () => {
      const f = $('#ph', m)?.files[0];
      await api('checkins/return', { method: 'POST', body: { id: c.id, condition: cond, note: $('#nt', m).value, photo: f ? await shrink(f) : '' } });
      m.remove(); toast('Saved. ' + (cond === 'damaged' ? 'Marked for maintenance.' : 'Warning added to guest.')); returns();
    });
  };
  $('#dm', m).onclick = () => issue('damaged', 'Damage report'); $('#wr', m).onclick = () => issue('warning', 'Patron warning');
}
function shrink(file, max = 900) {
  return new Promise((res, rej) => {
    const img = new Image(); img.onload = () => {
      const s = Math.min(1, max / Math.max(img.width, img.height)), c = document.createElement('canvas');
      c.width = img.width * s; c.height = img.height * s; c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); res(c.toDataURL('image/jpeg', .7));
    }; img.onerror = () => rej(new Error('Could not read photo')); img.src = URL.createObjectURL(file);
  });
}

/* ---------------- history / today ---------------- */
async function history(date) {
  const { checkins } = await api('checkins/list', { params: { date } });
  const tot = checkins.reduce((s, c) => s + +c.hire_fee, 0);
  view(`<div class="card"><h2>Check-ins</h2>${isMgr() ? `<input type="date" id="d" value="${date}" style="max-width:220px">` : '<p class="mut">Today</p>'}
   ${checkins.length ? `<table><tr><th>Time</th><th>Guest</th><th>Cart</th><th>Clubs</th><th>Hire</th><th></th></tr>${checkins.map(c => `<tr class="${isMgr() ? 'click' : ''}" data-id="${c.id}"><td>${hm(c.checked_in_at)}</td><td><b>${esc(c.guest)}</b>${c.golfers > 1 ? ' +' + (c.golfers - 1) : ''}<div class="mut">${esc(c.mobile)} ${esc(c.hotel || '')}</div></td><td>${esc(c.cart_name) || '—'}</td><td>${esc(c.clubs_name) || '—'}</td><td>${money(c.hire_fee)}</td>
   <td><span class="tag ${c.returned_at ? (c.return_condition === 'ok' ? '' : 'r') : 'o'}">${c.returned_at ? (c.return_condition === 'ok' ? 'Back ' + hm(c.returned_at) : c.return_condition) : 'Out'}</span></td></tr>`).join('')}</table><h3>Hire total: ${money(tot)}</h3>` : '<p class="mut">No check-ins.</p>'}</div>`, 'Check-ins', home);
  if (isMgr()) { $('#d').onchange = e => history(e.target.value); document.querySelectorAll('tr.click').forEach(tr => tr.onclick = () => detail(tr.dataset.id, () => history(date))); }
}
const detail = guard(async (id, back) => {
  const c = await api('checkins/get', { params: { id } });
  view(`<div class="card"><h2>Check-in #${c.id}</h2><p>${fmtDT(c.checked_in_at)} · ${c.holes} holes · ${esc(c.cart_name || 'walking')} · ${esc(c.clubs_name || 'own clubs')} · ${money(c.hire_fee)} (${esc(c.fee_status)})<br><span class="mut">Checked in by ${esc(c.staff_name)} · terms v${c.terms_version}</span></p>
   ${c.returned_at ? `<p>Returned ${fmtDT(c.returned_at)} – <b>${esc(c.return_condition)}</b> ${esc(c.return_note || '')}</p>${c.return_photo ? `<img src="${c.return_photo}" style="max-width:100%;border-radius:8px">` : ''}` : '<p><span class="tag o">Still out</span></p>'}
   <h3>Signed golfers</h3>${c.golfers.map(g => `<div class="card"><b>${esc(g.first_name)} ${esc(g.last_name)}</b> ${g.is_main == 1 ? '(main hirer)' : ''}<div class="mut">${esc(g.mobile)} ${esc(g.email || '')} · signed ${fmtDT(g.signed_at)}${g.guardian_name ? ' · guardian ' + esc(g.guardian_name) : ''}</div><img class="sigimg" src="${g.signature}"></div>`).join('')}
   <h3>Terms accepted</h3><div class="terms">${esc(c.terms)}</div><p><button onclick="print()">Print</button></p></div>`, 'Check-in', back);
});

/* ---------------- dashboard ---------------- */
async function dashboard() {
  const d = await api('dashboard'), mx = Math.max(1, ...d.days.map(x => +x.n));
  const st = Object.fromEntries(d.carts.map(x => [x.status, +x.n]));
  view(`<div class="stats"><div class="stat"><b>${d.today.rounds}</b>Check-ins today</div><div class="stat"><b>${d.today.out_now ?? 0}</b>On course now</div><div class="stat"><b>${money(d.today.fees)}</b>Hire today</div>
   <div class="stat"><b>${st.available || 0}</b>Carts available</div><div class="stat"><b>${(st.maintenance || 0) + (st.offline || 0) + (st.charging || 0)}</b>Carts unavailable</div></div>
   <div class="card" style="margin-top:14px"><h2>Check-ins, last 14 days</h2><div class="bars">${d.days.map(x => `<div style="height:${Math.round(100 * x.n / mx)}%" title="${x.d}: ${x.n}"><span>${x.n}</span></div>`).join('') || '<span class="mut">No data yet</span>'}</div></div>
   <div class="card"><h2>Recent damage &amp; warnings</h2>${d.issues.length ? d.issues.map(i => `<p><b>${esc(i.guest)}</b> · ${fmtDT(i.checked_in_at)}<br><span class="mut">${esc(i.return_note || '')}</span></p>`).join('') : '<p class="mut">None 🎉</p>'}</div>
   <button class="big alt" id="hist">📋 Browse history &amp; signed waivers</button>`, 'Dashboard', home);
  $('#hist').onclick = () => history(new Date().toLocaleDateString('en-CA'));
}

/* ---------------- settings ---------------- */
function settings(tab = 'fleet') {
  view(`<div class="tabs">${[['fleet', 'Carts & clubs'], ['terms', 'Terms'], ['users', 'Staff'], ['export', 'Export']].map(([k, l]) => `<button class="${tab === k ? 'on' : ''}" data-t="${k}">${l}</button>`).join('')}</div><div id="pane"></div>`, 'Settings', home);
  document.querySelectorAll('.tabs button').forEach(b => b.onclick = () => settings(b.dataset.t));
  guard(async () => ({ fleet, terms: termsPane, users: usersPane, export: exportPane })[tab]())();
}
async function fleet() {
  const { assets } = await api('assets', { params: { all: 1 } });
  const row = a => `<tr data-id="${a.id || ''}"><td><input class="n" value="${esc(a.name)}"></td><td><input class="ds" value="${esc(a.description || '')}" placeholder="e.g. Men's RH"></td><td><input class="f" type="number" step="0.5" value="${a.fee ?? 0}" style="width:80px"></td>
    <td><input class="g" value="${esc(a.gps_unit_id || '')}" placeholder="GPS unit" style="width:110px"></td><td><label><input class="m" type="checkbox" ${+a.maintenance ? 'checked' : ''}> Maint.</label><label><input class="ac" type="checkbox" ${a.active === undefined || +a.active ? 'checked' : ''}> Active</label></td>
    <td><button class="sv p" data-k="${a.kind}">Save</button></td></tr>`;
  const sect = (k, t) => `<div class="card"><h2>${t}</h2><table><tr><th>Name</th><th>Description</th><th>Fee</th><th>GPS ID</th><th></th><th></th></tr>${assets.filter(a => a.kind === k).map(row).join('')}${row({ kind: k, name: '', fee: k === 'cart' ? 40 : 50 })}</table><p class="mut">Last row adds a new one.</p></div>`;
  $('#pane').innerHTML = sect('cart', 'Golf carts') + sect('clubs', 'Club sets');
  document.querySelectorAll('button.sv').forEach(b => b.onclick = guard(async () => {
    const tr = b.closest('tr');
    await api('assets/save', { method: 'POST', body: { id: tr.dataset.id || undefined, kind: b.dataset.k, name: $('.n', tr).value, description: $('.ds', tr).value, fee: $('.f', tr).value, gps_unit_id: $('.g', tr).value, maintenance: $('.m', tr).checked, active: $('.ac', tr).checked } });
    toast('Saved'); fleet();
  }));
}
async function termsPane() {
  const t = await api('terms/current');
  $('#pane').innerHTML = `<div class="card"><h2>Waiver / terms of hire (version ${t.version})</h2><p class="alert">Have this wording reviewed by your lawyer. Saving creates a new version; past check-ins keep the version the guest signed.</p>
    <textarea id="tb" rows="16">${esc(t.body)}</textarea><p><button class="p" id="sv">Save as new version</button></p></div>`;
  $('#sv').onclick = guard(async () => { await api('terms/save', { method: 'POST', body: { body: $('#tb').value } }); toast('New version saved'); termsPane(); });
}
async function usersPane() {
  const { users } = await api('users/list');
  $('#pane').innerHTML = `<div class="card"><h2>Staff logins</h2><table><tr><th>Name</th><th>Username</th><th>Role</th><th>New password</th><th></th></tr>${[...users, { name: '', username: '', role: 'staff', active: 1 }].map(u => `<tr data-id="${u.id || ''}"><td><input class="n" value="${esc(u.name)}"></td><td><input class="un" value="${esc(u.username)}"></td>
    <td><select class="r"><option>staff</option><option ${u.role === 'manager' ? 'selected' : ''}>manager</option></select></td><td><input class="pw" type="password" placeholder="${u.id ? 'leave blank' : 'required'}"></td><td><label><input class="ac" type="checkbox" ${+u.active ? 'checked' : ''}> Active</label> <button class="sv p">Save</button></td></tr>`).join('')}</table></div>`;
  document.querySelectorAll('button.sv').forEach(b => b.onclick = guard(async () => {
    const tr = b.closest('tr');
    await api('users/save', { method: 'POST', body: { id: tr.dataset.id || undefined, name: $('.n', tr).value, username: $('.un', tr).value, role: $('.r', tr).value, password: $('.pw', tr).value, active: $('.ac', tr).checked } });
    toast('Saved'); usersPane();
  }));
}
function exportPane() {
  const t = new Date().toLocaleDateString('en-CA'), m = t.slice(0, 8) + '01';
  $('#pane').innerHTML = `<div class="card"><h2>Export</h2><div class="row"><div><label>From</label><input id="f" type="date" value="${m}"></div><div><label>To</label><input id="t" type="date" value="${t}"></div></div>
    <p><button class="p" id="a">Download check-ins (CSV)</button> <button id="b">Download marketing list (opted-in guests)</button></p></div>`;
  const go = type => location.href = `${API}?r=export&type=${type}&from=${$('#f').value}&to=${$('#t').value}`;
  $('#a').onclick = () => go('checkins'); $('#b').onclick = () => go('marketing');
}

/* ---------------- boot ---------------- */
(async () => {
  try { const r = await api('auth/me'); CUR = r.currency; COURSE = r.course; ME = r.user; } catch (e) { if (!/sign in/i.test(e.message)) { view(`<div class="card"><h2>Can't reach the server</h2><p>${esc(e.message)}</p></div>`); return; } }
  ME ? home() : login();
})();
