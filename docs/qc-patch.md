# Bản vá QC — 09/11/2026

## Debug xác minh trên máy local

Đặt `DEBUG_VERIFICATION_ENABLED=true` cùng `APP_ENV=local` trong `.env`. Truy cập qua `127.0.0.1` hoặc `localhost`, mở **Hồ sơ → Debug xác minh (local)**, chọn **Bật các nút xác minh debug** rồi **Lưu chế độ debug**. Hai nút cho phép mô phỏng email/SĐT và duyệt CCCD riêng biệt. Cần lưu số điện thoại hợp lệ trong hồ sơ trước khi dùng.

Mô phỏng chỉ có hiệu lực cho tài khoản và số điện thoại trong phiên hiện tại, không ghi dữ liệu xác minh vào CSDL. Tắt toggle, đổi số điện thoại hoặc đăng xuất sẽ loại bỏ hiệu lực mô phỏng. Endpoint từ chối khi môi trường không phải local, cấu hình tắt hoặc request không đến từ loopback. Không cần migration. Đặt `DEBUG_VERIFICATION_ENABLED=false` để ẩn toàn bộ tính năng. Kiểm tra bằng `php tests/debug_verification_test.php` trên CSDL local đã có tài khoản với số điện thoại hợp lệ.

## Cài đặt trước khi đưa code lên môi trường chạy

Chạy migration trên CSDL của ứng dụng bằng thông tin trong `.env`:

```powershell
php database/install_qc_verification.php --check
php database/install_qc_verification.php
```

Migration thêm bốn bảng và mười stored procedure cho xác minh liên hệ và tin nhắn riêng. Không sửa mật khẩu, vai trò hay trạng thái của tài khoản hiện có. Có thể chạy lại script. Cần quyền tạo bảng và routine; đây là migration MariaDB, theo quy ước `CREATE OR REPLACE PROCEDURE` hiện có của dự án. Triển khai schema trước code vì hồ sơ, hộp thư và luồng đặt phòng dùng các routine mới.

Không có cơ chế bỏ qua xác minh khi dịch vụ chưa cấu hình. Khi thiếu dịch vụ email, đăng ký mới báo lỗi rõ ràng và không tạo tài khoản chưa xác minh. Tài khoản cũ vẫn đăng nhập được, nhưng cần xác minh email và SMS trước lần đặt phòng tiếp theo. Xác minh giấy tờ hiện có được giữ riêng; nó không thay thế xác minh quyền sở hữu số điện thoại qua SMS.

### Dịch vụ cần cấu hình

