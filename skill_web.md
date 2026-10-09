# SKILL_LẬP TRÌNH WEB — VERSION 1.0

> **Tên gọi:** `Skill_Lập trình Web`  
> **Mục đích:** Bộ kỹ năng tái sử dụng cho bài lab PHP–MySQL, xây dựng giao diện, kiểm tra code, viết prompt cho coding agent và phát triển đồ án Web.  
> **Stack nền tảng:** HTML5, CSS3, JavaScript, Bootstrap 4.6.2, PHP, MySQL, phpMyAdmin, XAMPP, AJAX.

## 1. Mục tiêu và nguyên tắc

- Phân tích yêu cầu, thiết kế, triển khai, kiểm thử và hoàn thiện các bài tập hoặc dự án Web.
- Ưu tiên kỹ thuật đã học trong slide và ví dụ code do người dùng cung cấp.
- Viết code **tường minh, dễ đọc, dễ giải thích và đúng yêu cầu**.
- Không tùy tiện thêm framework, thư viện, tính năng hoặc nâng cấp phiên bản ngoài phạm vi công việc.
- Khi đề bài cụ thể khác quy tắc mặc định, **ưu tiên đề bài**; nếu có xung đột với an toàn dữ liệu, nêu rõ vấn đề và giải pháp phù hợp.

## 2. HTML Structure

**Kiến thức:** `<!DOCTYPE html>`, `html`, `head`, `body`; thẻ văn bản, liên kết, hình ảnh, danh sách; `table`, `thead`, `tbody`, `tr`, `th`, `td`; `form`, `input`, `select`, `option`, `textarea`, `button`, `label`; thuộc tính `id`, `class`, `name`, `value`, `method`, `action`.

**Quy tắc:**
- Sử dụng thẻ HTML đúng ngữ nghĩa và cấu trúc rõ ràng.
- Không dùng bảng để chia bố cục nếu đề bài không yêu cầu.
- Kiểm tra tính hợp lệ của HTML và kết nối biểu mẫu với luồng xử lý.

## 3. CSS & Layout

**Kiến thức:** CSS inline/internal/external; selector tag/class/ID; width, height, margin, padding, border, box model, display, float; fixed/fluid/hybrid layout; specificity và thứ tự ưu tiên.

**Quy tắc:**
- Ưu tiên CSS external khi phù hợp.
- Không lạm dụng `!important` hoặc khai báo CSS trùng lặp.
- Chọn giải pháp layout đơn giản, đáp ứng đúng giao diện yêu cầu.

## 4. Bootstrap 4.6.2

**Phiên bản mặc định bắt buộc khi dùng Bootstrap:** **4.6.2**.

**Kiến thức:** Grid System 12 cột, container/row/col, breakpoints, responsive utilities, typography, spacing, buttons, forms, tables, cards, navbar và các component phù hợp.

**Quy tắc:**
1. Tuân thủ bố cục **`container → row → col`**.
2. Ưu tiên class và component Bootstrap thay cho CSS tự viết nếu có thể.
3. Chỉ thêm custom CSS khi cần thiết.
4. Không tự nâng lên Bootstrap 5 hoặc phiên bản khác.
5. Kiểm tra responsive theo yêu cầu bài.
6. Dùng đúng cú pháp và class của Bootstrap 4.6.2.

## 5. JavaScript & DOM

**Kiến thức:** biến, hàm, điều kiện, vòng lặp; thao tác DOM; sự kiện `onclick`, `onchange`, `onkeyup`; validation phía client và cập nhật nội dung không tải lại trang.

**Quy tắc:**
- Ưu tiên JavaScript thuần nếu không cần thư viện ngoài.
- Gắn đúng event handler, tránh logic lặp, tách xử lý khi phù hợp.
- Không tự thêm React, Vue, Angular hay framework khác nếu không được yêu cầu.
- Validation phía client không thay thế validation tại server.

## 6. PHP Fundamentals & OOP

**Kiến thức:** cú pháp PHP, biến, kiểu dữ liệu, toán tử, điều kiện, vòng lặp, hàm, tham số, mảng, GET/POST, PHP kết hợp HTML, class, object.

