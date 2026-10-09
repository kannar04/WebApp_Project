# SKILL_USER — USER JOURNEY AUDIT & AUTONOMOUS UX REPAIR

**Version:** 1.0  
**Project:** Home2Home  
**Purpose:** Đóng vai người dùng thật để phát hiện, ưu tiên, sửa và kiểm tra lại các vướng mắc khi dùng website.  
**Companion skills:** `skill_web.md`, `skill_UIUX.md`  
**Source of truth:** `functions.txt`, sơ đồ chức năng, ERD, sơ đồ lớp, database `db_home2home`, source code thực tế.

## 1. Vai trò & nguyên tắc

Hành động đồng thời như **người dùng thực tế, UX auditor, QA engineer và full-stack debugger**. Đánh giá website dựa trên **khả năng hoàn thành nhiệm vụ thực sự**, không dựa riêng vào vẻ đẹp của giao diện hoặc việc trang tải thành công.

- **Test → Observe → Reproduce → Diagnose → Fix → Retest → Regression test.**
- Không kết luận hoạt động khi chỉ nhìn thấy màn hình hoặc code. Chỉ ghi `PASS` khi đã thực sự kiểm thử bằng phương pháp có thể mô tả.
- Không hardcode kết quả, bỏ validation, nuốt lỗi, vô hiệu hóa bảo mật hoặc xóa test để khiến test “xanh”.
- Không tự ý tạo nghiệp vụ mới. Mọi thay đổi phải phù hợp `functions.txt`, dữ liệu và quyền người dùng.
- Không phá hủy dữ liệu đang dùng chung; ưu tiên test database hoặc dữ liệu seed có thể dọn dẹp an toàn.
- Không mặc định mọi trang đều cần nút Back. **Bắt buộc phải có đường trở về hoặc đường đi tiếp rõ ràng**, tùy ngữ cảnh: breadcrumb, điều hướng chính, liên kết danh sách, “Quay lại kết quả”, “Tiếp tục tìm kiếm” hoặc browser Back hoạt động đúng. Tránh nút `history.back()` vô điều kiện nếu người dùng có thể truy cập trực tiếp.
- Ưu tiên sửa lỗi nhỏ nhất tại nguyên nhân gốc, sau đó kiểm tra các luồng liên quan.

## 2. Chuẩn bị trước kiểm thử

1. Đọc `functions.txt`; lập danh sách các nhiệm vụ **Must-have → Important → Optional**, không tự bịa chức năng.
2. Xác định các vai trò tồn tại trong project (ví dụ Guest, Host, Admin nếu tài liệu xác nhận) và quyền của từng vai trò.
3. Đọc `skill_web.md`, `skill_UIUX.md`, cấu trúc route, middleware, session, database và các mẫu UI.
4. Kiểm tra có thể khởi động PHP/Apache, truy cập MySQL, và chạy website bằng URL thực hay không. Kiểm tra CLI, console, server logs và browser automation nếu sẵn có.
5. Dùng tài khoản test được phép sử dụng; không hiển thị mật khẩu hoặc token trong log/báo cáo.
6. Ghi lại các giới hạn môi trường. Nếu không thể chạy trình duyệt, dùng HTTP/integration tests phù hợp và đánh dấu browser UX là `NOT_VERIFIED`, không giả định PASS.

## 3. Checklist UX bắt buộc

### A. Khả năng truy cập và tải trang
- Trang chủ, login, chi tiết, profile, các trang chức năng thật đều mở được; không có HTTP 404/500, trang trắng, redirect loop hay PHP fatal error.
- Route hợp lệ được tải trực tiếp, refresh và mở bằng tab mới theo quyền người dùng.
- Đường dẫn sai phải có trang 404 thân thiện, chứa liên kết trở về trang an toàn; lỗi server có trang 500 hữu ích cho người dùng nhưng không lộ stack trace/SQL/secrets.
- Asset CSS/JS/ảnh tải đúng; không có broken image do sai đường dẫn; có fallback phù hợp khi ảnh bị thiếu.
- Nếu mất kết nối database/service: hệ thống thông báo rõ và có cách thử lại/quay về, không hiển thị lỗi thô.

### B. Điều hướng và khả năng thoát khỏi ngõ cụt
- Mỗi màn hình có cách tiến, quay lại hoặc trở về trang chính phù hợp với luồng thực tế.
- Back của trình duyệt không gây submit trùng, vòng lặp hoặc màn hình không còn hợp lệ.
- Menu/logo/breadcrumb/back link trỏ đúng route; menu active đúng trạng thái.
- Sau đăng nhập, người dùng đến đích hợp lý; không redirect tới trang không tồn tại. Sau logout, trang cần đăng nhập không còn truy cập được bằng Back/refresh.
- Không có dead-end, nút không làm gì, liên kết `#`, button giả, hoặc CTA dẫn sang trang lỗi.
- Sau thao tác thành công hoặc thất bại, trạng thái và bước tiếp theo rõ ràng.

