# Home2Home — User Test Matrix

Latest Core follow-up (2026-10-09): the rows below are historical. Current 94-group implementation/verification, owned DB/server regression, new feature cases and blocked email delivery are in [requirements matrix](quality/requirements-matrix.md) and [final verification](quality/final-verification.md). No implicit PASS for old rows beyond the explicitly rerun suites.

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

## Additional source audit cases — 2026-10-09

| Case | Evidence | Result |
|---|---|---|
| Host invalid price/amenity and disguised JPEG upload preserve DB | tests/http_audit.ps1 | PASS |
| Admin approval/rejection event CHECK, self-role removal and duplicate email | tests/http_audit.ps1 + tests/source_audit_test.php | PASS |
| Anonymous/invalid favorite/invalid CSRF JSON responses | tests/http_audit.ps1 | PASS |
| Booking/calendar block, future completion and pending refund | tests/source_audit_test.php | PASS |
| Quote slow earlier response, error-to-success, cleared dates | tests/browser_audit.ps1 | PASS |
| Chrome home/detail 375/768/1024/1440 and Guest/Host/Admin pages | tests/browser_audit.ps1 | PASS |
| Database outage recovery HTML/API | tests/db_outage_audit.ps1 | PASS |
| Image seed repeated without duplicates and local JPEG references | database/seeds/seed_listing_images.php + tests/source_audit_test.php | PASS |
| Multi-process concurrency stress / full keyboard and screen reader audit | Not executed | NOT_VERIFIED |

Full scope and limitations: [syntax-audit-report.md](syntax-audit-report.md).

## Stored Procedure regression matrix — 2026-10-09

Bảng này cập nhật kết quả sau refactor; các số liệu trong phần cũ là snapshot của lần audit trước. [SQL mapping and limits](stored-procedure-audit.md).

| Case ID | Role / journey | Executed evidence | Result |
|---|---|---|---|
| SP-01 | Source SQL compliance / signatures | stored_procedure_audit_test.php: 0 business SQL, 106 CALL sites, 78 live signatures | PASS |
| SP-02 | Multi-result / cursor release | 100 sequential CALLs, caller deliberately reads only one row | PASS |
| SP-03 | Legacy Unicode city + notifications | Named-param fixes; actual city lookup; nonexistent notification returns 0 | PASS |
| SP-04 | Fresh DB + reproducibility | schema_test.php: 22 tables; seed twice; SQL routines installed; own DB dropped | PASS |
| SP-05 | Atomic roles / amenities / booking | procedure_flow_test.php + source_audit_test.php: SIGNAL, savepoint rollback and outer rollback | PASS |
| SP-06 | Two-process concurrency | Separate PHP/PDO workers, both holding stale read snapshots; one creation / one rejection | PASS |
| SP-07 | Booking price/policy/refunds/events | booking_flow_test.php and source_audit_test.php, confirmed and pending cancellations | PASS |
| SP-08 | Direct SQL-routine authorization | Invalid owner, forged Admin true/NULL/2, invalid amenity/role JSON | PASS |
| SP-09 | Registration/profile/Host/logout | Real HTTP POSTs with isolated marker accounts; no password in old input | PASS |
| SP-10 | Guest booking → Host confirm → Guest cancel | HTTP states verified through fixture inspect; duplicate submit makes no second booking | PASS |
| SP-11 | Host create/update / image references | Actual Listing create, HTTP update, real JPEG multipart upload and exact fixture cleanup | PASS |
| SP-12 | Host calendar / non-owner visibility | Service/calendar conflict guards; HTTP alert-danger and safe redirect for foreign listing | PASS |
| SP-13 | Admin users | HTTP status/roles/delete; model account/profile/NULL-avatar checks; self-demotion blocked | PASS |
| SP-14 | Admin listing CRUD/audit | procedure_flow_test.php + HTTP moderation and bad fee case with preserved form | PASS |
| SP-15 | Completed-stay review | Fresh isolated historical booking; eligible review succeeds, duplicate/other guest rejected | PASS |
| SP-16 | Search/detail/AJAX/wishlist browser | Chrome live quote, stale response, error recovery, clear fields, favorite toggle/restore | PASS |
| SP-17 | Responsive role pages | 48 browser page/viewport checks at 375/768/1024/1440 + login | PASS |
| SP-18 | Frontend syntax/runtime | Chrome V8 parse, nonempty CSS declaration validation, no JS exceptions on journeys | PASS |
| SP-19 | Network/DB recovery | HTTP smoke; separate controlled DB-outage server: safe HTML/API 500 | PASS |
| SP-20 | PHP syntax | 63 files linted | PASS |
| SP-21 | Seed idempotence / data preservation | image seeder dry-run + apply: 0 inserted, 5 skipped; 3 users/3 listings/10 photos/0 bookings/0 fixture users | PASS |
| SP-22 | Git/secret discipline | No commit/push/reset/grants change; SQL/source/docs not blanket ignored; diff whitespace check | PASS |
| SP-23 | Large-load stress / general deadlock retry policy | Not executed; concurrency verification is limited to two independent workers | NOT_VERIFIED |
| SP-24 | Full keyboard/screen reader / assistive technology | Not executed | NOT_VERIFIED |
| SP-25 | Production EXECUTE-only account / Oracle MySQL / non-Vietnam named zones | Not deployed; actual tested server is MariaDB 10.4.32 local XAMPP | NOT_VERIFIED |
| SP-26 | Payments, email delivery, reset-password and other absent routes | Outside implemented workflow scope; no claim of completion | NOT_APPLICABLE |