**Quy tắc:**
- Viết logic rõ ràng và bám sát kỹ thuật trong slide.
- Nếu yêu cầu tự triển khai thuật toán (ví dụ Bubble Sort), **không thay thế bằng hàm thư viện có sẵn** như `sort()`.
- Không dùng framework PHP ngoài phạm vi đề.
- Kiểm tra đầu vào ở server và escape dữ liệu khi đưa ra HTML.

## 7. MySQL & Database

**Kiến thức:** tạo database/table, kiểu dữ liệu, primary key, foreign key, CRUD (`SELECT`, `INSERT`, `UPDATE`, `DELETE`), `WHERE`, `ORDER BY`, kết nối PHP–MySQL, Stored Procedures, Routines, phpMyAdmin và XAMPP.

**Quy tắc:**
- Thiết kế bảng, cột, khóa và quan hệ theo đúng đề bài.
- Không tự sửa cấu trúc database hiện có khi chưa cần thiết.
- Dùng prepared statements cho truy vấn chứa dữ liệu đầu vào; tránh nối chuỗi SQL trực tiếp.
- Kiểm tra kết nối, lỗi truy vấn và trường hợp không có dữ liệu.
- Không đưa mật khẩu hoặc thông tin nhạy cảm vào mã nguồn công khai; với ứng dụng thực tế, mật khẩu phải được băm, không hiển thị dạng rõ.

## 8. PHP MVC Architecture

**Vai trò:**
- **Model:** định nghĩa entity, quản lý danh sách và thao tác dữ liệu. Ví dụ `UserEntity`, `Users`.
- **View:** hiển thị biểu mẫu, bảng dữ liệu và HTML/Bootstrap. Ví dụ `ListUser`.
- **Controller:** nhận request, gọi Model và chọn View/kết quả trả về. Ví dụ `List`.

**Quy tắc:**
- Phân tách trách nhiệm giữa Model, View, Controller.
- Tránh viết truy vấn SQL trực tiếp trong View.
- Không dồn tất cả logic vào một file khi yêu cầu MVC.
- Giữ nguyên tên class, file, cấu trúc thư mục do đề hoặc dự án quy định.

## 9. AJAX & Live Search

**Luồng chuẩn:**

`Người dùng nhập → onkeyup → AJAX request → PHP Controller → Model → MySQL → Response → cập nhật DOM/View`

**Kiến thức:** gửi request bằng JavaScript, PHP xử lý, truy vấn database, trả response và cập nhật nội dung mà không tải lại toàn trang.

**Quy tắc:**
- Dùng phương thức gọi AJAX được bài yêu cầu, ví dụ hàm `getdataajax` nếu đề chỉ định.
- Xử lý trường hợp dữ liệu rỗng, lỗi request và nhập tìm kiếm nhanh.
- Escape dữ liệu khi render và đảm bảo lọc đúng từ khóa.
- Không tự thêm thư viện AJAX khi bài yêu cầu JavaScript thuần.

## 10. Debugging & Code Validation

**Checklist:**
1. Kiểm tra cú pháp HTML, CSS, JavaScript, PHP, SQL.
2. Kiểm tra đường dẫn `include`/`require`, assets và cấu trúc thư mục.
3. Kiểm tra XAMPP, kết nối MySQL, truy vấn và dữ liệu mẫu.
4. Kiểm tra form, button, sự kiện, validation và CRUD.
5. Kiểm tra luồng MVC.
6. Kiểm tra AJAX, response và cập nhật DOM.
7. Kiểm tra Bootstrap 4.6.2, Grid System và giao diện.
8. Đối chiếu kết quả với từng yêu cầu và ảnh minh họa.

**Quy tắc:** xác định nguyên nhân gốc, chỉ sửa phần liên quan, không phá chức năng đang hoạt động; phân biệt lỗi cú pháp, logic, kết nối và UI. Không báo đã kiểm thử thành công khi chưa chạy kiểm thử thực tế.

## 11. Quy trình phân tích bài Lab và viết prompt cho Coding Agent

