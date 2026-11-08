# Facebook Login setup

Facebook Login is integrated alongside Google and email/password. It uses a server-side authorization-code flow and the existing identity table. Facebook sign-in does not require the image CAPTCHA; password login and payment still do.

## Cost

Basic Facebook Login is normally free: this integration has no paid SDK, third-party authentication service, or per-login billing configuration. A third-party product listing also identifies it as free: [Facebook Login listing](https://alternativeto.net/software/facebook-connect/about/). Meta's detailed documentation returned HTTP 429 during this check on 2026-11-08, so current account-specific publishing/review requirements could not be fully verified there. Review your own Meta developer dashboard before publishing. Website hosting, a domain/HTTPS setup, or optional commercial services may have separate costs; those are not a Facebook Login fee.

## 1. Install the database extension

From the project directory:

```powershell
php database/install_facebook_auth.php
```

This installs the base Google identity table/procedures if absent, expands its provider constraint to include Facebook, and adds three Facebook-specific procedures. It does not change existing users, passwords, bookings, roles, Google identities or Google procedure signatures. Rerunning is supported. The installer has already been applied to the local development database.

## 2. Create a Meta app

1. Open [Meta for Developers](https://developers.meta.com/) and create/register your developer account if needed.
2. Create an app for authenticating users with **Facebook Login**, choosing the website platform. Dashboard labels can vary; do not select Instagram or Facebook Login for Business for this integration.
3. Enable the Facebook Login use case/product and request only `public_profile` and `email`.
4. Set the website URL and register the exact **Valid OAuth Redirect URI**, for example:

```text
https://your-demo-host.example/auth/facebook/callback
```

For local development the application can use `http://127.0.0.1:8090/auth/facebook/callback`, but Meta may require HTTPS/allowed local host settings for your app. If the dashboard rejects HTTP localhost, expose the local server through an HTTPS development tunnel and register its exact callback. Use the same hostname throughout the login flow so the browser keeps the session cookie. Do not disable redirect validation to work around a mismatch.

5. Copy the app's **App ID** and **App Secret**. These are not your Facebook password, a client token, or an email SMTP key.
6. For a classroom demo, keep development mode and add the Facebook accounts used by your team as app developers/testers. They must accept invitations where required. Public sign-in may need additional permissions/access review or app publishing steps; follow the dashboard's requirements. [Supabase's current Meta setup walkthrough](https://supabase.com/docs/guides/auth/social-sign-in/auth-facebook) documents the developer-app setup and publishing constraints.

## 3. Configure `.env`

```dotenv
FACEBOOK_ENABLED=true
FACEBOOK_APP_ID=YOUR_NUMERIC_APP_ID
FACEBOOK_APP_SECRET=YOUR_APP_SECRET
FACEBOOK_REDIRECT_URI=http://127.0.0.1:8090/auth/facebook/callback
FACEBOOK_GRAPH_VERSION=v26.0
```

Replace the callback with your exact HTTPS URL when using a tunnel or hosted site. The version is pinned and configurable; v26.0 is a supported Facebook social-login version documented by [Auth0](https://support.auth0.com/center/s/article/Meta-Graph-API-supported-version), not a claim that it is Meta's newest release. Match a supported version available to your Meta app and review upgrades before the configured version retires.

The app's `.env` parser expects unquoted values. Existing process environment variables override `.env`; restart PHP/Apache if inherited values change. Never commit or share the App Secret. No SDK installation is needed; PHP cURL and valid TLS trust must work.

Facebook is temporarily disabled by default with `FACEBOOK_ENABLED=false`. This hides the sign-in and profile-link controls and blocks OAuth authorization even if credentials remain configured. Set it to `true` to re-enable the integration. When enabled, the Facebook button appears on login and registration and is disabled until configuration is present. Configuration readiness does not prove that Meta has activated the app or that credentials are correct.

## Account behavior

- A previously linked Facebook app-scoped user ID signs in to its existing Home2Home account, subject to active-account checks.
- An unlinked Facebook identity can create a guest account only if Meta supplies a valid email address. Users may decline the email permission or have no email returned. In that case, sign in/register using email first and link Facebook from the profile.
- A matching email never silently merges existing accounts. Sign in with your existing Home2Home password, open `/profile#connections`, confirm the current password, and select **Link Facebook account**.
- Google-only or Facebook-only accounts initially have a random local password. Use password recovery to set a local password before linking another provider; recovery requires working email delivery.
- Linking preserves the existing account's name, email, password, roles and bookings. No provider access/refresh tokens are stored in the database or session after callback processing.

OAuth starts with a CSRF-protected POST. The callback state is random, session-bound, single-use, expires after ten minutes, and binds profile-link requests to the initiating account/password version. PHP exchanges the authorization code using the App Secret, checks the token's validity, App ID, user ID and expiry through Meta's debug endpoint, then fetches `/me` with `appsecret_proof`. Browser-submitted profile information is not accepted as identity. Google and Facebook state stores are separate and cleared on successful sign-in.

## Test

1. Add a developer/tester account in Meta, configure credentials and open `/login`.
2. Click **Continue with Facebook** without completing the password CAPTCHA. You should reach Meta's consent screen.
3. Approve access and return to Home2Home. Test a new email and a previously linked account. Check the intended destination, role and bookings.
4. Cancel consent, try a replayed/expired callback, and test a denied/missing email. Expect a readable error rather than a new unintended account.
5. Try a Facebook account whose email matches an existing local account. It must ask you to sign in and link; it must not take over that account.
6. In the profile, link Facebook after confirming the current password. Sign out and use Facebook to sign in again.
7. Recheck Google and password sign-in. Payment/password CAPTCHA behavior remains independent.

Local checks cover state lifecycle, mocked Meta responses, provider separation, linking, account creation and collision rejection. A real successful Meta round trip still requires your App ID/Secret, registered callback and an authorized tester account.

## Publishing and rollback

For a public deployment, complete Meta's current requirements for a privacy-policy URL, user-data deletion instructions/callback, permissions/access and any requested review or verification. This change does not publish a Meta app or add a legal policy on your behalf. Development-mode testing is the intended first step for this demo.

To disable Facebook, set `FACEBOOK_ENABLED=false`; credentials and linked identities can remain preserved for later reactivation. Rolling back application code may leave the additive identity schema/procedures in place so Google continues working and Facebook links remain preserved. Do not rerun a destructive schema downgrade or delete identities as part of disabling the feature.

Meta references (rate-limited when checked): [Facebook Login](https://developers.facebook.com/docs/facebook-login/), [manual flow](https://developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow/), [security](https://developers.facebook.com/docs/facebook-login/security/).
