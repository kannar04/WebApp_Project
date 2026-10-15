# Báo cáo kiểm thử

Ngày kiểm thử: 2026-10-09 (Asia/Saigon).

Phần dưới là snapshot kiểm thử ban đầu. Kết quả cleanup mới và hướng dẫn seed có xác nhận: [codebase-cleanup-report.md](codebase-cleanup-report.md), [database-setup.md](database-setup.md). Đã kiểm thử hai process đồng thời và Chrome 48 page/viewport checks; không nhầm với stress test tải lớn. Import/seed mặc định giờ chặn DB đã có bảng.

## Kết quả đã chạy

| Nhóm | Lệnh/luồng | Kết quả |
|---|---|---|
| PHP syntax | `php -l` toàn bộ file PHP | PASS |
| Schema clean install | `tests/schema_test.php` | PASS — tạo 22 bảng trên DB tạm và cleanup |
| Database connection/seed | `database/import.php --seed-only` | PASS — 3 users, 3 listings |
| Booking integration | `tests/booking_flow_test.php` | PASS |
| HTTP home/detail/quote | `tests/http_smoke.ps1` | PASS sau khi sửa assertion encoding |
| HTTP auth/booking | login Guest → booking → trips | PASS |
| HTTP Host transition | login Host → confirmed | PASS |
| HTTP Guest cancellation | confirmed → cancelled | PASS |
| HTTP Admin user CRUD | create → soft delete → audit | PASS |
| HTTP Host availability | calendar → block date → DB → cleanup | PASS |
| Browser visual | Chrome headless desktop/mobile | PASS — home/login/detail captured |

Booking integration xác minh quote 2 đêm = 2.650.000đ; tạo đúng 2 `booking_nights`; request overlap bị chặn; state `pending → confirmed → cancelled`; tạo đủ 3 events và 3 notifications; refund theo snapshot là 100%; fixture được cleanup.

## Lỗi đã phát hiện và sửa

- Database hiện hữu theo ERD dùng tên cột khác bản phác thảo (`base_nightly_rate`, `policy_snapshot`, `reason`). Code đã được sửa để theo schema hiện hữu.
- Pipe SQL qua Windows PowerShell làm hỏng Unicode. Thay bằng importer PHP đọc trực tiếp UTF-8 và seed lại.
- Smoke test PowerShell đọc literal tiếng Việt theo ANSI. Assertion đổi sang token ASCII; HEX trong DB xác nhận UTF-8 đúng.

## Chưa xác minh

- Visual comparison đã chụp bằng Chrome headless; vẫn chưa xác minh trên thiết bị vật lý.
- SMTP/email delivery vì chưa có cấu hình dịch vụ.
- Load/concurrency nhiều process thực; transaction/locking mới được integration-test tuần tự.
- Các requirement mang trạng thái `IMPLEMENTED`, `IN_PROGRESS`, `NOT_STARTED` trong matrix.

## Quality gate

| Hạng mục | Trạng thái |
|---|---|
| OOP/MVC/separation | PASS |
| Bootstrap 4.6.2/design tokens/responsive rules | PASS (Chrome headless desktop/mobile) |
| Validation/Auth/Authz/CSRF/error handling | PASS cho flow đã test |
| Schema/constraints/prepared statements/transaction | PASS |
| AJAX JSON | PASS cho quote; NOT VERIFIED đầy đủ cho endpoint còn lại |
| Functional/integration/regression | PASS cho core booking/Admin/calendar; PARTIAL Important/Optional |
| Documentation/setup/traceability | PASS |

Ảnh kiểm chứng: [home](screenshots/home.png), [login](screenshots/login.png), [listing](screenshots/listing.png), [home mobile](screenshots/home-mobile.png).

## Kiểm thử sau khi gộp Nice-to-have — 2026-10-13

Lệnh: `C:\xampp\php\php.exe tests\fresh_setup_test.php --quality-regression --no-screenshots`. Test tạo DB và server PHP tạm riêng, chỉ cài test routines tại đó và dọn DB của chính nó; không đụng DB dùng chung. Từ bản này, `fresh_setup_test.php` tự chạy mọi `tests/feature_*_test.php` và `tests/feature_*_audit.ps1`.

Gate được chạy sau mỗi lần gộp: gộp 1 (trust + conversation) 176 dòng PASS; gộp 2 (+ booking extras) 201; gộp 3 (+ discovery) 219; gộp 4 (+ content) 264. Cả bốn lần exit 0, không có dòng FAIL, dòng cuối `PASS exact owned test database cleanup; shared database untouched`. Số dòng PASS của lần gộp 4:

