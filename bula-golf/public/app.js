'use strict';
const API = 'api/index.php';
const $ = (s, r = document) => r.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
let ME = null, CUR = 'FJD', COURSE = 'Bula Golf', COURSES = [], CID = 0, timer = null;
const store = { get: k => { try { return localStorage.getItem(k); } catch { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch { } } };
const courseName = (id = CID) => COURSES.find(c => c.id == id)?.name || COURSE;
const courseFull = (id = CID) => { const c = COURSES.find(x => x.id == id); return c?.full_name || c?.name || COURSE; };
const courseLogoUrl = c => `${API}?r=course/logo&id=${c.id}&v=${c.logo_v || 0}`;
/** Hire extras this course offers (set per course by head office; Natadola has no tennis). */
const feat = (id = CID) => { const c = COURSES.find(x => x.id == id) || {}; return { tennis: +c.tennis === 1, shoes: +c.shoes === 1, room: +c.room_charge === 1 }; };
/** A login locked to one course uses it; head office remembers the last course picked on this device. */
function pickCourse() {
  const saved = +store.get('bula_course');
  CID = ME?.course_id || (COURSES.some(c => c.id == saved) ? saved : COURSES[0]?.id || 0);
}

async function api(route, { method = 'GET', body, params } = {}) {
  const qs = new URLSearchParams({ r: route, ...(params || {}) });
  let res;
  try {
    res = await fetch(`${API}?${qs}`, {
      method, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'bula', ...(CID ? { 'X-Bula-Course': CID } : {}), ...(body ? { 'Content-Type': 'application/json' } : {}) },
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
  const switcher = ME && !ME.course_id && COURSES.length > 1 ? `<select id="cs" aria-label="Course">${COURSES.map(c => `<option value="${c.id}" ${c.id == CID ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>` : '';
  $('#app').innerHTML = `<header><h1>${ME && +COURSES.find(c => c.id == CID)?.has_logo ? `<img class="hlogo" src="${courseLogoUrl(COURSES.find(c => c.id == CID))}" alt="">` : '⛳'} ${esc(ME ? courseFull() : COURSE)}${title ? ' <em>· ' + esc(title) + '</em>' : ''}</h1><div>${switcher}${back ? '<button id="back">← Back</button> ' : ''}${ME ? `<button id="out">${esc(ME.name)} · Sign out</button>` : ''}</div></header><main>${html}</main>`;
  if (back) $('#back').onclick = back;
  if (switcher) $('#cs').onchange = e => { CID = +e.target.value; store.set('bula_course', CID); home(); };
  if (ME) $('#out').onclick = guard(async () => { await api('auth/logout', { method: 'POST' }); ME = null; login(); });
  window.scrollTo(0, 0);
};
const hireCell = c => `${esc(c.clubs_name) || '—'}${c.items ? `<div class="mut">${esc(c.items)}</div>` : ''}${c.bags ? `<div class="mut">Bag ${esc(c.bags)}</div>` : ''}`;
const isMgr = () => ME?.role === 'manager';
const isAdmin = () => isMgr() && !ME.course_id; // head office: all courses, staff, terms

/* ---------------- login ---------------- */
function login() {
  view(`<div class="card" style="max-width:420px;margin:30px auto"><h2>Staff sign in</h2>
    <label>Username</label><input id="u" autocapitalize="none" autocomplete="username">
    <label>Password</label><input id="p" type="password" autocomplete="current-password">
    <p><button class="p" id="go" style="width:100%">Sign in</button></p></div>`);
  const go = guard(async () => {
    const r = await api('auth/login', { method: 'POST', body: { username: $('#u').value, password: $('#p').value } });
    ME = r.user; COURSES = r.courses || []; pickCourse(); home();
  });
  $('#go').onclick = go; $('#p').onkeydown = e => e.key === 'Enter' && go();
}

/* ---------------- home ---------------- */
async function home() {
  const [act, ast] = await Promise.all([api('checkins/active'), api('assets')]).catch(() => [{ checkins: [] }, { assets: [] }]);
  const carts = ast.assets.filter(a => a.kind === 'cart'), free = carts.filter(a => a.status === 'available').length;
  view(`<div class="home">
   <button class="big wide" id="new"><i>➕</i><div>New check-in<small>${free} of ${carts.length} carts available</small></div></button>
   <button class="big alt" id="ret"><i>↩</i><div>Return cart / clubs<small>${act.checkins.length} on the course now</small></div></button>
   <button class="big alt" id="log"><i>📋</i><div>Today's check-ins</div></button>
   ${isMgr() ? '<button class="big alt" id="dash"><i>📊</i><div>Dashboard &amp; history</div></button><button class="big alt" id="set"><i>⚙</i><div>Settings<small>fleet, terms, staff, export</small></div></button>' : ''}</div>`);
  $('#new').onclick = () => startCheckin(); $('#ret').onclick = returns; $('#log').onclick = () => history(new Date().toLocaleDateString('en-CA'));
  if (isMgr()) { $('#dash').onclick = () => dashboard(); $('#set').onclick = () => settings(); }
}

/* ---------------- check-in wizard ---------------- */
let W;
function startCheckin() {
  W = { golfers: [], step: 1, cart: null, clubs: null, items: [], bags: '', holes: 18, fee: 'unpaid', tee: '', notes: '', terms: null, assets: [] };
  api('terms/current').then(t => W.terms = t).catch(() => {});
  golferForm();
}
const stepBar = n => `<div class="steps">${['Guest', 'Sign', 'Cart & clubs'].map((l, i) => `<span class="${i < n ? 'on' : ''}">${i + 1}. ${l}</span>`).join('')}</div>`;

const fmtD = s => s ? new Date(s + 'T00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';

function golferForm(g = {}) {
  const n = W.golfers.length + 1, main = W.golfers[0];
  view(`${stepBar(1)}<div class="card"><h2>${n === 1 ? 'Guest details' : 'Golfer ' + n}</h2>
   ${W.golfers.length ? `<div class="mut">Already added: ${W.golfers.map(x => esc(x.first_name)).join(', ')}</div>` : ''}
   <div class="row"><div><label>Mobile number *</label><input id="mobile" type="tel" inputmode="tel" value="${esc(g.mobile ?? main?.mobile ?? '')}"></div>
   <div><label>Member number <span class="mut">(members only)</span></label><input id="mno" value="${esc(g.member_no)}" autocapitalize="characters"></div></div>
   <div id="found"></div><div id="mstat"></div>
   <div class="row"><div><label>First name *</label><input id="fn" value="${esc(g.first_name)}"></div><div><label>Last name *</label><input id="ln" value="${esc(g.last_name)}"></div></div>
   <div class="row"><div><label>Email</label><input id="em" type="email" value="${esc(g.email)}"></div>
   <div><label>Where are they from?</label><select id="orig"><option value="overseas">Overseas visitor</option><option value="local">Fiji resident</option></select></div></div>
   <div id="ovr"><div class="row"><div><label>Country</label><input id="co" value="${esc(g.country ?? main?.country)}"></div><div><label>Hotel / where staying</label><input id="ho" value="${esc(g.hotel ?? main?.hotel ?? '')}"></div></div>
   <label>Room number</label><input id="ro" value="${esc(g.room ?? main?.room ?? '')}" style="max-width:320px"></div>
   <label>Date of birth (needed for under-18s)</label><input id="dob" type="date" value="${esc(g.dob)}" style="max-width:320px">
   <div id="guard" class="hide"></div>
   <label class="chk"><input id="mk" type="checkbox" ${g.marketing_optin ? 'checked' : ''}> Happy to receive offers and news from ${esc(courseFull())}</label>
   <p class="mut">Your details are kept to make future check-ins faster.</p>
   </div><div class="bar"><button id="add" class="sec">+ Add another golfer</button><button class="p" id="next">Next: terms &amp; signature →</button></div>`,
   'Check-in', () => W.golfers.length ? (W.golfers.pop(), golferForm()) : home());

  let list = [], MEM = null, memberAsked = false;
  const isFiji = c => /^fiji/i.test(String(c || '').trim());
  const toggleOrig = () => { const loc = $('#orig').value === 'local'; $('#ovr').style.display = loc ? 'none' : ''; if (loc) $('#co').value = 'Fiji'; else if (isFiji($('#co').value)) $('#co').value = ''; };
  $('#orig').value = isFiji(g.country ?? main?.country) ? 'local' : 'overseas'; $('#orig').onchange = toggleOrig; toggleOrig();
  const same = (a, b) => String(a || '').trim().toLowerCase() === String(b || '').trim().toLowerCase();
  /** Where this golfer stands against the course's member list. The server re-checks all of this when the check-in is saved. */
  const mstate = () => {
    if (!MEM) return { found: false };
    const nameOk = $('#fn').value.trim() && $('#ln').value.trim() && same($('#fn').value, MEM.first_name) && same($('#ln').value, MEM.last_name);
    return { found: true, nameOk, expired: +MEM.expired === 1, days: MEM.days_left == null ? null : +MEM.days_left };
  };
  const renderMember = () => {
    const el = $('#mstat'), s = mstate();
    if (!s.found) { el.innerHTML = memberAsked && $('#mno').value.trim() ? '<div class="alert">Member number not found on this course\'s member list. They will be checked in as a <b>visitor</b> (green fee payable).</div>' : ''; return; }
    if (!s.nameOk) { el.innerHTML = `<div class="found" id="usem"><b>Member record found: ${esc(MEM.first_name)} ${esc(MEM.last_name)}</b>${MEM.member_no ? ' · #' + esc(MEM.member_no) : ''}<br><span class="mut">Tap to use these details. The name must match the member record to count as a member.</span></div>`; $('#usem').onclick = fillMember; return; }
    if (s.expired) { el.innerHTML = `<div class="alert bad">✖ <b>Membership expired on ${fmtD(MEM.expires_on)}.</b> Check them in as a <b>visitor</b> (green fee payable) and ask them to renew.</div>`; return; }
    const soon = s.days !== null && s.days <= 30;
    el.innerHTML = `<div class="alert ok">✔ <b>Verified member</b>${MEM.expires_on ? ' · valid until ' + fmtD(MEM.expires_on) : ' · no expiry on record'}${MEM.member_no ? ' · #' + esc(MEM.member_no) : ''}</div>
      ${soon ? `<div class="alert">⚠ <b>Membership expires ${s.days === 0 ? 'today' : 'in ' + s.days + ' day' + (s.days === 1 ? '' : 's')} (${fmtD(MEM.expires_on)}).</b> Please remind them to renew.</div>` : ''}
      <label class="chk"><input type="checkbox" id="mcard"> I have seen their member card or photo ID</label>`;
  };
  const fillMember = () => { $('#fn').value = MEM.first_name; $('#ln').value = MEM.last_name; if (!$('#em').value) $('#em').value = MEM.email || ''; if (!$('#mobile').value) $('#mobile').value = MEM.mobile || ''; if (MEM.member_no) $('#mno').value = MEM.member_no; renderMember(); };
  const fill = x => { $('#fn').value = x.first_name; $('#ln').value = x.last_name; $('#em').value = x.email || ''; $('#co').value = x.country || ''; $('#orig').value = isFiji(x.country) ? 'local' : 'overseas'; toggleOrig(); $('#ho').value = x.hotel || $('#ho').value; $('#ro').value = x.room || $('#ro').value; $('#dob').value = x.dob || ''; $('#mk').checked = !!+x.marketing_optin; $('#found').innerHTML = ''; renderMember(); };
  const lookup = guard(async () => {
    const m = $('#mobile').value, no = $('#mno').value.trim();
    if (m.replace(/\D/g, '').length < 6 && !no) return;
    const r = await api('guests/lookup', { params: { mobile: m, member_no: no } });
    list = r.guests; MEM = r.member; memberAsked = true;
    $('#found').innerHTML = list.map((x, i) => `<div class="found" data-i="${i}"><b>Welcome back, ${esc(x.first_name)} ${esc(x.last_name)}</b> · visit #${+x.visits + 1}${x.last_clubs ? ' · last hired ' + esc(x.last_clubs) : ''}<br><span class="mut">Tap to fill in their details</span>
       ${x.alert_note ? `<div class="alert">⚠ ${esc(x.alert_note)}</div>` : ''}</div>`).join('');
    document.querySelectorAll('.found[data-i]').forEach(el => el.onclick = () => fill(list[+el.dataset.i]));
    if (list.length === 1 && !$('#fn').value) fill(list[0]);
    renderMember();
  });
  $('#mobile').onblur = lookup; $('#mno').onblur = lookup;
  $('#fn').oninput = $('#ln').oninput = renderMember;
  if (g.mobile || g.member_no || main) lookup();
  const read = () => {
    const s = mstate();
    return {
      mobile: $('#mobile').value.trim(), member_no: $('#mno').value.trim(), first_name: $('#fn').value.trim(), last_name: $('#ln').value.trim(), email: $('#em').value.trim(), country: $('#orig').value === 'local' ? 'Fiji' : $('#co').value.trim(),
      hotel: $('#orig').value === 'local' ? '' : $('#ho').value.trim(), room: $('#orig').value === 'local' ? '' : $('#ro').value.trim(), dob: $('#dob').value, marketing_optin: $('#mk').checked, guardian_name: $('#gd')?.value.trim() || '',
      card_checked: !!$('#mcard')?.checked, member_ok: !!(s.found && s.nameOk && !s.expired && $('#mcard')?.checked),
    };
  };
  const under18 = () => { const d = $('#dob').value; return d && (Date.now() - new Date(d)) / 31557600000 < 18; };
  $('#dob').onchange = () => { $('#guard').innerHTML = under18() ? '<label>Parent / guardian full name (they sign below) *</label><input id="gd">' : ''; $('#guard').className = ''; };
  const validate = () => {
    const r = read(), s = mstate();
    if (r.mobile.replace(/\D/g, '').length < 6 || !r.first_name || !r.last_name) throw new Error('Mobile, first name and last name are required');
    if (under18() && !r.guardian_name) throw new Error('Under 18: enter the parent/guardian name');
    if (s.found && s.nameOk && !s.expired && !r.card_checked) throw new Error('Tick the box to confirm you have seen their member card or photo ID. Otherwise clear the member number and check them in as a visitor.');
    return r;
  };
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
    <label class="chk"><input type="checkbox" id="agree"> I have read and agree to these terms</label>
    </div><div class="bar"><button id="clr" class="sec">Clear signature</button><button class="p" id="ok">${i + 1 < W.golfers.length ? 'Next golfer →' : 'Next: cart &amp; clubs →'}</button></div>`,
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
  const hasHotel = feat().room && W.golfers.some(g => g.hotel); // room charging: only where head office enabled it, and only with a hotel
  if (!hasHotel && W.fee === 'room') W.fee = 'unpaid';
  const allMem = W.golfers.length > 0 && W.golfers.every(g => g.member_ok);
  if (!allMem && W.fee === 'member') W.fee = 'unpaid'; else if (allMem && W.fee === 'unpaid') W.fee = 'member';
  const ft = feat(), shoes = ft.shoes ? assets.filter(a => a.kind === 'shoes') : [], tennis = ft.tennis ? assets.filter(a => a.kind === 'racquet') : [];
  const multi = list => list.map(a => {
    const off = a.status !== 'available';
    return `<div class="tile ${off ? 'off' : ''} ${W.items.includes(a.id) ? 'sel' : ''}" data-k="item" data-id="${a.id}" ${off ? 'data-off="1"' : ''}>${esc(a.name)}<small>${esc(a.description || '')} ${money(a.fee)}${off ? '<br>' + esc(a.status.replace('_', ' ')) : ''}</small></div>`;
  }).join('') || '<span class="mut">None set up</span>';
  view(`${stepBar(3)}<div class="card"><h2>${ft.shoes || ft.tennis ? 'Cart &amp; hire' : 'Cart &amp; club hire'}</h2>
    <h3>Golf cart ${sug ? `<span class="mut">— suggested: ${esc(sug.name)}</span>` : '<span class="mut">— none free</span>'}</h3>
    <div class="grid"><div class="tile ${W.cart === 0 ? 'sel' : ''}" data-k="cart" data-id="0">Walking<small>no cart</small></div>${tiles(carts, W.cart, 'cart')}</div>
    <h3>Club hire</h3>
    <div class="grid"><div class="tile ${W.clubs === 0 ? 'sel' : ''}" data-k="clubs" data-id="0">Own clubs<small>no hire</small></div>${tiles(clubs, W.clubs, 'clubs')}</div>
    ${ft.shoes ? `<h3>Shoe hire <span class="mut">— tap to add, tap again to remove</span></h3><div class="grid">${multi(shoes)}</div>` : ''}
    ${ft.tennis ? `<h3>Tennis hire <span class="mut">— racquets and tennis shoes</span></h3><div class="grid">${multi(tennis)}</div>` : ''}
    <label>Bag number(s)</label><input id="bags" placeholder="e.g. 12, 14" value="${esc(W.bags)}">
    <div class="row"><div><label>Holes</label><select id="holes"><option>18</option><option ${W.holes === 9 ? 'selected' : ''}>9</option></select></div>
    <div><label>Green fee</label><select id="fee">${allMem ? `<option value="member" ${W.fee === 'member' ? 'selected' : ''}>Member: no green fee</option>` : ''}<option value="unpaid" ${W.fee === 'unpaid' ? 'selected' : ''}>Collect at end</option><option value="paid" ${W.fee === 'paid' ? 'selected' : ''}>Paid</option>${hasHotel ? `<option value="room" ${W.fee === 'room' ? 'selected' : ''}>Charge to room</option>` : ''}</select></div></div>
    <div class="row"><div><label>Tee time</label><input id="tee" type="time" value="${esc(W.tee)}"></div><div><label>Notes</label><input id="notes" value="${esc(W.notes)}"></div></div>
    <h3 id="tot"></h3>
    </div><div class="bar"><button class="p" id="done">✔ Complete check-in</button></div>`, 'Cart & clubs', () => signStep(W.golfers.length - 1));
  const fee = id => W.assets.find(a => a.id === id)?.fee || 0;
  const total = () => { $('#tot').textContent = `Hire total: ${money(+fee(W.cart) + +fee(W.clubs) + W.items.reduce((s, id) => s + +fee(id), 0))}`; };
  total();
  document.querySelectorAll('.tile').forEach(t => t.onclick = () => {
    if (t.dataset.off) return toast('That one is not available', true);
    const id = +t.dataset.id;
    if (t.dataset.k === 'item') W.items = W.items.includes(id) ? W.items.filter(x => x !== id) : [...W.items, id];
    else W[t.dataset.k] = id;
    keep(); assetStepRender();
  });
  const keep = () => { W.holes = +$('#holes').value; W.fee = $('#fee').value; W.tee = $('#tee').value; W.notes = $('#notes').value; W.bags = $('#bags').value; };
  const assetStepRender = () => { const s = scrollY; assetStep().then(() => scrollTo(0, s)); };
  $('#done').onclick = guard(async () => {
    keep();
    $('#done').disabled = true;
    try {
      const r = await api('checkins/create', { method: 'POST', body: { golfers: W.golfers, holes: W.holes, fee_status: W.fee, tee_time: W.tee, notes: W.notes, bags: W.bags, cart_id: W.cart || null, clubs_id: W.clubs || null, items: W.items } });
      const cn = W.assets.find(a => a.id === W.cart)?.name, kn = W.assets.find(a => a.id === W.clubs)?.name;
      const extra = W.items.map(id => W.assets.find(a => a.id === id)?.name).filter(Boolean).join(', ');
      view(`<div class="card done"><div class="tick">✓</div><h2>Checked in</h2><p style="font-size:24px;margin:0"><b>${esc(W.golfers[0].first_name)} ${esc(W.golfers[0].last_name)}</b></p>
        <div class="cartno">${cn ? '🛺 ' + esc(cn) : 'Walking'}</div><p style="font-size:20px;margin:0">${kn ? '🏌 ' + esc(kn) : 'Own clubs'}</p>${extra ? `<p style="font-size:18px;margin:6px 0 0">${esc(extra)}</p>` : ''}${W.bags ? `<p class="mut" style="margin:6px 0 0">Bag ${esc(W.bags)}</p>` : ''}<p class="mut">${W.fee === 'member' ? 'Verified member: no green fee. ' : ''}Hire total: ${money(r.hire_fee)}</p></div><div class="bar"><button class="p" id="h">Done</button></div>`);
      $('#h').onclick = home;
    } catch (e) { $('#done').disabled = false; throw e; }
  });
}

/* ---------------- returns ---------------- */
async function returns() {
  const load = async () => {
    const { checkins } = await api('checkins/active');
    $('#list').innerHTML = checkins.length ? `<table><tr><th>Guest</th><th>Cart</th><th>Clubs</th><th>Out</th></tr>${checkins.map(c => `<tr class="click" data-id="${c.id}"><td><b>${esc(c.guest)}</b>${c.golfers > 1 ? ` +${c.golfers - 1}` : ''}<div class="mut">${esc(c.hotel || '')} ${esc(c.room || '')}</div></td><td>${esc(c.cart_name) || '—'}</td><td>${hireCell(c)}</td><td>${hm(c.checked_in_at)}</td></tr>`).join('')}</table>` : '<p class="mut">Nothing is out right now.</p>';
    document.querySelectorAll('#list tr.click').forEach(tr => tr.onclick = () => returnDialog(checkins.find(c => c.id == tr.dataset.id)));
  };
  view('<div class="card"><h2>Tap an item to return it</h2><div id="list"></div></div>', 'Returns', home);
  await load(); setTimer(load);
}
function returnDialog(c) {
  const m = document.createElement('div'); m.className = 'modal';
  m.innerHTML = `<div><h2>Return: ${esc(c.guest)}</h2><p>${esc(c.cart_name || 'No cart')} · ${esc(c.clubs_name || 'No clubs')}</p>${c.items ? `<p>${esc(c.items)}</p>` : ''}${c.bags ? `<p class="mut">Bag ${esc(c.bags)}</p>` : ''}
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
   ${checkins.length ? `<table><tr><th>Time</th><th>Guest</th><th>Cart</th><th>Clubs</th><th>Hire</th><th></th></tr>${checkins.map(c => `<tr class="${isMgr() ? 'click' : ''}" data-id="${c.id}"><td>${hm(c.checked_in_at)}</td><td><b>${esc(c.guest)}</b>${c.golfers > 1 ? ' +' + (c.golfers - 1) : ''}${c.fee_status === 'member' ? ' <span class="tag">Member</span>' : ''}<div class="mut">${esc(c.mobile)} ${esc(c.hotel || '')}</div></td><td>${esc(c.cart_name) || '—'}</td><td>${hireCell(c)}</td><td>${money(c.hire_fee)}</td>
   <td><span class="tag ${c.returned_at ? (c.return_condition === 'ok' ? '' : 'r') : 'o'}">${c.returned_at ? (c.return_condition === 'ok' ? 'Back ' + hm(c.returned_at) : c.return_condition) : 'Out'}</span></td></tr>`).join('')}</table><h3>Hire total: ${money(tot)}</h3>` : '<p class="mut">No check-ins.</p>'}</div>`, 'Check-ins', home);
  if (isMgr()) { $('#d').onchange = e => history(e.target.value); document.querySelectorAll('tr.click').forEach(tr => tr.onclick = () => detail(tr.dataset.id, () => history(date))); }
}
const detail = guard(async (id, back) => {
  const c = await api('checkins/get', { params: { id } });
  view(`<div class="card"><h2>Check-in #${c.id}</h2><p>${fmtDT(c.checked_in_at)} · ${c.holes} holes · ${esc(c.cart_name || 'walking')} · ${esc(c.clubs_name || 'own clubs')}${c.items.length ? ' · ' + c.items.map(i => esc(i.name)).join(', ') : ''}${c.bags ? ' · bag ' + esc(c.bags) : ''} · ${money(c.hire_fee)} (${esc(c.fee_status)})<br><span class="mut">Checked in by ${esc(c.staff_name)} · terms v${c.terms_version}</span></p>
   ${c.returned_at ? `<p>Returned ${fmtDT(c.returned_at)} – <b>${esc(c.return_condition)}</b> ${esc(c.return_note || '')}</p>${c.return_photo ? `<img src="${c.return_photo}" style="max-width:100%;border-radius:8px">` : ''}` : '<p><span class="tag o">Still out</span></p>'}
   <h3>Signed golfers</h3>${c.golfers.map(g => `<div class="card"><b>${esc(g.first_name)} ${esc(g.last_name)}</b> ${g.is_main == 1 ? '(main hirer)' : ''}${g.guest_type === 'member' ? ` <span class="tag">Member${g.member_no ? ' #' + esc(g.member_no) : ''}</span>` : ''}<div class="mut">${esc(g.mobile)} ${esc(g.email || '')} · signed ${fmtDT(g.signed_at)}${g.guardian_name ? ' · guardian ' + esc(g.guardian_name) : ''}</div><img class="sigimg" src="${g.signature}"></div>`).join('')}
   <h3>Terms accepted</h3><div class="terms">${esc(c.terms)}</div><p><button onclick="print()">Print</button></p></div>`, 'Check-in', back);
});

/* ---------------- dashboard ---------------- */
const DASH = { tab: null, range: 7 };
const mins = s => s ? Math.max(0, Math.round((Date.now() - new Date(s.replace(' ', 'T'))) / 60000)) : 0;
const dur = m => m < 60 ? m + 'm' : Math.floor(m / 60) + 'h ' + String(m % 60).padStart(2, '0') + 'm';
const isoDay = d => d.toLocaleDateString('en-CA');
const lastDays = n => Array.from({ length: n }, (_, i) => { const d = new Date(); d.setDate(d.getDate() - (n - 1 - i)); return d; });
const money0 = n => `${CUR} ${Math.round(+n || 0).toLocaleString()}`;
const stat = (v, label, sub = '') => `<div class="stat"><b>${v}</b>${label}${sub ? `<small>${sub}</small>` : ''}</div>`;
const meter = (label, n, total, right = '') => `<div class="meter"><span>${label}</span><div class="track"><i style="width:${total ? Math.round(100 * n / total) : 0}%"></i></div><em>${right || n}</em></div>`;
const chart = cols => { const mx = Math.max(1, ...cols.map(c => c.v)); return `<div class="chart">${cols.map(c => `<div class="col" title="${esc(c.tip || '')}"><div class="bar ${c.v ? '' : 'z'}" style="height:${Math.max(2, Math.round(100 * c.v / mx))}%"><span>${c.v || ''}</span></div><small>${esc(c.l)}</small></div>`).join('')}</div>`; };
const FL = { available: 'Available', out: 'Out on course', maintenance: 'Maintenance', charging: 'Charging', offline: 'Offline', out_of_bounds: 'Out of bounds' };

async function overviewHtml() {
  const { courses } = await api('dashboard/overview');
  const sum = f => courses.reduce((s, c) => s + +f(c), 0);
  return `<div class="stats">${stat(sum(c => c.today.rounds), 'Check-ins today', 'all courses')}${stat(sum(c => c.today.out_now), 'On course now')}${stat(money0(sum(c => c.today.fees)), 'Hire revenue today')}${stat(money0(sum(c => c.week.fees)), 'Hire revenue, 7 days', sum(c => c.week.rounds) + ' check-ins')}</div>
   <div class="cgrid">${courses.map(c => {
    const byDay = Object.fromEntries(c.days.map(x => [x.d, +x.n])), smx = Math.max(1, ...c.days.map(x => +x.n));
    return `<div class="card course">
      <div class="chead"><h2>${esc(c.name)}</h2>${+c.issues_week ? `<span class="tag r">${c.issues_week} issue${c.issues_week > 1 ? 's' : ''} this week</span>` : ''}</div>
      <div class="mini"><div><b>${c.today.rounds}</b>today</div><div><b>${c.today.out_now}</b>on course</div><div><b>${money0(c.today.fees)}</b>revenue</div></div>
      ${meter('Carts free', +c.carts.available, +c.carts.total, `${c.carts.available} of ${c.carts.total}`)}
      <div class="spark">${lastDays(14).map(d => { const n = byDay[isoDay(d)] || 0; return `<i title="${isoDay(d)}: ${n}" style="height:${Math.max(4, Math.round(100 * n / smx))}%"></i>`; }).join('')}</div>
      <p class="mut">7 days: ${c.week.rounds} check-ins · ${money0(c.week.fees)} &nbsp;|&nbsp; This month: ${c.month.rounds} · ${money0(c.month.fees)}</p>
      <button class="p" data-go="c:${c.id}" style="width:100%">Open ${esc(c.name)} dashboard →</button></div>`;
  }).join('')}</div>`;
}

async function courseHtml() {
  const d = await api('dashboard', { params: { range: DASH.range } });
  const p = d.period, t = d.today, r = DASH.range, label = r === 1 ? 'Today' : `Last ${r} days`;
  const carts = d.fleet.filter(a => a.kind === 'cart'), clubs = d.fleet.filter(a => a.kind === 'clubs'), ft = feat(), shoes = ft.shoes ? d.fleet.filter(a => a.kind === 'shoes') : [], tennis = ft.tennis ? d.fleet.filter(a => a.kind === 'racquet') : [];
  const free = carts.filter(a => a.status === 'available').length;
  const byDay = Object.fromEntries(d.days.map(x => [x.d, x])), byHour = Object.fromEntries(d.hours.map(x => [+x.h, +x.n]));
  const holes = Object.fromEntries(d.mix.holes.map(x => [x.k, +x.n])), tot = (+holes[9] || 0) + (+holes[18] || 0);
  const fee = Object.fromEntries(d.mix.fee.map(x => [x.k, x]));
  const late = c => mins(c.checked_in_at) > (+c.holes === 9 ? 210 : 360);
  const tiles = list => list.map(a => `<div class="fl ${a.status}" title="${esc(a.name)} · ${FL[a.status] || a.status}${a.guest ? ' · ' + esc(a.guest) : ''}">${esc(a.name.replace(/^(cart|set|shoes?|racquet)\s*/i, ''))}${a.status === 'out' ? `<small>${dur(mins(a.since))}</small>` : a.gps_battery != null && a.gps_battery < 25 ? '<small>🪫</small>' : ''}</div>`).join('') || '<span class="mut">None set up</span>';
  const legend = list => Object.keys(FL).map(s => [s, list.filter(a => a.status === s).length]).filter(([, n]) => n).map(([s, n]) => `<span class="lg"><i class="fl ${s}"></i>${FL[s]} ${n}</span>`).join('');
  return `<div class="seg" role="group">${[[1, 'Today'], [7, '7 days'], [30, '30 days']].map(([v, l]) => `<button class="${r === v ? 'on' : ''}" data-range="${v}">${l}</button>`).join('')}<span class="mut">Updated ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} · refreshes every 30s</span></div>
   <div class="stats">
     ${stat(p.rounds, 'Check-ins', label)}${stat(p.golfers, 'Golfers', label)}${stat(money0(p.fees), 'Hire revenue', label)}
     ${stat(t.out_now, 'On course now', 'right now')}${stat(`${free}<span class="of"> / ${carts.length}</span>`, 'Carts available')}${stat(money0(p.unpaid), 'Still to collect', 'unpaid hire, ' + label.toLowerCase())}${stat(p.members, 'Member rounds', `of ${p.rounds} check-ins`)}${stat(p.issues, 'Damage / warnings', label)}
   </div>
   <div class="two">
    <div class="card"><h2>Fleet board</h2><h3>Carts</h3><div class="fleet">${tiles(carts)}</div><div class="legend">${legend(carts)}</div>
      ${clubs.length ? `<h3>Club sets</h3><div class="fleet">${tiles(clubs)}</div><div class="legend">${legend(clubs)}</div>` : ''}${ft.shoes ? `<h3>Shoes</h3><div class="fleet">${tiles(shoes)}</div><div class="legend">${legend(shoes)}</div>` : ''}${ft.tennis ? `<h3>Tennis hire</h3><div class="fleet">${tiles(tennis)}</div><div class="legend">${legend(tennis)}</div>` : ''}</div>
    <div class="card"><h2>On the course now <span class="mut">(${d.out.length})</span></h2>
     ${d.out.length ? `<table><tr><th>Guest</th><th>Cart</th><th>Clubs</th><th>Out</th></tr>${d.out.map(c => `<tr><td><b>${esc(c.guest)}</b>${c.golfers > 1 ? ' +' + (c.golfers - 1) : ''}<div class="mut">${esc(c.hotel || '')} · ${c.holes} holes</div></td><td>${esc(c.cart_name) || '—'}</td><td>${hireCell(c)}</td><td><span class="tag ${late(c) ? 'r' : 'o'}">${dur(mins(c.checked_in_at))}</span></td></tr>`).join('')}</table>` : '<p class="mut">Nothing is out right now.</p>'}</div>
   </div>
   <div class="two">
    <div class="card"><h2>Check-ins, last 14 days</h2>${chart(lastDays(14).map(x => { const k = byDay[isoDay(x)]; return { l: x.getDate(), v: k ? +k.n : 0, tip: `${isoDay(x)}: ${k ? k.n : 0} check-ins, ${money0(k?.fees)}` }; }))}</div>
    <div class="card"><h2>Busiest hours <span class="mut">${label.toLowerCase()}</span></h2>${chart(Array.from({ length: 13 }, (_, i) => i + 6).map(h => ({ l: h > 12 ? h - 12 + 'p' : h === 12 ? '12p' : h + 'a', v: byHour[h] || 0 })))}</div>
   </div>
   <div class="two">
    <div class="card"><h2>Mix <span class="mut">${label.toLowerCase()}</span></h2>
     ${meter('18 holes', +holes[18] || 0, tot)}${meter('9 holes', +holes[9] || 0, tot)}
     <h3>Guests from</h3>${(() => { const o = Object.fromEntries((d.origin || []).map(x => [x.k, +x.n])); return meter('Fiji residents', o.local || 0, p.rounds) + meter('Overseas visitors', o.overseas || 0, p.rounds) + (o.unknown ? meter('Not recorded', o.unknown, p.rounds) : ''); })()}
     ${(d.countries || []).length ? d.countries.map(x => meter(esc(x.name), +x.n, +d.countries[0].n, x.n)).join('') : ''}
     <h3>Green fee</h3>${[['paid', 'Paid'], ['room', 'Charged to room'], ['member', 'Member: no green fee'], ['unpaid', 'Collect at end']].map(([k, l]) => meter(l, +fee[k]?.n || 0, p.rounds, `${+fee[k]?.n || 0} · ${money0(fee[k]?.amt)}`)).join('')}
     ${d.hotels.length ? `<h3>Top hotels</h3>${d.hotels.map(h => meter(esc(h.name), +h.n, +d.hotels[0].n, h.n)).join('')}` : ''}</div>
    <div class="card"><h2>Recent damage &amp; warnings</h2>${d.issues.length ? d.issues.map(i => `<p><span class="tag ${i.return_condition === 'damaged' ? 'r' : 'o'}">${i.return_condition}</span> <b>${esc(i.guest)}</b> · ${fmtDT(i.checked_in_at)}<br><span class="mut">${esc(i.return_note || '')}</span></p>`).join('') : '<p class="mut">None 🎉</p>'}</div>
   </div>
   <p style="display:flex;gap:10px;flex-wrap:wrap"><button data-go="hist">📋 Browse history &amp; signed waivers</button><button data-go="export">⬇ Export check-ins (CSV)</button></p>`;
}

const dashboard = guard(async tab => {
  const admin = !ME.course_id && COURSES.length > 1;
  tab = tab ?? DASH.tab ?? (admin ? 'all' : CID);
  if (!admin || !COURSES.some(c => c.id == tab)) tab = admin && tab === 'all' ? 'all' : CID;
  DASH.tab = tab;
  if (tab !== 'all') { CID = +tab; if (admin) store.set('bula_course', CID); }
  const paint = () => tab === 'all' ? overviewHtml() : courseHtml();
  const body = await paint();
  const tabs = admin ? `<div class="tabs">${[['all', 'All courses'], ...COURSES.map(c => [c.id, c.name])].map(([k, l]) => `<button class="${tab == k ? 'on' : ''}" data-t="${k}">${esc(l)}</button>`).join('')}</div>` : '';
  view(`${tabs}<div id="dash">${body}</div>`, 'Dashboard', home);
  document.querySelectorAll('.tabs button').forEach(b => b.onclick = () => dashboard(b.dataset.t === 'all' ? 'all' : +b.dataset.t));
  $('#dash').onclick = e => {
    const b = e.target.closest('[data-range],[data-go]'); if (!b) return;
    if (b.dataset.range) { DASH.range = +b.dataset.range; return dashboard(tab); }
    const go = b.dataset.go;
    if (go.startsWith('c:')) return dashboard(+go.slice(2));
    if (go === 'hist') return history(isoDay(new Date()));
    if (go === 'export') return settings('export');
  };
  setTimer(async () => { $('#dash').innerHTML = await paint(); });
});

/* ---------------- settings ---------------- */
function settings(tab = 'fleet') {
  view(`<div class="tabs">${[['fleet', 'Carts & clubs'], ['members', 'Members'], ...(isAdmin() ? [['terms', 'Terms'], ['users', 'Staff'], ['courses', 'Courses']] : [['users', 'Staff']]), ['export', 'Export']].map(([k, l]) => `<button class="${tab === k ? 'on' : ''}" data-t="${k}">${l}</button>`).join('')}</div><div id="pane"></div>`, 'Settings', home);
  document.querySelectorAll('.tabs button').forEach(b => b.onclick = () => settings(b.dataset.t));
  guard(async () => ({ fleet, terms: termsPane, users: usersPane, courses: coursesPane, members: membersPane, export: exportPane })[tab]())();
}
async function fleet() {
  const { assets } = await api('assets', { params: { all: 1 } });
  const row = a => `<tr data-id="${a.id || ''}"><td><input class="n" value="${esc(a.name)}"></td><td><input class="ds" value="${esc(a.description || '')}" placeholder="e.g. Men's RH"></td><td><input class="f" type="number" step="0.5" value="${a.fee ?? 0}" style="width:80px"></td>
    <td><input class="g" value="${esc(a.gps_unit_id || '')}" placeholder="GPS unit" style="width:110px"></td><td><label><input class="m" type="checkbox" ${+a.maintenance ? 'checked' : ''}> Maint.</label><label><input class="ac" type="checkbox" ${a.active === undefined || +a.active ? 'checked' : ''}> Active</label></td>
    <td><button class="sv p" data-k="${a.kind}">Save</button></td></tr>`;
  const sect = (k, t) => `<div class="card"><h2>${t} · ${esc(courseName())}</h2><table><tr><th>Name</th><th>Description</th><th>Fee</th><th>GPS ID</th><th></th><th></th></tr>${assets.filter(a => a.kind === k).map(row).join('')}${row({ kind: k, name: '', fee: k === 'cart' ? 40 : 50 })}</table><p class="mut">Last row adds a new one.</p></div>`;
  const bulk = `<div class="card"><h2>Add many at once · ${esc(courseName())}</h2><p class="mut">Creates a numbered run, e.g. Cart 1 to Cart 40. Names that already exist are skipped. Rename or re-price individual ones below.</p>
    <div class="row"><div><label>What</label><select id="bk"><option value="cart">Golf carts</option><option value="clubs">Club sets</option>${feat().shoes ? '<option value="shoes">Rental shoes</option>' : ''}${feat().tennis ? '<option value="racquet">Tennis hire</option>' : ''}</select></div><div><label>Name prefix</label><input id="bp" value="Cart"></div></div>
    <div class="row"><div><label>Start at number</label><input id="bf" type="number" min="0" value="1"></div><div><label>How many</label><input id="bn" type="number" min="1" max="200" value="${assets.some(a => a.kind === 'cart') ? 10 : 40}"></div></div>
    <div class="row"><div><label>Hire fee (${CUR})</label><input id="bfee" type="number" step="0.5" min="0" value="40"></div><div><label>Description (optional)</label><input id="bd" placeholder="e.g. Men's, Ladies', Tennis"></div></div><p><button class="p" id="bgo">Create</button></p></div>`;
  $('#pane').innerHTML = bulk + sect('cart', 'Golf carts') + sect('clubs', 'Club sets') + (feat().shoes ? sect('shoes', 'Rental shoes') : '') + (feat().tennis ? sect('racquet', 'Tennis hire (racquets, tennis shoes)') : '');
  const DEF = { cart: ['Cart', 40], clubs: ['Set', 50], shoes: ['Shoes', 10], racquet: ['Racquet', 15] };
  $('#bk').onchange = () => { const [p, f] = DEF[$('#bk').value]; $('#bp').value = p; $('#bfee').value = f; };
  $('#bgo').onclick = guard(async () => {
    const r = await api('assets/bulk', { method: 'POST', body: { kind: $('#bk').value, prefix: $('#bp').value, from: $('#bf').value, count: $('#bn').value, fee: $('#bfee').value, description: $('#bd').value } });
    toast(`${r.created} created${r.skipped ? `, ${r.skipped} already existed` : ''}`); fleet();
  });
  document.querySelectorAll('button.sv').forEach(b => b.onclick = guard(async () => {
    const tr = b.closest('tr');
    await api('assets/save', { method: 'POST', body: { id: tr.dataset.id || undefined, kind: b.dataset.k, name: $('.n', tr).value, description: $('.ds', tr).value, fee: $('.f', tr).value, gps_unit_id: $('.g', tr).value, maintenance: $('.m', tr).checked, active: $('.ac', tr).checked } });
    toast('Saved'); fleet();
  }));
}
async function termsPane() {
  const t = await api('terms/current');
  $('#pane').innerHTML = `<div class="card"><h2>Waiver / terms of hire · ${esc(courseName())} <span class="mut">(version ${t.version})</span></h2><p class="alert">Have this wording reviewed by your lawyer. Saving creates a new version; past check-ins keep the version the guest signed.</p>
    <textarea id="tb" rows="16">${esc(t.body)}</textarea><p><button class="p" id="sv">Save as new version</button></p></div>`;
  $('#sv').onclick = guard(async () => { await api('terms/save', { method: 'POST', body: { body: $('#tb').value } }); toast('New version saved'); termsPane(); });
}
async function usersPane() {
  const { users } = await api('users/list');
  const copt = id => `<option value="">All courses (head office)</option>${COURSES.map(c => `<option value="${c.id}" ${c.id == id ? 'selected' : ''}>${esc(c.name)} only</option>`).join('')}`;
  const adm = isAdmin();
  const cname = id => id ? esc(courseName(id)) : 'All courses';
  $('#pane').innerHTML = `<div class="card"><h2>${adm ? 'Staff logins' : 'Check-in staff · ' + esc(courseName())}</h2><p class="mut">${adm
    ? 'Everyone gets their own login. Suggested set-up per course: one <b>manager</b> (the course admin) and two <b>staff</b> (check-in), each set to that course only, so they never see the other course. Course admins can then manage their own check-in staff.'
    : 'Each person has their own login (no sharing). Add or disable your check-in staff here; leave the password blank to keep the current one.'}</p><table><tr><th>Name</th><th>Username</th>${adm ? '<th>Role</th><th>Course</th>' : ''}<th>New password</th><th></th></tr>${[...users, { name: '', username: '', role: 'staff', active: 1 }].map(u => `<tr data-id="${u.id || ''}"><td><input class="n" value="${esc(u.name)}"></td><td><input class="un" value="${esc(u.username)}"></td>
    ${adm ? `<td><select class="r"><option>staff</option><option ${u.role === 'manager' ? 'selected' : ''}>manager</option></select></td><td><select class="cr">${copt(u.course_id)}</select></td>` : ''}<td><input class="pw" type="password" placeholder="${u.id ? 'leave blank' : 'required'}"></td><td><label><input class="ac" type="checkbox" ${+u.active ? 'checked' : ''}> Active</label> <button class="sv p">Save</button></td></tr>`).join('')}</table></div>`;
  document.querySelectorAll('button.sv').forEach(b => b.onclick = guard(async () => {
    const tr = b.closest('tr');
    await api('users/save', { method: 'POST', body: { id: tr.dataset.id || undefined, name: $('.n', tr).value, username: $('.un', tr).value, role: $('.r', tr)?.value, course_id: $('.cr', tr)?.value || null, password: $('.pw', tr).value, active: $('.ac', tr).checked } });
    toast('Saved'); usersPane();
  }));
}
/** Logo picked in the browser: scaled down and kept as PNG (so transparency survives), stored with the course. */
function logoData(file, max = 360) {
  return new Promise((res, rej) => {
    const img = new Image();
    img.onload = () => {
      // draw, then trim empty (transparent or near-white) margins so the logo fills the header space
      const w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
      const src = document.createElement('canvas'); src.width = w; src.height = h;
      const sx = src.getContext('2d', { willReadFrequently: true }); sx.drawImage(img, 0, 0, w, h);
      let x0 = w, y0 = h, x1 = -1, y1 = -1;
      try {
        const px = sx.getImageData(0, 0, w, h).data;
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
          const i = (y * w + x) * 4;
          if (px[i + 3] > 20 && (px[i] < 242 || px[i + 1] < 242 || px[i + 2] < 242)) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
        }
      } catch { x1 = -1; }
      if (x1 < 0) { x0 = 0; y0 = 0; x1 = w - 1; y1 = h - 1; }
      const pad = Math.round(Math.max(x1 - x0, y1 - y0) * 0.03);
      x0 = Math.max(0, x0 - pad); y0 = Math.max(0, y0 - pad); x1 = Math.min(w - 1, x1 + pad); y1 = Math.min(h - 1, y1 + pad);
      const cw = x1 - x0 + 1, ch = y1 - y0 + 1, sc = Math.min(1, max / Math.max(cw, ch)), c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(cw * sc)); c.height = Math.max(1, Math.round(ch * sc));
      c.getContext('2d').drawImage(src, x0, y0, cw, ch, 0, 0, c.width, c.height);
      res(c.toDataURL('image/png'));
    };
    img.onerror = () => rej(new Error('Could not read that image'));
    img.src = URL.createObjectURL(file);
  });
}

async function coursesPane() {
  const row = c => `<tr data-id="${c.id || ''}">
    <td style="width:120px"><div class="lg-prev">${+c.has_logo ? `<img src="${courseLogoUrl(c)}" alt="">` : '<span class="mut">No logo</span>'}</div>
      <label class="upl">Upload logo<input class="lf" type="file" accept="image/*" hidden></label>${+c.has_logo ? '<button class="rl" type="button">Remove</button>' : ''}</td>
    <td><label>Short name (switcher, tabs)</label><input class="n" value="${esc(c.name)}" placeholder="e.g. Natadola">
      <label>Full name (header, guest screens)</label><input class="fn" value="${esc(c.full_name || '')}" placeholder="e.g. Natadola Bay Golf Course"></td>
    <td><label><input class="tn" type="checkbox" ${+c.tennis ? 'checked' : ''}> Tennis hire</label><label><input class="sh" type="checkbox" ${+c.shoes ? 'checked' : ''}> Shoe hire</label><label><input class="rc" type="checkbox" ${+c.room_charge ? 'checked' : ''}> Charge to room</label><label><input class="ac" type="checkbox" ${c.active === undefined || +c.active ? 'checked' : ''}> Active</label></td>
    <td><button class="sv p">${c.id ? 'Save' : 'Add'}</button>${c.id ? ` <button class="setup" data-c="${c.id}">Set up fleet →</button>` : ''}</td></tr>`;
  $('#pane').innerHTML = `<div class="card"><h2>Courses</h2><p class="mut">Each course has its own carts, club sets, member list, check-ins, terms and dashboard. The <b>logo</b> and <b>full name</b> show in the tablet header. <b>Tennis hire</b>, <b>Shoe hire</b> and <b>Charge to room</b> switch those extras on for a course (Natadola has no tennis). Room charging is off until you tick it, and then only appears for guests who give a hotel. A new course starts with no fleet: use "Set up fleet".</p><table>${[...COURSES, { name: '' }].map(row).join('')}</table></div>`;
  document.querySelectorAll('button.setup').forEach(b => b.onclick = () => { CID = +b.dataset.c; store.set('bula_course', CID); settings('fleet'); });
  document.querySelectorAll('input.lf').forEach(inp => inp.onchange = guard(async () => {
    const f = inp.files[0]; if (!f) return;
    const tr = inp.closest('tr'); tr._logo = await logoData(f); tr._rm = false;
    $('.lg-prev', tr).innerHTML = `<img src="${tr._logo}" alt="">`; toast('Logo ready: press Save');
  }));
  document.querySelectorAll('button.rl').forEach(b => b.onclick = () => { const tr = b.closest('tr'); tr._logo = ''; tr._rm = true; $('.lg-prev', tr).innerHTML = '<span class="mut">Removed: press Save</span>'; });
  document.querySelectorAll('button.sv').forEach(b => b.onclick = guard(async () => {
    const tr = b.closest('tr');
    await api('courses/save', { method: 'POST', body: { id: tr.dataset.id || undefined, name: $('.n', tr).value, full_name: $('.fn', tr).value, tennis: $('.tn', tr).checked, shoes: $('.sh', tr).checked, room_charge: $('.rc', tr).checked, active: $('.ac', tr).checked, logo: tr._logo || '', remove_logo: !!tr._rm } });
    const r = await api('auth/me'); COURSES = r.courses; if (!COURSES.some(c => c.id == CID)) pickCourse();
    toast('Saved'); settings('courses');
  }));
}

/* ---------------- members (each course keeps its own list) ---------------- */
const MP = { q: '', status: 'all' };
const MON = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

/** Day-first dates (Fiji/NZ): 30/06/2027, 30-6-27, 30 Jun 2027, 2027-06-30, or an Excel date number. Returns {ok, val} with val as YYYY-MM-DD (null = blank). */
function parseDate(v) {
  v = String(v ?? '').trim(); if (!v) return { ok: true, val: null };
  let y, m, d, x;
  if ((x = v.match(/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/))) { y = +x[1]; m = +x[2]; d = +x[3]; }
  else if ((x = v.match(/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/))) { d = +x[1]; m = +x[2]; y = +x[3]; if (y < 100) y += 2000; }
  else if ((x = v.match(/^(\d{1,2})[\s-]+([A-Za-z]{3,})[\s,-]+(\d{2,4})$/))) { d = +x[1]; m = MON.indexOf(x[2].slice(0, 3).toLowerCase()) + 1; y = +x[3]; if (y < 100) y += 2000; }
  else if (/^\d{5}$/.test(v)) { const t = new Date(Date.UTC(1899, 11, 30) + (+v) * 86400000); y = t.getUTCFullYear(); m = t.getUTCMonth() + 1; d = t.getUTCDate(); }
  else return { ok: false };
  const t = new Date(Date.UTC(y, m - 1, d));
  if (!m || t.getUTCFullYear() !== y || t.getUTCMonth() !== m - 1 || t.getUTCDate() !== d) return { ok: false };
  return { ok: true, val: `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}` };
}

function parseCSV(text) {
  text = text.replace(/^﻿/, '');
  const head = text.split(/\r?\n/)[0] || '';
  const delim = [',', ';', '\t'].map(d => [d, head.split(d).length]).sort((a, b) => b[1] - a[1])[0][0];
  const rows = []; let row = [], cur = '', quoted = false;
  const endRow = () => { row.push(cur); cur = ''; if (row.some(c => c.trim() !== '')) rows.push(row); row = []; };
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) { if (c === '"') { if (text[i + 1] === '"') { cur += '"'; i++; } else quoted = false; } else cur += c; }
    else if (c === '"') quoted = true;
    else if (c === delim) { row.push(cur); cur = ''; }
    else if (c === '\n' || c === '\r') { if (c === '\r' && text[i + 1] === '\n') i++; endRow(); }
    else cur += c;
  }
  if (cur !== '' || row.length) endRow();
  return rows;
}

