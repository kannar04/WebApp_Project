# Home2Home

**Đề tài:** Xây dựng website chia sẻ nhà ở Home-to-Home

**Học phần:** Phát triển Ứng dụng Web, Trường Công nghệ và Thiết kế, Đại học Kinh tế TP. Hồ Chí Minh

**Giảng viên hướng dẫn:** TS. Đặng Ngọc Hoàng Thành

## Thành viên nhóm

| MSSV | Họ và tên | Vai trò |
| --- | --- | --- |
| 31241021765 | Lê Trung Phong | Nhóm trưởng |
| 31241020790 | Đỗ Sơn Thành | Thành viên |
| 31241020025 | Nguyễn Tuấn Khôi | Thành viên |
| 31241025109 | Nguyễn Minh Thức | Thành viên |

## Giới thiệu đồ án

Home2Home là website kết nối khách cần chỗ ở ngắn hạn với chủ nhà có chỗ cho thuê. Khách có thể tìm nơi ở phù hợp theo điểm đến, ngày lưu trú, số khách và ngân sách. Chủ nhà có thể đăng chỗ ở, quản lý lịch trống và theo dõi đơn đặt phòng. Quản trị viên duyệt nội dung và xử lý các yêu cầu phát sinh trong quá trình vận hành.

| Vai trò | Chức năng chính |
| --- | --- |
| Khách (Guest) | Tìm và lọc chỗ ở, xem bản đồ và chi tiết, lưu yêu thích, đặt phòng, theo dõi thanh toán, nhắn tin và đánh giá sau chuyến đi. |
| Chủ nhà (Host) | Đăng và cập nhật chỗ ở, ảnh, giá, lịch trống và hướng dẫn nhận phòng. Quản lý đơn đặt, trả lời đánh giá và theo dõi các khoản chi trả. |
| Quản trị viên (Admin) | Quản lý tài khoản, duyệt yêu cầu trở thành chủ nhà và tin đăng, theo dõi đơn đặt và thanh toán, xử lý báo cáo và quản lý bài viết. |

## Cài đặt trên máy cá nhân

Các lệnh dưới đây dùng Windows PowerShell.

### 1. Chuẩn bị

- Cài Git, PHP, Composer và MariaDB. Có thể dùng PHP 8.4. Bật các phần mở rộng PHP `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl` và `gd`. CAPTCHA ảnh khi đăng nhập sử dụng GD.
- Dùng `php -v`, `php -m` và `composer --version` để kiểm tra môi trường.
- Lấy **bản SQL nền Home2Home** từ nhóm phát triển. Bản này cần có lược đồ và các thủ tục lưu trữ. Kho mã nguồn chỉ chứa các bản cập nhật trong [database/migrations](database/migrations/). Ưu tiên bản SQL đã cập nhật theo phiên bản mã nguồn đang chạy.

### 2. Tải mã nguồn và cài thư viện

```powershell
git clone https://github.com/kannar04/WebApp_Project.git
cd WebApp_Project
composer install
```

Nếu đã tải mã nguồn, mở PowerShell tại thư mục dự án và chạy `composer install`.

### 3. Khởi tạo cơ sở dữ liệu và cấu hình kết nối

