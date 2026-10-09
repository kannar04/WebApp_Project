# Home2Home — Visual UX audit & repair

Ngày: 2026-10-09. Phạm vi: các màn hình đang triển khai theo Functions.txt, không triển khai lại hệ thống. Kết quả dưới đây là kiểm chứng local Chrome/PHP/MariaDB, không phải chứng nhận production hoặc mọi thiết bị.

## Kết quả chính

Bộ lọc “Chỗ ở nổi bật” đã được sửa cấu trúc DOM/Bootstrap 4.6.2: năm trường có label, control cao 44px, tiện nghi từ database, nhóm nút Lọc/Đặt lại, thông báo kết quả/lỗi và giữ trạng thái. Không dùng offset để đẩy dropdown cho thẳng hàng.

Đã sửa thêm phản hồi đổi/reset mật khẩu, giữ input hồ sơ/báo cáo khi lỗi, nhãn form Admin/đánh giá, focus bàn phím, tương phản chữ phụ và màu nút disabled. Không có thay đổi Stored Procedure/schema trong lượt UX này.

Đã đọc đầy đủ skill_UIUX.md, skill_user.md, skill_web.md và Functions.txt. skill_UIUX dẫn đến đo alignment/contrast thực tế và quan sát ảnh; skill_user dẫn đến thử correction/retry/back/reset trên database/server riêng; skill_web giữ Bootstrap 4.6.2, MVC, CSRF/auth và CALL-only. Không chỉ đánh giá qua lint.

## Môi trường và bằng chứng

- Baseline: `php tests/fresh_setup_test.php --visual-baseline`, exit 0, 22 route/state × 4 viewport = 88 lượt kiểm tra; 25 ảnh trước sửa.
- Sau sửa: `php tests/fresh_setup_test.php --quality-regression --visual-ux`, exit 0. Có 23 route/state × 4 = 92 lượt audit mở rộng, ngoài 76 lượt page/viewport cơ bản, hai booking-detail checks, login, chuyển tháng và interaction regression. Đây là số lượt chạy (có trang được thử nhiều lần), không phải 168 màn hình riêng biệt.
- Viewport: 375, 768, 1024, 1440px. Các lượt kiểm tra thông thường dùng chiều cao 900px; ảnh bộ lọc mobile được mở rộng vùng capture để chứa toàn bộ form, không chỉnh pixel hoặc ghép ảnh.
- 48 ảnh sau sửa từ Chrome thật. Đã mở/quan sát ảnh bộ lọc ở cả bốn viewport; ảnh đại diện Guest/Host/Admin ở mobile và các màn hình desktop chính. DOM/computed CSS được đo trong browser, không suy đoán từ source.
- [Transcript thực tế của lượt cuối](visual/verification.txt), [ảnh trước](visual/screenshots/before), [ảnh sau](visual/screenshots/after).
- Login screenshot chỉ ẩn khối ghi mật khẩu demo trong DOM lúc capture để không đưa mật khẩu vào ảnh; không sửa ảnh raster. Khoảng trắng tại vị trí đó là redaction, không được dùng làm bằng chứng lỗi spacing.
- Không ghi thông tin .env/password reset token vào evidence. Tài khoản và dữ liệu minh họa trong ảnh thuộc database kiểm thử riêng.

## Lỗi đã xác minh và sửa

