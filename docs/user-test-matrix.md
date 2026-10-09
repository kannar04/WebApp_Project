# Home2Home — User Test Matrix

Môi trường: PHP 8.2.12, MySQL/XAMPP, `http://127.0.0.1:8090`, ngày 2026-10-09.

| Persona | Kịch bản | Kỳ vọng | Kết quả |
|---|---|---|---|
| Public | Mở trang chủ và listing chi tiết | HTTP 200, có dữ liệu listing và booking box | PASS |
| Public | Quote listing hợp lệ | JSON success, tổng tiền đúng snapshot giá | PASS |
| Public | Search checkout trước checkin | API 422, không đưa ngày lỗi xuống SQL | PASS |
| Guest | Truy cập `/bookings` khi chưa login rồi đăng nhập | Trở lại `/bookings` | PASS |
| Guest | Login bằng tài khoản Guest | Về trang chủ | PASS |
| Host | Login bằng tài khoản Host | Về `/host` | PASS |
| Admin | Login bằng tài khoản Admin | Về `/admin` | PASS |
| Host | Mở dashboard | Có link lịch khả dụng trên listing | PASS |
| Host | Mở form sửa listing #1 | 4 tiện nghi đã lưu được checked | PASS |
| Host | Seed lại dữ liệu danh mục | 3/3 loại phòng và 6/6 tiện nghi active | PASS |
| Auth user | POST form với CSRF hết hạn | Quay lại form, có thông báo phục hồi | PASS |
| Authorization | Guest/Host/Admin qua các route theo role | Guard role/ownership áp dụng ở controller | PASS (integration + code review) |
| Booking | Quote → pending → confirmed → cancelled | Chuyển trạng thái hợp lệ; chặn double booking | PASS |
| Database | Tạo schema sạch | Đủ 22 bảng | PASS |
| PHP | Lint toàn bộ source | Không lỗi cú pháp | PASS (50 files) |

## Lệnh kiểm thử

```powershell
C:\xampp\php\php.exe tests\schema_test.php
C:\xampp\php\php.exe tests\booking_flow_test.php
powershell -ExecutionPolicy Bypass -File tests\http_smoke.ps1
```

Các test tạo dữ liệu booking dùng fixture/transaction riêng và dọn dữ liệu kiểm thử; không xóa dữ liệu người dùng ngoài phạm vi fixture.
