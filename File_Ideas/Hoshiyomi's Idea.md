HOME 2 HOME — ĐẶC TẢ CHỨC NĂNG
Phiên bản: 1.0 · Nền tảng: Client Website + Admin Website + Backend API
1. Mục tiêu và vai trò
Home 2 Home kết nối người cần thuê chỗ ở với chủ nhà. Khách tìm và đặt chỗ; chủ nhà quản lý tin và lịch; quản trị viên kiểm duyệt hệ thống.
Vai trò
Quyền chính
Guest
Tìm kiếm, xem thông tin chỗ ở
Customer
Đặt chỗ, nhắn tin, lưu yêu thích, đánh giá
Host
Đăng chỗ ở, quản lý lịch, xử lý yêu cầu đặt
Admin
Duyệt tin, quản lý tài khoản và báo cáo

Một tài khoản có thể vừa là Customer vừa là Host.
2. Client Website
2.1. Tài khoản
CL-01: Đăng ký, đăng nhập, đăng xuất, quên/đổi mật khẩu.
CL-02: Xem và cập nhật hồ sơ; đăng ký vai trò Host.
2.2. Khách thuê
CL-03: Tìm theo địa điểm, ngày nhận/trả, số khách; lọc giá, loại nhà, tiện nghi; sắp xếp và phân trang.
CL-04: Xem ảnh, mô tả, địa chỉ, giá, sức chứa, tiện nghi, lịch trống, nội quy, đánh giá.
CL-05: Lưu/bỏ lưu chỗ ở yêu thích.
CL-06: Chọn ngày và số khách, xem tổng tiền, gửi yêu cầu đặt chỗ.
CL-07: Xem lịch sử, trạng thái, chi tiết và hủy đơn theo chính sách.
CL-08: Nhắn tin với chủ nhà và nhận thông báo.
CL-09: Đánh giá 1–5 sao sau khi hoàn thành lưu trú; mỗi đơn một đánh giá.
2.3. Chủ nhà
CL-10: Tạo, chỉnh sửa, ẩn tin; quản lý ảnh, giá, phụ phí, tiện nghi, nội quy.
CL-11: Xem lịch phòng, chặn hoặc mở ngày chưa có đơn xác nhận.
CL-12: Xem yêu cầu đặt, chấp nhận/từ chối và trao đổi với khách.
CL-13: Theo dõi đơn, doanh thu từ đơn hoàn thành và đánh giá.
3. Server: Admin Website và Backend API
3.1. Admin Website
AD-01: Dashboard: người dùng, tin đăng, đơn đặt, doanh thu.
AD-02: Tìm và xem tài khoản; khóa/mở khóa; quản lý vai trò.
AD-03: Duyệt/từ chối tin kèm lý do; ẩn tin vi phạm.
AD-04: Quản lý loại chỗ ở và danh mục tiện nghi.
AD-05: Tra cứu đơn; tiếp nhận và xử lý báo cáo/khiếu nại.
3.2. Backend API
SV-01: Xác thực, quản lý phiên và phân quyền theo vai trò/quyền sở hữu.
SV-02: CRUD chỗ ở; tải ảnh; tìm kiếm, lọc và phân trang tin đã duyệt.
SV-03: Kiểm tra lịch trống, số khách và ngăn xác nhận đặt trùng ngày.
SV-04: Tính tổng tiền trên server và lưu giá tại thời điểm đặt.
SV-05: Quản lý trạng thái đơn, thanh toán, đánh giá, tin nhắn và thông báo.
SV-06: Kiểm tra dữ liệu đầu vào, ghi nhật ký thao tác quản trị.
4. Quy tắc nghiệp vụ
Ngày trả phải sau ngày nhận; số khách không vượt sức chứa.
Tin chỉ xuất hiện công khai khi có trạng thái PUBLISHED.
Đơn CONFIRMED không được trùng khoảng lưu trú với đơn xác nhận khác của cùng chỗ ở. Kiểm tra và xác nhận trong cùng giao dịch dữ liệu.
Giá do server tính: tổng tiền = số đêm × giá/đêm + phụ phí − giảm giá.
Chủ nhà chỉ sửa tin của mình; khách chỉ xem đơn của mình; Admin truy cập chức năng quản trị.
Khách chỉ đánh giá đơn COMPLETED.
Trạng thái tin: DRAFT → PENDING_REVIEW → PUBLISHED hoặc REJECTED; tin đã đăng có thể HIDDEN.
Trạng thái đơn: PENDING → CONFIRMED → COMPLETED; từ PENDING có thể sang REJECTED hoặc CANCELLED; đơn CONFIRMED có thể hủy theo chính sách.
Thanh toán MVP: Thanh toán khi nhận phòng, ghi UNPAID/PAID. Tích hợp cổng thanh toán là phần mở rộng.
5. Dữ liệu chính
Bảng
Nội dung
users, user_roles
Tài khoản và vai trò
properties, property_images, amenities
Tin đăng, ảnh, tiện nghi
blocked_dates, bookings, payments
Lịch, đơn đặt, thanh toán
reviews, conversations, messages
Đánh giá và nhắn tin
notifications, reports
Thông báo và báo cáo vi phạm

6. API tham khảo
Method
Endpoint
Quyền
POST
/api/auth/register, /api/auth/login
Public
GET
/api/properties, /api/properties/{id}
Public
POST / PATCH
/api/properties, /api/properties/{id}
Host/chủ sở hữu
GET
/api/properties/{id}/availability
Public
POST / GET
/api/bookings, /api/bookings/my
Customer
PATCH
/api/bookings/{id}/confirm, /api/bookings/{id}/cancel
Người có quyền
POST
/api/bookings/{id}/reviews
Khách đã lưu trú
GET / PATCH
/api/admin/properties/pending, /api/admin/properties/{id}/review
Admin

7. Tiêu chí hoàn thành MVP
Chủ nhà đăng tin → Admin duyệt → khách tìm và đặt → chủ nhà xác nhận → server ngăn đặt trùng ngày → hoàn thành lưu trú → khách đánh giá
 
