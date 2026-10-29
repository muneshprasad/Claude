# Bula Golf – Expo app (Android tablets)

React Native / Expo front end for the same PHP + MySQL backend (`../public/api`). Staff use it for
check-in (with signatures), returns (damage photo / patron warning) and today's list; managers also get
the dashboard. Fleet, terms, staff logins and exports stay in the web admin (`../public`).

## Run it
```
cd expo-app
npm install
npx expo start            # scan the QR with Expo Go on the tablet (same Wi-Fi)
```
On first launch enter the server address (e.g. `https://yourdomain.com/golf`) on the sign-in screen.
Optional: copy `.env.example` to `.env` to pre-fill it.

## Build an installable APK (no Android Studio needed)
```
npm install -g eas-cli
eas login                  # free Expo account
eas build -p android --profile apk
```
EAS builds in Expo's cloud and gives a download link for the `.apk`; install it on the tablets (allow "unknown sources").
(The free plan has a monthly build quota and a queue.)

## How it talks to the server
- Same JSON API as the web app. The app signs in with `app: true` and receives a token, stored in the device's
  secure storage and sent as the `X-Bula-Token` header (a custom header because cPanel/Apache can strip `Authorization`).
- Tokens expire after 30 days without use; signing out deletes the token on the server.
- Existing installs need the new `api_tokens` table: run the `CREATE TABLE api_tokens` statement from `../sql/schema.sql`
  in phpMyAdmin, and upload the updated `api/index.php` and `api/lib.php`.

## Status
Verified: TypeScript compiles, Android bundle builds, and (via the web build) sign-in, home, returning-guest autofill
and the returns list work against the API. Not yet tested on a real tablet: the signature pad
(`react-native-signature-canvas`, WebView based) and the camera photo – please test those first.
Navigation is a simple screen switch rather than Expo Router; it can be migrated later if more screens are added.