| Suite | Dòng PASS | Nội dung chính |
|---|---|---|
| Setup độc lập (`fresh_setup_test.php`) | 3 | schema/seed chạy lặp, HTTP home/detail/login/quote, đăng nhập Guest/Host/Admin |
| `codebase_cleanup_test.php` | 4 | installer 159 định nghĩa production / 38 test opt-in, zero DROP; 35 bảng schema |
| `stored_procedure_audit_test.php` | 4 | 0 SQL nghiệp vụ trực tiếp; 236 CALL sites / 179 routine signatures |
| `booking_flow_test.php` | 1 | quote → pending → confirmed → cancelled, chặn trùng |
| `source_audit_test.php` | 6 | 96 route handlers, ràng buộc SQL, refund, guard lịch |
| `procedure_flow_test.php` | 7 | quyền routine, savepoint, hai process đặt trùng |
| `quality_core_test.php` | 12 | các chức năng Core/Important |
| `feature_booking_extras_test.php` (mới) | 9 | ngày lễ, giá Host, giá linh hoạt, mã khuyến mãi, booking có mã, Instant Book (kể cả hai process), thanh toán/hoàn tiền, HTTP, dọn fixture |
| `feature_trust_test.php` (mới) | 6 | gửi xác minh, Admin duyệt, huy hiệu Host uy tín, hướng dẫn nhận phòng, HTTP 302/403/404/419, dọn fixture |
| `feature_conversation_test.php` (mới) | 9 | Zalo, tin nhắn/chống dồn/đã đọc, độ dài 1–2000, quyền và cửa sổ 14 ngày, inbox, Admin tìm/ẩn/hiện/audit, nhắc lịch (idempotent, throttle 30 phút, CLI, DB lỗi không làm hỏng trang), HTTP, dọn fixture |
| `feature_discovery_test.php` (mới) | 10 | tọa độ, khung bản đồ (kể cả vượt kinh tuyến 180), khung sai 422, lịch sử xem, gợi ý, lịch sử tìm kiếm (20 lần gần nhất), riêng tư/xóa, HTTP bản đồ, HTTP gợi ý/hồ sơ/xóa, dọn fixture |
| `feature_content_test.php` (mới) | 12 | slug tiếng Việt, chỉ Admin trong SQL, slug trùng, validation SQL, audit xuất bản, danh sách công khai 9 bài/trang, xóa mềm, HTTP công khai/Guest 403/Admin CSRF + upload, dọn fixture |
| `http_smoke.ps1` | 1 | home/detail/quote |
| `http_audit.ps1` | 12 | hành trình HTTP Core |
| `browser_audit.ps1` | 158 | Chrome thật ở 375/768/1024/1440, gồm 17 trang mới × 4 độ rộng; không lỗi JavaScript |
| `feature_content_audit.ps1` (mới) | 9 | theme áp dụng trước lần vẽ đầu, nút chế độ tối desktop/mobile/đăng nhập, tương phản WCAG trên 88 lượt xem trang (Guest 7, Host 8, Admin 19 trang) |
| `feature_i18n_test.php` (mới, gộp sau cùng) | 7 | từ điển (1163 câu, 32 mẫu, 42 câu JS, không xung đột, chỗ trống khớp); bộ dịch; mọi chữ tiếng Việt trong view qua `__()` và JS qua `h2hT()` đều có bản dịch; 302 thông báo PHP + 248 thông báo routine có bản dịch/mẫu; `POST /locale` (CSRF, ngôn ngữ lạ, đường dẫn ngoài site); 80 lượt xem trang tiếng Anh cho 4 vai trò không còn câu chưa dịch |

Test có sẵn đã sửa: `schema_test.php` và `codebase_cleanup_test.php` đổi số bảng 22 → 35; `browser_audit.ps1` chỉ thêm trang mới vào danh sách; `fresh_setup_test.php` tự tìm suite `feature_*` và thêm cờ `--no-screenshots`; `stored_procedure_audit_test.php` bỏ qua thư mục `lang/` (từ điển là chữ hiển thị, không phải câu SQL).

Sau khi gộp giao diện tiếng Việt/tiếng Anh, gate chạy lại: exit 0, 271 dòng PASS, không có dòng FAIL; chạy lại lần nữa sau khi sửa các lỗi từ kiểm thử hành trình (mục dưới): vẫn exit 0, 271 dòng PASS. Số dòng của các suite cũ giữ nguyên như bảng trên; 7 dòng mới là của `feature_i18n_test.php`. Khi đang làm dở một phần bản dịch, chạy riêng bằng biến môi trường `H2H_I18N_SCOPE=<tiền tố đường dẫn, cách nhau bằng dấu phẩy>`: lỗi ngoài phạm vi chỉ được ghi chú, không làm test thất bại. Chạy `feature_i18n_test.php --report-vietnamese` với `HOME2HOME_TEST_URL` để liệt kê chữ tiếng Việt còn lại trên từng trang tiếng Anh: lần chạy ngày 2026-10-13 chỉ còn dữ liệu mẫu (tên chỗ ở, tên người, bài viết, địa chỉ) và nhãn nút “Tiếng Việt”.

