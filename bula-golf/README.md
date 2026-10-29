# Bula Golf – Guest Check-in System

Staff check guests in on a tablet: guest details (auto-filled for returning guests), **every golfer signs** the
terms, staff assign a cart (suggested next free one) and optional hire clubs, and later mark them returned
(undamaged / damaged with photo / patron warning). Managers get a dashboard, signed-waiver history, fleet and
terms editing, staff logins and CSV export.

Runs on ordinary cPanel shared hosting (PHP 8.3 + MySQL). Same web app is packaged as an Android APK.

## Layout
```
public/        upload this folder's contents to e.g. public_html/golf/
  index.html, app.js, style.css, manifest.json
  api/         PHP JSON API (index.php, lib.php, setup.php, config.sample.php)
sql/schema.sql tables (setup.php runs this for you)
cron/          gps_sync.php – cart GPS status sync (cPanel cron job)
capacitor/     Android APK wrapper config (simple option: wraps the web app)
expo-app/      Expo / React Native tablet app (native option; see expo-app/README.md)
prototype.html the original single-file offline prototype
```

## Install on cPanel
1. **MySQL Databases**: create a database + user, give the user *All Privileges*.
2. **File Manager / FTP**: upload the *contents* of `public/` to `public_html/golf/` (or a subdomain folder). Upload `sql/` and `cron/`
   one level above `public/` (same parent folder as `public`, ideally outside `public_html`; if you
   put `cron/` elsewhere, fix the `require` path at its top).
3. Copy `api/config.sample.php` to `api/config.php`; enter the DB details and set `setup_key` to a long random string.
4. Turn on **SSL** (AutoSSL / Let's Encrypt in cPanel). The app forces HTTPS via `.htaccess`.
5. Open `https://yourdomain/golf/api/setup.php?key=YOUR_SETUP_KEY` → create the first manager.
   This also loads **65 carts and 40 hire sets** (rename/price them in Settings).
6. **Delete `api/setup.php`** from the server.
7. Log in at `https://yourdomain/golf/`, then Settings → Terms: replace the placeholder wording with your lawyer's text.

## Tablets
- Browser: open the URL → "Add to Home screen".
- APK: see below.

## Android APK (two options)
**Option A – Expo app (recommended):** see `expo-app/README.md` (`eas build -p android --profile apk`).

**Option B – Capacitor wrapper of the web app.** Needs Node 18+ and Android Studio on any computer:
```
cd capacitor
# edit capacitor.config.json: server.url = your https URL
npm install
npx cap add android
npx cap sync android
npx cap open android      # Build > Build APK(s)
```
The APK is a shell that loads your hosted app, so updates to the site reach tablets without reinstalling.

## GPS cart status (future)
Set `gps_unit_id` on each cart (Settings → Carts & clubs). `cron/gps_sync.php` writes status/battery to the carts;
the check-in screen only offers carts that are *available* (not out, not in maintenance, GPS says ready).
Add cPanel cron: `* * * * * /usr/local/bin/php /home/USER/path/cron/gps_sync.php`.
Providers: `none`, `simulated` (for testing), `http` (fill in `fetch_http()` once the vendor API docs are available).

## Backups
cPanel → Backup, or schedule a nightly mysqldump cron and copy it to Google Drive. Guest signatures live in the DB.

## Security / privacy notes
- Signatures, phone numbers and photos are personal data: keep HTTPS on, use strong staff passwords, remove staff who leave (Settings → Staff).
- Login is rate-limited; all changes are logged in `audit_log`.
- Add a privacy notice and have the waiver wording reviewed locally before go-live.
