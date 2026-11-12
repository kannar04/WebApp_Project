# WebApp_Project

## Tên đề tài

** Ứng dụng home 2 home chia sẻ chổ ở, airBnB **

## Các thành viên

| MSSV        | Họ và tên                      |
| ----------- | ------------------------------ |
| 31241021765 | Lê Trung Phong *(Nhóm trưởng)* |
| 31241020790 | Đỗ Sơn Thành                   |
| 31241020025 | Nguyễn Tuấn Khôi               |
| 31241025109 | Nguyễn Minh Thức               |

## Local development

Home2Home is a PHP application backed by MySQL/MariaDB. Install PHP with PDO MySQL, mbstring, OpenSSL and fileinfo, plus Composer.

```powershell
composer install
Copy-Item .env.example .env
php -S 127.0.0.1:8090 -t public public/router.php
```

Copy the environment template only on a fresh checkout. Configure the database connection in `.env` and provision the application's database before using it. This repository contains incremental migrations, not a complete initial database dump. Keep credentials and uploaded files out of Git.

Application code lives in `app/`, framework helpers in `core/`, configuration in `config/`, routes in `routes/`, translations in `lang/`, and web assets in `public/`. Database migrations, installers and scheduled jobs live in `database/`. `demo_stays/` supplies the optional Airbnb demo seed scripts; `image/` contains project diagrams. Third-party asset notices and licenses remain alongside those assets.

## Booking email

Configure `MAIL_ENABLED`, sender and SMTP settings in `.env`. Install the booking email database changes before sending queued messages:

```powershell
php database/install_booking_emails.php
php database/send_booking_emails.php --test=guest@example.com
php database/send_booking_emails.php --limit=50
```

Confirmed bookings queue separate guest and host messages. Failed delivery leaves messages available for retry and does not undo the booking. Use a local mail catcher or your provider's SMTP credentials. Set `MAIL_BASE_URL` to the application's public address.

## Checks

```powershell
php tests/vnpay_gateway_test.php
php tests/debug_verification_test.php
```

Additional PHP and browser checks are in `tests/`; inspect each check's prerequisites before running it against a database. Local recovery snapshots, when present, are kept in the ignored `.local-backups/` directory.