- Email: dùng `MAIL_ENABLED`, `MAIL_FROM`, `MAIL_TRANSPORT` và nhóm `SMTP_*` đang có. Mã gửi tới địa chỉ email người dùng nhập, bao gồm Gmail; không giới hạn nhà cung cấp email.
- SMS: điền `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_VERIFY_SERVICE_SID` vào `.env`. Adapter dùng [Twilio Verify](https://www.twilio.com/docs/verify/api/verification) để gửi SMS và [Verification Check](https://www.twilio.com/docs/verify/api/verification-check) để kiểm tra mã. Không ghi mã SMS hoặc thông tin xác thực vào log.
- Tìm địa chỉ: dùng `GEOAPIFY_API_KEY` hiện có; endpoint mới dùng [Geoapify Forward Geocoding](https://apidocs.geoapify.com/docs/geocoding/forward-geocoding/). Khóa chỉ ở phía server. Khi thiếu khóa, host vẫn có thể chọn vị trí trên bản đồ.
- Thanh toán: dùng nhóm `PAYMENT_BANK_*`, `PAYMENT_ACCOUNT_*` cho VietQR hoặc `VNPAY_*` cho VNPay. Bản vá dùng luồng thanh toán hiện có, không giả lập thanh toán thành công khi chưa cấu hình.

## Kết quả theo yêu cầu QC

| Yêu cầu | Hành vi sau bản vá |
| --- | --- |
| Bấm xem ảnh | Bấm ảnh trên trang chi tiết hoặc quản lý ảnh để phóng lớn; có ảnh trước/tiếp, phím mũi tên, Escape và trả focus về nút ảnh. |
| Host tải nhiều ảnh | Chọn nhiều ảnh trong một lần, tối đa 20 ảnh/lần, 5 MB/ảnh, tối đa 100 ảnh/tin. Nếu một ảnh lỗi hoặc lưu CSDL thất bại, ảnh mới của lần lưu đó được dọn và dữ liệu được rollback. |
| Map theo địa chỉ | Host nhập địa chỉ/thành phố rồi chọn kết quả tìm kiếm; tọa độ nằm trong mục nâng cao. Khách có mục tìm khu vực theo địa chỉ trên `/map`, chọn kết quả để tìm chỗ ở quanh đó. |
| Preview khách vãng lai | Người chưa đăng nhập xem chi tiết công khai, ảnh, lịch, báo giá và chọn “Xem trước chuyến đi”; trang xem lại giải thích việc đăng nhập trước khi gửi yêu cầu. Không công khai tin chưa duyệt. |
| Phân biệt trạng thái | Yêu cầu đặt phòng dùng “Chờ chủ nhà xác nhận”; kiểm duyệt tin đăng giữ trạng thái riêng. Lịch cũng nói rõ yêu cầu đang chờ xác nhận vẫn giữ chỗ. |
| Tin nhắn AJAX | Trang `/messages/{booking}` gửi không tải lại và lấy cập nhật mỗi 4 giây khi tab đang hiển thị; lỗi gửi giữ bản nháp, không có banner “Đã gửi tin nhắn”. |
| Hộp thư riêng | Chủ nhà có thể được nhắn trước khi đặt phòng; liên hệ này tồn tại độc lập với thời hạn trò chuyện theo booking. Hộp thư lưu lịch sử và có nút nhắn quản trị viên. |
| Điều hướng | Nút thông báo xuất hiện trực tiếp trên header và trong không gian host; nút hộp thư nổi dẫn tới lịch sử. Chi tiết đặt phòng dẫn sang trang trò chuyện riêng. |
| Chi tiết và quyết định | Bấm nền dòng tin/đặt phòng để mở chi tiết; liên kết tên vẫn dùng được bằng bàn phím. Duyệt/từ chối tin và nhập lý do nằm trong chi tiết tin; quyết định đặt phòng và ghi nhận thu tiền nằm trong chi tiết đặt phòng. |
| Email/SMS | Đăng ký bằng mật khẩu chỉ tạo tài khoản sau mã email hợp lệ. Mã email hết hạn sau 10 phút, tối đa 5 lần nhập sai, gửi lại sau 60 giây. Trong hồ sơ: xác minh email trước, sau đó nhận/nhập mã SMS. Đổi số điện thoại làm số mới chưa được xác minh. |
| Điều kiện đặt phòng | Kiểm tra ở cả giao diện và service tạo booking; chưa xác minh sẽ có thông báo và liên kết tới phần xác minh hồ sơ. |

## Thanh toán ở đâu và ai xác nhận?

Khách vào **Chuyến đi → Chi tiết đặt phòng → Thanh toán online**, hoặc bấm **Thanh toán online** ngay trên thẻ chuyến đi. Nút xuất hiện khi booking đủ điều kiện thanh toán, thông thường sau khi chủ nhà xác nhận. Nếu chưa cấu hình phương thức online, trang thanh toán giải thích tình trạng và hướng dẫn thanh toán khi nhận phòng.

Với thanh toán trực tiếp: **Host → Yêu cầu đặt chỗ → Chi tiết → Tình trạng thanh toán → Đã thu tiền**. Chỉ xác nhận sau khi thực sự nhận tiền. Luồng hiện có chỉ cho ghi nhận từ ngày nhận phòng; có ghi chú thu tiền, thời điểm và người xác nhận. Bản vá đưa thao tác này vào chi tiết để tránh bấm nhầm từ bảng tổng quan.

Với VietQR: khách báo đã chuyển khoản; **Admin → Thanh toán** hoặc chi tiết booking để đối soát và xác nhận. Host được cảnh báo chờ đối soát, tránh thu thêm lần hai. Với VNPay: giữ kiểm tra chữ ký, số tiền và trạng thái ở luồng return/IPN hiện có.

## Kiểm tra và giới hạn xác minh

Đã chạy kiểm tra cú pháp PHP, JavaScript và kiểm tra độc lập:

```powershell
php tests/qc_patch_test.php
php database/install_qc_verification.php --check
php tests/qc_ui_fixture.php
```

Kiểm tra trình duyệt dùng Playwright có sẵn hoặc được cung cấp qua `NODE_PATH`:

```powershell
php -S 127.0.0.1:8091 -t public public/router.php
# Trong terminal khác, sau khi tạo fixture:
node tests/qc_ui_test.cjs
```

Nếu cần dùng Chromium đã cài riêng, đặt biến `QC_CHROMIUM` tới file thực thi. Fixture chỉ chạy bằng CLI, ghi HTML/ảnh kiểm tra vào thư mục tạm `h2h-qc-ui`, không tạo endpoint thử nghiệm công khai. Bài kiểm tra dùng phản hồi API giả lập, không gửi email/SMS, không gọi geocoding trả phí và không tạo giao dịch thanh toán.

Đã qua kiểm tra trình duyệt ở chiều rộng 1440 và 390 px: nội dung tin nhắn độc hại được hiển thị dạng văn bản, lỗi gửi giữ nháp, gửi thành công cập nhật lịch sử và xóa nháp, chọn địa chỉ điền tọa độ, chọn hai file ảnh, phóng ảnh/chuyển ảnh/Escape, không tràn ngang và không có lỗi JavaScript. Detector giao diện chỉ báo ba đường viền nhấn đã tồn tại trước bản vá trong `app.css`.

**Chưa xác minh được tích hợp CSDL và dịch vụ thật:** CSDL được cấu hình trong `.env` không kết nối được ở phiên làm việc này; local MySQL cũng không sẵn sàng. Migration mới chỉ được kiểm tra phân tách câu lệnh, chưa thực thi để xác nhận schema/routine. Cần chạy migration và kiểm tra trên CSDL thử nghiệm trước triển khai.

Các tình huống QC cần chạy khi dịch vụ sẵn sàng: đăng ký → email sai/đúng/hết hạn/dùng lại; resend và nhập sai 5 lần; SMS sai/đúng/đổi số; POST đặt phòng trực tiếp khi chưa xác minh; người ngoài truy cập hộp thư; host/khách/admin trao đổi từ hai phiên; upload một ảnh lỗi giữa batch; duyệt/từ chối tin; thu tiền trước/sau ngày nhận phòng; VietQR đối soát và VNPay callback hợp lệ/sai chữ ký/lặp lại. Các luồng thanh toán thật chưa được thử trong phiên này.
