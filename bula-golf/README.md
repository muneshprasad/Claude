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
2. **Subdomain** (e.g. `bulagolf.fijionline.nz`): cPanel > Domains/Subdomains shows its *document root* (often `/home/USER/bulagolf.fijionline.nz`). Upload the *contents* of `public/` straight into that document root, so the app is at the root of the domain.
   **File Manager / FTP**: upload the *contents* of `public/` to `public_html/golf/` (or a subdomain folder). Upload `sql/` and `cron/`
   one level above `public/` (same parent folder as `public`, ideally outside `public_html`; if you
   put `cron/` elsewhere, fix the `require` path at its top).
3. Copy `api/config.sample.php` to `api/config.php`; enter the DB details and set `setup_key` to a long random string.
4. Turn on **SSL** (AutoSSL / Let's Encrypt in cPanel). The app forces HTTPS via `.htaccess`.
5. Open `https://yourdomain/golf/api/setup.php?key=YOUR_SETUP_KEY` → create the first manager.
   This also loads **65 carts and 40 hire sets** (rename/price them in Settings).
6. **Delete `api/setup.php`** from the server.
7. Log in at `https://yourdomain/golf/`, then Settings → Terms: replace the placeholder wording with your lawyer's text.

## Courses (Natadola and Denarau)
One install serves both courses. Each course has its own carts, club sets, member list, terms, check-ins and dashboard; guests are shared. Natadola Bay Golf Course and Denarau Golf & Racquet Club each have a full name and logo (Settings > Courses).
- **Head office** logins (Settings > Staff > Course = "All courses") run everything from the console: Settings > Courses (add/rename, "Set up" jumps to that course's fleet), Carts & clubs (single edits or **Add many at once**), Staff (create logins locked to Natadola or Denarau), Terms, and an **All courses** dashboard plus a course switcher in the header.
- **Four roles, each person with their own login.** **Admin**: head office (no course) or one course's admin. **Supervisor**, **Golf attendant** and **Staff**: always tied to one course. By default **staff only check guests in**, and the **golf attendant returns carts and clubs and issues range balls**. A course admin creates as many supervisors and staff as needed (Settings > Team) and decides what they can open (Settings > Access: Dashboard, Reports, History & signed waivers, Carts & clubs, Members, Export; supervisors default to everything, staff to nothing extra). Access also covers Check guests in, Return carts & clubs, Range balls and Today's check-ins. Team and Access stay with admins; Terms and Courses with head office. Changes apply immediately, not at next sign-in.
- **Reports** (admin-controlled access): daily cash-up, rounds and peak times, **cart and equipment usage** (how many times each cart was used, most to least, for any week, month or custom period), guest mix, members (renewal call list, most active, gone quiet), incidents and audit, and a 12-month trend. Each can be printed or saved as CSV.
- **Hire extras are per course.** Settings > Courses has Tennis hire and Shoe hire switches. Denarau has both on (its slip also records shoes, racquets and bag numbers); Natadola has no tennis, so it never sees tennis anywhere. Every check-in also records bag number(s).
- **Members (Settings > Members).** Each course admin uploads their own member list as a CSV (member no., names, mobile, email, expiry date); the list is previewed before import and can be added to or replaced. At check-in the tablet matches the golfer to the list: it shows "Verified member", warns when membership expires within 30 days, and flags an expired one. Member status is never a tablet choice: the server counts a golfer as a member only if the name matches the list (with member no. or mobile), the membership is current and staff ticked that they saw the card or ID. "Member: no green fee" is offered only when every golfer on the check-in qualifies, and each member check-in is recorded against the member for audit.
- **Signed copies by email.** If a guest gives an email (optional), they are sent a PDF of exactly what they signed: club name and logo, the terms accepted, hire details, their signature and a promotional banner for the course. Sent in the background, so check-in is never slowed. Settings > Email (course admin): switch on/off, sender name, reply-to, and **use the system email or the course's own account** (own address, standard mail or SMTP with a Send test email button). Head office sets the system sender. SMTP passwords are stored encrypted. The banner is changed in the same screen: **recommended 1200 × 300 px (4:1), JPEG or PNG under about 400 KB**; it prints the full page width and up to about 5 cm tall. Logos: a PNG with a transparent background, about 600 × 200 px. Admins and supervisors with History access can download or resend any signed copy from the check-in record.
- **Returning guests.** Type a few letters of a name or a mobile number in "Returning guest?" and tap the match to fill every saved detail. Members tick "I'm a member" and find themselves by name; once verified, where-from, hotel, room and date of birth are not asked.
- **Tablets and iPads stay current.** The app checks for a newer version every minute and whenever it comes back to the front, updates itself from the home or sign-in screen, and shows an "Update now" banner mid check-in. There is also a ⟳ refresh button in the header, and the server tells browsers to always check for newer app files.
- **Green fees (Settings > Green fees).** A rate card per course: 9 or 18 holes for members, resort/hotel guests, Fiji residents and other visitors. The server prices every check-in from it (staff cannot change an amount): each golfer's fee is shown with the hire and a total to pay, and flows into takings on the dashboard, the cash-up and the Revenue report. Leave it at 0 to charge no green fees. "Member: nothing to pay" is offered only when everything on the check-in comes to zero.
- **Payment is a status only.** Everything is paid up front at the counter; check-in records Paid (or Charge to room where enabled). The system never takes money, and there is no "collect at end".
- **Guest types (Settings > Green fees).** Besides member, resort guest, Fiji resident and visitor, a course admin adds its own (Senior, PGA member, Junior...), each with its own 9 and 18 hole price and an optional "needs card / ID" tick. At check-in staff can place a golfer in one; the server prices it.
- **Club hire.** Club sets are chosen by number like carts (next free one suggested) with an optional separate 9-hole price (Settings > Carts & clubs). If none are set up the screen says so.
- **Range balls (Settings > Range balls).** The course lists its buckets and prices. Ordered at check-in and included in the total, the order appears on the golf attendant's home screen with a sound and vibration, and they tap **Issued** when done. (Browsers only allow sound after a first tap on the page.)
- **Returns.** A hired golf set must be checked on return: all there, or exactly what is missing (with an option to take the set out of service). Staff can add up to four photos for damage and three for a golf set. Admins can open any case from the Incidents report or the dashboard's recent-damage list to see what was reported and the photos.
- **Activity log (Settings > Activity).** Who did what and when (sign-ins and failed sign-ins, check-ins, edits, exports, emails), filterable and exportable. A course admin sees their own team; head office sees everyone.
- **Overdue-cart alerts and scheduled reports (Settings > Alerts & reports).** One alert per overdue cart by email and/or a webhook (use a service such as Zapier, Make, Teams or Slack to turn it into a text message). Weekly (Mondays, last week) and monthly (the 1st, last month) report emails with a CSV attached, e.g. cart usage. Both run while the app is in use, so no cron is needed; `cron/run_jobs.php` is an optional timer.
- **Working offline.** If the Wi-Fi drops, check-ins and returns are saved on the tablet (signatures and photos included) and sent automatically when it reconnects, once each. The tablet keeps the last-seen terms, carts, rates and list of who is out. Anything the server refuses (for example the cart was given to someone else) waits under the orange pill for a person to retry or discard. Connect each tablet once so it has saved the terms.
- **Existing install?** In phpMyAdmin > SQL run each of these once, in this order: `sql/migrate_courses.sql`, `sql/migrate_hire_and_terms.sql`, `sql/migrate_room_charge.sql`, `sql/migrate_roles.sql`, `sql/migrate_reports.sql`, `sql/migrate_cart_included.sql`, `sql/migrate_timing.sql`, `sql/migrate_email.sql`, `sql/migrate_operations.sql`, `sql/migrate_hire_types.sql`, then upload the new site files straight away. Everything already there is assigned to Natadola; Denarau starts empty, so use Settings > Courses > Set up fleet > Add many at once to create its fleet. The second migration adds per-course terms (Denarau's wording is transcribed from its paper slip: have it checked), hire extras, bag numbers, member lists and the full course names.
- The Expo app has no course switcher yet: a course-locked login works as is; a head-office login there always lands on the first course.

## Tablets
- **Android tablet (simplest):** open the site in Chrome → menu → **Install app** / **Add to Home screen**. It opens full-screen like an app, with its own icon, and keeps the screen awake while someone is signed in. Works in portrait and landscape.
- **Android APK:** see below.

## Android APK (two options)
**Option A – Capacitor wrapper of the web app (recommended).** It loads the live site, so every update to the site reaches the tablets with no new APK. Needs Node 18+ and Android Studio on any computer (`server.url` is already set to https://bulagolf.fijionline.nz/):

**Option B – Expo app.** A separate native app (`expo-app/README.md`, `eas build -p android --profile apk`). It does **not** yet have the multi-course, members, hire-extras or new design: use Option A or the browser install for those.

Option A steps:
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
