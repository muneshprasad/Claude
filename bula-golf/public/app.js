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
  if (ME?.course_id) store.set('bula_course', ME.course_id); // so this tablet's sign-in screen shows its own club
  const saved = +store.get('bula_course');
  CID = ME?.course_id || (COURSES.some(c => c.id == saved) ? saved : COURSES[0]?.id || 0);
}

async function api(route, { method = 'GET', body, params, quiet = false } = {}) {
  const qs = new URLSearchParams({ r: route, ...(params || {}) });
  let res; const ctl = new AbortController(), timer = setTimeout(() => ctl.abort(), 25000); // a connection that hangs counts as no connection
  try {
    res = await fetch(`${API}?${qs}`, {
      signal: ctl.signal,
      method, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'bula', ...(CID ? { 'X-Bula-Course': CID } : {}), ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch { const err = new Error('No connection to the server. Check Wi-Fi and try again.'); err.offline = true; err.quiet = quiet; throw err; } finally { clearTimeout(timer); }
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && ME) { ME = null; login(); throw new Error('Please sign in again'); }
  if (!res.ok) throw new Error(data.error || 'Something went wrong');
  return data;
}
function toast(msg, err = false) {
  const t = $('#toast'); t.textContent = msg; t.className = 'show' + (err ? ' err' : '');
  clearTimeout(toast.t); toast.t = setTimeout(() => t.className = '', err ? 4500 : 2200);
}
const guard = fn => async (...a) => { try { await fn(...a); } catch (e) { if (!e.quiet) toast(e.message, true); } };
const money = n => `${CUR} ${Number(n || 0).toFixed(2)}`;
const hm = s => s ? s.slice(11, 16) : '';
const fmtDT = s => s ? new Date(s.replace(' ', 'T')).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
const setTimer = (fn, ms = 10000) => { clearInterval(timer); timer = fn ? setInterval(() => document.hidden || fn().catch(() => {}), ms) : null; };
const view = (html, title = '', back = null) => {
  setTimer(null);
  const switcher = ME && !ME.course_id && COURSES.length > 1 ? `<select id="cs" aria-label="Course">${COURSES.map(c => `<option value="${c.id}" ${c.id == CID ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>` : '';
  $('#app').innerHTML = `<header><h1>${ME && +COURSES.find(c => c.id == CID)?.has_logo ? `<img class="hlogo" src="${courseLogoUrl(COURSES.find(c => c.id == CID))}" alt="">` : '<svg class="hflag" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 21V3l11 5-11 5"/><path d="M4 21h9"/></svg>'} ${esc(ME ? courseFull() : COURSE)}${title ? ' <em>· ' + esc(title) + '</em>' : ''}</h1><div>${switcher}${back ? '<button id="back">← Back</button> ' : ''}<button id="rfh" type="button" class="rfh" title="Refresh the app" aria-label="Refresh the app">⟳</button>${ME ? `<button id="out">${esc(ME.name)} · Sign out</button>` : ''}</div></header><main>${html}</main>`;
  if (back) $('#back').onclick = back;
  $('#rfh').onclick = () => location.reload(); // a home-screen app has no reload button of its own
  if (switcher) $('#cs').onchange = e => { CID = +e.target.value; store.set('bula_course', CID); home(); };
  if (ME) $('#out').onclick = guard(async () => { await api('auth/logout', { method: 'POST' }); ME = null; login(); });
  window.scrollTo(0, 0);
};
const hireCell = c => `${esc(c.clubs_name) || '—'}${c.items ? `<div class="mut">${esc(c.items)}</div>` : ''}${c.bags ? `<div class="mut">Bag ${esc(c.bags)}</div>` : ''}`;
/** A counter tablet shouldn't dim mid check-in: hold the screen awake while someone is signed in and the app is on screen (Android Chrome needs HTTPS; ignored elsewhere). */
let wake = null;
const keepAwake = async () => { try { if ('wakeLock' in navigator && !wake && !document.hidden && ME) { wake = await navigator.wakeLock.request('screen'); wake.addEventListener('release', () => { wake = null; }); } } catch { /* denied or unsupported: fine */ } };
document.addEventListener('visibilitychange', () => { if (!document.hidden) keepAwake(); });
/** Roles: admin (head office, or one course's admin), supervisor, staff. What a supervisor or staff member may open is set per course by that course's admin (Settings > Access). */
/* ---------------- working offline: saved on the tablet, sent when the connection comes back ---------------- */
const LS = {
  get: (k, d = null) => { try { const v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch { return d; } },
  set: (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); return true; } catch { return false; } },
};
const newRef = () => { const b = crypto.getRandomValues(new Uint8Array(16)); return [...b].map(x => x.toString(16).padStart(2, '0')).join(''); };
const isOffline = e => !!e?.offline;
/** The last good copy of what staff need when the signal drops: the terms, the carts, the rates, who is out. Kept per course. */
const cacheSet = (k, v) => LS.set(`bula_c_${CID}_${k}`, v);
const cacheGet = k => LS.get(`bula_c_${CID}_${k}`);
const QKEY = 'bula_queue';
const queue = () => LS.get(QKEY, []);

/** Save a check-in or return to send later. The signature and photos travel with it. */
function queueAdd(route, body, label) {
  const q = queue();
  if (q.length >= 30) throw new Error('Too many unsent items on this tablet. Reconnect to Wi-Fi first.');
  q.push({ id: newRef(), route, body, course: CID, label, at: Date.now(), error: null });
  if (!LS.set(QKEY, q)) throw new Error('This tablet has no space left to save it. Reconnect to Wi-Fi first.');
  queueBanner(); setTimeout(queueFlush, 4000);
}

let flushing = false;
/** Send everything waiting, oldest first. Stops at the first sign the connection is still down. */
async function queueFlush() {
  if (flushing || !ME) return;
  flushing = true;
  try {
    for (const it of queue()) {
      if (it.error) continue; // needs a person: see the banner
      const saved = CID; CID = it.course;
      try { await api(it.route, { method: 'POST', body: it.body, quiet: true }); LS.set(QKEY, queue().filter(x => x.id !== it.id)); }
      catch (e) {
        if (isOffline(e) || e.message === 'Please sign in again') break; // not now: try again later
        if (it.route === 'checkins/return' && /already returned/i.test(e.message)) { LS.set(QKEY, queue().filter(x => x.id !== it.id)); continue; } // sent twice: done
        const q = queue(), x = q.find(z => z.id === it.id); if (x) { x.error = e.message; LS.set(QKEY, q); }
      } finally { CID = saved; }
    }
  } finally { flushing = false; queueBanner(); }
}

function queueBanner() {
  const q = queue(), bad = q.filter(x => x.error).length, off = navigator.onLine === false;
  let el = document.getElementById('qbar');
  if (!q.length && !off) { el?.remove(); return; }
  if (!el) { el = document.createElement('button'); el.id = 'qbar'; el.type = 'button'; el.onclick = queueDialog; document.body.append(el); }
  el.className = bad ? 'bad' : '';
  el.textContent = bad ? `⚠ ${bad} item${bad > 1 ? 's' : ''} need${bad > 1 ? '' : 's'} attention` : q.length ? `${off ? '📶 Offline · ' : '⏳ '}${q.length} item${q.length > 1 ? 's' : ''} waiting to send` : '📶 Offline: using saved information';
}

function queueDialog() {
  const q = queue(), m = document.createElement('div'); m.className = 'modal';
  m.innerHTML = `<div><h2>Waiting to send</h2>${q.length ? q.map(it => `<div class="alert ${it.error ? 'bad' : ''}" style="margin:8px 0"><b>${esc(it.label)}</b><div class="mut">Saved ${new Date(it.at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}${it.error ? ' · ' + esc(it.error) : ' · will send automatically'}</div>
      ${it.error ? `<button type="button" data-r="${it.id}">Try again</button><button type="button" class="d" data-d="${it.id}">Discard</button>` : ''}</div>`).join('') : `<p class="mut">${navigator.onLine === false ? 'There is no connection right now. Nothing is waiting; the tablet is using the last information it saved.' : 'Nothing is waiting.'}</p>`}
    <p class="mut" style="font-size:13px">If a check-in can't be sent (for example its cart was given to someone else meanwhile), it stays here until someone decides what to do. A discarded check-in is gone for good.</p><button type="button" id="qx">Close</button></div>`;
  document.body.append(m);
  m.onclick = e => {
    if (e.target === m || e.target.id === 'qx') return m.remove();
    const r = e.target.closest('[data-r]'), d = e.target.closest('[data-d]');
    if (r) { const l = queue(); const x = l.find(z => z.id === r.dataset.r); if (x) { x.error = null; LS.set(QKEY, l); } m.remove(); queueBanner(); queueFlush(); }
    if (d && confirm('Discard this item? The guest would have to be checked in again.')) { LS.set(QKEY, queue().filter(z => z.id !== d.dataset.d)); m.remove(); queueBanner(); }
  };
}
window.addEventListener('online', () => { queueBanner(); queueFlush(); });
window.addEventListener('offline', queueBanner);
setInterval(() => { if (queue().length) queueFlush(); }, 15000);
setTimeout(queueBanner, 500);

/** Keeps a long-running tablet current. iPad home-screen apps in particular hold an old copy for ages: ask the server if the app changed, every minute and whenever the app comes back to the front. */
let APP_VER = null;
const updateReady = () => {
  const idle = !!document.querySelector('.home') || !!document.querySelector('#lf'); // home or sign-in: nobody is mid check-in, so just update
  if (idle) { toast('Updating to the latest version…'); setTimeout(() => location.reload(), 900); return; }
  if (document.getElementById('upd')) return;
  const b = document.createElement('div'); b.id = 'upd'; b.setAttribute('role', 'status');
  b.innerHTML = '<span>A new version is ready.</span><button type="button" id="updgo">Update now</button><button type="button" id="updlater" class="ghost">Later</button>';
  document.body.append(b);
  $('#updgo').onclick = () => location.reload(); $('#updlater').onclick = () => b.remove();
};
const checkVersion = async () => {
  try {
    const r = await (await fetch(`${API}?r=version`, { cache: 'no-store' })).json();
    if (!r.v) return;
    if (APP_VER === null) APP_VER = r.v; else if (r.v !== APP_VER) updateReady();
  } catch { /* offline: try again later */ }
};
document.addEventListener('visibilitychange', () => { if (!document.hidden) checkVersion(); });
window.addEventListener('online', checkVersion);
setInterval(() => { if (!document.hidden) checkVersion(); }, 60000);
checkVersion();

const can = p => ME?.role === 'admin' || (ME?.perms || []).includes(p);
const isCourseAdmin = () => ME?.role === 'admin';
const isAdmin = () => ME?.role === 'admin' && !ME.course_id; // head office: all courses, terms, courses
const canSettings = () => isCourseAdmin() || can('fleet') || can('members') || can('export');

/* ---------------- login ---------------- */
const ICON = {
  plus: '<path d="M12 5v14M5 12h14"/>',
  ret: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
  list: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4v2h6V4M9 11h6M9 15h6"/>',
  doc: '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>',
  users: '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.5"/><path d="M17 14c2.5 0 4.5 2 4.5 4.5"/>',
  cart: '<rect x="5" y="9" width="14" height="6" rx="2"/><path d="M7 9V4h10l1.5 5"/><circle cx="8" cy="18" r="2"/><circle cx="16" cy="18" r="2"/>',
  chart: '<path d="M5 20v-9M12 20V5M19 20v-7"/><path d="M3 20h18"/>',
  gear: '<circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>',
};
const ico = n => `<span class="ic"><svg viewBox="0 0 24 24" aria-hidden="true">${ICON[n]}</svg></span>`;
const greet = () => { const h = new Date().getHours(); return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening'; };

function login() {
  setTimer(null);
  const saved = COURSES.find(c => c.id == +store.get('bula_course')), shown = saved ? [saved] : COURSES; // a tablet remembers its club
  const logos = shown.map(c => `<div class="lg-logo">${+c.has_logo ? `<img src="${courseLogoUrl(c)}" alt="${esc(courseFull(c.id))}">` : `<span>${esc(courseFull(c.id))}</span>`}</div>`).join('') || `<div class="lg-logo"><span>${esc(COURSE)}</span></div>`;
  $('#app').innerHTML = `<div class="lg-wrap"><section class="lg-hero"><div class="lg-logos">${logos}</div><h1>Guest Check-in</h1><div class="lg-rule"></div><p>Golf carts · Club &amp; equipment hire</p></section>
   <section class="lg-panel"><form class="lg-form" id="lf"><h2>Welcome</h2><p class="sub">Sign in to start checking in guests.</p>
   <label for="u">Username</label><input id="u" autocapitalize="none" autocomplete="username" autofocus>
   <label for="p">Password</label><div class="pw"><input id="p" type="password" autocomplete="current-password"><button type="button" id="eye">Show</button></div>
   <button class="p lg-go" id="go" type="submit">Sign in</button><p class="lg-note">Authorised staff only · sign-ins are recorded</p></form></section></div>`;
  window.scrollTo(0, 0);
  const go = guard(async () => {
    const r = await api('auth/login', { method: 'POST', body: { username: $('#u').value, password: $('#p').value } });
    ME = r.user; COURSES = r.courses || []; pickCourse(); home();
  });
  $('#lf').onsubmit = e => { e.preventDefault(); go(); };
  $('#eye').onclick = () => { const p = $('#p'), show = p.type === 'password'; p.type = show ? 'text' : 'password'; $('#eye').textContent = show ? 'Hide' : 'Show'; };
}

/* ---------------- home ---------------- */
async function home() {
  keepAwake();
  const [act, ast] = await Promise.all([api('checkins/active', { quiet: true }), api('assets', { quiet: true })])
    .then(([a, s]) => { cacheSet('active', a.checkins); cacheSet('assets', s.assets); return [a, s]; })
    .catch(() => [{ checkins: cacheGet('active') || [] }, { assets: cacheGet('assets') || [] }]); // offline: show what was last known
  const carts = ast.assets.filter(a => a.kind === 'cart'), free = carts.filter(a => a.status === 'available').length;
  view(`<div class="welcome"><small>${new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' })}</small><h2>${greet()}, ${esc(String(ME.name).split(' ')[0])}</h2></div><div class="home">
   ${can('checkin') ? `<button class="big wide" id="new">${ico('plus')}<div>New check-in<small>${free} of ${carts.length} carts available</small></div></button>` : ''}
   ${can('returns') ? `<button class="big alt" id="ret">${ico('ret')}<div>Return cart / clubs<small>${act.checkins.length} on the course now</small></div></button>` : ''}
   ${can('today') || can('history') ? `<button class="big alt" id="log">${ico('list')}<div>Today's check-ins</div></button>` : ''}
   ${can('checkin') ? `<button class="big alt" id="mem">${ico('users')}<div>Member check<small>is this golfer a current member?</small></div></button>` : ''}
   ${can('fleet') ? `<button class="big alt" id="cst">${ico('cart')}<div>Cart status<small>mark a cart in or out of service</small></div></button>` : ''}
   ${can('reports') ? `<button class="big alt" id="rep">${ico('doc')}<div>Reports<small>cart usage, cash-up, members, trends</small></div></button>` : ''}${can('dashboard') || can('history') ? `<button class="big alt" id="dash">${ico('chart')}<div>${can('dashboard') ? 'Dashboard &amp; history' : 'History'}</div></button>` : ''}${canSettings() ? `<button class="big alt" id="set">${ico('gear')}<div>Settings<small>${isCourseAdmin() ? 'fleet, members, team, access, export' : 'the areas your admin has opened to you'}</small></div></button>` : ''}</div>
   ${can('range') ? '<div id="rangebox" style="margin-top:18px"></div>' : ''}
   <div id="homepool" style="margin-top:22px">${cartPool({ fleet: ast.assets, out: act.checkins })}</div>`);
  setTimer(async () => { const [a2, s2] = await Promise.all([api('checkins/active'), api('assets')]); const el = $('#homepool'); if (el) el.innerHTML = cartPool({ fleet: s2.assets, out: a2.checkins }); rangeRefresh(); }, 10000); // keep the carts-out panel and range-ball orders live
  rangeRefresh();
  if ($('#new')) $('#new').onclick = () => startCheckin(); if ($('#ret')) $('#ret').onclick = returns; if ($('#log')) $('#log').onclick = () => history(new Date().toLocaleDateString('en-CA'));
  if ($('#dash')) $('#dash').onclick = () => can('dashboard') ? dashboard() : history(new Date().toLocaleDateString('en-CA'));
  if ($('#set')) $('#set').onclick = () => settings();
  if ($('#rep')) $('#rep').onclick = () => reportsScreen();
  if ($('#mem')) $('#mem').onclick = memberCheck;
  if ($('#cst')) $('#cst').onclick = () => cartBoard();
}

/* ---------------- range balls: the golf attendant's alert ---------------- */
const RANGE_SEEN = new Set();
function beep() {
  try {
    const c = new (window.AudioContext || window.webkitAudioContext)();
    [880, 1175, 880].forEach((f, i) => { const o = c.createOscillator(), g = c.createGain(), t = c.currentTime + i * 0.28; o.frequency.value = f; o.connect(g); g.connect(c.destination); g.gain.setValueAtTime(0.25, t); g.gain.exponentialRampToValueAtTime(0.001, t + 0.24); o.start(t); o.stop(t + 0.26); });
  } catch { /* browsers only allow sound after a first tap on the page */ }
  try { navigator.vibrate && navigator.vibrate([250, 120, 250]); } catch { }
}
async function rangeRefresh() {
  const el = $('#rangebox'); if (!el) return;
  let r; try { r = await api('range/pending', { quiet: true }); } catch { return; }
  if (r.orders.some(o => !RANGE_SEEN.has(o.id))) beep();
  r.orders.forEach(o => RANGE_SEEN.add(o.id));
  el.innerHTML = r.orders.length
    ? `<div class="rangealert"><h3 style="margin:0 0 8px">🎯 Range balls to issue <span class="mut">(${r.orders.length})</span></h3>${r.orders.map(o => `<div class="rorder"><div><b>${esc(o.guest_name || 'Guest')}</b><div class="mut">${+o.qty} × ${esc(o.product_name)} · ${+o.free ? '<b>FREE – member</b>' : 'paid'} · ${hm(o.created_at)}</div></div><button class="p" data-iss="${o.id}">✔ Issued</button></div>`).join('')}</div>`
    : '<p class="mut">🎯 No range-ball orders waiting.</p>';
  el.querySelectorAll('[data-iss]').forEach(b => b.onclick = guard(async () => { b.disabled = true; await api('range/issue', { method: 'POST', body: { id: +b.dataset.iss } }); toast('Marked as issued'); rangeRefresh(); }));
}

/* ---------------- check-in wizard ---------------- */
let W;
function startCheckin() {
  W = { golfers: [], step: 1, cart: null, clubs: null, items: [], bags: '', holes: 18, fee: 'paid', range: {}, tee: '', notes: '', terms: null, assets: [], ref: newRef() }; // ref: makes a re-sent check-in count once
  api('terms/current', { quiet: true }).then(t => { W.terms = t; cacheSet('terms', t); }).catch(() => { W.terms = cacheGet('terms'); });
  golferForm();
}
const stepBar = n => `<div class="steps">${['Guest', 'Sign', 'Cart & clubs'].map((l, i) => `<span class="${i < n ? 'on' : ''}">${i + 1}. ${l}</span>`).join('')}</div>`;

const fmtD = s => s ? new Date(s + 'T00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';

function golferForm(g = {}) {
  const n = W.golfers.length + 1, main = W.golfers[0];
  view(`${stepBar(1)}<div class="card"><h2>${n === 1 ? 'Guest details' : 'Golfer ' + n}</h2>
   ${W.golfers.length ? `<div class="mut">Already added: ${W.golfers.map(x => esc(x.first_name)).join(', ')}</div>` : ''}
   <label for="gs">Returning guest? <span style="text-transform:none;letter-spacing:0;font-weight:400">type a name or mobile number</span></label><input id="gs" type="search" placeholder="e.g. Sarah, Mitchell or 700 1234" autocomplete="off"><div id="gres"></div>
   <label class="chk" style="margin-top:14px"><input id="ismem" type="checkbox"> I'm a member of ${esc(courseName())}</label>
   <div id="mfind" style="display:none"><label for="mfq">Find me in the member list <span style="text-transform:none;letter-spacing:0;font-weight:400">type your name or member number</span></label><input id="mfq" type="search" placeholder="e.g. Smith or M014" autocomplete="off"><div id="mfres"></div></div>
   <div class="row"><div><label>Mobile number *</label><input id="mobile" type="tel" inputmode="tel" value="${esc(g.mobile ?? main?.mobile ?? '')}"></div>
   <div><label>Member number <span class="mut">(members only)</span></label><input id="mno" value="${esc(g.member_no)}" autocapitalize="characters"></div></div>
   <div id="found"></div><div id="mstat"></div>
   <div class="row"><div><label>First name *</label><input id="fn" value="${esc(g.first_name)}"></div><div><label>Last name *</label><input id="ln" value="${esc(g.last_name)}"></div></div>
   <div class="row"><div><label>Email <span style="text-transform:none;letter-spacing:0;font-weight:400">(optional: we'll email you a copy of what you sign)</span></label><input id="em" type="email" inputmode="email" autocapitalize="none" value="${esc(g.email)}"></div>
   <div id="origwrap"><label>Where are they from?</label><select id="orig"><option value="overseas">Overseas visitor</option><option value="local">Fiji resident</option></select></div></div>
   <div id="ovr"><div class="row"><div><label>Country</label><input id="co" value="${esc(g.country ?? main?.country)}"></div><div><label>Hotel / where staying</label><input id="ho" value="${esc(g.hotel ?? main?.hotel ?? '')}"></div></div>
   <label>Room number</label><input id="ro" value="${esc(g.room ?? main?.room ?? '')}" style="max-width:320px"></div>
   <div id="dobwrap"><label>Date of birth (needed for under-18s)</label><input id="dob" type="date" value="${esc(g.dob)}" style="max-width:320px"></div>
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
  /** A verified, current member doesn't need where-they-are-from or date of birth, so those fields step aside (they return if the match fails). */
  const hideFields = () => { const s = mstate(); return $('#ismem').checked && s.found && s.nameOk && !s.expired; };
  const applyHide = () => {
    const hide = hideFields();
    $('#origwrap').style.display = hide ? 'none' : ''; $('#dobwrap').style.display = hide ? 'none' : '';
    if (hide) { $('#ovr').style.display = 'none'; $('#guard').innerHTML = ''; } else toggleOrig();
  };
  const renderMember0 = () => {
    const el = $('#mstat'), s = mstate();
    if (!s.found) { el.innerHTML = memberAsked && $('#mno').value.trim() ? '<div class="alert">Member number not found on this course\'s member list. They will be checked in as a <b>visitor</b> (green fee payable).</div>' : ''; return; }
    if (!s.nameOk) { el.innerHTML = `<div class="found" id="usem"><b>Member record found: ${esc(MEM.first_name)} ${esc(MEM.last_name)}</b>${MEM.member_no ? ' · #' + esc(MEM.member_no) : ''}<br><span class="mut">Tap to use these details. The name must match the member record to count as a member.</span></div>`; $('#usem').onclick = fillMember; return; }
    if (s.expired) { el.innerHTML = `<div class="alert bad">✖ <b>Membership expired on ${fmtD(MEM.expires_on)}.</b> Check them in as a <b>visitor</b> (green fee payable) and ask them to renew.</div>`; return; }
    const soon = s.days !== null && s.days <= 30;
    el.innerHTML = `<div class="alert ok">✔ <b>Verified member</b>${MEM.expires_on ? ' · valid until ' + fmtD(MEM.expires_on) : ' · no expiry on record'}${MEM.member_no ? ' · #' + esc(MEM.member_no) : ''}</div>
      ${soon ? `<div class="alert">⚠ <b>Membership expires ${s.days === 0 ? 'today' : 'in ' + s.days + ' day' + (s.days === 1 ? '' : 's')} (${fmtD(MEM.expires_on)}).</b> Please remind them to renew.</div>` : ''}
      <label class="chk"><input type="checkbox" id="mcard"> I have seen their member card or photo ID</label>`;
  };
  const renderMember = () => {
    renderMember0();
    const s = mstate();
    if (s.found && s.nameOk && !s.expired && !$('#ismem').checked) { $('#ismem').checked = true; $('#mfind').style.display = ''; } // found on the list: tick it for them
    if ($('#ismem').checked && memberAsked && !MEM && !$('#mstat').innerHTML) $('#mstat').innerHTML = '<div class="alert">We could not find them on this course\'s member list. Try the name search above, or check them in as a <b>visitor</b>.</div>';
    applyHide();
  };
  const fillMember = () => { $('#fn').value = MEM.first_name; $('#ln').value = MEM.last_name; if (!$('#em').value) $('#em').value = MEM.email || ''; if (!$('#mobile').value) $('#mobile').value = MEM.mobile || ''; if (MEM.member_no) $('#mno').value = MEM.member_no; renderMember(); };
  const fill = x => { if (x.mobile) $('#mobile').value = x.mobile; $('#fn').value = x.first_name; $('#ln').value = x.last_name; $('#em').value = x.email || ''; $('#co').value = x.country || ''; $('#orig').value = isFiji(x.country) ? 'local' : 'overseas'; toggleOrig(); $('#ho').value = x.hotel || $('#ho').value; $('#ro').value = x.room || $('#ro').value; $('#dob').value = x.dob || ''; $('#mk').checked = !!+x.marketing_optin; $('#found').innerHTML = ''; renderMember(); };
  const lookup = guard(async () => {
    const m = $('#mobile').value, no = $('#mno').value.trim();
    if (m.replace(/\D/g, '').length < 6 && !no) return;
    const r = await api('guests/lookup', { params: { mobile: m, member_no: no }, quiet: true });
    list = r.guests; MEM = r.member; memberAsked = true;
    $('#found').innerHTML = list.map((x, i) => `<div class="found" data-i="${i}"><b>Welcome back, ${esc(x.first_name)} ${esc(x.last_name)}</b> · visit #${+x.visits + 1}${x.last_clubs ? ' · last hired ' + esc(x.last_clubs) : ''}<br><span class="mut">Tap to fill in their details</span>
       ${x.alert_note ? `<div class="alert">⚠ ${esc(x.alert_note)}</div>` : ''}</div>`).join('');
    document.querySelectorAll('.found[data-i]').forEach(el => el.onclick = () => fill(list[+el.dataset.i]));
    if (list.length === 1 && !$('#fn').value) fill(list[0]);
    renderMember();
  });
  $('#mobile').onblur = lookup; $('#mno').onblur = lookup;
  // returning guests: a few letters of the name, or the mobile number, brings their saved details up
  let gl = [], gt;
  const gsearch = guard(async () => {
    const q = $('#gs').value.trim(); if (q.length < 2) { $('#gres').innerHTML = ''; return; }
    gl = (await api('guests/search', { params: { q }, quiet: true })).guests;
    $('#gres').innerHTML = gl.length ? gl.map((x, i) => `<div class="found" data-g="${i}"><b>${esc(x.first_name)} ${esc(x.last_name)}</b> · ${esc(x.mobile)}${x.hotel ? ' · ' + esc(x.hotel) : x.country ? ' · ' + esc(x.country) : ''}<br><span class="mut">${+x.visits} visit${+x.visits === 1 ? '' : 's'} · tap to fill in their details</span>${x.alert_note ? `<div class="alert">⚠ ${esc(x.alert_note)}</div>` : ''}</div>`).join('') : '<p class="mut">No matching guest yet. Fill in the details below.</p>';
  });
  $('#gs').oninput = () => { clearTimeout(gt); gt = setTimeout(gsearch, 250); };
  $('#gres').onclick = e => { const el = e.target.closest('[data-g]'); if (!el) return; fill(gl[+el.dataset.g]); $('#gres').innerHTML = ''; $('#gs').value = ''; lookup(); };
  $('#fn').oninput = $('#ln').oninput = renderMember;
  // "I'm a member": find them by name (they may not know their number); the details and number fill in from the list
  let ml = [], mt;
  const mfind = guard(async () => {
    const q = $('#mfq').value.trim(); if (q.length < 2) { $('#mfres').innerHTML = ''; return; }
    ml = (await api('members/check', { params: { q }, quiet: true })).members;
    $('#mfres').innerHTML = ml.length ? ml.map((x, i) => `<div class="found" data-m="${i}"><b>${esc(x.first_name)} ${esc(x.last_name)}</b>${x.member_no ? ' · #' + esc(x.member_no) : ''}<br><span class="mut">${+x.expired ? '✖ Expired ' + fmtD(x.expires_on) : x.expires_on ? 'Current until ' + fmtD(x.expires_on) : 'Current'} · tap to use</span></div>`).join('') : '<p class="mut">Not on the member list. Check the spelling, or check them in as a visitor.</p>';
  });
  $('#mfq').oninput = () => { clearTimeout(mt); mt = setTimeout(mfind, 250); };
  $('#mfres').onclick = e => { const el = e.target.closest('[data-m]'); if (!el) return; const x = ml[+el.dataset.m]; $('#fn').value = x.first_name; $('#ln').value = x.last_name; if (x.member_no) $('#mno').value = x.member_no; $('#mfres').innerHTML = ''; $('#mfq').value = ''; lookup(); };
  $('#ismem').onchange = () => { $('#mfind').style.display = $('#ismem').checked ? '' : 'none'; if ($('#ismem').checked) { lookup(); setTimeout(() => $('#mfq')?.focus(), 50); } renderMember(); };
  if (g.mobile || g.member_no || main) lookup();
  const read = () => {
    const s = mstate();
    return {
      mobile: $('#mobile').value.trim(), member_no: $('#mno').value.trim(), first_name: $('#fn').value.trim(), last_name: $('#ln').value.trim(), email: $('#em').value.trim(), country: hideFields() ? '' : $('#orig').value === 'local' ? 'Fiji' : $('#co').value.trim(),
      hotel: hideFields() || $('#orig').value === 'local' ? '' : $('#ho').value.trim(), room: hideFields() || $('#orig').value === 'local' ? '' : $('#ro').value.trim(), dob: hideFields() ? '' : $('#dob').value, marketing_optin: $('#mk').checked, guardian_name: $('#gd')?.value.trim() || '',
      free_claimed: !!(s.found && +MEM.free_claimed), card_checked: !!$('#mcard')?.checked, member_ok: !!(s.found && s.nameOk && !s.expired && $('#mcard')?.checked),
    };
  };
  const under18 = () => { if (hideFields()) return false; const d = $('#dob').value; return d && (Date.now() - new Date(d)) / 31557600000 < 18; };
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
  if (!W.terms) { try { W.terms = await api('terms/current', { quiet: true }); cacheSet('terms', W.terms); } catch (e) { W.terms = cacheGet('terms'); if (!W.terms) throw new Error('No connection, and the terms have not been saved on this tablet yet. Connect to Wi-Fi once to load them.'); } }
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
  let assets; W.offlineAssets = false;
  try { ({ assets } = await api('assets', { quiet: true })); cacheSet('assets', assets); }
  catch (e) { if (!isOffline(e)) throw e; assets = cacheGet('assets'); if (!assets) throw new Error('No connection, and the carts have not been saved on this tablet yet. Connect to Wi-Fi once.'); W.offlineAssets = true; }
  W.assets = assets;
  if (!W.rates) { try { const r = await api('rates/get', { quiet: true }); W.rates = r.rates || {}; W.types = r.types || []; W.rprods = r.range || []; cacheSet('rates', W.rates); cacheSet('types', W.types); cacheSet('rprods', W.rprods); } catch { W.rates = cacheGet('rates') || {}; W.types = cacheGet('types') || []; W.rprods = cacheGet('rprods') || []; } }
  const carts = assets.filter(a => a.kind === 'cart'), clubs = assets.filter(a => a.kind === 'clubs');
  const sug = carts.find(a => a.status === 'available'), sugC = clubs.find(a => a.status === 'available');
  if (W.cart === null && sug) W.cart = null; // staff must choose; suggestion is highlighted
  const cfee = a => a.kind === 'clubs' && +W.holes === 9 && a.fee9 != null && a.fee9 !== '' ? +a.fee9 : +a.fee; // a club set can have its own 9-hole price
  const tiles = (list, sel, key) => list.map(a => {
    const off = a.status !== 'available';
    return `<div class="tile ${off ? 'off' : ''} ${sel === a.id ? 'sel' : ''} ${(key === 'cart' ? sug : sugC)?.id === a.id && sel !== a.id ? 'sug' : ''}" data-k="${key}" data-id="${a.id}" ${off ? 'data-off="1"' : ''}>${esc(a.name)}<small>${esc(a.description || '')} ${key === 'cart' ? 'included' : money(cfee(a))}${off ? '<br>' + esc(a.status.replace('_', ' ')) : ''}${a.gps_battery != null && !off ? ' · 🔋' + a.gps_battery + '%' : ''}</small></div>`;
  }).join('') || (key === 'clubs' ? '<span class="mut">No hire sets set up yet. An admin adds them under Settings &rsaquo; Carts &amp; clubs.</span>' : '<span class="mut">None set up</span>');
  const hasHotel = feat().room && W.golfers.some(g => g.hotel); // room charging: only where head office enabled it, and only with a hotel
  if (!hasHotel && W.fee === 'room') W.fee = 'paid';
  // what is owed: green fees from the course's rate card (the server works out the same amounts), plus any hire
  const hold = +W.holes === 9 ? 9 : 18;
  const CORE = [['member', 'Member'], ['resort', 'Resort / hotel guest'], ['resident', 'Fiji resident'], ['visitor', 'Visitor']];
  const TY = W.types && W.types.length ? W.types : CORE.map(([code, label]) => ({ code, label, proof: 0 }));
  const CATN = Object.fromEntries(TY.map(t => [t.code, t.label]));
  const EXTRA = TY.filter(t => !CORE.some(([c]) => c === t.code)); // senior, PGA member, junior... the course's own types
  const autoCat = g => g.member_ok ? 'member' : g.hotel ? 'resort' : /^fiji/i.test(g.country || '') ? 'resident' : 'visitor';
  const catOf = g => g.member_ok ? 'member' : g.rate_cat && g.rate_cat !== 'member' && CATN[g.rate_cat] ? g.rate_cat : autoCat(g);
  const greenLines = W.golfers.map(g => { const c = catOf(g); return { g, c, amt: +(W.rates?.[hold]?.[c] || 0) }; });
  const greenSum = greenLines.reduce((s, l) => s + l.amt, 0);
  const hasRates = [9, 18].some(h => Object.values(W.rates?.[h] || {}).some(v => +v > 0));
  const feeA = id => { const a = W.assets.find(x => x.id === id); return a ? cfee(a) || 0 : 0; };
  const hireNow = feeA(W.clubs) + W.items.reduce((s, id) => s + feeA(id), 0);
  const rprods = W.rprods || [], rq = id => +W.range[id] || 0, rangeSum = rprods.reduce((t, p) => t + rq(p.id) * +p.price, 0);
  const freeP = rprods.find(p => +p.member_free);
  const allMem = W.golfers.length > 0 && W.golfers.every(g => g.member_ok);
  const memberFree = allMem && greenSum + hireNow + rangeSum < 0.005; // nothing to pay
  if (W.fee !== 'room') W.fee = 'paid';
  const ft = feat(), shoes = ft.shoes ? assets.filter(a => a.kind === 'shoes') : [], tennis = ft.tennis ? assets.filter(a => a.kind === 'racquet') : [];
  const multi = list => list.map(a => {
    const off = a.status !== 'available';
    return `<div class="tile ${off ? 'off' : ''} ${W.items.includes(a.id) ? 'sel' : ''}" data-k="item" data-id="${a.id}" ${off ? 'data-off="1"' : ''}>${esc(a.name)}<small>${esc(a.description || '')} ${money(a.fee)}${off ? '<br>' + esc(a.status.replace('_', ' ')) : ''}</small></div>`;
  }).join('') || '<span class="mut">None set up</span>';
  view(`${stepBar(3)}<div class="card"><h2>${ft.shoes || ft.tennis ? 'Cart &amp; hire' : 'Cart &amp; club hire'}</h2>
    ${W.offlineAssets ? '<div class="alert">📶 No connection. The carts below are as they were when this tablet last had a signal, so check a cart is really free. The check-in will be saved here and sent automatically when the connection returns.</div>' : ''}
    <h3>Golf cart ${sug ? `<span class="mut">— suggested: ${esc(sug.name)}</span>` : '<span class="mut">— none free</span>'}</h3>
    <div class="grid"><div class="tile ${W.cart === 0 ? 'sel' : ''}" data-k="cart" data-id="0">Walking<small>no cart</small></div>${tiles(carts, W.cart, 'cart')}</div>
    <h3>Club hire ${sugC ? `<span class="mut">— suggested: ${esc(sugC.name)}</span>` : clubs.length ? '<span class="mut">— none free</span>' : ''}</h3>
    <div class="grid"><div class="tile ${W.clubs === 0 ? 'sel' : ''}" data-k="clubs" data-id="0">Own clubs<small>no hire</small></div>${tiles(clubs, W.clubs, 'clubs')}</div>
    ${ft.shoes ? `<h3>Shoe hire <span class="mut">— tap to add, tap again to remove</span></h3><div class="grid">${multi(shoes)}</div>` : ''}
    ${ft.tennis ? `<h3>Tennis hire <span class="mut">— racquets and tennis shoes</span></h3><div class="grid">${multi(tennis)}</div>` : ''}
    ${rprods.length ? `<h3>Range balls <span class="mut">— paid now; the golf attendant is alerted to issue them</span></h3><div class="grid">${rprods.map(p => `<div class="tile ${rq(p.id) ? 'sel' : ''}" data-k="range" data-id="${p.id}">${esc(p.name)}<small>${money(p.price)}${rq(p.id) ? ` · <b>× ${rq(p.id)}</b> <button type="button" class="rm" data-rm="${p.id}" style="padding:2px 10px;min-height:0">−</button>` : ' · tap to add'}</small></div>`).join('')}</div>` : ''}
    ${freeP ? W.golfers.filter(g => g.member_ok).map(g => `<div class="alert ${g.free_claimed ? '' : 'ok'}">🎁 <b>${esc(g.first_name)} ${esc(g.last_name)}</b>: ${g.free_claimed ? 'free bucket already claimed today. Any more are charged at the normal price.' : `free ${esc(freeP.name)} today (one per member per day). <label class="chk" style="display:inline-flex"><input type="checkbox" class="gfree" data-i="${W.golfers.indexOf(g)}" ${g.skip_free ? '' : 'checked'}> give it</label>`}</div>`).join('') : ''}
    ${EXTRA.length ? `<h3>Guest type <span class="mut">— automatic from their details; change it for seniors, PGA members and so on</span></h3>${W.golfers.map((g, i) => g.member_ok ? '' : `<div class="row" style="align-items:end"><div><label>${esc(g.first_name)} ${esc(g.last_name)}</label><select class="gty" data-i="${i}"><option value="">Automatic: ${esc(CATN[autoCat(g)])}</option>${EXTRA.map(t => `<option value="${t.code}" ${g.rate_cat === t.code ? 'selected' : ''}>${esc(t.label)}</option>`).join('')}</select></div>${EXTRA.find(t => t.code === g.rate_cat && +t.proof) ? `<div><label class="chk"><input type="checkbox" class="gpf" data-i="${i}" ${g.proof_checked ? 'checked' : ''}> I have seen their ${esc(CATN[g.rate_cat])} card or ID</label></div>` : '<div></div>'}</div>`).join('')}` : ''}
    <label>Bag number(s)</label><input id="bags" placeholder="e.g. 12, 14" value="${esc(W.bags)}">
    <div class="row"><div><label>Holes</label><select id="holes"><option>18</option><option ${W.holes === 9 ? 'selected' : ''}>9</option></select></div>
    <div><label>Payment status</label><select id="fee"><option value="paid" ${W.fee === 'paid' ? 'selected' : ''}>${memberFree ? 'Nothing to pay (verified member)' : 'Paid'}</option>${hasHotel ? `<option value="room" ${W.fee === 'room' ? 'selected' : ''}>Charge to room</option>` : ''}</select></div></div>
    <p class="mut" style="margin-top:-4px">Everything is paid up front, at the counter. This only records that it has been paid; no money is taken in this app.</p>
    <div class="row"><div><label>Tee time</label><input id="tee" type="time" value="${esc(W.tee)}"></div><div><label>Notes</label><input id="notes" value="${esc(W.notes)}"></div></div>
    <div id="tot" class="pricebox"></div>
    </div><div class="bar"><button class="p" id="done">✔ Complete check-in</button></div>`, 'Cart & clubs', () => signStep(W.golfers.length - 1));
  const fee = feeA;
  const total = () => {
    const hire = +fee(W.clubs) + W.items.reduce((s, id) => s + +fee(id), 0);
    $('#tot').innerHTML = (hasRates ? greenLines.map(l => `<div class="pl"><span>${esc(l.g.first_name)} ${esc(l.g.last_name)}<small>${CATN[l.c]} · ${hold} holes</small></span><b>${money(l.amt)}</b></div>`).join('') + `<div class="pl sub"><span>Green fees</span><b>${money(greenSum)}</b></div>` : '')
      + `<div class="pl"><span>Hire <small>clubs, shoes, tennis</small></span><b>${money(hire)}</b></div>` + (rangeSum > 0 ? `<div class="pl"><span>Range balls</span><b>${money(rangeSum)}</b></div>` : '') + `<div class="pl tot"><span>Total to pay</span><b>${money(greenSum + hire + rangeSum)}</b></div>`;
  };
  total();
  document.querySelectorAll('select.gty').forEach(sel => sel.onchange = () => { const g = W.golfers[+sel.dataset.i]; g.rate_cat = sel.value || undefined; g.proof_checked = false; keep(); assetStepRender(); });
  document.querySelectorAll('input.gfree').forEach(cb => cb.onchange = () => { W.golfers[+cb.dataset.i].skip_free = !cb.checked; });
  document.querySelectorAll('input.gpf').forEach(cb => cb.onchange = () => { W.golfers[+cb.dataset.i].proof_checked = cb.checked; });
  document.querySelectorAll('.tile').forEach(t => t.onclick = e => {
    if (t.dataset.k === 'range') { const id = +t.dataset.id; W.range[id] = e.target.closest('[data-rm]') ? Math.max(0, rq(id) - 1) : Math.min(20, rq(id) + 1); keep(); return assetStepRender(); }
    if (t.dataset.off) return toast('That one is not available', true);
    const id = +t.dataset.id;
    if (t.dataset.k === 'item') W.items = W.items.includes(id) ? W.items.filter(x => x !== id) : [...W.items, id];
    else W[t.dataset.k] = id;
    keep(); assetStepRender();
  });
  const keep = () => { W.holes = +$('#holes').value; W.fee = $('#fee').value; W.tee = $('#tee').value; W.notes = $('#notes').value; W.bags = $('#bags').value; };
  const assetStepRender = () => { const s = scrollY; assetStep().then(() => scrollTo(0, s)); };
  $('#holes').onchange = () => { keep(); assetStepRender(); }; // 9 or 18 changes the green fee
  $('#done').onclick = guard(async () => {
    keep();
    $('#done').disabled = true;
    try {
      const payload = { golfers: W.golfers, holes: W.holes, pay_method: W.fee === 'room' ? 'room' : 'paid', range: rprods.filter(p => rq(p.id)).map(p => ({ id: p.id, qty: rq(p.id) })), tee_time: W.tee, notes: W.notes, bags: W.bags, cart_id: W.cart || null, clubs_id: W.clubs || null, items: W.items, client_ref: W.ref };
      let r;
      try { r = await api('checkins/create', { method: 'POST', body: payload }); }
      catch (e) {
        if (!isOffline(e)) throw e;
        // no connection: keep the whole check-in (signatures included) on this tablet; it is sent, once, when the connection returns
        queueAdd('checkins/create', payload, `${W.golfers[0].first_name} ${W.golfers[0].last_name} · check-in`);
        const used = [W.cart, W.clubs, ...W.items].filter(Boolean); // so the same cart isn't offered again while offline
        cacheSet('assets', (cacheGet('assets') || []).map(a => used.includes(a.id) ? { ...a, status: 'out' } : a));
        r = { hire_fee: hireNow, green_total: greenSum, range_total: rangeSum, emailing: 0, offline: true };
      }
      const cn = W.assets.find(a => a.id === W.cart)?.name, kn = W.assets.find(a => a.id === W.clubs)?.name;
      const extra = W.items.map(id => W.assets.find(a => a.id === id)?.name).filter(Boolean).join(', ');
      view(`<div class="card done"><div class="tick">✓</div><h2>Checked in</h2><p style="font-size:24px;margin:0"><b>${esc(W.golfers[0].first_name)} ${esc(W.golfers[0].last_name)}</b></p>
        <div class="cartno">${cn ? '🛺 ' + esc(cn) : 'Walking'}</div><p style="font-size:20px;margin:0">${kn ? '🏌 ' + esc(kn) : 'Own clubs'}</p>${extra ? `<p style="font-size:18px;margin:6px 0 0">${esc(extra)}</p>` : ''}${W.bags ? `<p class="mut" style="margin:6px 0 0">Bag ${esc(W.bags)}</p>` : ''}<p class="mut">${W.fee === 'member' ? 'Verified member: nothing to pay. ' : ''}${+r.green_total > 0 ? `Green fees ${money(r.green_total)} · ` : ''}Hire ${money(r.hire_fee)}${+r.range_total > 0 ? ' · Range balls ' + money(r.range_total) : ''}</p>${+r.green_total + +r.hire_fee + +(r.range_total || 0) > 0 ? `<p style="font-size:22px;margin:4px 0"><b>Total ${W.fee === 'room' ? 'charged to room' : 'paid'} ${money(+r.green_total + +r.hire_fee + +(r.range_total || 0))}</b></p>` : ''}${+r.range_total > 0 || (r.free_buckets || []).length ? '<p style="font-size:18px;margin:6px 0 0">🎯 Range balls: the golf attendant has been alerted.</p>' : ''}${(r.free_buckets || []).length ? `<p style="font-size:18px;margin:6px 0 0">🎁 Free member bucket: ${r.free_buckets.map(esc).join(', ')}</p>` : ''}${r.emailing ? '<p class="mut">📧 A copy of the signed agreement is being emailed.</p>' : ''}${r.offline ? '<div class="alert" style="text-align:left;margin-top:12px">📶 <b>Saved on this tablet.</b> There is no connection right now, so this check-in will be sent automatically when it returns. The amounts shown are from the rate card on this tablet. Please keep the tablet switched on and in the app.</div>' : ''}</div><div class="bar"><button class="p" id="h">Done</button></div>`);
      $('#h').onclick = home;
    } catch (e) { $('#done').disabled = false; throw e; }
  });
}

/* ---------------- returns ---------------- */
async function returns() {
  const load = async () => {
    let checkins, off = false;
    try { ({ checkins } = await api('checkins/active', { quiet: true })); cacheSet('active', checkins); }
    catch (e) { if (!isOffline(e)) throw e; checkins = cacheGet('active') || []; off = true; } // no signal: the list as it was last seen
    $('#list').innerHTML = (off ? '<div class="alert">📶 No connection. This is the list from when the tablet last had a signal. Returns you record now are saved here and sent when it reconnects.</div>' : '') + (checkins.length ?`<table><tr><th>Guest</th><th>Cart</th><th>Clubs</th><th>Out</th></tr>${checkins.map(c => `<tr class="click" data-id="${c.id}"><td><b>${esc(c.guest)}</b>${c.golfers > 1 ? ` +${c.golfers - 1}` : ''}<div class="mut">${esc(c.hotel || '')} ${esc(c.room || '')}</div></td><td>${esc(c.cart_name) || '—'}</td><td>${hireCell(c)}</td><td>${hm(c.checked_in_at)}</td></tr>`).join('')}</table>` : '<p class="mut">Nothing is out right now.</p>');
    document.querySelectorAll('#list tr.click').forEach(tr => tr.onclick = () => returnDialog(checkins.find(c => c.id == tr.dataset.id)));
  };
  view('<div class="card"><h2>Tap an item to return it</h2><div id="list"></div></div>', 'Returns', home);
  await load(); setTimer(load);
}
/** Send a return now, or (no connection) keep it on the tablet to send later. Returns true when it was kept for later. */
async function sendReturn(c, body) {
  try { await api('checkins/return', { method: 'POST', body }); return false; }
  catch (e) {
    if (!isOffline(e)) throw e;
    queueAdd('checkins/return', body, `Return: ${c.guest}`);
    cacheSet('active', (cacheGet('active') || []).filter(x => x.id !== c.id)); // it no longer shows as out
    return true;
  }
}

/** A small photo taker: thumbnails plus an "Add photo" button (the camera on a tablet). get() returns the shots as [{kind, data}]. */
function photoPicker(host, kind, max = 3) {
  const shots = [];
  host.innerHTML = '<div class="shots"></div><label class="upl">📷 Add photo<input type="file" accept="image/*" capture="environment" hidden></label>';
  const row = host.querySelector('.shots'), inp = host.querySelector('input'), lab = host.querySelector('label');
  const draw = () => { row.innerHTML = shots.map((s, i) => `<span class="shot"><img src="${s}" alt="Photo ${i + 1}"><button type="button" data-i="${i}" aria-label="Remove photo">×</button></span>`).join(''); lab.style.display = shots.length >= max ? 'none' : ''; };
  inp.onchange = guard(async () => { const f = inp.files[0]; inp.value = ''; if (!f || shots.length >= max) return; shots.push(await shrink(f)); draw(); });
  row.onclick = e => { const b = e.target.closest('button[data-i]'); if (b) { shots.splice(+b.dataset.i, 1); draw(); } };
  return { get: () => shots.map(data => ({ kind, data })) };
}

function returnDialog(c) {
  const m = document.createElement('div'); m.className = 'modal';
  m.innerHTML = `<div><h2>Return: ${esc(c.guest)}</h2><p>${esc(c.cart_name || 'No cart')} · ${esc(c.clubs_name || 'No clubs')}</p>${c.items ? `<p>${esc(c.items)}</p>` : ''}${c.bags ? `<p class="mut">Bag ${esc(c.bags)}</p>` : ''}
    ${c.clubs_name ? `<div class="clubcheck"><h3>Golf set check · ${esc(c.clubs_name)}</h3>
      <label class="chk"><input type="radio" name="cc" value="ok"> All clubs are here, bag in good order</label>
      <label class="chk"><input type="radio" name="cc" value="missing"> Something is missing or not right</label>
      <div id="ccm" style="display:none"><input id="ccmt" maxlength="255" placeholder="What's missing? e.g. 7 iron, driver headcover, towel"><div id="ccph" style="margin:6px 0"></div><label class="chk"><input type="checkbox" id="ccos"> Take this set out of service until it's sorted</label></div></div>` : ''}
    <label for="rn">Notes <span style="text-transform:none;letter-spacing:0;font-weight:400">(optional)</span></label><textarea id="rn" rows="2" maxlength="500" placeholder="e.g. left early, low battery, lost a towel…"></textarea>
    <button class="p" id="ok">✔ Returned – undamaged</button><button class="d" id="dm">⚠ Damaged</button><button class="d" id="wr">🚫 Raise patron warning</button><button id="cx">Cancel</button></div>`;
  document.body.append(m);
  const clubShots = c.clubs_name ? photoPicker($('#ccph', m), 'clubs', 3) : null; // photos of what is missing or damaged in the set
  document.querySelectorAll('input[name=cc]', m).forEach(r => r.onchange = () => { $('#ccm', m).style.display = document.querySelector('input[name=cc]:checked', m).value === 'missing' ? '' : 'none'; if ($('#ccm', m).style.display === '') $('#ccmt', m).focus(); });
  /** The golf set must be checked before anything is returned: all there, or exactly what is missing. */
  const clubsPart = () => {
    if (!c.clubs_name) return {};
    const pick = document.querySelector('input[name=cc]:checked', m);
    if (!pick) throw new Error('Check the golf set first: all clubs here, or what is missing');
    if (pick.value === 'ok') return { clubs_check: 'ok' };
    const what = $('#ccmt', m).value.trim(); if (!what) throw new Error('Say what is missing from the golf set');
    return { clubs_check: 'missing', clubs_missing: what, clubs_out_of_service: $('#ccos', m).checked, photos: clubShots.get() };
  };
  $('#cx', m).onclick = () => m.remove();
  $('#ok', m).onclick = guard(async () => { const cp = clubsPart(); const later = await sendReturn(c, { id: c.id, condition: 'ok', note: $('#rn', m).value, ...cp }); m.remove(); toast(later ? 'Saved on this tablet: will send when the connection returns' : cp.clubs_check === 'missing' ? 'Returned. Missing items recorded.' : 'Returned'); returns(); });
  const issue = (cond, title) => {
    let cp; try { cp = clubsPart(); } catch (e) { return toast(e.message, true); }
    const typed = $('#rn', m)?.value || ''; // anything already typed carries over
    m.firstElementChild.innerHTML = `<h2>${title}</h2><label>What happened? *</label><textarea id="nt" rows="3">${esc(typed)}</textarea>
      ${cond === 'damaged' ? '<label>Photos of the damage</label><div id="dph"></div>' : ''}
      <button class="d" id="sv">Save</button><button id="cx2">Cancel</button>`;
    const dmgShots = cond === 'damaged' ? photoPicker($('#dph', m), 'damage', 4) : null;
    $('#cx2', m).onclick = () => m.remove();
    $('#sv', m).onclick = guard(async () => {
      const later = await sendReturn(c, { id: c.id, condition: cond, note: $('#nt', m).value, ...cp, photos: [...(cp.photos || []), ...(dmgShots ? dmgShots.get() : [])] });
      m.remove(); toast(later ? 'Saved on this tablet: will send when the connection returns' : 'Saved. ' + (cond === 'damaged' ? 'Marked for maintenance.' : 'Warning added to guest.')); returns();
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
  const tot = checkins.reduce((s, c) => s + +c.hire_fee + +c.green_total, 0);
  view(`<div class="card"><h2>Check-ins</h2>${can('history') ? `<input type="date" id="d" value="${date}" style="max-width:220px">` : '<p class="mut">Today</p>'}
   ${checkins.length ? `<table><tr><th>Time</th><th>Guest</th><th>Cart</th><th>Clubs</th><th>Total</th><th></th></tr>${checkins.map(c => `<tr class="${can('history') ? 'click' : ''}" data-id="${c.id}"><td>${hm(c.checked_in_at)}</td><td><b>${esc(c.guest)}</b>${c.golfers > 1 ? ' +' + (c.golfers - 1) : ''}${c.fee_status === 'member' ? ' <span class="tag">Member</span>' : ''}<div class="mut">${esc(c.mobile)} ${esc(c.hotel || '')}</div></td><td>${esc(c.cart_name) || '—'}</td><td>${hireCell(c)}</td><td>${money(+c.hire_fee + +c.green_total)}</td>
   <td><span class="tag ${c.returned_at ? (c.return_condition === 'ok' ? '' : 'r') : 'o'}">${c.returned_at ? (c.return_condition === 'ok' ? 'Back ' + hm(c.returned_at) : c.return_condition) : 'Out'}</span>${c.clubs_missing ? `<div style="max-width:220px;color:var(--warn);font-size:13px;font-weight:600">⚠ Set missing: ${esc(c.clubs_missing)}</div>` : ''}${c.return_note ? `<div class="mut" style="max-width:220px">📝 ${esc(c.return_note)}</div>` : ''}</td></tr>`).join('')}</table><h3>Total for the day: ${money(tot)}</h3>` : '<p class="mut">No check-ins.</p>'}</div>`, 'Check-ins', home);
  if (can('history')) { $('#d').onchange = e => history(e.target.value); document.querySelectorAll('tr.click').forEach(tr => tr.onclick = () => detail(tr.dataset.id, () => history(date))); }
}
const detail = guard(async (id, back) => {
  const c = await api('checkins/get', { params: { id } });
  view(`<div class="card"><h2>Check-in #${c.id}</h2><p>${fmtDT(c.checked_in_at)} · ${c.holes} holes · ${esc(c.cart_name || 'walking')} · ${esc(c.clubs_name || 'own clubs')}${c.items.length ? ' · ' + c.items.map(i => esc(i.name)).join(', ') : ''}${c.bags ? ' · bag ' + esc(c.bags) : ''} · ${money(c.hire_fee)} (${esc(c.fee_status)})<br><span class="mut">Checked in by ${esc(c.staff_name)} · terms v${c.terms_version}</span></p>
   ${c.returned_at ? `<div class="card" style="background:var(--bg)"><h3 style="margin-top:0">Return</h3>
     <p>Returned ${fmtDT(c.returned_at)}${c.returned_by ? ' · checked by ' + esc(c.returned_by) : ''} · <span class="tag ${c.return_condition === 'ok' ? '' : 'r'}">${c.return_condition === 'ok' ? 'No damage reported' : esc(c.return_condition)}</span></p>
     ${c.clubs_missing ? `<div class="alert bad">⚠ <b>Golf set${c.clubs_name ? ' ' + esc(c.clubs_name) : ''} returned with items missing:</b> ${esc(c.clubs_missing)}</div>` : (+c.clubs_checked ? '<p class="mut">✔ Golf set checked: complete</p>' : '')}
     ${c.return_note ? `<p>📝 ${esc(c.return_note)}</p>` : ''}
     ${(() => { const shots = [...(c.return_photo ? [{ kind: 'damage', photo: c.return_photo }] : []), ...(c.photos || [])]; return shots.length ? `<div class="gallery">${shots.map(p => `<figure><img src="${p.photo}" alt="Photo" data-zoom="1"><figcaption>${p.kind === 'clubs' ? 'Golf set' : 'Damage'}</figcaption></figure>`).join('')}</div><p class="mut">Tap a photo to enlarge.</p>` : ''; })()}</div>` : '<p><span class="tag o">Still out</span></p>'}
   <h3>Signed golfers</h3>${c.golfers.map(g => `<div class="card"><b>${esc(g.first_name)} ${esc(g.last_name)}</b> ${g.is_main == 1 ? '(main hirer)' : ''}${g.guest_type === 'member' ? ` <span class="tag">Member${g.member_no ? ' #' + esc(g.member_no) : ''}</span>` : ''}<div class="mut">${esc(g.mobile)} ${esc(g.email || '')} · signed ${fmtDT(g.signed_at)}${g.guardian_name ? ' · guardian ' + esc(g.guardian_name) : ''}</div><img class="sigimg" src="${g.signature}">
     ${(c.emails || []).filter(m => m.golfer_id == g.golfer_id).map(m => `<div class="mut">📧 ${m.status === 'sent' ? 'Emailed to' : 'Email to'} ${esc(m.to_email)}${m.status === 'sent' ? '' : ' <b style="color:var(--warn)">failed</b>'} · ${fmtDT(m.sent_at)}</div>`).join('')}
     ${can('history') ? `<p class="noprint" style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 0"><a class="linkbtn" href="${API}?r=checkins/pdf&id=${g.golfer_id}&course=${CID}" target="_blank" rel="noopener">⬇ PDF copy</a><button type="button" class="mailcopy" data-g="${g.golfer_id}" data-e="${esc(g.email || '')}">📧 Email a copy</button></p>` : ''}</div>`).join('')}
   <h3>Terms accepted</h3><div class="terms">${esc(c.terms)}</div><p><button onclick="print()">Print</button></p></div>`, 'Check-in', back);
  document.querySelectorAll('img[data-zoom]').forEach(im => im.onclick = () => { // tap a photo to see it full size
    const lb = document.createElement('div'); lb.className = 'lightbox'; lb.innerHTML = `<img src="${im.src}" alt="Photo">`; lb.onclick = () => lb.remove(); document.body.append(lb);
  });
  document.querySelectorAll('.mailcopy').forEach(b => b.onclick = guard(async () => {
    const to = prompt('Email a PDF copy of the signed agreement to:', b.dataset.e || ''); if (!to) return;
    await api('checkins/email', { method: 'POST', body: { id: +b.dataset.g, to: to.trim() } }); toast('Copy emailed to ' + to.trim()); detail(id, back);
  }));
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
  return `<div class="stats">${stat(sum(c => c.today.rounds), 'Check-ins today', 'all courses')}${stat(sum(c => c.today.out_now), 'On course now')}${stat(money0(sum(c => c.today.fees)), 'Takings today')}${stat(money0(sum(c => c.week.fees)), 'Takings, 7 days', sum(c => c.week.rounds) + ' check-ins')}</div>
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

/* ---------------- live cart pool: carts out on the course ---------------- */
const POOL = { seen: new Set(), last: { out: [], late: [], avail: [], away: [] } }; // seen: only newly-out carts slide in; last: what the chips open
const CART_SVG = '<svg class="cart-svg" viewBox="0 0 52 34" aria-hidden="true"><rect class="cbag" x="2" y="9" width="5" height="14" rx="2"/><path class="cr" d="M12 14V5h27l3 9"/><rect class="cb" x="8" y="13" width="36" height="10" rx="4"/><g class="cw"><circle cx="16" cy="27" r="5"/><path d="M16 22v10M11 27h10"/></g><g class="cw"><circle cx="38" cy="27" r="5"/><path d="M38 22v10M33 27h10"/></g></svg>';
/** Round times for a course, in minutes (set by its admin in Settings > Round times). */
const timing = (id = CID) => { const c = COURSES.find(x => x.id == id) || {}; return { t9: +c.t9 || 135, t18: +c.t18 || 270, late9: +c.late9 || 210, late18: +c.late18 || 360 }; };
const cartNo = n => String(n || '').replace(/^cart\s*/i, '');

/** Estimated position from time since check-in against the course's expected round time. Real GPS positions can replace this later. */
function cartPool(d) {
  const tm = timing(), carts = d.fleet.filter(a => a.kind === 'cart'), out = d.out.filter(c => c.cart_name);
  const avail = carts.filter(a => a.status === 'available'), away = carts.filter(a => !['available', 'out'].includes(a.status));
  const exp = c => +c.holes === 9 ? tm.t9 : tm.t18, lateAt = c => +c.holes === 9 ? tm.late9 : tm.late18;
  const items = out.map(c => { const m = mins(c.checked_in_at); return { c, m, p: Math.min(1, m / exp(c)), late: m > lateAt(c), over: Math.max(0, m - lateAt(c)) }; }).sort((a, b) => a.p - b.p);
  const lateItems = items.filter(i => i.late).sort((a, b) => b.over - a.over);
  POOL.last = { out: [...items].sort((a, b) => b.m - a.m), late: lateItems, avail, away };
  const lanes = items.length > 18 ? 4 : 3;
  const badges = items.map((it, i) => {
    const x = 5 + it.p * 88, isNew = !POOL.seen.has(it.c.id); POOL.seen.add(it.c.id);
    const who = `${it.c.guest}${it.c.golfers > 1 ? ' +' + (it.c.golfers - 1) : ''}`;
    return `<div class="cartb ${it.late ? 'late' : ''} ${isNew ? 'enter' : ''}" style="--x:${x}%;left:${x}%;top:${36 + (i % lanes) * 50}px;--d:${(i % 6) * 0.3}s" title="${esc(it.c.cart_name)} · ${esc(who)} · out ${dur(it.m)}${it.late ? ' · overdue' : ''}">${CART_SVG}<b>${esc(cartNo(it.c.cart_name))}</b><small>${dur(it.m)}</small></div>`;
  }).join('');
  const dots = Array.from({ length: 18 }, (_, h) => `<i class="${(h + 1) % 3 === 0 || h === 0 ? 'lab' : ''}"><span>${h + 1}</span></i>`).join('');
  const chip = (k, cls, n, label) => `<button type="button" class="chip ${cls}" data-pool="${k}" aria-label="Show the ${label} carts"><b>${n}</b> ${label}</button>`;
  return `<div class="card pool"><div class="pool-head"><h2>Carts on the course <span class="mut">right now</span></h2>
    <div class="pool-chips">${chip('out', 'out', out.length, 'out')}${chip('avail', 'ok', avail.length, 'in the shed')}${away.length ? chip('away', 'off', away.length, 'unavailable') : ''}${lateItems.length ? chip('late', 'late', lateItems.length, 'overdue') : ''}</div></div>
    <div class="track" style="height:${lanes * 50 + 80}px"><div class="tflag l"><b>Clubhouse</b><small>start</small></div><div class="tflag r"><b>Clubhouse</b><small>finish</small></div><div class="lane-line"></div>
      ${out.length ? badges : '<div class="allhome">✔ Every cart is home</div>'}<div class="holes">${dots}</div></div>
    <p class="mut" style="margin:4px 0 0;font-size:13px">Estimated position from check-in time: nine holes about ${dur(tm.t9)}, eighteen about ${dur(tm.t18)}. Red means overdue (after ${dur(tm.late9)} / ${dur(tm.late18)}). Tap a number above for the cart list.</p></div>`;
}

/** The list behind a chip: cart numbers first, then who has them and for how long. */
function poolDialog(kind) {
  const L = POOL.last;
  const T = { late: ['Overdue carts', L.late], out: ['Carts on the course', L.out], avail: ['Carts in the shed', L.avail], away: ['Carts unavailable', L.away] }[kind];
  if (!T) return;
  const rows = T[1], isGuest = kind === 'late' || kind === 'out';
  const nums = rows.map(r => isGuest ? cartNo(r.c.cart_name) : cartNo(r.name));
  const m = document.createElement('div'); m.className = 'modal';
  m.innerHTML = `<div><h2>${T[0]} <span class="mut">(${rows.length})</span></h2>
    ${rows.length ? `<div class="nums ${kind === 'late' ? 'late' : ''}">${nums.map((n, i) => `<span>${esc(n)}</span>`).join('')}</div>` : '<p class="mut">None right now.</p>'}
    ${isGuest && rows.length ? `<table><tr><th>Cart</th><th>Guest</th><th>Out for</th></tr>${rows.map(r => `<tr><td><b>${esc(cartNo(r.c.cart_name))}</b></td><td>${esc(r.c.guest)}${r.c.golfers > 1 ? ' +' + (r.c.golfers - 1) : ''}<div class="mut">${r.c.holes} holes${r.c.clubs_name ? ' · ' + esc(r.c.clubs_name) : ''}</div></td><td>${dur(r.m)}${r.late ? `<div style="color:var(--warn);font-size:13px;font-weight:600">${dur(r.over)} overdue</div>` : ''}</td></tr>`).join('')}</table>` : ''}
    ${kind === 'away' && rows.length ? `<table><tr><th>Cart</th><th>Status</th></tr>${rows.map(a => `<tr><td><b>${esc(cartNo(a.name))}</b></td><td>${esc(String(a.status).replace('_', ' '))}</td></tr>`).join('')}</table>` : ''}
    <button type="button" id="pc">Close</button></div>`;
  document.body.append(m);
  const close = () => m.remove();
  $('#pc', m).onclick = close; m.onclick = e => { if (e.target === m) close(); };
}
document.addEventListener('click', e => { const b = e.target.closest('[data-pool]'); if (b) poolDialog(b.dataset.pool); });

async function courseHtml() {
  const d = await api('dashboard', { params: { range: DASH.range } });
  const p = d.period, t = d.today, r = DASH.range, label = r === 1 ? 'Today' : `Last ${r} days`;
  const carts = d.fleet.filter(a => a.kind === 'cart'), clubs = d.fleet.filter(a => a.kind === 'clubs'), ft = feat(), shoes = ft.shoes ? d.fleet.filter(a => a.kind === 'shoes') : [], tennis = ft.tennis ? d.fleet.filter(a => a.kind === 'racquet') : [];
  const free = carts.filter(a => a.status === 'available').length;
  const byDay = Object.fromEntries(d.days.map(x => [x.d, x])), byHour = Object.fromEntries(d.hours.map(x => [+x.h, +x.n]));
  const holes = Object.fromEntries(d.mix.holes.map(x => [x.k, +x.n])), tot = (+holes[9] || 0) + (+holes[18] || 0);
  const fee = Object.fromEntries(d.mix.fee.map(x => [x.k, x]));
  const late = c => mins(c.checked_in_at) > (+c.holes === 9 ? timing().late9 : timing().late18);
  const tiles = list => list.map(a => `<div class="fl ${a.status}" title="${esc(a.name)} · ${FL[a.status] || a.status}${a.guest ? ' · ' + esc(a.guest) : ''}">${esc(a.name.replace(/^(cart|set|shoes?|racquet)\s*/i, ''))}${a.status === 'out' ? `<small>${dur(mins(a.since))}</small>` : a.gps_battery != null && a.gps_battery < 25 ? '<small>🪫</small>' : ''}</div>`).join('') || '<span class="mut">None set up</span>';
  const legend = list => Object.keys(FL).map(s => [s, list.filter(a => a.status === s).length]).filter(([, n]) => n).map(([s, n]) => `<span class="lg"><i class="fl ${s}"></i>${FL[s]} ${n}</span>`).join('');
  return `${cartPool(d)}<div class="seg" role="group">${[[1, 'Today'], [7, '7 days'], [30, '30 days']].map(([v, l]) => `<button class="${r === v ? 'on' : ''}" data-range="${v}">${l}</button>`).join('')}<span class="mut">Updated ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} · refreshes every 30s</span></div>
   <div class="stats">
     ${stat(p.rounds, 'Check-ins', label)}${stat(p.golfers, 'Golfers', label)}${stat(money0(p.fees), 'Takings', label)}${+p.green ? stat(money0(p.green), 'Green fees', 'part of the takings') : ''}
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
    <div class="card"><h2>Recent damage &amp; warnings</h2>${d.issues.length ? d.issues.map(i => `<p class="issue" ${can('history') ? `data-ci="${i.id}" style="cursor:pointer"` : ''}><span class="tag ${i.return_condition === 'damaged' || i.clubs_missing ? 'r' : 'o'}">${i.clubs_missing && i.return_condition === 'ok' ? 'set missing items' : i.return_condition}</span> <b>${esc(i.guest)}</b> · ${fmtDT(i.checked_in_at)}<br>${i.clubs_missing ? `<span style="color:var(--warn)">Missing: ${esc(i.clubs_missing)}</span><br>` : ''}<span class="mut">${esc(i.return_note || '')}</span></p>`).join('') : '<p class="mut">None 🎉</p>'}</div>
   </div>
   <p style="display:flex;gap:10px;flex-wrap:wrap">${can('history') ? '<button data-go="hist">📋 Browse history &amp; signed waivers</button>' : ''}${can('export') ? '<button data-go="export">⬇ Export check-ins (CSV)</button>' : ''}</p>`;
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
    const iss = e.target.closest('.issue[data-ci]'); if (iss) return detail(+iss.dataset.ci, () => dashboard(tab)); // open a damage / missing-items case, photos included
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
function settings(tab) {
  const TABS = [...(can('fleet') ? [['fleet', 'Carts & clubs']] : []), ...(can('members') ? [['members', 'Members']] : []), ...(isCourseAdmin() ? [['users', 'Team'], ['access', 'Access'], ['activity', 'Activity'], ['alerts', 'Alerts & reports'], ['email', 'Email'], ['rates', 'Green fees'], ['range', 'Range balls'], ['timing', 'Round times']] : []), ...(isAdmin() ? [['terms', 'Terms'], ['courses', 'Courses']] : []), ...(can('export') ? [['export', 'Export']] : [])];
  if (!TABS.length) return toast('Nothing in Settings is open to you. Ask your admin.', true);
  if (!TABS.some(([k]) => k === tab)) tab = TABS[0][0];
  view(`<div class="tabs">${TABS.map(([k, l]) => `<button class="${tab === k ? 'on' : ''}" data-t="${k}">${l}</button>`).join('')}</div><div id="pane"></div>`, 'Settings', home);
  document.querySelectorAll('.tabs button').forEach(b => b.onclick = () => settings(b.dataset.t));
  guard(async () => ({ fleet, terms: termsPane, users: usersPane, access: accessPane, activity: activityPane, alerts: alertsPane, email: emailPane, rates: ratesPane, range: rangePane, timing: timingPane, courses: coursesPane, members: membersPane, export: exportPane })[tab]())();
}
async function fleet() {
  const { assets } = await api('assets', { params: { all: 1 } });
  const row = a => `<tr data-id="${a.id || ''}"><td><input class="n" value="${esc(a.name)}"></td><td><input class="ds" value="${esc(a.description || '')}" placeholder="e.g. Men's RH"></td><td><input class="f" type="number" step="0.5" value="${a.kind === 'cart' ? 0 : a.fee ?? 0}" style="width:80px" ${a.kind === 'cart' ? 'disabled title="Carts come with the round: no hire fee"' : ''}></td>
    <td>${a.kind === 'clubs' ? `<input class="f9" type="number" step="0.5" value="${a.fee9 ?? ''}" placeholder="same" style="width:80px" title="Leave blank to charge the same for 9 holes">` : ''}</td>
    <td><input class="g" value="${esc(a.gps_unit_id || '')}" placeholder="GPS unit" style="width:110px"></td><td><label><input class="m" type="checkbox" ${+a.maintenance ? 'checked' : ''}> Maint.</label><label><input class="ac" type="checkbox" ${a.active === undefined || +a.active ? 'checked' : ''}> Active</label></td>
    <td><button class="sv p" data-k="${a.kind}">Save</button></td></tr>`;
  const sect = (k, t) => `<div class="card"><h2>${t} · ${esc(courseName())}</h2><table><tr><th>Name</th><th>Description</th><th>Fee</th><th>9-hole fee</th><th>GPS ID</th><th></th><th></th></tr>${assets.filter(a => a.kind === k).map(row).join('')}${row({ kind: k, name: '', fee: k === 'cart' ? 0 : 50 })}</table><p class="mut">${k === 'cart' ? 'Carts come with the round of golf, so they have no hire fee. ' : ''}Last row adds a new one.</p></div>`;
  const bulk = `<div class="card"><h2>Add many at once · ${esc(courseName())}</h2><p class="mut">Creates a numbered run, e.g. Cart 1 to Cart 40. Names that already exist are skipped. Rename or re-price individual ones below.</p>
    <div class="row"><div><label>What</label><select id="bk"><option value="cart">Golf carts</option><option value="clubs">Club sets</option>${feat().shoes ? '<option value="shoes">Rental shoes</option>' : ''}${feat().tennis ? '<option value="racquet">Tennis hire</option>' : ''}</select></div><div><label>Name prefix</label><input id="bp" value="Cart"></div></div>
    <div class="row"><div><label>Start at number</label><input id="bf" type="number" min="0" value="1"></div><div><label>How many</label><input id="bn" type="number" min="1" max="200" value="${assets.some(a => a.kind === 'cart') ? 10 : 40}"></div></div>
    <div class="row"><div><label>Hire fee (${CUR})</label><input id="bfee" type="number" step="0.5" min="0" value="0" disabled title="Carts come with the round: no hire fee"></div><div><label>Description (optional)</label><input id="bd" placeholder="e.g. Men's, Ladies', Tennis"></div></div><p><button class="p" id="bgo">Create</button></p></div>`;
  $('#pane').innerHTML = bulk + sect('cart', 'Golf carts') + sect('clubs', 'Club sets') + (feat().shoes ? sect('shoes', 'Rental shoes') : '') + (feat().tennis ? sect('racquet', 'Tennis hire (racquets, tennis shoes)') : '');
  const DEF = { cart: ['Cart', 0], clubs: ['Set', 50], shoes: ['Shoes', 10], racquet: ['Racquet', 15] };
  $('#bk').onchange = () => { const [p, f] = DEF[$('#bk').value]; $('#bp').value = p; $('#bfee').value = f; $('#bfee').disabled = $('#bk').value === 'cart'; };
  $('#bgo').onclick = guard(async () => {
    const r = await api('assets/bulk', { method: 'POST', body: { kind: $('#bk').value, prefix: $('#bp').value, from: $('#bf').value, count: $('#bn').value, fee: $('#bfee').value, description: $('#bd').value } });
    toast(`${r.created} created${r.skipped ? `, ${r.skipped} already existed` : ''}`); fleet();
  });
  document.querySelectorAll('button.sv').forEach(b => b.onclick = guard(async () => {
    const tr = b.closest('tr');
    await api('assets/save', { method: 'POST', body: { id: tr.dataset.id || undefined, kind: b.dataset.k, name: $('.n', tr).value, description: $('.ds', tr).value, fee: $('.f', tr).value, fee9: $('.f9', tr)?.value ?? '', gps_unit_id: $('.g', tr).value, maintenance: $('.m', tr).checked, active: $('.ac', tr).checked } });
    toast('Saved'); fleet();
  }));
}
async function termsPane() {
  const t = await api('terms/current');
  $('#pane').innerHTML = `<div class="card"><h2>Waiver / terms of hire · ${esc(courseName())} <span class="mut">(version ${t.version})</span></h2><p class="alert">Have this wording reviewed by your lawyer. Saving creates a new version; past check-ins keep the version the guest signed.</p>
    <textarea id="tb" rows="16">${esc(t.body)}</textarea><p><button class="p" id="sv">Save as new version</button></p></div>`;
  $('#sv').onclick = guard(async () => { await api('terms/save', { method: 'POST', body: { body: $('#tb').value } }); toast('New version saved'); termsPane(); });
}
const ROLE_LABEL = { admin: 'Admin', supervisor: 'Supervisor', staff: 'Staff', attendant: 'Golf attendant' };

async function usersPane() {
  const { users } = await api('users/list');
  const head = isAdmin(); // head office can also create admins and place logins in any course
  const ROLES = head ? ['staff', 'attendant', 'supervisor', 'admin'] : ['staff', 'attendant', 'supervisor'];
  const ropt = r => ROLES.map(x => `<option value="${x}" ${x === r ? 'selected' : ''}>${ROLE_LABEL[x]}</option>`).join('');
  const copt = id => `<option value="">No course (head office)</option>${COURSES.map(c => `<option value="${c.id}" ${c.id == id ? 'selected' : ''}>${esc(c.name)} only</option>`).join('')}`;
  const n = r => users.filter(u => u.role === r && +u.active).length;
  $('#pane').innerHTML = `<div class="card"><h2>${head ? 'Team' : 'Team · ' + esc(courseName())}</h2>
    <p class="mut">Everyone has their own login (no sharing). <b>Admin</b> manages the team and decides access. <b>Supervisor</b> oversees the day, within what you allow under <b>Access</b>. <b>Golf attendant</b> returns carts and clubs and issues range balls. <b>Staff</b> check guests in. ${head
      ? 'Admins and supervisors with a course only ever see that course; an admin with no course is head office.'
      : 'You can add as many supervisors and staff as you need. Leave the password blank to keep the current one.'}</p>
    <p class="mut">${head ? '' : `${n('supervisor')} supervisor${n('supervisor') === 1 ? '' : 's'} · ${n('attendant')} attendant${n('attendant') === 1 ? '' : 's'} · ${n('staff')} staff active`}</p>
    <table><tr><th>Name</th><th>Username</th><th>Role</th>${head ? '<th>Course</th>' : ''}<th>New password</th><th></th></tr>${[...users, { name: '', username: '', role: 'staff', active: 1 }].map(u => `<tr data-id="${u.id || ''}"><td><input class="n" value="${esc(u.name)}"></td><td><input class="un" value="${esc(u.username)}"></td>
    <td><select class="r">${ropt(u.role)}</select></td>${head ? `<td><select class="cr">${copt(u.course_id)}</select></td>` : ''}<td><input class="pw" type="password" placeholder="${u.id ? 'leave blank' : 'required'}"></td><td style="white-space:nowrap"><label><input class="ac" type="checkbox" ${+u.active ? 'checked' : ''}> Active</label> <button class="sv p">${u.id ? 'Save' : 'Add'}</button></td></tr>`).join('')}</table></div>`;
  document.querySelectorAll('button.sv').forEach(b => b.onclick = guard(async () => {
    const tr = b.closest('tr');
    await api('users/save', { method: 'POST', body: { id: tr.dataset.id || undefined, name: $('.n', tr).value, username: $('.un', tr).value, role: $('.r', tr).value, course_id: $('.cr', tr)?.value || null, password: $('.pw', tr).value, active: $('.ac', tr).checked } });
    toast('Saved'); usersPane();
  }));
}

/** How long a round takes at this course (minutes). Drives the carts-out animation, the overdue count and the overdue-rounds report. */
async function timingPane() {
  const t = timing(), DEF = { t9: 135, t18: 270, late9: 210, late18: 360 };
  const num = (id, label, v, lo, hi) => `<div><label for="${id}">${label}</label><input id="${id}" type="number" inputmode="numeric" min="${lo}" max="${hi}" step="5" value="${v}"></div>`;
  $('#pane').innerHTML = `<div class="card"><h2>Round times · ${esc(courseName())}</h2>
    <p class="mut">How long a round normally takes at this course, in minutes. The carts-out animation places each cart by how far through its <b>expected time</b> it is. A cart still out after its <b>overdue time</b> turns red and is counted as overdue on the home screen, the dashboard and the reports.</p>
    <div class="row">${num('t9', 'Expected time out · 9 holes (minutes)', t.t9, 20, 400)}${num('t18', 'Expected time out · 18 holes (minutes)', t.t18, 40, 720)}</div>
    <div class="row">${num('l9', 'Overdue after · 9 holes (minutes)', t.late9, 20, 600)}${num('l18', 'Overdue after · 18 holes (minutes)', t.late18, 40, 900)}</div>
    <p class="mut" id="tprev"></p>
    <p style="display:flex;gap:10px;flex-wrap:wrap"><button class="p" id="tsave">Save round times</button><button id="treset">Reset to usual times</button></p></div>`;
  const val = () => ({ t9: +$('#t9').value, t18: +$('#t18').value, late9: +$('#l9').value, late18: +$('#l18').value });
  const prev = () => { const v = val(); $('#tprev').textContent = `9 holes: about ${dur(v.t9)}, overdue after ${dur(v.late9)}.  18 holes: about ${dur(v.t18)}, overdue after ${dur(v.late18)}.`; };
  ['t9', 't18', 'l9', 'l18'].forEach(id => $('#' + id).oninput = prev); prev();
  $('#treset').onclick = () => { $('#t9').value = DEF.t9; $('#t18').value = DEF.t18; $('#l9').value = DEF.late9; $('#l18').value = DEF.late18; prev(); };
  $('#tsave').onclick = guard(async () => {
    await api('course/timing', { method: 'POST', body: val() });
    const r = await api('auth/me'); COURSES = r.courses; toast('Round times saved');
  });
}

/** What an admin lets supervisors and staff open. Checking guests in and out is always available to everyone. */
async function accessPane() {
  const d = await api('access/get'), m = d.matrix;
  const LAB = {
    checkin: ['Check guests in', 'Start new check-ins and take payment'],
    returns: ['Return carts & clubs', 'Check carts and hire sets back in, with condition and photos'],
    range: ['Range balls', 'See range-ball orders and mark them issued'],
    today: ["Today's check-ins", "See the list of today's check-ins"],
    dashboard: ['Dashboard', 'Figures, fleet board and who is on the course'],
    reports: ['Reports', 'Cash-up, peak times, fleet use, guest mix, members, incidents and trends'],
    history: ['History & signed waivers', 'Browse past check-ins by date and open signed terms'],
    fleet: ['Carts & clubs', 'Add or edit carts, club sets and hire items; mark for maintenance'],
    members: ['Members', 'View, add and upload the member list'],
    export: ['Export', 'Download check-in lists as CSV'],
  };
  $('#pane').innerHTML = `<div class="card"><h2>Access · ${esc(courseName())}</h2>
    <p class="mut">Choose what supervisors, golf attendants and staff at this course can open. Admins always have everything. By default staff only check guests in, and attendants return carts and clubs and issue range balls. Managing the team and these settings stays with admins, and Terms and Courses with head office.</p>
    <table><tr><th>Area</th><th style="text-align:center">Admin</th><th style="text-align:center">Supervisor</th><th style="text-align:center">Attendant</th><th style="text-align:center">Staff</th></tr>${d.perms.map(p => `<tr><td><b>${LAB[p][0]}</b><div class="mut">${LAB[p][1]}</div></td><td style="text-align:center">✔</td>
      <td style="text-align:center"><input type="checkbox" data-r="supervisor" data-p="${p}" ${m.supervisor[p] ? 'checked' : ''}></td><td style="text-align:center"><input type="checkbox" data-r="attendant" data-p="${p}" ${m.attendant[p] ? 'checked' : ''}></td><td style="text-align:center"><input type="checkbox" data-r="staff" data-p="${p}" ${m.staff[p] ? 'checked' : ''}></td></tr>`).join('')}</table>
    <p><button class="p" id="sa">Save access</button></p></div>`;
  $('#sa').onclick = guard(async () => {
    const matrix = { supervisor: {}, attendant: {}, staff: {} };
    document.querySelectorAll('input[data-r]').forEach(i => { matrix[i.dataset.r][i.dataset.p] = i.checked; });
    await api('access/save', { method: 'POST', body: { matrix } }); toast('Access saved');
  });
}

/* ---------------- reports ---------------- */
const RPT = { type: 'playmix', preset: 'month', from: '', to: '', scope: 'current' };
const REPORTS = [
  ['playmix', 'Golfers: members, resort guests, 9 vs 18', 'How many members, resort guests, residents and other visitors played, 9 or 18 holes, and how many paid'],
  ['memberyear', 'Member usage over the year', 'Rounds each member played in their membership year (renewal talks, low-use members)'],
  ['revenue', 'Revenue: green fees & hire', 'Green fees by golfer type and 9 or 18 holes, hire, average per golfer'],
  ['cashup', 'Daily cash-up', 'Green fees and hire due, what is paid, charged to room and still to collect, by day and staff member'],
  ['rounds', 'Rounds & peak times', 'Busiest days and hours, golfers per round, time out on the course'],
  ['fleet', 'Cart & equipment usage', 'How many times each cart and hire item was used, most to least, plus idle and damaged'],
  ['guests', 'Guest mix', 'Fiji residents and overseas, top countries and hotels, returning guests'],
  ['members', 'Members', 'Renewal call list, most active members, members who have gone quiet'],
  ['incidents', 'Incidents & audit', 'Damage, warnings, member no-fee check-ins by staff, carts not returned'],
  ['trends', '12-month trend', 'Month by month, for budgeting and planning (ignores the period)'],
];
const presetRange = k => {
  const t = new Date(), y = t.getFullYear(), m = t.getMonth(), back = n => { const f = new Date(t); f.setDate(f.getDate() - n); return isoDay(f); };
  const mon = new Date(t); mon.setDate(t.getDate() - ((t.getDay() + 6) % 7)); // Monday of this week
  const lastMon = new Date(mon); lastMon.setDate(mon.getDate() - 7);
  const lastSun = new Date(mon); lastSun.setDate(mon.getDate() - 1);
  return { today: [isoDay(t), isoDay(t)], week: [isoDay(mon), isoDay(t)], lastweek: [isoDay(lastMon), isoDay(lastSun)], '7': [back(6), isoDay(t)], month: [isoDay(new Date(y, m, 1)), isoDay(t)], last: [isoDay(new Date(y, m - 1, 1)), isoDay(new Date(y, m, 0))], '90': [back(89), isoDay(t)] }[k];
};
const fmtCell = (v, t) => {
  if (v === null || v === undefined || v === '') return '—';
  if (t === 'money') return money(v);
  if (t === 'int') return Number(v).toLocaleString();
  if (t === 'pct') return v + '%';
  if (t === 'mins') return dur(+v);
  if (t === 'date') return /^\d{4}-\d{2}-\d{2}/.test(String(v)) ? fmtD(String(v).slice(0, 10)) : esc(v);
  if (t === 'datetime') return fmtDT(String(v));
  return esc(v);
};
const reportCSV = d => {
  const q = v => { const s = String(v ?? ''); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
  const lines = [[courseFull(), d.title, `${d.from} to ${d.to}`], ...d.summary.map(([l, v]) => [l, v]), []];
  d.sections.forEach(s => { lines.push([s.title], s.columns.map(c => c[0]), ...s.rows, []); });
  return '﻿' + lines.map(r => r.map(q).join(',')).join('\r\n');
};

const reportsScreen = guard(async (opts = {}) => {
  Object.assign(RPT, opts);
  if (!RPT.from) [RPT.from, RPT.to] = presetRange('month');
  const d = await api('reports/run', { params: { type: RPT.type, from: RPT.from, to: RPT.to, scope: RPT.scope } });
  const trend = RPT.type === 'trends', yearly = RPT.type === 'memberyear'; // these two ignore the period
  view(`<div class="card noprint"><h2>Reports · ${esc(courseName())}</h2>
    <div class="rgrid">${REPORTS.map(([k, t, s]) => `<button class="rcard ${RPT.type === k ? 'on' : ''}" data-r="${k}"><b>${t}</b><span>${s}</span></button>`).join('')}</div>
    ${yearly ? `<h3>Which members</h3><div class="seg">${[['current', 'All current members'], ['expiring', 'Expiring in 30 days'], ['expired', 'Expired in the last 12 months']].map(([k, l]) => `<button data-s="${k}" class="${RPT.scope === k ? 'on' : ''}">${l}</button>`).join('')}</div>
    <p class="mut">Counts each member's rounds from their start date (or 12 months before expiry if there is no start date) up to expiry. Only check-ins where staff verified the member card are counted.</p>` : `<h3>Period</h3>
    <div class="seg">${[['today', 'Today'], ['week', 'This week'], ['lastweek', 'Last week'], ['month', 'This month'], ['last', 'Last month'], ['90', 'Last 90 days']].map(([k, l]) => `<button data-p="${k}" class="${RPT.preset === k ? 'on' : ''}">${l}</button>`).join('')}</div>
    <div class="row"><div><label>From</label><input id="rf" type="date" value="${RPT.from}"></div><div><label>To</label><input id="rt" type="date" value="${RPT.to}"></div></div>
    <p><button class="p" id="rrun">Run report</button></p>`}</div>
   <div class="card" id="rout"><div class="rhead"><div><h2>${esc(d.title)}</h2><div class="mut">${esc(courseFull())} · ${trend ? 'last 12 months' : yearly ? 'each member\'s membership year' : `${fmtD(d.from)} to ${fmtD(d.to)}`}</div></div>
     <div class="noprint" style="display:flex;gap:8px;flex-wrap:wrap"><button id="rprint">🖨 Print</button><button id="rcsv">⬇ Save as CSV</button></div></div>
    ${d.summary.length ? `<div class="stats">${d.summary.map(([l, v, t]) => stat0(fmtCell(v, t), l)).join('')}</div>` : ''}
    ${d.sections.map(s => `<h3>${esc(s.title)}</h3>${s.rows.length ? `<table><tr>${s.columns.map(c => `<th${['int', 'money', 'pct', 'mins'].includes(c[1]) ? ' style="text-align:right"' : ''}>${esc(c[0])}</th>`).join('')}</tr>${s.rows.map((r, ri) => `<tr${r[0] === 'Total' ? ' style="font-weight:700"' : ''}${s.ids && can('history') ? ` class="click" data-ci="${s.ids[ri]}"` : ''}>${r.map((v, i) => `<td${['int', 'money', 'pct', 'mins'].includes(s.columns[i][1]) ? ' style="text-align:right"' : ''}>${fmtCell(v, s.columns[i][1])}</td>`).join('')}</tr>`).join('')}</table>${s.ids && can('history') && s.rows.length ? '<p class="mut noprint" style="font-size:13px">Tap a row to open the check-in and see its details and photos.</p>' : ''}` : '<p class="mut">Nothing in this period.</p>'}`).join('')}
    <p class="mut noprint" style="margin-top:18px">Figures come from check-ins recorded on the tablets. Hire income is the hire fees recorded at check-in (green-fee rates are not yet part of the system).</p></div>`, 'Reports', home);
  document.querySelectorAll('#rout tr[data-ci]').forEach(tr => tr.onclick = () => detail(+tr.dataset.ci, () => reportsScreen())); // open the check-in behind a row, photos and all
  document.querySelectorAll('.rcard').forEach(b => b.onclick = () => reportsScreen({ type: b.dataset.r }));
  document.querySelectorAll('[data-p]').forEach(b => b.onclick = () => { const [f, t] = presetRange(b.dataset.p); reportsScreen({ preset: b.dataset.p, from: f, to: t }); });
  document.querySelectorAll('[data-s]').forEach(b => b.onclick = () => reportsScreen({ scope: b.dataset.s }));
  if ($('#rrun')) $('#rrun').onclick = () => reportsScreen({ preset: '', from: $('#rf').value, to: $('#rt').value });
  $('#rprint').onclick = () => window.print();
  $('#rcsv').onclick = () => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([reportCSV(d)], { type: 'text/csv' })); a.download = `${courseName().toLowerCase().replace(/\W+/g, '-')}-${d.type}-${d.from}-to-${d.to}.csv`; a.click(); };
});

/* ---------------- member check (counter lookup) ---------------- */
function memberCheck() {
  const m = document.createElement('div'); m.className = 'modal';
  m.innerHTML = `<div><h2>Member check</h2><p class="mut">Search this course's member list by name, member number or mobile.</p>
    <input id="mq2" type="search" placeholder="e.g. Smith, M014 or 7001234" autocomplete="off" autofocus><div id="mres" style="margin-top:12px"></div><button type="button" id="mx">Close</button></div>`;
  document.body.append(m);
  const close = () => m.remove(); $('#mx', m).onclick = close; m.onclick = e => { if (e.target === m) close(); };
  let t;
  const run = guard(async () => {
    const q = $('#mq2', m).value.trim(); if (q.length < 2) { $('#mres', m).innerHTML = ''; return; }
    const { members } = await api('members/check', { params: { q } });
    $('#mres', m).innerHTML = members.length ? members.map(x => {
      const dl = x.days_left === null ? null : +x.days_left, bad = +x.expired === 1, soon = !bad && dl !== null && dl <= 30;
      const cls = bad ? 'bad' : soon ? '' : 'ok', msg = bad ? `Expired ${fmtD(x.expires_on)}` : x.expires_on ? (soon ? `Expires in ${dl} day${dl === 1 ? '' : 's'} (${fmtD(x.expires_on)})` : `Current until ${fmtD(x.expires_on)}`) : 'Current (no expiry on record)';
      return `<div class="alert ${cls}" style="margin:8px 0"><b>${esc(x.first_name)} ${esc(x.last_name)}</b>${x.member_no ? ' · #' + esc(x.member_no) : ''}<br>${bad ? '✖' : soon ? '⚠' : '✔'} ${msg}</div>`;
    }).join('') : '<p class="mut">No member found. They would be checked in as a visitor.</p>';
  });
  $('#mq2', m).oninput = () => { clearTimeout(t); t = setTimeout(run, 250); };
  setTimeout(() => $('#mq2', m)?.focus(), 50);
}

/* ---------------- cart status board (needs Carts & clubs access) ---------------- */
const cartBoard = guard(async () => {
  const { assets } = await api('assets', { params: { all: 1 } });
  const carts = assets.filter(a => a.kind === 'cart' && +a.active);
  const n = s => carts.filter(a => a.status === s).length;
  view(`<div class="card"><h2>Cart status · ${esc(courseName())}</h2>
    <p class="mut">Tap a cart to mark it in maintenance, or put it back in service. Carts that are out on the course can't be changed until they're returned.</p>
    <div class="pool-chips" style="margin:6px 0 14px"><span class="chip ok"><b>${n('available')}</b> available</span><span class="chip out"><b>${n('out')}</b> out</span><span class="chip off"><b>${carts.length - n('available') - n('out')}</b> not available</span></div>
    <div class="fleet big">${carts.map(a => `<button type="button" class="fl ${a.status}" data-id="${a.id}" title="${esc(a.name)} · ${esc(String(a.status).replace('_', ' '))}">${esc(cartNo(a.name))}<small>${esc(String(a.status).replace('_', ' '))}</small></button>`).join('')}</div>
    <div class="legend">${Object.keys(FL).map(s => [s, n(s)]).filter(([, c]) => c).map(([s, c]) => `<span class="lg"><i class="fl ${s}"></i>${FL[s]} ${c}</span>`).join('')}</div></div>`, 'Cart status', home);
  document.querySelectorAll('.fleet.big .fl').forEach(b => b.onclick = () => {
    const a = carts.find(x => x.id == b.dataset.id);
    if (a.status === 'out') return toast(`${a.name} is out on the course`, true);
    const inMaint = +a.maintenance === 1, md = document.createElement('div'); md.className = 'modal';
    md.innerHTML = `<div><h2>${esc(a.name)}</h2><p>Currently <b>${esc(String(a.status).replace('_', ' '))}</b>.</p><button type="button" class="${inMaint ? 'p' : 'd'}" id="mt">${inMaint ? '✔ Back in service' : '🔧 Mark in maintenance'}</button><button type="button" id="mc">Cancel</button></div>`;
    document.body.append(md); $('#mc', md).onclick = () => md.remove(); md.onclick = e => { if (e.target === md) md.remove(); };
    $('#mt', md).onclick = guard(async () => { await api('assets/maintenance', { method: 'POST', body: { id: a.id, maintenance: inMaint ? 0 : 1 } }); md.remove(); toast(inMaint ? `${a.name} back in service` : `${a.name} marked for maintenance`); cartBoard(); });
  });
});

/* ---------------- email settings (signed-agreement copies) ---------------- */
/** Promo banner picked in the browser: scaled to at most 1200 px wide, flattened onto white and saved as a JPEG (keeps the emailed PDF small). */
function promoData(file) {
  return new Promise((res, rej) => {
    const img = new Image();
    img.onload = () => {
      const w = img.naturalWidth || img.width, h = img.naturalHeight || img.height, s = Math.min(1, 1200 / w);
      const c = document.createElement('canvas'); c.width = Math.max(1, Math.round(w * s)); c.height = Math.max(1, Math.round(h * s));
      const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(img, 0, 0, c.width, c.height);
      res({ url: c.toDataURL('image/jpeg', 0.86), w: c.width, h: c.height });
    };
    img.onerror = () => rej(new Error('Could not read that image'));
    img.src = URL.createObjectURL(file);
  });
}

async function emailPane() {
  const d = await api('email/get'), c = d.course || {}, sy = d.system || {}, sa = d.sending_as;
  const own = c.use_system !== undefined && +c.use_system === 0, enabled = c.enabled === undefined || +c.enabled === 1;
  const acct = (p, v) => `<div class="row"><div><label for="${p}from">Sending address</label><input id="${p}from" type="email" inputmode="email" autocapitalize="none" value="${esc(v.from_email || '')}" placeholder="noreply@yourclub.com"></div>
    <div><label for="${p}method">Method</label><select id="${p}method"><option value="mail">Standard server mail</option><option value="smtp">SMTP account (best for your own domain)</option></select></div></div>
    <div id="${p}smtp"><div class="row"><div><label for="${p}host">SMTP server</label><input id="${p}host" autocapitalize="none" value="${esc(v.smtp_host || '')}" placeholder="mail.yourclub.com"></div><div><label for="${p}port">Port</label><input id="${p}port" type="number" value="${esc(v.smtp_port || 587)}"></div></div>
      <div class="row"><div><label for="${p}sec">Security</label><select id="${p}sec"><option value="tls">STARTTLS (usually port 587)</option><option value="ssl">SSL/TLS (usually port 465)</option><option value="none">None (not recommended)</option></select></div><div></div></div>
      <div class="row"><div><label for="${p}user">Username</label><input id="${p}user" autocomplete="off" autocapitalize="none" value="${esc(v.smtp_user || '')}"></div><div><label for="${p}pass">Password</label><input id="${p}pass" type="password" autocomplete="new-password" placeholder="${v.has_pass ? 'saved: leave blank to keep it' : ''}"></div></div></div>`;
  const test = id => `<div class="row" style="align-items:end"><div><label for="${id}to">Send a test email to</label><input id="${id}to" type="email" inputmode="email" autocapitalize="none" placeholder="you@example.com"></div><div><button type="button" id="${id}go">Send test email</button></div></div><p class="mut">The test uses what is <b>saved</b> above, so save first.</p>`;
  $('#pane').innerHTML = `<div class="card"><h2>Signed-agreement emails · ${esc(courseName())}</h2>
    <p class="mut">When a guest gives an email address at check-in, they are sent a PDF copy of what they signed. Currently sending as <b>${esc(sa.name)}</b> &lt;${esc(sa.from)}&gt; through the <b>${sa.source === 'own' ? "course's own" : 'system'}</b> email${sa.method === 'smtp' ? ' (SMTP)' : ''}${sa.enabled ? '' : '. <span style="color:var(--warn)">Emailing is switched off for this course.</span>'}.</p>
    <label class="chk"><input id="c_on" type="checkbox" ${enabled ? 'checked' : ''}> Email guests a PDF copy of what they sign</label>
    <div class="row"><div><label for="c_name">Sender name</label><input id="c_name" value="${esc(c.from_name || '')}" placeholder="${esc(courseFull())}"></div><div><label for="c_reply">Replies go to</label><input id="c_reply" type="email" inputmode="email" autocapitalize="none" value="${esc(c.reply_to || '')}" placeholder="proshop@yourclub.com"></div></div>
    <h3>Which email account sends it</h3>
    <label class="chk"><input type="radio" name="c_src" value="system" ${own ? '' : 'checked'}> Use the system email <span class="mut">(recommended: nothing to set up)</span></label>
    <label class="chk"><input type="radio" name="c_src" value="own" ${own ? 'checked' : ''}> Use this course's own email account</label>
    <div id="c_own" style="${own ? '' : 'display:none'}">${acct('c_', c)}</div>
    <p><button class="p" id="c_save">Save email settings</button></p>${test('c_')}</div>

   <div class="card"><h2>Promotional banner on the PDF</h2>
    <p class="mut">A banner printed at the foot of every emailed agreement, for offers, events or a new package. You can change it any time: new emails use the new banner straight away.</p>
    <div class="promo-prev">${d.promo ? `<img src="${d.promo}" alt="Promotional banner">` : '<span class="mut">No banner yet</span>'}</div>
    <div class="sizing"><b>Recommended size: 1200 × 300 pixels</b> (a wide 4:1 banner).<ul>
      <li>JPEG or PNG, under about 400 KB. Saved as a JPEG for you.</li>
      <li>It prints the full width of the page (about 17 cm), up to about 5 cm tall. Taller images are shrunk to fit.</li>
      <li>Keep words large and away from the edges. Fine print will be unreadable at this size.</li>
      <li>Use a solid or photo background, not transparency: the PDF is printed on white.</li></ul></div>
    <p id="pinfo" class="mut"></p>
    <p style="display:flex;gap:10px;flex-wrap:wrap"><label class="upl">${d.promo ? 'Replace banner' : 'Upload banner'}<input id="pfile" type="file" accept="image/*" hidden></label>${d.promo ? '<button type="button" id="prm" class="d">Remove banner</button>' : ''}</p></div>

   ${d.is_head_office ? `<div class="card"><h2>System email · all courses</h2>
    <p class="mut">The default account that courses use unless they choose their own. Head office only.</p>
    <div class="row"><div><label for="s_name">Sender name (used if a course has none)</label><input id="s_name" value="${esc(sy.from_name || '')}"></div><div><label for="s_reply">Replies go to (default)</label><input id="s_reply" type="email" inputmode="email" autocapitalize="none" value="${esc(sy.reply_to || '')}"></div></div>
    ${acct('s_', sy)}<p><button class="p" id="s_save">Save system email</button></p>${test('s_')}</div>` : ''}`;

  const setSel = (id, v) => { const el = $('#' + id); if (el) el.value = v; };
  setSel('c_method', c.method || 'mail'); setSel('c_sec', c.smtp_secure || 'tls'); setSel('s_method', sy.method || 'mail'); setSel('s_sec', sy.smtp_secure || 'tls');
  const showSmtp = p => { const el = $('#' + p + 'smtp'); if (el) el.style.display = $('#' + p + 'method').value === 'smtp' ? '' : 'none'; };
  ['c_', 's_'].forEach(p => { if ($('#' + p + 'method')) { showSmtp(p); $('#' + p + 'method').onchange = () => showSmtp(p); } });
  document.querySelectorAll('input[name=c_src]').forEach(r => r.onchange = () => { $('#c_own').style.display = document.querySelector('input[name=c_src]:checked').value === 'own' ? '' : 'none'; });
  const grab = p => ({ from_email: $('#' + p + 'from').value, method: $('#' + p + 'method').value, smtp_host: $('#' + p + 'host').value, smtp_port: $('#' + p + 'port').value, smtp_secure: $('#' + p + 'sec').value, smtp_user: $('#' + p + 'user').value, smtp_pass: $('#' + p + 'pass').value });
  $('#c_save').onclick = guard(async () => {
    await api('email/save', { method: 'POST', body: { scope: 'course', enabled: $('#c_on').checked, use_system: document.querySelector('input[name=c_src]:checked').value === 'system', from_name: $('#c_name').value, reply_to: $('#c_reply').value, ...grab('c_') } });
    toast('Email settings saved'); emailPane();
  });
  if ($('#s_save')) $('#s_save').onclick = guard(async () => {
    await api('email/save', { method: 'POST', body: { scope: 'system', from_name: $('#s_name').value, reply_to: $('#s_reply').value, ...grab('s_') } });
    toast('System email saved'); emailPane();
  });
  const runTest = (p, scope) => guard(async () => {
    const to = $('#' + p + 'to').value.trim(); if (!to) throw new Error('Type the address to send the test to');
    $('#' + p + 'go').disabled = true;
    try { await api('email/test', { method: 'POST', body: { scope, to } }); toast('Test email sent to ' + to); } finally { $('#' + p + 'go').disabled = false; }
  });
  $('#c_go').onclick = runTest('c_', 'course'); if ($('#s_go')) $('#s_go').onclick = runTest('s_', 'system');
  $('#pfile').onchange = guard(async e => {
    const f = e.target.files[0]; if (!f) return;
    const { url, w, h } = await promoData(f), ratio = w / h;
    $('#pinfo').textContent = `${w} × ${h} px` + (ratio < 2.2 ? ': this is fairly square, so it will print smaller than the full width. A 4:1 banner fills the page better.' : ratio > 6 ? ': very wide, so text may be tiny when printed.' : ': good proportions.');
    await api('course/promo', { method: 'POST', body: { promo: url } }); toast('Banner saved'); emailPane();
  });
  if ($('#prm')) $('#prm').onclick = guard(async () => { await api('course/promo', { method: 'POST', body: { promo: '' } }); toast('Banner removed'); emailPane(); });
}

/** The course's green-fee rate card: one amount per round length and golfer type. The server prices every check-in from this; staff cannot change an amount. */
async function ratesPane() {
  const { rates, types } = await api('rates/get', { params: { all: 1 } });
  const CORE = ['member', 'resort', 'resident', 'visitor'];
  const act = types.filter(t => +t.active);
  const hint = { member: 'a verified member', resort: 'staying at a hotel or resort', resident: 'a Fiji resident', visitor: 'anyone else' };
  $('#pane').innerHTML = `<div class="card"><h2>Green fees · ${esc(courseName())}</h2>
    <p class="mut">What each guest type pays for a round, in ${CUR}. At check-in the type is worked out automatically (<b>member</b>: verified on the member list; <b>resort</b>: staying at a hotel; <b>resident</b>: Fiji resident; otherwise <b>visitor</b>). Staff can also place a golfer in any extra type you add below, such as Senior or PGA member. Leave everything at 0 to charge no green fees. Staff cannot change an amount at check-in.</p>
    <div style="overflow-x:auto"><table><tr><th></th>${act.map(t => `<th style="text-align:right">${esc(t.label)}</th>`).join('')}</tr>${[18, 9].map(h => `<tr><td><b>${h} holes</b></td>${act.map(t => `<td><input class="rt" data-h="${h}" data-c="${t.code}" type="number" inputmode="decimal" min="0" step="0.5" value="${rates[h]?.[t.code] ?? 0}" style="text-align:right;min-width:110px"></td>`).join('')}</tr>`).join('')}</table></div>
    <p class="mut">Cart use is included with the round and is never charged separately. Club, shoe and tennis hire fees are set under Carts &amp; clubs; range balls under Range balls.</p>
    <p><button class="p" id="rsave">Save green fees</button></p></div>
    <div class="card"><h2>Guest types</h2>
    <p class="mut">Add the kinds of golfer your course charges differently: Senior, PGA member, Junior, Staff, Complimentary and so on. Tick <b>Needs card / ID</b> and staff must confirm they have seen it. The four standard types stay; the type is then priced in the table above.</p>
    <table><tr><th>Name</th><th>Needs card / ID</th><th>Active</th></tr>${[...types, { code: '', label: '', proof: 0, active: 1 }].map(t => `<tr data-code="${esc(t.code)}"><td><input class="tl" value="${esc(t.label)}" ${CORE.includes(t.code) ? '' : 'placeholder="e.g. Senior (65+)"'}></td><td>${CORE.includes(t.code) ? '<span class="mut">' + (hint[t.code] || '') + '</span>' : `<input class="tp" type="checkbox" ${+t.proof ? 'checked' : ''}>`}</td><td>${CORE.includes(t.code) ? '' : `<input class="ta" type="checkbox" ${+t.active ? 'checked' : ''}>`}</td></tr>`).join('')}</table>
    <p><button class="p" id="tsv">Save guest types</button></p></div>`;
  $('#rsave').onclick = guard(async () => {
    const out = { 9: {}, 18: {} };
    document.querySelectorAll('input.rt').forEach(i => { out[i.dataset.h][i.dataset.c] = i.value === '' ? 0 : +i.value; });
    await api('rates/save', { method: 'POST', body: { rates: out } });
    toast('Green fees saved');
  });
  $('#tsv').onclick = guard(async () => {
    const list = [...document.querySelectorAll('#pane tr[data-code]')].map(tr => ({ code: tr.dataset.code || undefined, label: $('.tl', tr).value, proof: $('.tp', tr)?.checked || false, active: $('.ta', tr) ? $('.ta', tr).checked : true }));
    await api('types/save', { method: 'POST', body: { types: list } });
    toast('Guest types saved'); ratesPane();
  });
}

/** Range balls a course sells (buckets / token sizes). Guests pay at check-in and the golf attendant issues them. */
async function rangePane() {
  const { products } = await api('range/products');
  $('#pane').innerHTML = `<div class="card"><h2>Range balls · ${esc(courseName())}</h2>
    <p class="mut">What guests can buy at check-in, e.g. Small bucket, Large bucket. When a guest checks in with range balls, the golf attendant gets an alert on their home screen with a sound, and taps <b>Issued</b> once the balls have been handed over. Tick <b>Free for members</b> on one item and every verified member gets one of it free, once per day; any extra buckets are charged at the normal price. Prices are in ${CUR}.</p>
    <table><tr><th>Name</th><th>Price</th><th></th></tr>${[...products, { name: '', price: '', active: 1 }].map(p => `<tr data-id="${p.id || ''}"><td><input class="n" value="${esc(p.name)}" placeholder="e.g. Large bucket (80 balls)"></td><td><input class="pr" type="number" min="0" step="0.5" value="${p.price}" style="width:100px"></td><td style="white-space:nowrap"><label><input class="ac" type="checkbox" ${+p.active ? 'checked' : ''}> Active</label> <label title="Verified members get one of these free, once a day"><input class="mf" type="checkbox" ${+p.member_free ? 'checked' : ''}> Free for members</label> <button class="sv p">${p.id ? 'Save' : 'Add'}</button></td></tr>`).join('')}</table></div>`;
  document.querySelectorAll('#pane button.sv').forEach(b => b.onclick = guard(async () => {
    const tr = b.closest('tr');
    await api('range/products/save', { method: 'POST', body: { id: tr.dataset.id || undefined, name: $('.n', tr).value, price: $('.pr', tr).value, active: $('.ac', tr).checked, member_free: $('.mf', tr).checked } });
    toast('Saved'); rangePane();
  }));
}

/* ---------------- activity log (admins) ---------------- */
const ACTION_LABEL = {
  login: 'Signed in', login_failed: 'Failed sign-in', checkin: 'Checked guests in', return: 'Returned cart / clubs',
  types_save: 'Changed guest types', range_product: 'Saved a range-ball option', range_issue: 'Issued range balls', user_save: 'Saved a team member', access_save: 'Changed access settings', asset_save: 'Edited a cart / hire item', asset_bulk: 'Added carts / hire items', asset_maintenance: 'Cart / item maintenance change',
  terms_save: 'Changed the terms', course_save: 'Changed a course', course_timing: 'Changed round times', rates_save: 'Changed green fees', promo_set: 'Changed the PDF banner',
  members_import: 'Uploaded member list', member_save: 'Saved a member', member_delete: 'Deleted a member', export: 'Exported data',
  email_settings: 'Changed email settings', email_test: 'Sent a test email', pdf_view: 'Opened a signed copy', pdf_email: 'Emailed a signed copy', guest_alert: 'Changed a guest warning',
  report_sent: 'Scheduled report emailed', overdue_alert: 'Overdue-cart alert sent', schedule_save: 'Changed scheduled reports', alert_save: 'Changed overdue alerts',
};
const AP = { from: '', to: '', user: '', q: '' };

async function activityPane() {
  const d = await api('audit/list', { params: { from: AP.from, to: AP.to, user: AP.user, q: AP.q } });
  AP.from = d.from; AP.to = d.to;
  const when = s => new Date(s.replace(' ', 'T')).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  const label = a => ACTION_LABEL[a] || a.replace(/_/g, ' ');
  $('#pane').innerHTML = `<div class="card"><h2>Activity log${d.head_office ? '' : ' · ' + esc(courseName())}</h2>
    <p class="mut">A record of what people did in the system: who, what and when. Newest first, up to 400 entries.${d.head_office ? ' As head office you see everyone.' : ' You see your own team.'}</p>
    <div class="row"><div><label for="af">From</label><input id="af" type="date" value="${d.from}"></div><div><label for="at">To</label><input id="at" type="date" value="${d.to}"></div></div>
    <div class="row"><div><label for="au">Person</label><select id="au"><option value="">Everyone</option>${d.people.map(p => `<option value="${p.id}" ${AP.user == p.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></div><div><label for="aq">Search</label><input id="aq" type="search" value="${esc(AP.q)}" placeholder="e.g. export, member, email"></div></div>
    <p style="display:flex;gap:10px;flex-wrap:wrap"><button class="p" id="ago">Show</button><button id="acsv">⬇ Save as CSV</button></p>
    ${d.rows.length ? `<table><tr><th>When</th><th>Who</th><th>What</th><th>Details</th>${d.head_office ? '<th>Course</th>' : ''}<th>From</th></tr>${d.rows.map(r => `<tr><td style="white-space:nowrap">${when(r.at)}</td><td>${esc(r.name || r.username || '—')}${r.role ? `<div class="mut">${esc(r.role)}</div>` : ''}</td><td${r.action === 'login_failed' ? ' style="color:var(--warn);font-weight:600"' : ''}>${esc(label(r.action))}</td><td class="mut">${esc(r.detail || '')}</td>${d.head_office ? `<td>${esc(r.course || '—')}</td>` : ''}<td class="mut">${esc(r.ip || '')}</td></tr>`).join('')}</table>` : '<p class="mut">Nothing recorded for this period.</p>'}</div>`;
  const apply = () => { AP.from = $('#af').value; AP.to = $('#at').value; AP.user = $('#au').value; AP.q = $('#aq').value.trim(); activityPane(); };
  $('#ago').onclick = apply; $('#aq').onkeydown = e => { if (e.key === 'Enter') apply(); };
  $('#acsv').onclick = () => {
    const q = v => { const s = String(v ?? ''); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
    const lines = [['When', 'Who', 'Role', 'What', 'Details', 'Course', 'IP'], ...d.rows.map(r => [r.at, r.name || r.username, r.role, label(r.action), r.detail, r.course, r.ip])];
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + lines.map(r => r.map(q).join(',')).join('\r\n')], { type: 'text/csv' })); a.download = `activity-${d.from}-to-${d.to}.csv`; a.click();
  };
}

/* ---------------- overdue alerts and scheduled report emails ---------------- */
async function alertsPane() {
  const [a, sc] = await Promise.all([api('alert/get'), api('schedule/list')]);
  const al = a.alert, t = a.timing;
  const typeOpt = v => Object.entries(sc.types).map(([k, l]) => `<option value="${k}" ${k === v ? 'selected' : ''}>${esc(l)}</option>`).join('');
  const row = s => `<tr data-id="${s.id}"><td>${esc(sc.types[s.report_type] || s.report_type)}</td><td>${s.frequency === 'weekly' ? 'Every Monday (last week)' : 'On the 1st (last month)'}</td>
    <td><input class="sr" value="${esc(s.recipients)}" style="min-width:220px"></td><td class="mut">${s.last_sent ? 'covers up to ' + fmtD(s.last_sent) : 'not sent yet'}</td>
    <td style="white-space:nowrap"><label><input class="se" type="checkbox" ${+s.enabled ? 'checked' : ''}> On</label></td>
    <td style="white-space:nowrap"><button class="ssv p" type="button">Save</button> <button class="ssn" type="button">Send now</button> <button class="sdl d" type="button">Remove</button></td></tr>`;
  $('#pane').innerHTML = `<div class="card"><h2>Overdue cart alerts · ${esc(courseName())}</h2>
    <p class="mut">When a cart is still out after the overdue time (currently <b>${dur(t.late9)}</b> for nine holes and <b>${dur(t.late18)}</b> for eighteen; change under Round times), an alert goes out <b>once per cart</b>. Alerts are checked every few minutes while the app is in use.</p>
    <label class="chk"><input id="al_on" type="checkbox" ${+al.enabled ? 'checked' : ''}> Send overdue-cart alerts</label>
    <label for="al_to">Email these people <span style="text-transform:none;letter-spacing:0;font-weight:400">(separate with commas)</span></label><input id="al_to" autocapitalize="none" value="${esc(al.recipients || '')}" placeholder="supervisor@yourclub.com, proshop@yourclub.com">
    <label for="al_hook">Webhook for text messages or chat <span style="text-transform:none;letter-spacing:0;font-weight:400">(optional)</span></label><input id="al_hook" autocapitalize="none" value="${esc(al.webhook_url || '')}" placeholder="https://hooks.example.com/…">
    <p class="mut">A webhook lets you send a text message or a Teams/Slack message: paste the address from a service such as Zapier, Make, Teams or Slack. It receives the alert as JSON with a ready-to-send <code>text</code> field.</p>
    <p style="display:flex;gap:10px;flex-wrap:wrap"><button class="p" id="al_save">Save alert settings</button><button id="al_test">Send a test alert</button></p></div>

   <div class="card"><h2>Scheduled report emails · ${esc(courseName())}</h2>
    <p class="mut">Reports emailed automatically, with the full report attached as a CSV for Excel. <b>Weekly</b> reports go out on Monday morning for the week just finished (Monday to Sunday); <b>monthly</b> reports on the 1st for the month just finished. They go out from 6 am once someone opens the app that day.</p>
    ${sc.schedules.length ? `<table><tr><th>Report</th><th>When</th><th>Sent to</th><th>Last sent</th><th></th><th></th></tr>${sc.schedules.map(row).join('')}</table>` : '<p class="mut">No scheduled reports yet.</p>'}
    <h3>Add a scheduled report</h3>
    <div class="row"><div><label for="ns_t">Report</label><select id="ns_t">${typeOpt('fleet')}</select></div><div><label for="ns_f">How often</label><select id="ns_f"><option value="weekly">Weekly (Monday, for last week)</option><option value="monthly">Monthly (the 1st, for last month)</option></select></div></div>
    <label for="ns_r">Send to</label><input id="ns_r" autocapitalize="none" placeholder="manager@yourclub.com, owner@yourclub.com">
    <p><button class="p" id="ns_add">Add scheduled report</button></p></div>`;
  $('#al_save').onclick = guard(async () => { await api('alert/save', { method: 'POST', body: { enabled: $('#al_on').checked, recipients: $('#al_to').value, webhook_url: $('#al_hook').value } }); toast('Alert settings saved'); });
  $('#al_test').onclick = guard(async () => { await api('alert/save', { method: 'POST', body: { enabled: $('#al_on').checked, recipients: $('#al_to').value, webhook_url: $('#al_hook').value } }); await api('alert/test', { method: 'POST', body: {} }); toast('Test alert sent'); });
  $('#ns_add').onclick = guard(async () => { await api('schedule/save', { method: 'POST', body: { report_type: $('#ns_t').value, frequency: $('#ns_f').value, recipients: $('#ns_r').value, enabled: true } }); toast('Scheduled'); alertsPane(); });
  document.querySelectorAll('tr[data-id]').forEach(tr => {
    const s = sc.schedules.find(x => x.id == tr.dataset.id);
    $('.ssv', tr).onclick = guard(async () => { await api('schedule/save', { method: 'POST', body: { report_type: s.report_type, frequency: s.frequency, recipients: $('.sr', tr).value, enabled: $('.se', tr).checked } }); toast('Saved'); alertsPane(); });
    $('.ssn', tr).onclick = guard(async () => { if (!confirm('Email this report now (for last week / last month) to the people listed?')) return; await api('schedule/send', { method: 'POST', body: { id: s.id } }); toast('Report emailed'); });
    $('.sdl', tr).onclick = guard(async () => { if (!confirm('Remove this scheduled report?')) return; await api('schedule/delete', { method: 'POST', body: { id: s.id } }); toast('Removed'); alertsPane(); });
  });
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
  $('#pane').innerHTML = `<div class="card"><h2>Courses</h2><p class="mut">Each course has its own carts, club sets, member list, check-ins, terms and dashboard. The <b>logo</b> and <b>full name</b> show in the tablet header and on the emailed PDF. <b>Logo size:</b> a PNG with a transparent background, about <b>600 × 200 pixels</b> (3:1), under 200 KB; it is trimmed and scaled for you. <b>Tennis hire</b>, <b>Shoe hire</b> and <b>Charge to room</b> switch those extras on for a course (Natadola has no tennis). Room charging is off until you tick it, and then only appears for guests who give a hotel. A new course starts with no fleet: use "Set up fleet".</p><table>${[...COURSES, { name: '' }].map(row).join('')}</table></div>`;
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
    starts_on: ['startdate', 'start', 'joined', 'joineddate', 'datejoined', 'joindate', 'membershipstart', 'membershipstartdate', 'commenced', 'commencementdate'],
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
    const dt = parseDate(g('expires_on')), sd = parseDate(g('starts_on'));
    if (!first || !last) { problems.push(`Row ${i + 2}: needs a first and last name`); return; }
    if (!dt.ok) { problems.push(`Row ${i + 2} (${first} ${last}): can't read the expiry date "${g('expires_on')}"`); return; }
    if (!sd.ok) { problems.push(`Row ${i + 2} (${first} ${last}): can't read the start date "${g('starts_on')}"`); return; }
    members.push({ member_no: g('member_no'), first_name: first, last_name: last, mobile: g('mobile'), email: g('email'), starts_on: sd.val, expires_on: dt.val });
  });
  return { members, problems };
}

async function membersPane() {
  const d = await api('members/list', { params: { q: MP.q, status: MP.status } }), c = d.counts, today = isoDay(new Date());
  const stat = m => { if (!m.expires_on) return ['No expiry', 'tag']; const dl = Math.round((new Date(m.expires_on + 'T00:00') - new Date(today + 'T00:00')) / 864e5); return dl < 0 ? ['Expired', 'tag r'] : dl <= 30 ? [`Expires in ${dl}d`, 'tag o'] : ['Current', 'tag']; };
  const row = m => `<tr data-id="${m.id || ''}"><td><input class="mn" value="${esc(m.member_no || '')}" placeholder="No." style="width:90px"></td><td><input class="mf" value="${esc(m.first_name)}" placeholder="First"></td><td><input class="ml" value="${esc(m.last_name)}" placeholder="Last"></td>
    <td><input class="mm" value="${esc(m.mobile || '')}" placeholder="Mobile" style="width:120px"></td><td><input class="me" value="${esc(m.email || '')}" placeholder="Email"></td><td><input class="ms" type="date" value="${esc(m.starts_on || '')}"></td><td><input class="mx" type="date" value="${esc(m.expires_on || '')}"></td>
    <td>${m.id ? `<span class="${stat(m)[1]}">${stat(m)[0]}</span>` : ''}</td><td style="white-space:nowrap"><button class="sv p">${m.id ? 'Save' : 'Add'}</button>${m.id ? ' <button class="d dl">Delete</button>' : ''}</td></tr>`;
  $('#pane').innerHTML = `
   <div class="stats">${stat0(c.total, 'Members', courseName())}${stat0(c.active, 'Current')}${stat0(c.expiring, 'Expiring in 30 days')}${stat0(c.expired, 'Expired')}</div>
   <div class="card"><h2>Upload member list · ${esc(courseName())}</h2>
    <p class="mut">Export your member list from Excel as <b>CSV</b> and upload it here. The first row must be headings: <b>Member no</b>, <b>First name</b>, <b>Last name</b>, <b>Mobile</b>, <b>Email</b>, <b>Start date</b> (optional: when this membership year began), <b>Expiry date</b> (a single <b>Name</b> column also works). Dates are read day first, e.g. 30/06/2027. Nothing is saved until you press Import.</p>
    <p><button id="tpl">⬇ Download a template</button></p>
    <input id="mf" type="file" accept=".csv,text/csv,text/plain">
    <div id="prev"></div></div>
   <div class="card"><h2>Member list</h2>
    <div class="row"><div><input id="mq" type="search" placeholder="Search name, member no. or mobile" value="${esc(MP.q)}"></div>
    <div><select id="ms">${[['all', 'All members'], ['active', 'Current'], ['expiring', 'Expiring in 30 days'], ['expired', 'Expired']].map(([v, l]) => `<option value="${v}" ${MP.status === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div></div>
    <table><tr><th>No.</th><th>First</th><th>Last</th><th>Mobile</th><th>Email</th><th>Joined</th><th>Expiry</th><th>Status</th><th></th></tr>${row({ first_name: '', last_name: '' })}${d.members.map(row).join('')}</table>
    ${d.members.length >= 300 ? '<p class="mut">Showing the first 300. Use search to find others.</p>' : ''}</div>`;
  const reload = () => membersPane();
  $('#mq').onchange = e => { MP.q = e.target.value.trim(); reload(); };
  $('#ms').onchange = e => { MP.status = e.target.value; reload(); };
  document.querySelectorAll('button.sv').forEach(b => b.onclick = guard(async () => {
    const tr = b.closest('tr');
    await api('members/save', { method: 'POST', body: { id: tr.dataset.id || undefined, member_no: $('.mn', tr).value, first_name: $('.mf', tr).value, last_name: $('.ml', tr).value, mobile: $('.mm', tr).value, email: $('.me', tr).value, starts_on: $('.ms', tr).value || null, expires_on: $('.mx', tr).value || null } });
    toast('Saved'); reload();
  }));
  document.querySelectorAll('button.dl').forEach(b => b.onclick = guard(async () => {
    const tr = b.closest('tr'); if (!confirm('Delete this member from the list?')) return;
    await api('members/delete', { method: 'POST', body: { id: +tr.dataset.id } }); toast('Deleted'); reload();
  }));
  $('#tpl').onclick = () => {
    const blob = new Blob(['Member no,First name,Last name,Mobile,Email,Start date,Expiry date\r\nM001,Jane,Smith,7001234,jane@example.com,01/07/2026,30/06/2027\r\n'], { type: 'text/csv' });
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