Fixtures use exact generated markers/IDs. Uploaded test images are removed only after matching the fixture listing prefix, generated basename and resolved public/uploads root. Other uploads/files are untouched. Historical dates are never injected into the live database.

## Cleanup regression — 2026-10-09

| ID | Priority / scope | Executed evidence | Result |
|---|---|---|---|
| CL-01 | Must: Auth/roles/profile/Host enrollment/logout | Expanded http_audit.ps1 + fresh_setup_test.php | PASS |
| CL-02 | Must: browse/detail/quote/booking/history/Host confirm/Guest cancel | HTTP smoke/audit + booking/source/procedure integration | PASS |
| CL-03 | Must: Host create/edit/calendar/visibility/photo upload | Real fixture create/upload, bad MIME rollback, owner/calendar guards | PASS |
| CL-04 | Must: Admin user edit role payload validation | HTTP scalar/unknown/nested role payloads rejected; form data retained | PASS |
| CL-05 | Must: Admin listing mutations | Update/moderation/visibility/soft-delete routines; missing listing POST recovers to Admin | PASS |
| CL-06 | Important: wishlist and post-stay review | Actual Chrome add/remove/restore; isolated completed stay duplicate/owner review guards | PASS |
| CL-07 | Database parity and setup safety | schema_test.php --compare-live; recording PDO proves zero DROP; importer refusal checks | PASS |
| CL-08 | Team Git setup | 16 ignored/26 shared paths; eligible file copy into TEMP, isolated fresh DB/HTTP server | PASS |
| CL-09 | Syntax/UX regression | 66 PHP lint; Chrome V8/CSS; 48 checks at 375/768/1024/1440; controlled DB outage | PASS |
| CL-10 | Must: complete Host deletion and image reorder/removal | No dedicated routes in current app; absent before cleanup; not introduced in stabilization | NOT_VERIFIED |
| CL-11 | Important: password reset/change, category CRUD, reports, inbox/email, map, full filter/pagination | Absent or incomplete before cleanup; see requirements-matrix.md | NOT_VERIFIED |
| CL-12 | Optional: payments/messages/promotion/Instant Book/news/i18n/dark mode | Not implemented by cleanup | NOT_VERIFIED |
| CL-13 | Remote GitHub clone/permissions and teammate Apache setup | Independent local copy tested, not a remote clone or other machine | NOT_VERIFIED |
| CL-14 | Full keyboard/screen reader, load/deadlock stress, production grants/Oracle MySQL | Not executed/deployed | NOT_VERIFIED |

These results apply to implemented workflows, not to every planned feature in Functions.txt. Existing runtime uploads and team assets were preserved; live record counts are allowed to change during normal app use.

## Visual UX follow-up — 2026-10-09

| ID | Executed evidence | Result |
|---|---|---|
| VUX-T01 | Chrome before/after; filter DOM alignment/44px/border/radius/labels/amenity overlap at 375/768/1024/1440 | PASS |
| VUX-T02 | Real GET type/price/multiple amenities, invalid→correct, empty→reset, main-context reset, refresh/detail/Back/Enter; 13-row HTTP pagination | PASS |
| VUX-T03 | Real Tab/Space/focus outline; keyboard Enter mobile navbar open/close; ArrowRight labelled Admin table scroll | PASS |
| VUX-T04 | Invalid profile/report retains editable input and leaves DB unchanged; change/reset-password success visible after logout redirect | PASS |
| VUX-T05 | 23 route/state × 4 = 92 extended responsive checks plus 76 basic page/viewport checks and related interactions; actual screenshots inspected | PASS within tested scope |
| VUX-T06 | Chrome helper/footer computed contrast 5.12/5.53; controls have accessible labels; CSS/V8/PHP lint 86 | PASS for targeted checks |
| VUX-T07 | Real recovery email delivery | BLOCKED — đang chờ cấu hình |
| VUX-T08 | Full screen reader/zoom/Safari/touch/native dialogs/external Maps/share/CDN outage | NOT_VERIFIED |