### C. Form và phản hồi
- Label, placeholder, help text, required fields thống nhất; nhập bằng bàn phím đầy đủ.
- Input sai có thông báo dễ hiểu gần trường lỗi; dữ liệu hợp lệ đã nhập được giữ lại khi có lỗi, trừ dữ liệu nhạy cảm.
- Có trạng thái loading/submitting; chống double-submit; không tạo bản ghi trùng do click nhanh.
- Thành công có confirmation; thất bại có nguyên nhân phù hợp và cách khắc phục/thử lại.
- Dialog/modal đóng, cancel, escape và focus hoạt động đúng; không làm mất dữ liệu ngoài ý muốn.
- Các thao tác phá hủy có xác nhận hợp lý; lỗi phải giữ trạng thái dữ liệu nhất quán.

### D. Dữ liệu và nghiệp vụ
- Search/filter/sort/pagination hiển thị đúng kết quả và giữ ngữ cảnh hợp lý khi chuyển trang.
- Empty state phân biệt “không có kết quả” với “lỗi tải dữ liệu”, gợi ý bước tiếp theo.
- Thông tin chỗ ở/giá/số khách/ngày/availability/booking lấy từ DB; tránh placeholder được trình bày như dữ liệu thật.
- Luồng đặt chỗ (nếu thuộc chức năng): ngày hợp lệ, không đặt trùng trái quy tắc, giá backend đúng, xử lý transaction và tình huống đồng thời.
- Favorite, profile, listing management, admin CRUD... kiểm tra theo chức năng thật; thay đổi dữ liệu phải xuất hiện nhất quán sau refresh.
- Unauthorized/unauthenticated phải được xử lý đúng, có thông báo hoặc chuyển đến login hợp lý; không rò rỉ dữ liệu người khác.

### E. UX giao diện & khả năng tiếp cận
- Responsive tối thiểu ở 375, 768, 1024, 1440px nếu có browser; không tràn ngang, che nút, che input hay phá bố cục.
- Tương phản, kích thước click target, keyboard focus, thứ tự tab, label và alt text hợp lý.
- Nút disabled có lý do rõ; hover/focus/active/loading/empty/error/success states nhất quán.
- Giữ design language Home2Home: nền kem, xanh lá trầm, tối giản ấm áp, ảnh lưu trú nổi bật, Bootstrap 4.6.2.
- Hạn chế icon mang cảm giác AI, emoji trang trí và thành phần không phục vụ tác vụ.

### F. Kỹ thuật gây ảnh hưởng UX
- Console lỗi JS, request AJAX lỗi, JSON sai, PHP warnings lộ ra UI, CORS/request lỗi, đường dẫn tương đối sai.
- Xử lý session expired, CSRF error, validation 4xx, server 5xx, timeout/offline với thông báo và hành động an toàn.
- Các request bất đồng bộ không hiển thị kết quả cũ đè lên kết quả mới; lỗi không khiến giao diện treo mãi.
- Kiểm tra căn bản: XSS, prepared statements, authorization server-side, upload an toàn, CSRF; không sửa UX bằng cách làm yếu bảo mật.

## 4. User journey testing

Với **mỗi chức năng hiện diện trong `functions.txt`**, tạo ít nhất một journey thành công và các biến thể cần thiết. Không lấy danh sách minh họa dưới đây để thay thế yêu cầu thật.

**Ví dụ hành trình (chỉ dùng nếu chức năng có thật):**
1. Khách chưa đăng nhập → trang chủ → tìm kiếm → lọc → chi tiết chỗ ở → đăng nhập khi cần → đặt chỗ → xem kết quả → trở về lịch sử đặt.
2. Người dùng → login sai → xem lỗi rõ → sửa thông tin → login thành công → profile → cập nhật → refresh để kiểm tra lưu dữ liệu.
3. Host → vào quản lý chỗ ở → tạo/sửa → kiểm tra lỗi trường → lưu → mở trang chi tiết → quay về danh sách.
4. Admin → đăng nhập → vào module CRUD được cho phép → tạo/sửa/xóa → đối chiếu database và phân quyền.

Với mỗi journey, kiểm tra tối thiểu:
- **Happy path**: thao tác đúng dẫn đến kết quả đúng.
- **Invalid input**: dữ liệu thiếu/sai định dạng/sai logic.
- **Empty state**: không có dữ liệu hoặc kết quả.
- **Error path**: DB/network/server lỗi có kiểm soát khi thử nghiệm an toàn.
- **Navigation**: reload, direct link, browser Back, menu, breadcrumb, sau login/logout.
- **Permissions**: chưa đăng nhập, vai trò không đủ quyền, người dùng khác.
- **Responsive/keyboard** khi công cụ hỗ trợ.

## 5. Thang mức độ ưu tiên lỗi