/** CSV text to member rows, matching columns by their heading so the course can send whatever layout they already have. */
function membersFromCSV(text) {
  const rows = parseCSV(text);
  if (rows.length < 2) return { members: [], problems: ['The file has no member rows (it needs a heading row, then one row per member).'] };
  const key = h => String(h).toLowerCase().replace(/[^a-z0-9]/g, '');
  const WANT = {
    member_no: ['memberno', 'membernumber', 'memberid', 'membershipno', 'membershipnumber', 'number', 'no', 'id'],
    first_name: ['firstname', 'first', 'givenname', 'forename'], last_name: ['lastname', 'last', 'surname', 'familyname'],
    name: ['name', 'fullname', 'membername', 'member'], mobile: ['mobile', 'mobileno', 'mobilenumber', 'mobilephone', 'phone', 'phoneno', 'phonenumber', 'telephone', 'tel', 'cell', 'contact'],
    email: ['email', 'emailaddress'], expires_on: ['expiry', 'expirydate', 'expires', 'expireson', 'expiration', 'expirationdate', 'renewal', 'renewaldate', 'validuntil', 'validto', 'enddate', 'membershipexpiry'],
  };
  const heads = rows[0].map(key), col = {};
  for (const [f, names] of Object.entries(WANT)) { const i = heads.findIndex(h => names.includes(h)); if (i >= 0) col[f] = i; }
  const problems = [];
  if (col.first_name === undefined && col.name === undefined) problems.push('No name column found. Use "First name" and "Last name" (or a single "Name") in the heading row.');
  if (col.expires_on === undefined) problems.push('No expiry column found. Add a column headed "Expiry date" so expiry warnings work (members will be treated as having no expiry).');
  const members = [];
  rows.slice(1).forEach((r, i) => {
    const g = f => col[f] === undefined ? '' : String(r[col[f]] ?? '').trim();
    let first = g('first_name'), last = g('last_name');
    if ((!first || !last) && g('name')) { const nm = g('name'); if (nm.includes(',')) { [last, first] = nm.split(',').map(s => s.trim()); } else { const p = nm.split(/\s+/); last = p.length > 1 ? p.pop() : ''; first = p.join(' '); } }
    const dt = parseDate(g('expires_on'));
    if (!first || !last) { problems.push(`Row ${i + 2}: needs a first and last name`); return; }
    if (!dt.ok) { problems.push(`Row ${i + 2} (${first} ${last}): can't read the expiry date "${g('expires_on')}"`); return; }
    members.push({ member_no: g('member_no'), first_name: first, last_name: last, mobile: g('mobile'), email: g('email'), expires_on: dt.val });
  });
  return { members, problems };
}