Full isolated regression `php tests/fresh_setup_test.php --quality-regression --visual-ux` exited 0. No shared database mutation, source deletion, commit or push. Evidence and repaired files: [Visual UX audit](quality/ui-ux-visual-audit.md). Historical entries above remain snapshots of their original scope.

## Nice-to-have test cases — 2026-10-13

Gate sau khi gộp 5 nhánh: `php tests/fresh_setup_test.php --quality-regression --no-screenshots` → exit 0, 264 dòng PASS, không FAIL (DB/server tạm riêng). Cột “Các bước thủ công” lấy từ kịch bản click-through trong báo cáo của từng nhánh, dùng tài khoản demo `guest@home2home.test`, `host@home2home.test`, `admin@home2home.test` (mật khẩu: README › “Tài khoản local/test”). Cột “Kết quả” chỉ dựa trên test tự động; các bước thủ công chưa được chạy lại sau khi gộp. Dòng CL-12 phía trên là snapshot cũ, được thay bằng bảng này.

| ID | Vai trò | Các bước thủ công | Kỳ vọng | Evidence tự động | Kết quả |
|---|---|---|---|---|---|
| NTH-01 | Guest | Mở `/listings/3` (Sea Breeze Homestay) | Thấy nhãn “Đặt ngay”, giá cuối tuần, giá ngày lễ, giảm 10%/20%, mục “Ngày lễ sắp tới” | feature_booking_extras_test (HTTP trang chi tiết) | PASS |
| NTH-02 | Guest | Trên `/listings/3` chọn 14/04/2027 → 21/04/2027, 2 khách; nhập mã `CHAOHE10`; sau đó đổi sang `HETHAN99` | Bảng giá: 5 đêm thường, 1 đêm cuối tuần, 1 đêm ngày lễ (16/04), giảm từ 7 đêm −683.000đ, phụ phí 100.000đ; mã CHAOHE10 −500.000đ, tổng 5.747.000đ; mã sai báo “Mã khuyến mãi không tồn tại.”, tổng về 6.247.000đ | feature_booking_extras_test (giá linh hoạt, mã khuyến mãi, HTTP quote); số tiền được kiểm tra lại bằng `sp_booking_quote` trên DB nâng cấp 2026-10-13 | PASS |
| NTH-03 | Guest | Nhập lại `CHAOHE10`, bấm “Đặt ngay” | Booking `confirmed` ngay; `/bookings` hiện “CHƯA THANH TOÁN”, “⚡ Đặt ngay”; chi tiết có bảng giá đã lưu và mục “Thanh toán” | feature_booking_extras_test (Instant Book, booking có mã) | PASS |
| NTH-04 | Guest | Đặt chồng ngày lên booking ở NTH-03 | Báo “Khoảng ngày này đã có yêu cầu đặt chỗ khác.” | feature_booking_extras_test (hai process tranh chấp) | PASS |
| NTH-05 | Host | `/host` → “Giá & đặt chỗ” của Sea Breeze; sửa giá, % giảm, bật/tắt Đặt ngay; thử nhập 95% | Lưu thành công, chỗ ở không bị gửi duyệt lại; 95% bị từ chối “từ 0 đến 90%” | feature_booking_extras_test (giá Host, HTTP pricing) | PASS |
| NTH-06 | Host | Trên `/host`, booking đã xác nhận đến ngày nhận phòng → “Đã thu tiền” | Trạng thái “ĐÃ THANH TOÁN”; trước ngày nhận phòng bị từ chối | feature_booking_extras_test (thanh toán khi nhận phòng) | PASS |
| NTH-07 | Admin | `/admin/holidays`: thêm, lưu lại ngày “Dữ liệu mẫu”, xóa | Ngày mới xuất hiện; lưu lại thì mất nhãn mẫu; xóa có xác nhận và audit | feature_booking_extras_test (ngày lễ) | PASS |
| NTH-08 | Admin | `/admin/promotions`: tạo, sửa, tắt/bật, xóa mã chưa dùng; xem mã đã dùng | Mã đã dùng bị khóa ô mã và không có nút Xóa, chỉ Tắt | feature_booking_extras_test (mã khuyến mãi) | PASS |
| NTH-09 | Admin | `/admin/payments`: lọc, tìm; hoàn tiền booking đã thu (có ghi chú); thử hoàn tiền không ghi chú | “ĐÃ HOÀN TIỀN”, Guest nhận thông báo, audit `refund_payment`; thiếu ghi chú bị từ chối | feature_booking_extras_test (hoàn tiền), browser_audit | PASS |
| NTH-10 | Guest | Hồ sơ → “Xác minh danh tính”: nhập SĐT `12345`; sau đó nhập SĐT và CCCD 12 số hợp lệ | SĐT sai báo lỗi, giữ họ tên/SĐT; hợp lệ → “Đang chờ duyệt”, CCCD hiện dạng `********NNNN`, không gửi thêm được | feature_trust_test (gửi xác minh) | PASS |
| NTH-11 | Admin | `/admin/verifications`: từ chối không lý do; từ chối có lý do; duyệt yêu cầu gửi lại | Thiếu lý do báo lỗi; từ chối thành công và CCCD bị che; duyệt xong hồ sơ có “✓ Đã xác minh” | feature_trust_test (Admin duyệt) | PASS |
| NTH-12 | Host | `/host` xem thẻ “Huy hiệu Host uy tín” | Hiện tiến độ 3 tiêu chí (đánh giá, điểm trung bình, tỷ lệ từ chối/hủy) | feature_trust_test (huy hiệu Host) | PASS |
| NTH-13 | Host → Guest → Admin | Host sửa “Hướng dẫn nhận phòng” của Ngôi nhà thông Đà Lạt; Guest đặt chỗ đó, xem chi tiết booking trước và sau khi Host chấp nhận; Admin mở booking; tài khoản không liên quan mở URL booking | Trước khi xác nhận chỉ có dòng chờ; sau khi xác nhận thấy hướng dẫn, mã cửa/Wi-Fi bị che và có nút “Hiện”; Admin thấy; người ngoài nhận 404 | feature_trust_test (hướng dẫn nhận phòng, HTTP 404) | PASS |
| NTH-14 | Guest → Host | Guest gửi tin trong chi tiết booking; thử gửi chỉ dấu cách; Host mở “Tin nhắn” và trả lời | Tin gửi thành công; tin rỗng báo “Tin nhắn cần từ 1 đến 2000 ký tự.”; Host thấy huy hiệu chưa đọc, trả lời xong huy hiệu mất; Guest thấy “Đã xem” | feature_conversation_test (gửi/đọc, validation, inbox) | PASS |
| NTH-15 | Host / Guest | Host chấp nhận booking | Nút “Liên hệ qua Zalo” xuất hiện cạnh số điện thoại của bên kia | feature_conversation_test (Zalo) | PASS |
| NTH-16 | Guest / Host | Hủy hoặc từ chối một booking rồi mở khung tin nhắn | Chỉ xem, không còn ô nhập | feature_conversation_test (quyền, cửa sổ 14 ngày) | PASS |
| NTH-17 | Guest / Host | Có booking `confirmed` nhận phòng hôm nay/ngày mai; chạy `C:\xampp\php\php.exe database\checkin_reminders.php --days=1` hai lần; mở “Thông báo” | Lần đầu báo số nhắc lịch đã tạo, lần hai “0 created”; Guest thấy “NHẮC LỊCH”, Host thấy “Khách … sẽ nhận phòng…” | feature_conversation_test (nhắc lịch, CLI); CLI chạy lại thủ công trên DB nâng cấp 2026-10-13: “2 created” rồi “0 created” | PASS |
| NTH-18 | Admin | `/admin/messages`: tìm “gửi xe” hoặc mã booking; ẩn tin có lý do; hiện lại | Tin “ĐANG ẨN”, Guest/Host thấy “Tin nhắn đã bị quản trị viên ẩn.”; hiện lại cần lý do; cả hai ghi audit | feature_conversation_test (Admin tìm/ẩn/hiện/audit) | PASS |
| NTH-19 | Public / Guest | Mở `/map`; kéo bản đồ ra biển cho đến khi hết nhãn; thu nhỏ; gõ “Hội An” và “Tìm trên bản đồ” | 3 nhãn giá lúc đầu; vùng trống hiện “Không có chỗ ở trong khu vực này”; Hội An còn 2 chỗ ở | feature_discovery_test (API khung bản đồ, 422), browser_audit (`/map` × 4 độ rộng). Thao tác kéo thật chỉ kiểm tra bằng Chrome CDP trong báo cáo discovery | PASS (API); kéo chuột NOT_VERIFIED tự động |
| NTH-20 | Public | Mở `/listings/1`, mục “Vị trí” | Bản đồ nhỏ có ghim ở Đà Lạt (cần Internet để tải tile) | Chỉ kiểm tra bằng Chrome CDP trong báo cáo discovery | NOT_VERIFIED |
| NTH-21 | Guest | Đăng nhập, mở 2 chỗ ở, tìm “Hội An”, về trang chủ không lọc; vào “Tài khoản” → “Xóa lịch sử xem & tìm kiếm” | Thấy “Gợi ý cho bạn” (mỗi thẻ có lý do) và “Đã xem gần đây”; sau khi xóa, trang chủ không còn mục gợi ý | feature_discovery_test (lịch sử, gợi ý, riêng tư/xóa, HTTP) | PASS |
| NTH-22 | Host | “Sửa chỗ ở” → “Vị trí trên bản đồ”; “Chọn trên bản đồ”; lưu khi chỉ nhập một ô | Hai ô vĩ độ/kinh độ tự điền; chỉ một ô → báo lỗi cần nhập cả hai | feature_discovery_test (tọa độ khi tạo/sửa) | PASS |
| NTH-23 | Public | Bấm “Cẩm nang” trên menu; lọc tab; mở một bài; mở `/articles/khong-ton-tai` | 4 bài mẫu, có “Bài viết liên quan”; slug không tồn tại → 404 | feature_content_test (danh sách công khai, HTTP) | PASS |
| NTH-24 | Admin | `/admin/articles` → “Viết bài mới”: lưu khi trống tiêu đề; lưu nháp có ảnh bìa; xem trước; xuất bản; sửa slug trùng; ẩn; xóa | Lỗi giữ dữ liệu đã nhập; bản nháp chưa công khai; xuất bản lên đầu danh sách; slug trùng báo lỗi; ẩn/xóa biến khỏi trang công khai | feature_content_test (validation, xuất bản/audit, xóa mềm, HTTP Admin CSRF/upload) | PASS |
| NTH-25 | Guest | Mở `/admin/articles` | 403 | feature_content_test (Guest 403) | PASS |
| NTH-26 | Tất cả | Bấm nút mặt trăng trên header (mobile: trong menu); tải lại trang; xóa `h2h-theme` trong localStorage | Chuyển tối ngay, giữ sau khi tải lại; xóa lựa chọn thì theo cài đặt hệ điều hành | feature_content_audit.ps1 (theme trước lần vẽ đầu, nút trên desktop/mobile/đăng nhập, tương phản 88 lượt xem trang) | PASS |
| NTH-27 | Guest / Admin | Guest đặt chỗ, Host xác nhận; Guest mở booking → “Thanh toán online” (`/bookings/{id}/pay`) → “Chuyển khoản VietQR”; quét mã bằng app ngân hàng, chuyển đúng số tiền và nội dung; bấm “Tôi đã chuyển khoản”. Admin mở `/admin/payments` → “Chuyển khoản chờ xác nhận”: thử từ chối khi lý do dưới 5 ký tự, rồi xác nhận. Lặp lại với booking khác, chọn “Thanh toán qua VNPay” và trả bằng thẻ thử NCB trong README | Trang mã QR hiện ngân hàng, số tài khoản, số tiền, nội dung `H2H…`; sau khi báo, Admin có thông báo và booking hiện “Đã báo chuyển khoản”; lý do quá ngắn bị chặn; xác nhận xong booking hiện “Đã thanh toán” (VietQR) và Guest có thông báo. VNPay: chuyển sang trang VNPay sandbox, trả xong quay về booking với “Đã thanh toán” (VNPay) | feature_payment_test (quyền, VietQR, kết quả VNPay, chữ ký, HTTP) | PASS (thủ công 2026-10-17: VietQR báo chuyển khoản + Admin xác nhận; VNPay sandbox thật với thẻ test NCB); không chuyển tiền thật qua mã QR |
| NTH-28 | Tất cả | Bấm nút “EN” trên header (trang đăng nhập: góc trên); đi qua trang chủ, chi tiết chỗ ở, đặt chỗ, trang Host/Admin; nhập sai mật khẩu; bấm “VI” | Quay lại đúng trang, giao diện và thông báo bằng tiếng Anh; dữ liệu người dùng nhập giữ nguyên; bấm “VI” trở về tiếng Việt | feature_i18n_test (đổi ngôn ngữ, 80 lượt xem trang tiếng Anh, flash/JSON) | PASS |