Ngoài gate, ngày 2026-10-13 đã thử đường nâng cấp DB cũ (22 bảng) lên 35 bảng trên một DB riêng: dữ liệu 22 bảng cũ giữ nguyên số dòng và CHECKSUM, `tests\schema_test.php --compare-live` PASS 35 bảng, `database\checkin_reminders.php` tạo 2 nhắc lịch rồi 0 ở lần chạy lại. Chi tiết: [database-setup.md](database-setup.md).

### Kiểm thử hành trình Guest/Host/Admin (2026-10-13)

Sau khi gộp giao diện tiếng Việt/tiếng Anh, mỗi vai trò được kiểm thử bằng tay trên một bản sao DB và server riêng, ở cả hai ngôn ngữ (Chrome headless, 375 px và 1440 px, thêm 768–1200 px cho header). Kết quả: 37 lỗi, gồm 0 lỗi P1, 8 lỗi P2 và 29 lỗi P3. Đã sửa trong commit “Sửa lỗi phát hiện khi kiểm thử hành trình Guest/Host/Admin”:

- Chức năng: giá cuối tuần/ngày lễ bằng 0 làm đêm đó miễn phí (nay phải lớn hơn 0 ở controller, routine và ô nhập); booking đã đánh giá vẫn hiện form đánh giá; Admin thấy nút duyệt yêu cầu xác minh của chính mình (luôn bị từ chối); bỏ chọn hết vai trò thì nhật ký ghi rỗng nhưng lưu `guest`; lỗi CSRF làm mất bộ lọc tìm kiếm.
- Hiển thị: mã trạng thái trong CSDL (`pending`, `confirmed`, `visible`…) và mã loại báo cáo hiện thẳng ra giao diện; ngày giờ có micro giây; giờ nhận phòng có giây; booking đã hủy vẫn ghi “Thanh toán khi nhận phòng”; ★ 0.0 cho chỗ ở chưa có đánh giá; thông báo hủy ghi `2080000.00 (100.00%)`.
- Tiếng Anh: nút thao tác trùng chữ trạng thái (“Paid”, “Confirmed”, “Completed”); số thập phân dùng dấu phẩy; tên phụ phí không thống nhất; lỗi mạng hiện chữ của trình duyệt; số ít/số nhiều; chữ bị ngắt giữa từ trong lịch ở 375 px.

Kiểm tra lại sau khi sửa: `feature_i18n_test.php` PASS, gate exit 0 với 271 dòng PASS, và quét HTTP 16 trang của ba vai trò (8 Guest, 3 Host, 5 Admin) trên 3 DB kiểm thử, ở cả hai ngôn ngữ, không còn mã trạng thái thô, ngày ISO, giờ có giây, ★ 0.0, dấu phẩy thập phân trong tiếng Anh hay mã vai trò/loại báo cáo.

Còn lại (có từ trước, chưa sửa):

- Thông báo “Booking #… chuyển sang confirmed.” trong routine `020_booking.sql` vẫn ghi mã trạng thái.
- Chặn ngày cho chỗ ở đã xóa/không tồn tại báo “không thể chặn ngày đã có booking xác nhận” thay vì “không tìm thấy”.
- Booking của chỗ ở đã xóa mềm vẫn có liên kết “Xem chỗ ở công khai”/“Tạo hướng dẫn nhận phòng” dẫn tới trang 404.
- Danh sách chính sách hủy sắp theo tên (Linh hoạt, Nghiêm ngặt, Trung bình); dòng số tiền hoàn ghi “VND” trong khi nơi khác ghi “đ”; giá trên ghim bản đồ tiếng Anh dạng `2,1M`.
- Thông báo cũ đã lưu trước khi sửa vẫn giữ định dạng số cũ (không sửa dữ liệu đã có).

### Chưa xác minh (Nice-to-have)

- Thao tác kéo bản đồ và bản đồ nhỏ ở trang chi tiết: chỉ kiểm tra thủ công bằng Chrome (CDP) trong báo cáo nhánh discovery, không có test tự động; tile cần Internet.
- Lịch Windows Task Scheduler và cron chưa được tạo/chạy thử; cron chưa thử trên Linux.
- Nâng cấp qua giao diện phpMyAdmin chưa chạy thử (đã thử bằng `mysql.exe ... source`).
- VNPay/MoMo chưa tích hợp (đã bổ sung VietQR + VNPay sandbox ngày 2026-10-15; phần chưa xác minh của thanh toán online ở mục dưới).
- Giao diện tiếng Anh: test tự động kiểm tra các trang GET và thông báo theo từ điển. Thao tác POST (đặt chỗ, hủy, duyệt, thu tiền/hoàn tiền, lưu form lỗi, tin nhắn, báo cáo, xác minh) được kiểm thử theo hành trình Guest, Host và Admin ở cả hai ngôn ngữ, mỗi vai trò trên một DB/server riêng, ở 375 px và 1440 px; không có test tự động riêng cho các thao tác này.
- Các kịch bản click-through thủ công trong báo cáo từng nhánh chưa được chạy lại sau khi gộp; kết quả sau gộp dựa trên test tự động.