async function membersPane() {
  const d = await api('members/list', { params: { q: MP.q, status: MP.status } }), c = d.counts, today = isoDay(new Date());
  const stat = m => { if (!m.expires_on) return ['No expiry', 'tag']; const dl = Math.round((new Date(m.expires_on + 'T00:00') - new Date(today + 'T00:00')) / 864e5); return dl < 0 ? ['Expired', 'tag r'] : dl <= 30 ? [`Expires in ${dl}d`, 'tag o'] : ['Current', 'tag']; };
  const row = m => `<tr data-id="${m.id || ''}"><td><input class="mn" value="${esc(m.member_no || '')}" placeholder="No." style="width:90px"></td><td><input class="mf" value="${esc(m.first_name)}" placeholder="First"></td><td><input class="ml" value="${esc(m.last_name)}" placeholder="Last"></td>
    <td><input class="mm" value="${esc(m.mobile || '')}" placeholder="Mobile" style="width:120px"></td><td><input class="me" value="${esc(m.email || '')}" placeholder="Email"></td><td><input class="mx" type="date" value="${esc(m.expires_on || '')}"></td>
    <td>${m.id ? `<span class="${stat(m)[1]}">${stat(m)[0]}</span>` : ''}</td><td style="white-space:nowrap"><button class="sv p">${m.id ? 'Save' : 'Add'}</button>${m.id ? ' <button class="d dl">Delete</button>' : ''}</td></tr>`;
  $('#pane').innerHTML = `
   <div class="stats">${stat0(c.total, 'Members', courseName())}${stat0(c.active, 'Current')}${stat0(c.expiring, 'Expiring in 30 days')}${stat0(c.expired, 'Expired')}</div>
   <div class="card"><h2>Upload member list · ${esc(courseName())}</h2>
    <p class="mut">Export your member list from Excel as <b>CSV</b> and upload it here. The first row must be headings: <b>Member no</b>, <b>First name</b>, <b>Last name</b>, <b>Mobile</b>, <b>Email</b>, <b>Expiry date</b> (a single <b>Name</b> column also works). Dates are read day first, e.g. 30/06/2027. Nothing is saved until you press Import.</p>
    <p><button id="tpl">⬇ Download a template</button></p>
    <input id="mf" type="file" accept=".csv,text/csv,text/plain">
    <div id="prev"></div></div>
   <div class="card"><h2>Member list</h2>
    <div class="row"><div><input id="mq" type="search" placeholder="Search name, member no. or mobile" value="${esc(MP.q)}"></div>
    <div><select id="ms">${[['all', 'All members'], ['active', 'Current'], ['expiring', 'Expiring in 30 days'], ['expired', 'Expired']].map(([v, l]) => `<option value="${v}" ${MP.status === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div></div>
    <table><tr><th>No.</th><th>First</th><th>Last</th><th>Mobile</th><th>Email</th><th>Expiry</th><th>Status</th><th></th></tr>${row({ first_name: '', last_name: '' })}${d.members.map(row).join('')}</table>
    ${d.members.length >= 300 ? '<p class="mut">Showing the first 300. Use search to find others.</p>' : ''}</div>`;
  const reload = () => membersPane();
  $('#mq').onchange = e => { MP.q = e.target.value.trim(); reload(); };
  $('#ms').onchange = e => { MP.status = e.target.value; reload(); };
  document.querySelectorAll('button.sv').forEach(b => b.onclick = guard(async () => {
    const tr = b.closest('tr');
    await api('members/save', { method: 'POST', body: { id: tr.dataset.id || undefined, member_no: $('.mn', tr).value, first_name: $('.mf', tr).value, last_name: $('.ml', tr).value, mobile: $('.mm', tr).value, email: $('.me', tr).value, expires_on: $('.mx', tr).value || null } });
    toast('Saved'); reload();
  }));
  document.querySelectorAll('button.dl').forEach(b => b.onclick = guard(async () => {
    const tr = b.closest('tr'); if (!confirm('Delete this member from the list?')) return;
    await api('members/delete', { method: 'POST', body: { id: +tr.dataset.id } }); toast('Deleted'); reload();
  }));
  $('#tpl').onclick = () => {
    const blob = new Blob(['Member no,First name,Last name,Mobile,Email,Expiry date\r\nM001,Jane,Smith,7001234,jane@example.com,30/06/2027\r\n'], { type: 'text/csv' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'member-list-template.csv'; a.click();
  };
  $('#mf').onchange = guard(async e => {
    const f = e.target.files[0]; if (!f) return;
    const { members, problems } = membersFromCSV(await f.text());
    const noExp = members.filter(m => !m.expires_on).length;
    $('#prev').innerHTML = `<h3>${members.length} member${members.length === 1 ? '' : 's'} ready to import${noExp ? ` <span class="mut">(${noExp} without an expiry date)</span>` : ''}</h3>
      ${problems.length ? `<div class="alert">${problems.slice(0, 8).map(esc).join('<br>')}${problems.length > 8 ? `<br>…and ${problems.length - 8} more` : ''}</div>` : ''}
      ${members.length ? `<table><tr><th>No.</th><th>Name</th><th>Mobile</th><th>Expiry</th></tr>${members.slice(0, 5).map(m => `<tr><td>${esc(m.member_no)}</td><td>${esc(m.first_name)} ${esc(m.last_name)}</td><td>${esc(m.mobile)}</td><td>${m.expires_on ? fmtD(m.expires_on) : '—'}</td></tr>`).join('')}</table><p class="mut">First 5 shown.</p>
      <label>What should happen to the current list?</label><select id="mode"><option value="merge">Add new members and update existing ones (matched by member no., or name + mobile)</option><option value="replace">Replace the whole list with this file (removes anyone not in it)</option></select>
      <p><button class="p" id="imp">Import ${members.length} member${members.length === 1 ? '' : 's'}</button></p>` : ''}`;
    if (!members.length) return;
    $('#imp').onclick = guard(async () => {
      if ($('#mode').value === 'replace' && !confirm(`Replace the ${esc(courseName())} member list with these ${members.length} members? Anyone not in the file will be removed.`)) return;
      $('#imp').disabled = true;
      try {
        const r = await api('members/import', { method: 'POST', body: { rows: members, mode: $('#mode').value } });
        toast(`${r.created} added, ${r.updated} updated${r.error_count ? `, ${r.error_count} skipped` : ''}`); MP.q = ''; MP.status = 'all'; reload();
      } catch (err) { $('#imp').disabled = false; throw err; }
    });
  });
}
const stat0 = (v, label, sub = '') => `<div class="stat"><b>${v}</b>${label}${sub ? `<small>${esc(sub)}</small>` : ''}</div>`;

function exportPane() {
  const t = new Date().toLocaleDateString('en-CA'), m = t.slice(0, 8) + '01';
  $('#pane').innerHTML = `<div class="card"><h2>Export</h2>${isAdmin() && COURSES.length > 1 ? `<label>Course</label><select id="cx"><option value="${CID}">${esc(courseName())}</option><option value="all">All courses</option></select>` : ''}<div class="row"><div><label>From</label><input id="f" type="date" value="${m}"></div><div><label>To</label><input id="t" type="date" value="${t}"></div></div>
    <p><button class="p" id="a">Download check-ins (CSV)</button> <button id="b">Download marketing list (opted-in guests)</button></p></div>`;
  const go = type => location.href = `${API}?r=export&course=${$('#cx')?.value || CID}&type=${type}&from=${$('#f').value}&to=${$('#t').value}`;
  $('#a').onclick = () => go('checkins'); $('#b').onclick = () => go('marketing');
}

/* ---------------- boot ---------------- */
(async () => {
  try { const r = await api('auth/me'); CUR = r.currency; COURSE = r.course; ME = r.user; COURSES = r.courses || []; pickCourse(); } catch (e) { if (!/sign in/i.test(e.message)) { view(`<div class="card"><h2>Can't reach the server</h2><p>${esc(e.message)}</p></div>`); return; } }
  ME ? home() : login();
})();
