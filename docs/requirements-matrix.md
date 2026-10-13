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
| MAP-01 | Hiển thị vị trí bản đồ | Important | Guest | ListingController | Listing | listings/show, components/discovery-mini-map | listings.latitude/longitude | feature_discovery_test (lưu tọa độ); bản đồ nhỏ chưa có test tự động | IMPLEMENTED |
| WISH-01 | Lưu/bỏ lưu/xem yêu thích | Important | Guest | WishlistController | Wishlist | home/wishlist | wishlist_items | lint | IMPLEMENTED |
| SHARE-01 | Chia sẻ/copy link | Important | Guest | — | JS | listing/show | — | browser chưa chạy | IMPLEMENTED |
| REPORT-01 | Báo cáo/khiếu nại và xử lý | Important | Guest/Admin | — | — | — | reports | — | NOT_STARTED |
| ADMIN-CAT-01 | CRUD loại chỗ ở/tiện nghi | Important | Admin | — | — | — | property_types,amenities | — | NOT_STARTED |
| ADMIN-STAT-01 | Dashboard số liệu | Important | Admin | AdminController | AdminRepository | admin/dashboard | aggregate queries | lint | IMPLEMENTED |
| AUDIT-01 | Nhật ký quản trị | Important | Hệ thống | AdminController | AdminRepository | — | admin_audit_logs | lint | IMPLEMENTED |
| OPTIONAL-01 | Tin nhắn theo booking, inbox, nút liên hệ Zalo | Optional | Guest/Host | MessageController | BookingMessage/ConversationService/ZaloLink | messages/index, messages/thread, bookings/show | booking_messages,notifications | feature_conversation_test | TESTED |
| OPTIONAL-02 | Thanh toán khi nhận phòng: trạng thái, Host/Admin đánh dấu đã thu, Admin hoàn tiền | Optional | Guest/Host/Admin | BookingExtrasController | BookingExtras | bookings/show, host/dashboard, admin/payments | booking_payments,booking_payment_events | feature_booking_extras_test | TESTED |
| OPTIONAL-03 | Giá cuối tuần/ngày lễ, giảm giá theo tuần/tháng | Optional | Host/Admin | BookingExtrasController | BookingExtras/BookingService | host/pricing, admin/holidays, listings/show | listing_booking_settings,public_holidays,booking_price_breakdowns | feature_booking_extras_test | TESTED |
| OPTIONAL-04 | Xác minh danh tính SĐT/CCCD, huy hiệu đã xác minh | Optional | Guest/Host/Admin | TrustController, ProfileController | Trust | profile/verification, admin/verifications, components/trust-badges | identity_verifications | feature_trust_test | TESTED |
| OPTIONAL-05 | Chế độ tối (dark mode) | Optional | Chung | — (layout) | theme.js | layouts/main, layouts/auth | — | feature_content_audit.ps1 | TESTED |
| OPTIONAL-06 | CRUD tin tức/cẩm nang, trang bài viết công khai | Optional | Admin/Chung | AdminArticleController, ArticleController | Article/ArticleText | admin/articles/*, articles/* | articles | feature_content_test + feature_content_audit.ps1 | TESTED |
| OPTIONAL-07 | Mã giảm giá/khuyến mãi | Optional | Guest/Admin | BookingExtrasController, ListingController (quote), BookingController | BookingExtras/BookingService | admin/promotions, listings/show | promotions,booking_price_breakdowns | feature_booking_extras_test | TESTED |
| OPTIONAL-08 | Đặt ngay (Instant Book) | Optional | Host/Guest | BookingExtrasController, BookingController | BookingService | host/pricing, listings/show | listing_booking_settings,bookings | feature_booking_extras_test (kể cả hai process tranh chấp) | TESTED |
| OPTIONAL-09 | Huy hiệu Host uy tín | Optional | Host/Guest/Admin | Home/Listing/Host/AdminController | Trust | components/trust-badges, host/trust-status, admin/trust-host-state | reviews,bookings | feature_trust_test | TESTED |
| OPTIONAL-10 | Hướng dẫn nhận phòng (mã cửa, Wi-Fi) | Optional | Host/Guest | TrustController, BookingController | Trust | host/checkin-guide, bookings/checkin-guide | listing_checkin_guides | feature_trust_test | TESTED |
| OPTIONAL-11 | Tìm kiếm bằng cách kéo bản đồ | Optional | Guest | DiscoveryController | Discovery/DiscoveryService | discovery/map, map.js | listings (latitude/longitude) | feature_discovery_test (API khung bản đồ); thao tác kéo thật chỉ kiểm tra thủ công | TESTED |
| OPTIONAL-12 | Gợi ý theo lịch sử xem/tìm kiếm, xóa lịch sử | Optional | Guest | HomeController, ListingController, ProfileController, DiscoveryController | Discovery/DiscoveryService | components/discovery-home, components/discovery-history | listing_views,search_history | feature_discovery_test | TESTED |
| OPTIONAL-13 | Nhắc lịch trước ngày check-in (thông báo trong ứng dụng) | Optional | Guest/Host | NotificationController, `database/checkin_reminders.php` (CLI) | CheckInReminder/ConversationService | notifications/index | booking_reminders,notifications | feature_conversation_test | TESTED |
| OPTIONAL-14 | Admin quản lý tin nhắn (tìm, ẩn/hiện, audit) | Optional | Admin | MessageController | BookingMessage | admin/messages | booking_messages,admin_audit_logs | feature_conversation_test | TESTED |
| OPTIONAL-15 | Thanh toán trực tuyến VNPay/MoMo | Optional | Guest/Admin | — | — | — | booking_payments.method (điểm tích hợp dự kiến) | — | NOT_STARTED |
| OPTIONAL-16 | Giao diện tiếng Việt/tiếng Anh | Optional | Chung | — | — | — | — | — | IN_PROGRESS |

Cập nhật 2026-10-13: OPTIONAL-01 đến OPTIONAL-14 được tách theo từng chức năng Nice-to-have đã gộp; các test `feature_*` chạy tự động trong `php tests/fresh_setup_test.php --quality-regression` (gate sau gộp: exit 0, 264 dòng PASS). VNPay/MoMo cần thông tin merchant nên chưa làm; giao diện tiếng Việt/tiếng Anh đang được xây dựng. Trạng thái chi tiết theo từng dòng yêu cầu: [quality/requirements-matrix.md](quality/requirements-matrix.md).