## Thanh toán online (VietQR + VNPay sandbox) — 2026-10-15

Lệnh như trên: `C:\xampp\php\php.exe tests\fresh_setup_test.php --quality-regression --no-screenshots`. Kết quả: exit 0, **277 dòng PASS** (271 cũ + 6 của suite mới), không có dòng FAIL hay SKIP, dòng cuối `PASS exact owned test database cleanup; shared database untouched`. `fresh_setup_test.php` truyền cho server tạm một tài khoản nhận tiền giả và một `VNPAY_HASH_SECRET` sinh ngẫu nhiên mỗi lần chạy, nên gate không đọc thông tin ngân hàng hay khóa VNPay trong `.env` của máy chạy.

| Suite | Dòng PASS | Nội dung chính |
|---|---|---|
| `codebase_cleanup_test.php` | 4 | installer 167 định nghĩa production / 41 test opt-in, zero DROP; 36 bảng schema |
| `stored_procedure_audit_test.php` | 4 | 0 SQL nghiệp vụ trực tiếp; 248 CALL sites / 190 routine signatures |
| `feature_payment_test.php` (mới) | 6 | quyền (chỉ Guest, chỉ booking confirmed, chỉ VietQR/VNPay); VietQR (một mã cho mỗi số tiền, khách báo đã chuyển + thông báo Admin, từ chối có lý do, trả lại, Admin xác nhận, audit); kết quả VNPay (mã lạ, sai số tiền, booking đã hủy, thành công một lần, gọi lại an toàn, tiền về trùng hoặc sau khi hủy thì báo Admin hoàn tiền); ký VNPay (giờ GMT+7, số tiền ×100, HMAC-SHA512, chặn sửa tham số/giả chữ ký/sai merchant) và link ảnh VietQR; HTTP (trang chỉ cho Guest, CSRF, trang QR có số tài khoản và mã, báo chuyển khoản, hàng chờ Admin/403/xác nhận, chữ ký link chuyển sang VNPay, return/IPN giả trả 97, return hợp lệ, IPN 02/01/04); dọn fixture |
| `feature_i18n_test.php` | 7 | thêm câu tiếng Anh cho trang thanh toán và thông báo mới; 332 thông báo PHP + 291 thông báo routine có bản dịch/mẫu; 89 trang tiếng Anh không còn câu chưa dịch |

Test có sẵn đã sửa: `schema_test.php` và `codebase_cleanup_test.php` đổi số bảng 35 → 36; `fresh_setup_test.php` thêm biến môi trường thanh toán giả cho server tạm. Số dòng của các suite còn lại giữ nguyên.

Kiểm thử thủ công 2026-10-17 trên `db_home2home` máy phát triển, với merchant VNPay sandbox đăng ký riêng (mã TMN và secret chỉ nằm trong `.env`): một booking trả qua VNPay bằng thẻ test NCB, VNPay trả mã `00` kèm mã giao dịch và booking chuyển “Đã thanh toán” (VNPay) qua return URL; một booking khác khách báo chuyển khoản VietQR, Admin xác nhận ở `/admin/payments`, booking chuyển “Đã thanh toán” (VietQR). Ở cả hai booking, lần thanh toán còn mở bằng cách kia tự đóng. Booking VietQR sau đó được khách hủy trong vòng 24 giờ trước giờ nhận phòng: chính sách Linh hoạt hoàn 0đ nên không cần hoàn tiền.

Chưa xác minh và giới hạn:

- Test tự động chỉ giả lập kết quả VNPay có chữ ký đúng theo tài liệu tích hợp, không gọi tới máy chủ VNPay; vòng thanh toán thật chỉ được chạy thủ công (đoạn trên).
- Khách hủy booking đã thanh toán online khi chính sách còn hoàn tiền: số tiền hoàn được tính và lưu như mọi lần hủy, nhưng Admin không nhận thông báo riêng; Admin hoàn tiền thủ công ở `/admin/payments` (ghi nhận toàn bộ số đã thu).
- IPN cần địa chỉ công khai; trên localhost kết quả chỉ được ghi qua return URL có chữ ký.
- Chưa quét mã VietQR bằng app ngân hàng thật; test kiểm tra link ảnh `img.vietqr.io` và nội dung trang, không tải ảnh (ảnh cần Internet).
- MoMo/ZaloPay không có cổng riêng; khách dùng các app này để quét mã VietQR.