**Bước 1 — Đọc yêu cầu:** xác định mục tiêu, chức năng, dữ liệu, công nghệ được phép và tiêu chí hoàn thành.

**Bước 2 — Khai thác nguồn:** đọc slide, trích code mẫu, nêu rõ giới hạn kỹ thuật; không dùng chỉ dẫn mơ hồ như “dựa vào skill trong file” trong prompt gửi agent khi agent không truy cập được file.

**Bước 3 — Thiết kế:** liệt kê file cần tạo/sửa, luồng xử lý, cấu trúc MVC (nếu cần), database và UI.

**Bước 4 — Triển khai:** làm từng chức năng, tận dụng code mẫu phù hợp, tuân thủ Bootstrap 4.6.2 và quy tắc PHP–MySQL.

**Bước 5 — Kiểm thử:** thử từng luồng, kiểm tra lỗi và so sánh với yêu cầu.

**Bước 6 — Báo cáo:** nêu file đã thay đổi, chức năng đã làm, cách chạy, kết quả test và các hạn chế còn lại.

**Yêu cầu với prompt cho agent:** cụ thể, độc lập, có tên file/class/hàm/đầu vào/đầu ra, hạn chế công nghệ và tiêu chí nghiệm thu; không dùng câu chung chung hoặc giả định agent có tài liệu mà chưa được cung cấp.

## 12. Chế độ áp dụng

| Chế độ | Mục đích | Ưu tiên |
|---|---|---|
| **A — Academic Lab** | Hoàn thành lab theo slide | Đúng bài, đúng giới hạn kỹ thuật, giải thích được code |
| **B — Web Project** | Xây dựng đồ án Web nhiều chức năng | Kiến trúc rõ ràng, dữ liệu nhất quán, tính thực tế |
| **C — Code Review** | Kiểm tra và sửa mã nguồn | Phát hiện lỗi, sửa tối thiểu, bảo toàn hành vi |

**Chế độ mặc định:** dùng **Academic Lab** khi làm bài lab; dùng **Web Project** khi làm đồ án. Có thể kết hợp Code Review và Prompt Engineering theo yêu cầu.

## 13. Phạm vi kiến thức và mở rộng

Các kỹ năng trên tổng hợp từ tài liệu và bài tập đã trao đổi. Không mặc định người học đã được đào tạo chính thức về REST API, deployment, bảo mật chuyên sâu, automated testing hoặc framework hiện đại nếu chưa có tài liệu xác nhận.

Khi có slide mới: bổ sung nội dung, nguồn tương ứng và thay đổi phiên bản skill, không tự động ghi đè các nguyên tắc nền tảng.

## 14. Cách gọi tái sử dụng

- `Sử dụng Skill_Lập trình Web (chế độ Academic Lab) để phân tích bài này, xác định giới hạn công nghệ và tạo prompt Codex hoàn chỉnh.`
- `Áp dụng Skill_Lập trình Web (chế độ Web Project) để thiết kế và triển khai chức năng CRUD với PHP MVC, MySQL và Bootstrap 4.6.2.`
- `Dùng Skill_Lập trình Web (chế độ Code Review) kiểm tra lỗi cú pháp, logic, bảo mật đầu vào, MVC và giao diện.`
- `Dùng Skill_Lập trình Web (chế độ Prompt Engineering) viết prompt độc lập, chi tiết cho coding agent, bao gồm các bước test.`

---

*Phiên bản 1.0 — Bộ kỹ năng tái sử dụng, có thể cập nhật theo những slide và lab Web mới.*

## HOME2HOME PROJECT EXTENSION

Phần này là kỹ thuật mở rộng riêng cho đồ án Home2Home, không thay thế nội dung học tập phía trên.

### Phân loại nguồn kỹ năng

- **Đã được tài liệu học tập xác nhận:** HTML/CSS/JavaScript, Bootstrap 4.6.2, PHP OOP, MySQL, MVC, AJAX, CRUD, prepared statement và quy trình debug cơ bản.
- **Mở rộng cần thiết cho dự án:** front controller/router, service xử lý transaction, session authentication, role/ownership authorization, CSRF, password hashing, upload ảnh an toàn, JSON endpoint, accessibility, automated integration test và cấu hình triển khai.
- **Chưa triển khai thực tế:** reset mật khẩu bằng token/email, external payment, bản đồ, email delivery, CI/CD và production deployment. Không được mô tả các mục này là đã hoàn thành.

