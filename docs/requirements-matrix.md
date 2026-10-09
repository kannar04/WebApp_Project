# Requirement Traceability Matrix

Trạng thái: `TESTED` chỉ dùng khi đã có kiểm chứng tự động tương ứng; `IMPLEMENTED` là có code nhưng chưa đủ E2E; `NOT_STARTED` là chưa có code hoàn chỉnh.

| ID | Chức năng | Ưu tiên | Vai trò | Controller | Model/Service | View | Database | Test | Trạng thái |
|---|---|---|---|---|---|---|---|---|---|
| AUTH-01 | Đăng ký/đăng nhập/đăng xuất | Must | Chung | AuthController | User/Auth | auth/* | users,user_roles | HTTP login đã chạy | TESTED |
| AUTH-02 | Quản lý session và role | Must | Hệ thống | Core | Auth/Session | layout | user_roles | role page guards | IMPLEMENTED |
| PROFILE-01 | Xem/cập nhật hồ sơ, avatar | Must | Guest/Host | ProfileController | User/UploadService | profile/show | users | PHP lint | IMPLEMENTED |
| HOST-ROLE-01 | Đăng ký vai trò Host | Must | Guest | ProfileController | User | profile/show | user_roles | PHP lint | IMPLEMENTED |
| LISTING-01 | Tạo/sửa listing đầy đủ | Must | Host | HostController | Listing | host/listing-form | listings | schema + lint | IMPLEMENTED |
| LISTING-02 | Upload/quản lý ảnh | Must | Host | HostController | UploadService/Listing | host/listing-form | listing_photos | MIME/size code review | IMPLEMENTED |
| LISTING-03 | Ẩn/hiện listing | Must | Host | HostController | Listing | host/dashboard | listings | ownership code review | IMPLEMENTED |
| AVAIL-01 | Xem/chặn/mở ngày trống | Must | Host | HostController | Listing | host/availability | listing_availability | HTTP block + DB + cleanup | TESTED |
| HOST-BOOK-01 | Xem yêu cầu/lịch sử booking | Must | Host | HostController | Booking | host/dashboard | bookings | HTTP host dashboard | TESTED |
| HOST-BOOK-02 | Chấp nhận/từ chối/hoàn thành | Must | Host | HostController | BookingService | host/dashboard | bookings,events | booking_flow_test | TESTED |
| SEARCH-01 | Danh sách tin đã duyệt/hiển thị | Must | Guest | HomeController | Listing | home | listings,photos | HTTP smoke | TESTED |
| SEARCH-02 | Tìm địa điểm/ngày/số khách | Must | Guest | HomeController | Listing | home | listings,availability,bookings | query integration | IMPLEMENTED |
| DETAIL-01 | Chi tiết ảnh/giá/tiện nghi/Host/lịch | Must | Guest | ListingController | Listing | listings/show | 7 bảng liên quan | HTTP smoke | TESTED |
| BOOK-01 | Quote server theo ngày/phí | Must | Guest | ListingController | BookingService | listing/show | listings,availability | HTTP + booking test | TESTED |
| BOOK-02 | Gửi yêu cầu và lưu snapshot | Must | Guest | BookingController | BookingService | listing/show | bookings,nights,events | booking_flow_test | TESTED |
| BOOK-03 | Danh sách/lịch sử/chi tiết trạng thái | Must | Guest | BookingController | Booking | bookings/index | bookings | HTTP authenticated | TESTED |
| BOOK-04 | Hủy theo chính sách | Must | Guest | BookingController | BookingService | bookings/index | cancellations,events | booking_flow_test | TESTED |
| SYS-VALIDATE-01 | Ngày/sức chứa/khả dụng | Must | Hệ thống | Controllers | BookingService | form/JSON | listings,availability | booking_flow_test | TESTED |
| SYS-DOUBLE-01 | Chống đặt trùng | Must | Hệ thống | Host/Booking | BookingService | JSON/flash | bookings | overlap assertion | TESTED |
| SYS-PRICE-01 | Tính server và snapshot giá | Must | Hệ thống | BookingController | BookingService | quote | bookings,nights | amount/nights assertion | TESTED |
| SYS-LIFECYCLE-01 | Vòng đời + đồng bộ sự kiện | Must | Hệ thống | Host/Booking | BookingService | dashboards | bookings,events | 3 event assertion | TESTED |
| ADMIN-USER-01 | Tìm/xem/khóa/mở/role | Must | Admin | AdminController | User/AdminRepository | admin/dashboard | users,roles,audit | HTTP Admin | TESTED |
| ADMIN-USER-02 | Thêm/sửa/xóa user | Must | Admin | AdminController | User/AdminRepository | admin/dashboard | users,roles,audit | create/delete/audit HTTP | TESTED |
| ADMIN-LIST-01 | Duyệt/từ chối kèm lý do | Must | Admin | AdminController | AdminRepository | admin/dashboard | moderation_events,audit | lint | IMPLEMENTED |
| ADMIN-LIST-02 | Sửa/ẩn/gỡ listing vi phạm | Must | Admin | AdminController | AdminRepository | admin/listing-edit | listings,audit | edit GET + lint | IMPLEMENTED |
| ADMIN-BOOK-01 | Tra cứu booking | Must | Admin | AdminController | Booking | admin/dashboard | bookings | lint | IMPLEMENTED |
| ADMIN-BOOK-02 | Cập nhật trạng thái theo quyền | Must | Admin | AdminController | BookingService | admin/dashboard | bookings,events | booking_flow_test | TESTED |
| PASSWORD-01 | Quên/đổi mật khẩu | Important | Chung | — | — | — | password_reset_tokens | — | NOT_STARTED |
| HOUSE-01 | Nội quy/check-in/check-out | Important | Host/Guest | Host/Listing | Listing | form/detail | listings | HTTP detail | IMPLEMENTED |
| POLICY-01 | Chính sách hủy/refund | Important | Chung | BookingController | BookingService | detail/bookings | policies,snapshot,cancellations | refund assertion | TESTED |
| REVIEW-01 | Guest đánh giá một lần sau lưu trú | Important | Guest | BookingController | Review | bookings/detail | reviews | constraint + code | IMPLEMENTED |
| REVIEW-02 | Host phản hồi/Admin quản lý review | Important | Host/Admin | — | — | — | reviews | — | NOT_STARTED |
| NOTIFY-01 | Thông báo theo booking event | Important | Chung | Booking/Host | BookingService | — | notifications | count assertion | TESTED |
| HOST-STAT-01 | Thống kê Host/doanh thu | Important | Host | HostController | HostRepository | host/dashboard | listings,bookings | lint | IMPLEMENTED |
| SEARCH-ADV-01 | Giá/loại/phòng/amenity/sort/page | Important | Guest | HomeController | Listing | home | listings,amenities | một phần | IN_PROGRESS |
| MAP-01 | Hiển thị vị trí bản đồ | Important | Guest | — | — | — | lat/longitude | — | NOT_STARTED |
| WISH-01 | Lưu/bỏ lưu/xem yêu thích | Important | Guest | WishlistController | Wishlist | home/wishlist | wishlist_items | lint | IMPLEMENTED |
| SHARE-01 | Chia sẻ/copy link | Important | Guest | — | JS | listing/show | — | browser chưa chạy | IMPLEMENTED |
| REPORT-01 | Báo cáo/khiếu nại và xử lý | Important | Guest/Admin | — | — | — | reports | — | NOT_STARTED |
| ADMIN-CAT-01 | CRUD loại chỗ ở/tiện nghi | Important | Admin | — | — | — | property_types,amenities | — | NOT_STARTED |
| ADMIN-STAT-01 | Dashboard số liệu | Important | Admin | AdminController | AdminRepository | admin/dashboard | aggregate queries | lint | IMPLEMENTED |
| AUDIT-01 | Nhật ký quản trị | Important | Hệ thống | AdminController | AdminRepository | — | admin_audit_logs | lint | IMPLEMENTED |
| OPTIONAL-01 | Tin nhắn/liên hệ | Optional | Guest/Host | — | — | — | — | — | NOT_STARTED |
| OPTIONAL-02 | Thanh toán VNPay/MoMo | Optional | Guest | — | — | — | — | — | NOT_STARTED |
| OPTIONAL-03 | Giá linh hoạt/mã giảm/Instant Book | Optional | Chung | — | — | — | — | — | NOT_STARTED |
| OPTIONAL-04 | Xác minh/huy hiệu/gợi ý/check-in guide | Optional | Chung | — | — | — | — | — | NOT_STARTED |
| OPTIONAL-05 | Song ngữ/dark mode/nhắc lịch | Optional | Chung | — | — | — | — | — | NOT_STARTED |
| OPTIONAL-06 | CRUD tin tức/cẩm nang | Optional | Admin | — | — | — | — | — | NOT_STARTED |