1. Khởi động MariaDB. Trong công cụ quản trị cơ sở dữ liệu hoặc trình dòng lệnh MariaDB, tạo cơ sở dữ liệu bằng lệnh sau.

   ```sql
   CREATE DATABASE db_home2home CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Nhập bản SQL nền vào `db_home2home` bằng công cụ quản trị hoặc lệnh `SOURCE` của trình dòng lệnh MariaDB. Nếu bản SQL nền cũ hơn mã nguồn, áp dụng các bản cập nhật còn thiếu theo phiên bản mà nhóm cung cấp. Một số bản cập nhật dùng cú pháp MariaDB như `CREATE OR REPLACE PROCEDURE`.
3. Tạo tệp `.env` khi cài lần đầu. Nếu tệp đã có, mở tệp hiện tại để chỉnh sửa.

   ```powershell
   Copy-Item .env.example .env
   ```

4. Điền tên cơ sở dữ liệu và thông tin tài khoản theo máy đang dùng. Ví dụ cho MariaDB chạy trên cùng máy với cổng mặc định:

   ```dotenv
   APP_ENV=local
   APP_DEBUG=false
   APP_URL=http://127.0.0.1:8090
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=db_home2home
   DB_USERNAME=root
   DB_PASSWORD=
   ```

   Điền mật khẩu thực tế vào `DB_PASSWORD` và giữ tệp `.env` ngoài Git. Tài khoản kết nối cần có quyền truy cập cơ sở dữ liệu và gọi các thủ tục lưu trữ của ứng dụng.

### 4. Khởi chạy website

Tại thư mục gốc của dự án, chạy:

```powershell
php -S 127.0.0.1:8090 -t public public/router.php
```

Mở [Home2Home trên máy cá nhân](http://127.0.0.1:8090). Kiểm tra trang chủ, tìm kiếm và mở chi tiết một chỗ ở nếu cơ sở dữ liệu đã có tin đăng. Nhấn `Ctrl+C` trong PowerShell để dừng máy chủ.

### 5. Cấu hình dịch vụ theo tính năng cần dùng

Dùng [.env.example](.env.example) để tra các biến cấu hình.

| Tính năng | Cấu hình |
| --- | --- |
| Đăng ký bằng email, khôi phục mật khẩu và gửi thư đặt phòng | Đặt `MAIL_ENABLED=true`, cấu hình địa chỉ gửi, các biến `SMTP_*` và đặt `MAIL_BASE_URL` theo địa chỉ website đang chạy. |
| Xác minh số điện thoại trước khi đặt phòng | Điền `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN` và `TWILIO_VERIFY_SERVICE_SID`. |
| Đăng nhập Google hoặc Facebook | Điền thông tin ứng dụng và đăng ký đúng địa chỉ callback tương ứng. Bật `FACEBOOK_ENABLED` nếu dùng Facebook. |
| Hiển thị dịch vụ lân cận trên trang chi tiết | Điền `GEOAPIFY_API_KEY`. |
| Thanh toán trực tuyến | Điền thông tin tài khoản nhận chuyển khoản cho VietQR hoặc thông tin đơn vị thanh toán thử nghiệm cho VNPay. |

Nếu bản SQL nền chưa có hàng đợi thư đặt phòng, chạy lệnh cài đặt dưới đây. Sau khi thiết lập SMTP, dùng địa chỉ email của bạn để gửi thử, rồi chạy lệnh xử lý hàng đợi khi cần.

```powershell
php database/install_booking_emails.php
php database/send_booking_emails.php --test=guest@example.com
php database/send_booking_emails.php --limit=50
```

## Hướng dẫn sử dụng

1. **Khách:** Từ trang chủ, nhập điểm đến, ngày nhận và trả phòng, số khách rồi chọn chỗ ở. Đăng ký và xác minh email, sau đó xác minh số điện thoại trong hồ sơ trước khi đặt. Kiểm tra lịch, giá và thông tin đơn trên trang xác nhận. Vào **Chuyến đi** (`/bookings`) để xem trạng thái, thanh toán khi có phương thức khả dụng và đánh giá sau khi chuyến đi hoàn tất.
2. **Chủ nhà:** Đăng nhập, vào **Hồ sơ** (`/profile`) và gửi yêu cầu trở thành chủ nhà. Sau khi được duyệt, vào trang **Chủ nhà** (`/host`), tạo tin đăng, tải ảnh, đặt giá và cập nhật lịch trống. Dùng trang quản lý để theo dõi tin và xử lý các đơn đặt phòng.
3. **Quản trị viên:** Đăng nhập bằng tài khoản đã có quyền quản trị và mở `/admin`. Duyệt yêu cầu trở thành chủ nhà và tin đăng. Dùng các mục quản lý tương ứng để xử lý đơn đặt, thanh toán, đánh giá, báo cáo và bài viết.

Để trình diễn cả ba vai trò, chuẩn bị một tài khoản quản trị từ bộ dữ liệu của nhóm và dùng hai tài khoản riêng cho khách và chủ nhà.

## Mã nguồn

Ứng dụng dùng PHP theo mô hình MVC, PDO để kết nối cơ sở dữ liệu và Composer để quản lý thư viện PHP. Giao diện sử dụng HTML, CSS và JavaScript, hỗ trợ tiếng Việt và tiếng Anh.

| Thư mục | Nội dung |
| --- | --- |
| [app](app/) | Controller, model, service và giao diện. |
| [core](core/) | Khởi động ứng dụng, định tuyến, xác thực, phiên đăng nhập và kết nối cơ sở dữ liệu. |
| [config](config/), [routes](routes/), [lang](lang/) | Cấu hình, khai báo đường dẫn và bản dịch. |
| [public](public/) | Điểm vào website, tài nguyên giao diện và thư mục tải lên. |
| [database](database/), [demo_stays](demo_stays/) | Bản cập nhật SQL, tác vụ nền và dữ liệu hỗ trợ tạo chỗ ở mẫu. |
| [tests](tests/) | Các kiểm tra PHP và trình duyệt. |

## Kiểm tra nhanh

```powershell
composer check-platform-reqs
php tests/vnpay_gateway_test.php
php tests/debug_verification_test.php
```

Xem điều kiện chạy của từng bài kiểm tra trước khi chạy các bài cần cơ sở dữ liệu hoặc trình duyệt.
