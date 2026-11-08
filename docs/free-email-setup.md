# Free email delivery: full Home2Home setup

Checked against provider documentation on 2026-11-08. This guide configures the existing booking email feature; no additional application integration is needed.

## Choose a provider

Use **Brevo Free** for a small deployment with an authenticated domain. Its current allowance is 300 emails per day, without rollover, and free-plan branding applies. Each confirmed Home2Home booking normally sends two messages, so that is at most 150 confirmations per day if no other emails are sent. Password resets and configuration tests also consume sends. This arithmetic is a capacity estimate, not a delivery guarantee. [Brevo Free limits](https://help.brevo.com/hc/en-us/articles/208580669-FAQs-What-are-the-limits-of-the-Free-plan).

Brevo provides outbound delivery. It does not give this application a mailbox for reading replies or a custom domain for free. If you already have a domain, use a sender on that domain. If you have no domain and need a completely free student/demo setup, use the Gmail alternative below.

The flow is:

```text
Guest makes instant booking / host approves request
  -> booking confirmation event commits
  -> database contains one email queue row per recipient
  -> confirmation request sends this booking's emails immediately after commit
  -> SMTP provider accepts each message
  -> guest and host receive confirmation emails
```

## 1. Prepare the application

In PowerShell:

```powershell
Set-Location D:\dan\WebApp_Project
composer install
php database/install_booking_emails.php
```

This assumes the base application database is already installed and working. The email installer adds only the email queue index, trigger and worker procedures. Running it again is safe; it does not backfill old bookings.

Confirm that CLI PHP has PDO MySQL and OpenSSL:

```powershell
php --version
php --ini
php -m
```

The PHP used by your scheduled task must have the same required extensions and database access as the website.

## 2. Create and activate Brevo

1. Create a Free account at [Brevo](https://www.brevo.com/).
2. Verify your account email and complete the requested account information.
3. Open the transactional email area and complete any activation/review requested by Brevo. Account registration alone may not activate transactional sending.
4. In the sender/domain settings, add your domain and publish the exact DNS records Brevo supplies. Verify the domain in Brevo.
5. Create the sender, for example `Home2Home <bookings@your-domain.com>`, and finish any sender verification.
6. Open [SMTP settings](https://app.brevo.com/settings/keys/smtp), copy the displayed **SMTP login**, and generate a dedicated SMTP key named `Home2Home`.

Use the displayed SMTP login even if it differs from your account email. The SMTP key is the SMTP password; the account password and REST API key are different credentials. [Official SMTP setup](https://help.brevo.com/hc/en-us/articles/7924908994450-Send-transactional-emails-using-Brevo-SMTP), [activation troubleshooting](https://help.brevo.com/hc/en-us/articles/115000188150-Troubleshooting-Issues-with-Brevo-SMTP).

For DNS, copy the provider's current DKIM, verification and DMARC instructions. Do not invent record values or create a second SPF record if your domain already has one. Brevo may rewrite unauthenticated/free-address senders to a provider address; use your authenticated domain for a stable branded sender. [Sender requirements](https://help.brevo.com/hc/en-us/articles/14925263522578-Comply-with-Gmail-Yahoo-and-Microsoft-s-requirements-for-email-senders).

## 3. Configure `.env`

Edit the existing `.env` and replace the mail settings with:

```dotenv
MAIL_ENABLED=true
MAIL_TRANSPORT=smtp
MAIL_FROM=bookings@your-domain.com
MAIL_FROM_NAME=Home2Home
MAIL_BASE_URL=http://127.0.0.1:8090
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=465
SMTP_USERNAME=YOUR_DISPLAYED_BREVO_SMTP_LOGIN
SMTP_PASSWORD=YOUR_BREVO_SMTP_KEY
SMTP_ENCRYPTION=ssl
SMTP_CA_FILE=
PASSWORD_RESET_BASE_URL=http://127.0.0.1:8090
```

Port 465 uses implicit TLS (`ssl` is the application's configuration label). This matches Brevo's documented encrypted connection option. [Brevo connection settings](https://developers.brevo.com/docs/smtp-integration).

Keep only one entry per key. The application's simple `.env` loader does not remove surrounding quotes, so enter values without quotes. Never commit the SMTP key or paste it into chat. If a variable is already set in the process environment, it takes precedence over `.env`; restart the PHP process after changing inherited configuration.

For a hosted website, replace all application base URLs with the public HTTPS address:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
MAIL_BASE_URL=https://your-domain.com
PASSWORD_RESET_BASE_URL=https://your-domain.com
```

Localhost links work only on the computer running the application. External recipients need the public HTTPS URL to open booking details. Google login credentials are separate from SMTP credentials; update its authorized callback and `GOOGLE_REDIRECT_URI` when moving the site to a public domain.

## 4. Check SMTP connectivity and send a test

```powershell
Test-NetConnection smtp-relay.brevo.com -Port 465
php database/send_booking_emails.php --test=YOUR_REAL_INBOX@example.com
```

Replace the test recipient with an inbox you control. Expected output:

```text
Test email accepted by the configured transport.
```

Check the inbox, spam folder and Brevo transactional logs. Acceptance does not guarantee inbox placement. The test command does not create a booking.

If TLS fails on Windows, use `php --ini` to identify the CLI configuration, then configure a trusted CA bundle there or set `SMTP_CA_FILE` to its absolute path. Keep certificate verification enabled. If the port is blocked, check the hosting provider's outbound SMTP policy before changing settings.

## 5. Test the actual booking flow

1. Use a guest account and a separate host account with inboxes you control.
2. Create a new future instant booking, or create a request and have the host approve it.
3. Wait for the confirmation request to finish; it sends the emails immediately. To retry a failed delivery manually, run `php database/send_booking_emails.php --limit=50`.
4. Expect one guest email and one host email. Check the dates, listing, price and booking link.
5. Run the command again. Already-sent rows should not send again.
6. Create a pending request without approving it. It should not queue a confirmation email.

Earlier bookings are not automatically emailed. Confirmations created after installing the trigger queue even while mail delivery is disabled. Inspect existing queued rows before first enabling delivery if the site already has users.

## 6. Optional scheduled retries on Windows

The demo sends emails during confirmation and does not need a scheduled task. Use this section only if you want automatic retries after delivery failures.

Find the PHP executable:

```powershell
(Get-Command php).Source
```

Open **Task Scheduler > Create Task**:

| Setting | Value |
| --- | --- |
| Name | Home2Home booking emails |
| User | Your account with access to the project, `.env`, database and network |
| Trigger | Daily; repeat every 1 minute indefinitely; enabled |
| Program/script | Absolute `php.exe` path returned above |
| Arguments | `database/send_booking_emails.php --limit=50` |
| Start in | `D:\dan\WebApp_Project` |
| Overlap behavior | Do not start a new instance |

Enable “Run whether user is logged on or not” if delivery should continue after logout, and supply Windows credentials if Task Scheduler requests them. On a laptop, review the task's AC-power conditions if you want delivery on battery.

Select **Run** once and check the task's Last Run Result (`0x0` for success). New bookings send immediately; scheduled runs retry unsent messages. The computer, database and internet connection must stay running; sleep/shutdown prevents delivery.

## 7. Run on a hosted Linux server instead

Use the production HTTPS URL and install the same Composer dependencies and migration on the server. Add a cron entry under the application account using `crontab -e`:

```cron
* * * * * cd /srv/home2home && /usr/bin/php database/send_booking_emails.php --limit=50 >> /srv/home2home/booking-mail.log 2>&1
```

Replace both paths with your actual project and PHP paths. The account must be able to read `.env` and write the log, which must remain outside the public web root. Rotate the log. The worker uses a database lock to avoid concurrent delivery by multiple worker processes.

For website hosting, select a service that supports all of these application requirements:

- PHP with the required extensions and Composer dependencies.
- MariaDB with stored procedures and trigger installation permissions.
- A document root pointing to `public/`, with front-controller routing.
- Persistent uploaded files and writable PHP sessions.
- Scheduled PHP CLI execution and outbound SMTP to port 465.
- Public HTTPS for links and authentication.

[Oracle Cloud Always Free](https://www.oracle.com/cloud/free/) is one possible VM route where you can install this stack yourself. Free compute is subject to resource limits and capacity; it is not a preconfigured PHP host. Check the console's current Always Free eligibility and [capacity FAQ](https://www.oracle.com/cloud/free/faq/) before creating resources. This guide does not provision a server, DNS or certificates. A free SMTP plan does not make a domain or website server free automatically.

## Gmail alternative: demo without buying a domain

1. Use a dedicated Gmail account for the application.
2. Enable Google 2-Step Verification.
3. Open [App Passwords](https://myaccount.google.com/apppasswords) and create one for `Home2Home`.
4. Use the generated app password, without display spaces, in `.env`:

```dotenv
MAIL_ENABLED=true
MAIL_TRANSPORT=smtp
MAIL_FROM=your.demo.account@gmail.com
MAIL_FROM_NAME=Home2Home
MAIL_BASE_URL=http://127.0.0.1:8090
SMTP_HOST=smtp.gmail.com
SMTP_PORT=465
SMTP_USERNAME=your.demo.account@gmail.com
SMTP_PASSWORD=YOUR_GOOGLE_APP_PASSWORD
SMTP_ENCRYPTION=ssl
SMTP_CA_FILE=
PASSWORD_RESET_BASE_URL=http://127.0.0.1:8090
```

Then follow steps 4–6 above, using `smtp.gmail.com` for the connection test. Use the same Gmail address for username and sender. Your normal Google password and the website's Google OAuth client secret do not replace an app password. Some account policies do not permit app passwords. [Google app-password requirements](https://support.google.com/accounts/answer/185833).

Gmail applies sending and anti-abuse limits; its help documents a 500-email/day threshold for personal accounts, but do not treat that as a guaranteed automated SMTP capacity. It suits a small demo better than a growing booking service. [Google sending limits](https://support.google.com/mail/answer/22839).

## Failure checklist

| Symptom | Check |
| --- | --- |
| Email disabled/not configured | `MAIL_ENABLED`, sender address, Composer install, all SMTP fields, valid base URL |
| Authentication rejected | Provider's SMTP login and SMTP key; Gmail app password and 2-Step Verification |
| Timeout/cannot connect | DNS, internet, firewall, host's outbound SMTP restrictions |
| TLS failure | Correct encryption/port and trusted CA bundle for CLI PHP |
| Accepted but missing | Spam folder, provider logs, sender/domain activation, quota |
| Manual delivery works, automatic fails | Task PHP path, working directory, account permissions, sleeping machine |
| Wrong booking link | Public `MAIL_BASE_URL`; restart processes with stale environment values |
| No email after an old booking | No historical backfill; test a new confirmation |
| Failed rows stop retrying | Five-attempt cap; inspect and deliberately reset only reviewed rows |

Home2Home does not currently enforce a provider-wide daily quota. Monitor provider usage. Repeated quota rejections can exhaust the five local attempts within a few scheduled runs; pause the worker until quota resets, then review failed rows. Do not blindly reset or replay every row.

For queue inspection SQL, deliberate single-row retries, crash-related duplicate limitations and rollback instructions, see [booking-emails.md](booking-emails.md).