| ID / mức | Actual → expected và nguyên nhân | Sửa tại | Xác minh sau sửa |
|---|---|---|---|
| VUX-01 / P2 | Hai select không có label; top thấp hơn/khác các input 32,39px vì select là con trực tiếp của flex, input nằm trong wrapper có label. Cần năm form-group nhất quán. | app/Views/home.php; public/assets/css/app.css | Năm label thật, desktop cùng top/bottom; 44px; border/radius/gap đồng nhất; bốn viewport PASS. |
| VUX-02 / P2 | Tiện nghi/action thiếu phân nhóm rõ ràng, không có Đặt lại. Cần grid có label, primary Lọc và secondary Đặt lại trong form. | app/Views/home.php; public/assets/css/app.css | Sáu tiện nghi seed lấy bằng routine hiện hữu, checkbox không chồng nhau; reset thực tế xóa advanced/page, giữ địa điểm/ngày/số khách. |
| VUX-03 / P1 | Khi lọc lỗi, input bị mất và có thể hiển thị kết quả không lọc, khiến người dùng hiểu sai. Cần báo chưa áp dụng và giữ dữ liệu để sửa. | app/Controllers/HomeController.php; app/Views/home.php; public/assets/js/app.js | Giá đảo bị chặn native submit/focus; bypass JS vẫn báo lỗi server, giữ type/sort/giá/tiện nghi, không chạy search hoặc hiện kết quả mặc định. API lỗi giữ HTTP 422/envelope. |
| VUX-04 / P2 | Search thiếu trạng thái gửi và ngữ cảnh kết quả; Back có nguy cơ giữ nút đã disabled. | app/Views/home.php; public/assets/js/app.js | aria-busy/Đang tìm, summary số kết quả dưới form, fragment tới kết quả; Back khôi phục filter và nút dùng được. Booking button cũng được phục hồi qua pageshow. |
| VUX-05 / P2 | Admin create chỉ dùng placeholder; tìm user/ghi chú duyệt, review và email hồ sơ thiếu label được liên kết. | app/Views/admin/dashboard.php; app/Views/bookings/index.php; app/Views/profile/show.php | Audit visible form controls ở 23 route/state không còn control thiếu accessible label; review và Admin create có label nhìn thấy; bảng Admin có region label/tabindex. |
| VUX-06 / P1 | Mật khẩu đổi thành công nhưng login không hiện xác nhận. Auth layout chỉ đọc error; sau logout cookie bị hết hạn và flash không theo được redirect. | app/Views/layouts/auth.php; core/Auth.php | HTTP đổi/reset mật khẩu hiện alert-success; mật khẩu cũ bị từ chối, mật khẩu mới dùng được; phiên thứ hai bị vô hiệu. Không nới quyền auth. |
| VUX-07 / P2 | Tên dài 151 ký tự khiến hồ sơ quay về giá trị DB, mất tên/phone vừa nhập. | app/Controllers/ProfileController.php; app/Views/profile/show.php | Test tái hiện FAIL trước sửa, PASS sau sửa; giữ hai field editable, DB không đổi; không giữ mật khẩu/file upload trong old input. |
| VUX-08 / P2 | Báo cáo nội dung ngắn bị từ chối nhưng textarea/category bị reset. | app/Controllers/ReportController.php; app/Views/reports/index.php | Test gửi `short`/fraud FAIL trước sửa, PASS sau sửa; giữ nội dung/category theo đúng target, số báo cáo DB không tăng. Gửi hợp lệ vẫn lưu và Admin xử lý được. |
| VUX-09 / P2 | Chữ hướng dẫn Bootstrap #6c757d trên nền #fff6d9 đạt 4,34:1, chưa đạt ngưỡng 4,5 cho chữ nhỏ. Footer auth #999 cũng nhạt. | public/assets/css/app.css | Browser computed color #626b62: hướng dẫn 5,12:1; footer nền trắng 5,53:1. Không suy rộng thành chứng nhận toàn bộ contrast website. |
| VUX-10 / P2 | Outline bàn phím bị `.form-control:focus` Bootstrap ưu tiên hơn selector element:focus-visible. | public/assets/css/app.css | Test Tab thật tái hiện FAIL, sửa specificity rồi PASS outline solid ≥2px, không bỏ box-shadow hiện hữu. |
| VUX-11 / P3 | Nút recovery disabled dùng màu xanh dương mặc định Bootstrap thay vì xanh lá hệ thống. | public/assets/css/app.css | Quan sát ảnh trước/sau: disabled xanh lá nhạt; vẫn disabled vì mail chưa cấu hình. |

Không phát hiện P0 trong các journey đã chạy. Đã loại CSS `.filter-row` cũ sau khi xác nhận runtime không còn dùng; không xóa dữ liệu/source của nhóm.

## Đo bộ lọc trước/sau

Các tọa độ dưới đây lấy khi trang vừa tải tại scrollY=0; screenshot được cuộn tới form nên vị trí tuyệt đối trong ảnh khác. So sánh khoảng cách giữa các control, không so sánh top tuyệt đối của toàn section giữa hai thiết kế.

| Viewport | Trước sửa | Sau sửa | Kết quả |
|---|---|---|---|
| 375 | Hai select chung hàng nhưng không có label; các input ở hàng khác | Năm trường một cột, cùng left/right; khoảng top liên tiếp 88,47px; mỗi control 44px | PASS |
| 768 | Select top 628,06; price top 660,45; bedroom ở hàng sau | Hai cột, top 737,52 cho type/sort, 825,98 cho giá; bedroom 914,45 | PASS |
| 1024 | Select top 526,34; input top 558,73; chênh 32,39px | Cả năm top 635,80; bottom 679,80; gap ngang 10px | PASS |
| 1440 | Select top 526,34; input top 558,73; chênh 32,39px | Cả năm top 635,80; bottom 679,80; gap ngang 10px | PASS |

