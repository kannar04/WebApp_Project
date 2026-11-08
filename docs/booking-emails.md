# Booking confirmation emails

For a complete provider walkthrough, see [Free email setup with Brevo or Gmail](free-email-setup.md).

Home2Home queues separate confirmation emails for the guest and host when an instant booking succeeds or a host approves a booking. Pending requests do not send confirmation emails. Messages include the stay, dates, guests, price and a link to the booking details. They confirm the reservation, not payment.

Email delivery runs in a separate CLI worker. SMTP failures do not undo a successful booking. The queue is written in the same database transaction as the confirmation event; a rolled-back confirmation queues nothing. Historical bookings are not backfilled.

## Install

From the project directory, using the PHP installation configured for this application:

```powershell
composer install
php database/install_booking_emails.php
```

The installer uses the database settings in `.env`. It adds a trigger, queue index and worker procedures to the existing MariaDB database. It can be run again without queuing duplicate emails. Deploy the migration before enabling the worker.

## Local SMTP testing

Install [Mailpit](https://mailpit.axllent.org/docs/install/) or run its Docker image:

```powershell
docker run -d --name home2home-mailpit -p 127.0.0.1:8025:8025 -p 127.0.0.1:1025:1025 axllent/mailpit
```

Set these values in your local `.env`:

```dotenv
MAIL_ENABLED=true
MAIL_TRANSPORT=smtp
MAIL_FROM=bookings@home2home.test
MAIL_FROM_NAME=Home2Home
MAIL_BASE_URL=http://127.0.0.1:8090
SMTP_HOST=127.0.0.1
SMTP_PORT=1025
SMTP_USERNAME=
SMTP_PASSWORD=
SMTP_ENCRYPTION=none
SMTP_CA_FILE=
```

Mailpit captures messages locally; open its inbox at `http://127.0.0.1:8025`. Its default local SMTP service uses no authentication or encryption. Restart the PHP server after configuration changes if it inherited old environment values.

Send a configuration test, then create a new instant booking or approve a new booking request and run the queue:

```powershell
php database/send_booking_emails.php --test=guest@example.com
php database/send_booking_emails.php --limit=50
```

The test command does not create or change a booking. Expect two separate confirmation messages after a booking is confirmed, one per recipient. Running the worker again does not resend rows marked sent.

## Production SMTP

Use your email provider's SMTP credentials and verified sender address:

```dotenv
MAIL_ENABLED=true
MAIL_TRANSPORT=smtp
MAIL_FROM=bookings@your-domain.com
MAIL_FROM_NAME=Home2Home
MAIL_BASE_URL=https://your-domain.com
SMTP_HOST=smtp.your-provider.com
SMTP_PORT=587
SMTP_USERNAME=your-smtp-username
SMTP_PASSWORD=your-smtp-password
SMTP_ENCRYPTION=tls
SMTP_CA_FILE=
```

Use `tls` for STARTTLS (usually port 587), or `ssl` for implicit TLS (usually port 465), following your provider's instructions. Use `none` only for a trusted local SMTP capture/relay. Keep credentials in `.env` or server environment variables, never in source control. Configure your provider's SPF/DKIM records and sender verification as instructed by that provider.

`MAIL_BASE_URL` must be your public HTTPS application URL, including any application subdirectory. HTTP is accepted only for localhost development. Booking links require the recipient to sign in. To enable existing password recovery through the same mail transport, also set `PASSWORD_RESET_BASE_URL` to your application base URL.

## Schedule the worker

The website queues messages; it does not launch the worker. Schedule it every minute. On Windows, create a Task Scheduler task with:

- Trigger: daily, repeating every 1 minute indefinitely.
- Program: the absolute path to your `php.exe`.
- Arguments: `database/send_booking_emails.php --limit=50`.
- Start in: the absolute project directory, for example `D:\dan\WebApp_Project`.
- Run under an account that can read `.env` and reach MariaDB and the SMTP server. Select “Do not start a new instance” if the task is already running.

On Linux, an example cron entry is:

```cron
* * * * * cd /srv/home2home && /usr/bin/php database/send_booking_emails.php --limit=50 >> /var/log/home2home-booking-mail.log 2>&1
```

Create the log file with appropriate permissions. The worker also uses a database advisory lock so overlapping workers skip delivery. Exit code 0 means success or another worker holds the lock; 1 means configuration, database or delivery failure; 2 means invalid CLI arguments.

## Queue behavior and troubleshooting

- Disabled or incomplete mail configuration leaves the queue untouched. Confirmations still queue while delivery is disabled.
- Failed deliveries retry on later worker runs, up to five attempts per recipient. There is no additional retry delay beyond the worker schedule.
- Cancelled bookings, stays already checked out, inactive/deleted accounts and invalid recipients are skipped and recorded as failed with attempt count 5 and a delivery note.
- “Accepted” means the SMTP transport accepted the message, not guaranteed inbox delivery. Check your provider's delivery logs and spam folder.
- Delivery is at least once: a process crash after SMTP acceptance but before saving the sent status may cause a duplicate on retry. Retries use a stable Message-ID, but inbox deduplication is not guaranteed.
- Transport logs omit credentials, recipients and message contents. For TLS certificate failures, configure PHP's trusted CA bundle or set `SMTP_CA_FILE` to an absolute path to a trusted CA bundle. Do not disable certificate verification.

Inspect queue state from a private database administration session:

```sql
SELECT id, booking_id, delivery_status, attempt_count, body, sent_at
FROM notifications
WHERE channel='email' AND event_type='booking_confirmed'
ORDER BY id DESC LIMIT 50;
```

After fixing a delivery issue, an administrator can deliberately retry one failed row by replacing `123` with its reviewed notification ID:

```sql
UPDATE notifications SET attempt_count=0, delivery_status='pending'
WHERE id=123 AND channel='email' AND event_type='booking_confirmed'
  AND delivery_status='failed';
```

Check whether the recipient already received the email before retrying, since SMTP acceptance may precede a crash. Skipped bookings/accounts must also become eligible before retrying.

To stop delivery, disable the scheduled task and set `MAIL_ENABLED=false`. Existing queue rows remain available. To roll back queue creation as well, an administrator can run `DROP TRIGGER IF EXISTS h2h_booking_confirmation_email`; retain the queue and procedures for inspection. Existing booking procedures are unchanged.

Transport implementation: [PHPMailer](https://github.com/PHPMailer/PHPMailer). Local capture configuration: [Mailpit SMTP documentation](https://mailpit.axllent.org/docs/usage/sending-messages/).