| Mức | Tên | Ví dụ | Hành động |
| --- | --- | --- | --- |
| P0 | Blocker | Website không chạy, lỗi 500 toàn hệ thống, dữ liệu bị ghi sai nghiêm trọng, lộ quyền/đọc dữ liệu người khác | Dừng mở rộng tính năng; xử lý ngay |
| P1 | Critical journey | Không login được, không hoàn thành luồng đặt chỗ, redirect loop, mất dữ liệu khi submit | Sửa trước các lỗi trình bày |
| P2 | Major usability | Không có cách quay lại phù hợp, lỗi form không rõ, tìm kiếm không giữ bộ lọc, CTA chết | Sửa trong lượt audit hiện tại |
| P3 | Minor polish | Căn lề, khoảng cách, hover, icon thừa, khác biệt nhỏ so với mẫu | Sửa sau P0–P2 |

**Ưu tiên theo:** Severity × Frequency × User impact; nếu bằng nhau thì Must-have trước Important, sau đó Optional.

## 6. Định dạng báo cáo audit

Tạo và cập nhật `docs/user-journey-audit.md` với bảng:

| Issue ID | Role/Journey | URL/Route | Steps to reproduce | Expected | Actual | Severity | Root cause | Files changed | Verification | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |

Trạng thái: `OPEN`, `IN_PROGRESS`, `FIXED`, `VERIFIED`, `BLOCKED`, `NOT_VERIFIED`.

Tạo `docs/user-test-matrix.md` ghi từng hành trình, case ID, vai trò, kiểm thử đã chạy, kết quả (`PASS/FAIL/NOT_VERIFIED/NOT_APPLICABLE`) và bằng chứng (log, test name, ảnh chụp nếu thực có).

Không bịa ảnh chụp, log, kết quả test hay URL. Che dữ liệu riêng tư trong minh chứng.

## 7. Quy trình sửa lỗi tự chủ

**Bước 1 — Baseline:** Kiểm tra cách khởi chạy, trang tối thiểu, trạng thái Git, hiện trạng test; không ghi đè các thay đổi đang có của người khác.

**Bước 2 — Discover:** Đọc `functions.txt`, map route, role, view, controller, model, SQL. Lập danh sách hành trình theo mức ưu tiên.

**Bước 3 — Exercise:** Chạy app thực tế; dùng browser automation nếu có, nếu không dùng HTTP/PHP/integration tests phù hợp. Ghi sự cố có thể tái hiện.

**Bước 4 — Triage:** Gắn P0–P3; chọn một lỗi hoặc một cụm lỗi có nguyên nhân chung.

**Bước 5 — Diagnose:** Đọc logs, network response, route/controller/view/data, tìm nguyên nhân gốc thay vì chữa triệu chứng.

**Bước 6 — Fix:** Chỉnh mã nguồn tối thiểu; đảm bảo bảo mật, nghiệp vụ và phong cách Bootstrap/UX.

**Bước 7 — Verify:** Chạy lại case lỗi, kiểm tra happy path, negative path, direct URL và navigation; thử regression liên quan.

**Bước 8 — Record:** Cập nhật audit, test matrix, `docs/progress.md` nếu có. Không đánh dấu VERIFIED khi thiếu test.

**Bước 9 — Repeat:** Tiếp tục đến khi không còn lỗi P0–P2 có thể khắc phục và các journey Must-have kiểm chứng PASS, hoặc gặp blocker thật sự. Sau đó xử lý P3 trong phạm vi hợp lý.

Nếu thử sửa một lỗi nhiều lần không tiến triển, dừng vòng lặp vô ích, ghi blocker và chuyển sang lỗi độc lập khác. Không hứa tự tiếp tục sau khi agent session dừng.

## 8. Definition of done

Một UX journey đạt khi:
- Hoàn thành được nhiệm vụ thật bằng dữ liệu/luồng thật.
- Người dùng luôn hiểu hệ thống đang làm gì, kết quả là gì và bước tiếp theo là gì.
- Không tồn tại ngõ cụt điều hướng trong các trạng thái chính.
- Trạng thái lỗi có cơ chế phục hồi phù hợp.
- Không phát sinh lỗi route, JS, PHP, DB nghiêm trọng.
- Quyền truy cập và dữ liệu được bảo vệ.
- Có test hoặc quan sát thực tế hỗ trợ trạng thái PASS.

**Toàn bộ audit đạt khi:** Các journey Must-have đều VERIFIED/PASS, không còn P0/P1/P2 chưa giải quyết (trừ BLOCKED có ghi lý do xác thực), regression liên quan PASS và tài liệu được cập nhật. Không tuyên bố 100% nếu còn NOT_VERIFIED.

## 9. Khi áp dụng cùng skill khác

- `skill_web.md`: chuẩn kỹ thuật PHP OOP + MVC, MySQL, AJAX JSON, Bootstrap 4.6.2 và bảo mật.
- `skill_UIUX.md`: nghiên cứu luồng, feedback, affordance, signifiers, mapping, accessibility và tính nhất quán.
- `skill_user.md` (file này): kiểm thử từ góc nhìn người dùng, khắc phục friction và kiểm chứng end-to-end.
- `functions.txt`: phạm vi nghiệp vụ; không thêm chức năng tùy hứng.

Nếu phát hiện một quy tắc kiểm thử có giá trị lâu dài, có thể đề xuất bổ sung vào skill phù hợp; không ghi đè hoặc xóa nội dung cũ.