Sau sửa: label desktop top 607,33; gap label → input 8px; border `1px solid rgb(234,229,202)`; radius 8px. Tiện nghi dùng Bootstrap row/col-12/col-sm-6/col-lg-4 và custom-checkbox, danh sách lấy từ database.

Đối chiếu chính: [desktop trước](visual/screenshots/before/home-filter-1440.png) / [desktop sau](visual/screenshots/after/home-filter-1440.png); [mobile trước](visual/screenshots/before/home-filter-375.png) / [mobile sau](visual/screenshots/after/home-filter-375.png). Baseline mobile chỉ chứa phần form đang trong viewport, không chứa toàn form; không tạo lại ảnh “trước” bằng code đã sửa.

## Filter journeys — dữ liệu thực, không mock

| Ca kiểm thử | Kết quả thực tế trong isolated seed | Trạng thái |
|---|---|---|
| Không chọn filter | Ba listing approved/visible | PASS |
| Chỉ type=2 | Listing #3 | PASS |
| Chỉ giá 1.000.000–1.500.000 | Listing #1 | PASS |
| min=2.000.000/max=0.0 | Listing #2; giữ nghĩa zero=no bound của SP cũ | PASS |
| min>max | Native submit bị chặn, giữ giá, focus max; sửa max rồi trả #2 | PASS |
| GET lỗi bỏ qua JS | Inline/general error; không trả kết quả mặc định; giữ điều kiện | PASS |
| amenities 1 và 2 | Cả hai tiện nghi bắt buộc; listing #1 và #2 | PASS |
| Không có kết quả | Empty state khác validation error, có đường sửa/xóa điều kiện | PASS |
| Đặt lại | Xóa type/sort/price/bedrooms/amenities/page; giữ location/dates/guests | PASS |
| Enter | CDP keyboard Enter trên input submit form GET thật, trả #2 | PASS |
| Refresh | Type và kết quả được giữ trong query string | PASS |
| Chi tiết → Back | Checkbox chọn được giữ; nút không bị kẹt disabled | PASS |
| Pagination | 13 fixture thật, trang 12+1, total/pages đúng; link giữ toàn bộ query filter | PASS |
| Tab/Space | Thứ tự five controls → checkbox; Space toggle; focus outline rõ | PASS |

Giai đoạn test Enter ban đầu bị native select popup chiếm phím; đã sửa cách automation gửi Enter từ input, không thêm code submit giả hoặc mock API để “làm xanh” test. Lazy images ngoài viewport được cuộn vào để tải rồi vẫn kiểm tra naturalWidth > 0.

## Audit các màn hình khác

| Nhóm / route/state | Visual/responsive kiểm chứng | Journey kiểm chứng / giới hạn |
|---|---|---|
| Public `/`, `/login`, `/register` | 4 viewport; ảnh, control labels, typography/container và runtime checks PASS | Filter, register invalid/valid, login/roles/logout PASS; quyền protected routes không thay đổi. |
| `/listings/1` và booking quote | 4 viewport; gallery/booking container, ảnh không hỏng PASS | AJAX quote thật, sửa lỗi/clear giá, chống response cũ, điều hướng calendar PASS; Maps/native share vẫn NOT_VERIFIED. |
| `/forgot-password` | 4 viewport, cảnh báo cấu hình và nút disabled rõ ràng PASS | Gửi email BLOCKED — đang chờ cấu hình theo xác nhận người dùng; không giả lập gửi. |
| Guest `/profile`, `/bookings`, `/wishlist` | 4 viewport; ảnh card/avatar, label/form, review alignment PASS; booking detail 375/1440 | HTTP update/upload/password, booking pending→confirm→cancel, completed review; browser favorite toggle/restore và booking detail PASS. |
| Guest `/notifications`, `/reports`, `/reports?listing=1` | 4 viewport, báo cáo có form target thật; ảnh mobile/desktop PASS | HTTP inbox ownership/read, report validation/retention/create và closure PASS. |
| Host `/host`, create/edit, availability, reviews | 4 viewport; form, bảng, calendar, labels và ảnh đại diện PASS | Actual HTTP/DB upload/edit/photo/calendar guards, reply, completion; browser next-month PASS. |
| Admin `/admin`, user/listing edit, preview, catalog, reviews, reports | 4 viewport; bảng chỉ scroll trong region, label/controls PASS; actual ArrowRight keyboard scroll PASS | Actual HTTP/DB role/edit/moderation/preview/catalog/report/review journeys PASS; auth/CSRF/ownership vẫn được kiểm tra. |
| Modals/dialogs | Không có Bootstrap modal riêng trong các View hiện tại; dùng native confirm/alert | Native dialog visual/accept/cancel bằng browser NOT_VERIFIED; không tuyên bố modal PASS từ đọc source. Các mutation/guards được kiểm chứng HTTP/DB riêng. |