### Kiến trúc PHP OOP + MVC thực tế

- Mọi request đi qua `public/index.php`, bootstrap autoload/config/session rồi Router chọn Controller.
- Controller validate request và authorization, gọi Model hoặc Service, sau đó trả View/redirect/JSON.
- Model chỉ truy cập dữ liệu qua PDO prepared statement; View không chứa SQL.
- Service dùng cho nghiệp vụ nhiều bảng hoặc cần transaction, đặc biệt booking, snapshot giá, event và notification.
- Không tạo lớp trung gian nếu không có trách nhiệm rõ ràng.

### Request lifecycle và routing

`Browser → Apache/.htaccess → Front Controller → Router → Controller → Model/Service → PDO/MySQL → View hoặc JSON → Browser`.

Route động phải ép kiểu ID và Controller luôn kiểm tra ownership/role; không tin ID gửi từ client.

### Database và dataflow

- Cấu hình DB tập trung trong `config/database.php`, lấy credential từ environment.
- Dùng `utf8mb4`, exception mode, native prepared statement và transaction cho thay đổi liên bảng.
- Giá booking phải tính lại ở server, lưu `booking_nights` và `policy_snapshot`; không nhận tổng tiền từ browser.
- Khi chuyển trạng thái booking phải khóa bản ghi, kiểm tra overlap, ghi `booking_events`, cập nhật notification trong cùng transaction.
- Schema thay đổi bằng SQL có thể lặp an toàn; không xóa database/dữ liệu thật để sửa lỗi.

### Validation, lỗi và bảo mật

- Validate required/type/range/date ở server; client validation chỉ hỗ trợ UX.
- Hash mật khẩu bằng `password_hash`, kiểm tra bằng `password_verify`, regenerate session ID sau login.
- Mọi POST/AJAX thay đổi trạng thái dùng CSRF token.
- Escape output bằng `htmlspecialchars`; JSON dùng cấu trúc `success`, `message`, `data`, `errors` và HTTP status phù hợp.
- Upload ảnh kiểm tra error, dung lượng và MIME bằng `finfo`; tên file sinh ngẫu nhiên; chỉ cho phép JPEG/PNG/WebP.
- Authorization gồm cả role và ownership; Host không được sửa listing/booking của Host khác.

### Bootstrap, responsive và accessibility

- Dùng Bootstrap 4.6.2 theo `container → row → col`, token CSS tập trung và component tái sử dụng.
- Form có label, ảnh có alt, focus rõ, skip link, heading đúng thứ bậc, touch target đủ lớn.
- Kiểm tra ít nhất desktop/mobile và hỗ trợ `prefers-reduced-motion`.

### Testing, Git và deployment

- Vòng lặp: lint → schema test → service/integration test → HTTP smoke → browser review → regression.
- Test booking phải kiểm tra transaction, snapshot, event, notification, double-booking và cleanup fixture.
- Commit nhỏ theo feature/fix, không force push hoặc commit `.env`, upload và credential.
- XAMPP trỏ DocumentRoot vào `public/`; production tắt debug, dùng HTTPS và account DB có quyền tối thiểu.

### Bài học đã kiểm chứng trong Home2Home

- ERD hiện hữu dùng `base_nightly_rate`, `policy_snapshot`, `booking_nights` và event log; code phải theo schema thay vì tạo tên cột thuận tay.
- Seed Unicode qua PowerShell pipe có thể bị đổi encoding; importer PHP đọc UTF-8 trực tiếp tránh làm hỏng tiếng Việt.
- `CREATE TABLE IF NOT EXISTS` không đồng nghĩa schema cũ đã tương thích; phải audit `information_schema` trước khi seed/chạy code.
- Lint chỉ chứng minh cú pháp; dataflow chỉ được đánh dấu TESTED sau khi đã kiểm tra HTTP và trạng thái thật trong MySQL.
