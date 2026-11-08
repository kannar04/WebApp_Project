# Google sign-in

Google is the first supported social provider. Email/password sign-in and existing account roles remain available. Google-only registrations receive the Guest role.

## Set up

1. Apply the additive database migration:

   ```powershell
   php database/install_google_auth.php
   ```

   The migration creates `oauth_identities` and three routines. It can be rerun and does not modify existing users, passwords or roles.

2. In [Google Cloud Console](https://console.cloud.google.com/auth/clients), configure the OAuth consent screen and create an OAuth client of type **Web application**. If the app is in testing mode, add your Google account as a test user.
3. Register this exact authorized redirect URI for the local site:

   ```text
   http://127.0.0.1:8090/auth/google/callback
   ```

4. Add credentials to the local `.env` (never commit the secret):

   ```dotenv
   GOOGLE_CLIENT_ID=your_client_id.apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=your_client_secret
   GOOGLE_REDIRECT_URI=http://127.0.0.1:8090/auth/google/callback
   ```

   The callback host must match the browser host: `localhost` and `127.0.0.1` are different. Use HTTPS and the deployed callback URL in production. Restart PHP if these values were inherited from its environment.

5. Visit `/login` or `/register` and choose **Continue with Google**. The button is disabled when credentials or a valid callback URI have not been configured.

## Existing accounts

Matching email addresses are never automatically merged. Sign in using your existing password, then visit `/profile#connections`. Confirm your current password and choose **Link Google account**. Future Google sign-ins resolve the stable Google subject to that account and preserve its roles and bookings.

A Google-only registration has an unknown random password hash. To add a local password, use the existing password-reset flow, which requires the app's mail transport to be configured. Linking never changes an existing name, password or email. No Google access/refresh tokens are stored.

## Validation and failure behavior

Authorization starts with a CSRF-protected POST. Callback state is single-use, expires after ten minutes and is bound to the initiating browser and account. The code exchange uses PKCE S256 and the client secret. Identity comes from Google's HTTPS UserInfo endpoint using the access token obtained server-side; browser-supplied profile claims and ID tokens are not trusted or parsed. New accounts require a verified email. Suspended or deleted local accounts cannot sign in via Google. External HTTP requests retain certificate verification, including the native certificate store on supported Windows cURL builds.

Provider cancellation, expired/replayed state, invalid tokens, duplicate identities and unavailable Google endpoints redirect back with a readable message. Successful sign-in retains the existing safe return-target and role-based redirect behavior.

## Rollout and rollback

Apply the migration before deploying the PHP changes; configure credentials last. To disable Google sign-in, clear the client credentials while keeping email/password login available. Keep the identity table when rolling back application code so existing connections survive re-enablement. Removing that table would discard connection data and is not part of the installer.

Local checks are in `tests/google_auth_test.php`, `tests/google_identity_test.php` and `tests/google_auth_browser_test.cjs`. The repository currently ignores `tests/`. A real provider consent flow still requires your own Google OAuth credentials and a test user.

References: [Google server OAuth flow](https://developers.google.com/identity/protocols/oauth2/web-server), [Google OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect), [button branding](https://developers.google.com/identity/branding-guidelines).