Mỗi lượt Check-Page đòi HTTP 200, không horizontal page overflow, không broken image sau khi tải, không duplicate ID và không PHP error. Không có browser JavaScript exception ở lượt cuối. Những checks này không tự chúng đồng nghĩa mọi nút của mọi trang đã được thử bằng chuột, hoặc toàn website đạt accessibility certification.

## Dataflow và bảo toàn nghiệp vụ

Filter GET/query → HomeController validatedFilters → Listing::searchPage → `CALL sp_listing_search_filtered(...)` → listings + total → SSR cards/summary/pagination. Tiện nghi/type vẫn từ routines hiện hữu, không hardcode danh mục vào View.

Invalid GET giữ input và trả lỗi trước search; API giữ HTTP 422. JavaScript chỉ validation/feedback, không thay kết quả bằng dữ liệu local. Reset chỉ xây URL chứa main context và bỏ page. Form không đổi tên field/backend contract. Khi refresh/back, query string là nguồn trạng thái.

Hồ sơ/báo cáo giữ input không nhạy cảm trong session riêng cho form; dữ liệu hợp lệ vẫn đi qua User/Report models và Stored Procedures. Không đưa password/token/upload vào old input. Auth logout tạo phiên ẩn danh mới để flash theo redirect, không phục hồi phiên authenticated cũ.

## Regression và phạm vi an toàn

- Lượt cuối exit 0: codebase_cleanup_test, stored_procedure_audit_test, booking_flow_test, source_audit_test, procedure_flow_test, quality_core_test, http_smoke, http_audit và Chrome browser audit.
- CALL-only audit: zero direct business SQL violations; 140 CALL sites/108 routine signatures; 61 route handlers; concurrency và rollback guards không regression.
- PHP lint: 86 file, không lỗi; PowerShell parser cho hai browser test files không lỗi; JS được Chrome V8 parse; CSS declarations được Chrome kiểm tra; git diff --check không lỗi whitespace.
- Mỗi run tạo database tên ngẫu nhiên và HTTP server riêng; chỉ xóa đúng database/fixture upload do harness sở hữu. Shared db_home2home và upload người dùng không bị sửa/xóa bởi test. Profile/Report fixtures không được tạo trong database dùng chung.
- Không chỉnh .env, không migration/SP thay đổi trong lượt này, không commit/push/untrack; các thay đổi đang có của nhóm được giữ nguyên. Cảnh báo Git LF→CRLF là cảnh báo line-ending, không phải lỗi source.

## Các vấn đề còn lại

- Email recovery: **BLOCKED — đang chờ cấu hình**, đúng câu trả lời của người dùng. Token/throttle/expiry/consume có test, nhưng không chứng minh delivery.
- Native share/clipboard, liên kết Maps/địa chỉ ngoài hệ thống: **NOT_VERIFIED**; không nâng trạng thái từ audit trước.
- Full screen reader, zoom 200%, mobile Safari/touch hardware, mọi native dialog và load/production deployment: **NOT_VERIFIED**. Thứ tự Tab/focus/navbar/table cụ thể đã thử không thay thế full accessibility audit.
- Không mở rộng các Optional chưa triển khai. Thống kê 94 nhóm Functions.txt của [requirements matrix](requirements-matrix.md) không bị tăng do các sửa UX này.
- CDN/font/ảnh ngoài hệ thống đã tải trong môi trường audit; offline/outage CDN riêng chưa thử.

Không còn lỗi P0/P1 đã tái hiện chưa sửa trong những journey của lượt này; những giới hạn trên vẫn được giữ rõ ràng thay vì gán PASS cho toàn website.
