# CAPTCHA ảnh PNG cho đăng nhập và thanh toán

CAPTCHA được tạo trực tiếp bằng PHP GD và lưu trong session, theo quy trình `$alphabet` → `makeCaptcha($source, $len)` → session → `setPNGHeader()` → `makePNGCaptcha($captcha)` → đối chiếu câu trả lời. Không cần tài khoản, domain, API key hoặc kết nối đến dịch vụ CAPTCHA bên ngoài.

## Thiết lập

PHP cần bật extension **GD** và session hoạt động bình thường. Kiểm tra:

```powershell
php -m
```

Nếu chưa có `gd`, bật `extension=gd` trong `php.ini` của PHP đang chạy website, rồi khởi động lại PHP/Apache. Với PHP hiện tại của dự án, GD đã được bật. Không cần migration hoặc cấu hình `.env` cho CAPTCHA. Các biến `TURNSTILE_*` cũ đã được bỏ.

## Đối chiếu với yêu cầu

Mã nguồn chính: `app/Services/Captcha.php`.

1. **Bảng ký tự:** `$alphabet` lấy từ `Captcha::ALPHABET`, gồm `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`. Bỏ `I`, `O`, `0`, `1` để dễ đọc.
2. **Tạo chuỗi:** `makeCaptcha($source, $len)` dùng `random_int()` chọn ngẫu nhiên 6 ký tự.
3. **Lưu session:** `issue($action)` lưu chuỗi, mục đích sử dụng và thời hạn trong `$_SESSION['_captchas'][$id]`. Mỗi form/tab có ID ngẫu nhiên riêng.
4. **Vẽ ảnh:** trình duyệt tải `/captcha/image?id=...`; `image()` gọi `setPNGHeader()` rồi `makePNGCaptcha($captcha)` để xuất PNG 220 × 72, với ký tự và nhiễu nhẹ. Dùng font đi kèm GD, không cần tải font. Câu trả lời không xuất hiện trong HTML, URL, alt text hoặc JSON.
5. **Kiểm tra:** `ensure($action)` lấy `captcha_id` và `captcha_answer` từ POST, so sánh với session bằng `hash_equals()`. Không phân biệt chữ hoa/thường. Mã hết hạn sau 5 phút và được xóa sau mỗi lần kiểm tra, kể cả nhập sai.

Nút **Đổi mã** gửi AJAX `POST /captcha/refresh` kèm CSRF token. Server chỉ đổi CAPTCHA thuộc session hiện tại; mã cũ bị vô hiệu hóa, form cập nhật ảnh và xóa câu trả lời cũ. Nếu không bật JavaScript, tải lại trang để lấy CAPTCHA khác; nhập và gửi form vẫn hoạt động.

## Phạm vi

| Form | Mục đích trong session |
| --- | --- |
| Đăng nhập email/mật khẩu | `login` |
| Tạo/mở giao dịch VietQR | `pay_vietqr` |
| Chuyển sang VNPay | `pay_vnpay` |
| Báo đã chuyển khoản | `report_transfer` |

Kiểm tra CAPTCHA diễn ra trước xác thực mật khẩu hoặc thay đổi giao dịch thanh toán. Đăng nhập Google không yêu cầu CAPTCHA; vẫn kiểm tra CSRF và xác thực OAuth. Callback/IPN VNPay dùng xác thực chữ ký của cổng thanh toán; không yêu cầu người dùng nhập CAPTCHA. Các thao tác quản trị/host giữ kiểm tra quyền và CSRF hiện tại.

## Kiểm thử thủ công

1. Mở `/login`, nhập mã trong ảnh và đăng nhập. Thử nhập chữ thường.
2. Nhập sai, bỏ trống hoặc dùng lại mã đã gửi: yêu cầu phải bị từ chối và form tải lại có ảnh mới.
3. Nhấn **Đổi mã**: chỉ ảnh/mã trong form được cập nhật, trang không tải lại. Mã cũ không còn dùng được.
4. Mở hai tab đăng nhập: mỗi tab có mã độc lập. Mã của tab này không xác nhận form tab khác.
5. Mở thanh toán booking hợp lệ: VietQR và VNPay có CAPTCHA riêng. CAPTCHA sai không tạo giao dịch; báo chuyển khoản sai CAPTCHA không đổi trạng thái giao dịch.
6. Kiểm tra Network: ảnh có `Content-Type: image/png` và `Cache-Control: private, no-store, max-age=0`; không còn request đến Cloudflare.

Đây là CAPTCHA ảnh tự tạo để phục vụ demo/yêu cầu môn học. Khả năng chống bot/OCR có giới hạn; không phải là xác nhận đã nhận tiền. Người dùng cần đọc được hình ảnh để nhập mã; hiện chưa có CAPTCHA âm thanh.
