<?php
declare(strict_types=1);

/*
 * English for complete, fixed text produced by the stored procedures in database/procedures/[0-9]*.sql:
 * SIGNAL ... MESSAGE_TEXT errors (shown as flash/JSON messages), notification titles/bodies,
 * booking history reasons and recommendation reasons. Text built with CONCAT(...) lives in
 * patterns_procedures.php. The Vietnamese key must stay byte-for-byte equal to the SQL literal.
 */
return [
    // ---- 010_models.sql
    'Tài khoản không khả dụng.' => 'This account is not available.',
    'Trạng thái tài khoản không hợp lệ.' => 'Invalid account status.',
    'Thông tin hoặc quyền tạo chỗ ở không hợp lệ.' => 'Invalid listing details or no permission to create a listing.',
    'Tọa độ cần có cả vĩ độ (-90 đến 90) và kinh độ (-180 đến 180), hoặc để trống cả hai.' => 'Coordinates need both a latitude (-90 to 90) and a longitude (-180 to 180), or leave both empty.',
    'Thông tin hoặc quyền sửa chỗ ở không hợp lệ.' => 'Invalid listing details or no permission to edit this listing.',
    'Không có quyền thay đổi hiển thị chỗ ở.' => 'You do not have permission to change this listing’s visibility.',
    'Đánh giá không hợp lệ.' => 'Invalid review.',

    // ---- 011_queries.sql
    'Email và họ tên là bắt buộc.' => 'Email and full name are required.',
    'Tài khoản không tồn tại.' => 'The account does not exist.',
    'Phân trang không hợp lệ.' => 'Invalid pagination.',

    // ---- 020_booking.sql: errors
    'Ngày nhận hoặc trả phòng không hợp lệ.' => 'Invalid check-in or check-out date.',
    'Chỗ ở không khả dụng.' => 'This place is not available.',
    'Số khách vượt quá sức chứa.' => 'The number of guests exceeds the capacity.',
    'Khoảng ngày đã chọn có ngày không cho thuê.' => 'The selected dates include days that are not available.',
    'Khoảng ngày này đã có yêu cầu đặt chỗ khác.' => 'These dates already have another booking request.',
    'Bạn không thể đặt chỗ ở của chính mình.' => 'You cannot book your own place.',
    'Booking không tồn tại.' => 'The booking does not exist.',
    'Quyền cập nhật không hợp lệ.' => 'Invalid update permission.',
    'Bạn không có quyền cập nhật booking này.' => 'You do not have permission to update this booking.',
    'Chuyển trạng thái booking không hợp lệ.' => 'Invalid booking status change.',
    'Chỉ có thể hoàn thành booking sau ngày trả phòng.' => 'A booking can only be completed after the check-out date.',
    'Ngày này vừa được booking khác xác nhận.' => 'These dates were just confirmed for another booking.',
    'Khoảng ngày có ngày đang bị chặn.' => 'The selected dates include blocked days.',
    'Booking không tồn tại hoặc không thuộc về bạn.' => 'The booking does not exist or does not belong to you.',
    'Booking ở trạng thái này không thể hủy.' => 'A booking in this status cannot be cancelled.',
    'Chưa cấu hình múi giờ để tính hoàn tiền an toàn.' => 'The time zone is not configured, so the refund cannot be calculated safely.',

    // ---- 020_booking.sql: booking history reasons (booking_events.reason)
    'Khách gửi yêu cầu đặt chỗ.' => 'Guest sent a booking request.',
    'Đặt ngay (Instant Book): hệ thống tự xác nhận.' => 'Instant Book: confirmed automatically by the system.',
    'Admin cập nhật trạng thái.' => 'Admin updated the status.',
    'Host cập nhật trạng thái.' => 'Host updated the status.',
    'Khách hủy booking.' => 'Guest cancelled the booking.',

    // ---- 020_booking.sql: notification titles and fixed bodies
    'Cập nhật booking' => 'Booking update',
    'Yêu cầu đặt chỗ đã được xác nhận.' => 'Your booking request has been confirmed.',
    'Yêu cầu đặt chỗ đã bị từ chối.' => 'Your booking request has been rejected.',
    'Chuyến ở đã hoàn thành.' => 'Your stay has been completed.',
    'Khách đã hủy booking.' => 'The guest cancelled the booking.',
    'Đã hủy booking' => 'Booking cancelled',

    // ---- 030_atomic_writes.sql
    'Thông tin tài khoản không hợp lệ.' => 'Invalid account details.',
    'Vai trò không hợp lệ.' => 'Invalid role.',
    'Không có quyền sửa chỗ ở.' => 'You do not have permission to edit this listing.',
    'Tiện nghi không hợp lệ.' => 'Invalid amenities.',
    'Tiện nghi không tồn tại hoặc đã ngừng sử dụng.' => 'The amenity does not exist or is no longer used.',
    'Không có quyền thêm ảnh chỗ ở.' => 'You do not have permission to add photos to this listing.',
    'Đường dẫn ảnh không hợp lệ.' => 'Invalid photo path.',
    'Ngày không hợp lệ.' => 'Invalid date.',
    'Bạn không có quyền kiểm duyệt.' => 'You do not have moderation permission.',
    'Chỗ ở không tồn tại.' => 'The listing does not exist.',
    'Quyết định kiểm duyệt hoặc lý do không hợp lệ.' => 'Invalid moderation decision or reason.',
    'Bạn không có quyền quản trị.' => 'You do not have Admin permission.',
    'Thông tin chỗ ở không hợp lệ.' => 'Invalid listing details.',
    'Hiển thị không hợp lệ.' => 'Invalid visibility.',

    // ---- 050_quality_core.sql
    'Không có quyền quản lý chỗ ở.' => 'You do not have permission to manage this listing.',
    'Hãy xử lý booking đang chờ hoặc đã xác nhận trước khi xóa. Có thể ẩn tin để ngừng nhận yêu cầu mới.' => 'Handle pending or confirmed bookings before deleting. You can hide the listing to stop receiving new requests.',
    'Ảnh đã thay đổi đồng thời. Hãy tải lại trang trước khi lưu.' => 'The photos were changed at the same time elsewhere. Reload the page before saving.',
    'Danh sách ảnh không hợp lệ.' => 'Invalid photo list.',
    'ID ảnh không hợp lệ.' => 'Invalid photo ID.',
    'Ảnh không thuộc chỗ ở này hoặc bị lặp.' => 'A photo does not belong to this listing or is duplicated.',
    'Khoảng lịch không hợp lệ (tối đa 62 ngày).' => 'Invalid calendar range (maximum 62 days).',
    'Không có quyền xem booking.' => 'You do not have permission to view this booking.',
    'Chưa cấu hình múi giờ tính hoàn tiền.' => 'The time zone for refund calculation is not configured.',
    'Trạng thái lọc không hợp lệ.' => 'Invalid status filter.',
    'Không có quyền Host.' => 'You do not have Host permission.',
    'Không có quyền xem ảnh quản lý.' => 'You do not have permission to view these listing photos.',
    'Thông báo không tồn tại.' => 'The notification does not exist.',

    // ---- 055_quality_search.sql
    'Tiện nghi lọc không hợp lệ.' => 'Invalid amenity filter.',
    'ID tiện nghi không hợp lệ.' => 'Invalid amenity ID.',
    'Tiện nghi lọc đã ngừng sử dụng hoặc không tồn tại.' => 'A filtered amenity is no longer in use or does not exist.',

    // ---- 060_quality_community.sql
    'Phản hồi cần từ 1 đến 5000 ký tự.' => 'Reply must be 1 to 5000 characters.',
    'Không có quyền phản hồi đánh giá này.' => 'You do not have permission to reply to this review.',
    'Trạng thái đánh giá không hợp lệ.' => 'Invalid review status.',
    'Đánh giá không tồn tại.' => 'The review does not exist.',
    'Báo cáo cần nội dung từ 10 đến 5000 ký tự và danh mục hợp lệ.' => 'A report needs 10 to 5000 characters of details and a valid category.',
    'Không có quyền khiếu nại booking này.' => 'You do not have permission to report this booking.',
    'Chỗ ở không khả dụng để báo cáo.' => 'This place is not available for reporting.',
    'Trạng thái hoặc kết luận xử lý không hợp lệ.' => 'Invalid status or resolution.',
    'Loại danh mục không hợp lệ.' => 'Invalid catalog type.',
    'Thông tin danh mục không hợp lệ.' => 'Invalid catalog details.',
    'Danh mục không tồn tại.' => 'The catalog item does not exist.',
    'Tiện nghi không tồn tại.' => 'The amenity does not exist.',
    'Mật khẩu mới không hợp lệ.' => 'Invalid new password.',
    'Mật khẩu hiện tại đã thay đổi hoặc tài khoản không khả dụng.' => 'The current password has changed or the account is not available.',

    // ---- 065_password_recovery.sql
    'Yêu cầu khôi phục không hợp lệ.' => 'Invalid recovery request.',
    'Vui lòng chờ trước khi yêu cầu khôi phục lại.' => 'Please wait before requesting another recovery link.',
    'Liên kết không hợp lệ, đã hết hạn hoặc đã sử dụng.' => 'This link is invalid, expired or already used.',

    // ---- 070_booking_extras.sql: promo code checks (quote message and strict SIGNAL)
    'Mã khuyến mãi không hợp lệ (4–20 chữ cái hoặc số).' => 'Invalid promo code (4–20 letters or digits).',
    'Mã khuyến mãi không tồn tại.' => 'This promo code does not exist.',
    'Mã khuyến mãi đã ngừng áp dụng.' => 'This promo code is no longer active.',
    'Mã khuyến mãi đã hết hạn.' => 'This promo code has expired.',
    'Mã khuyến mãi đã hết lượt sử dụng.' => 'This promo code has reached its usage limit.',

    // ---- 070_booking_extras.sql: Host pricing, holidays, promotions, payments
    'Số ngày lễ cần hiển thị không hợp lệ.' => 'Invalid number of holidays to show.',
    'Giá cuối tuần/ngày lễ phải lớn hơn 0.' => 'Weekend/holiday prices must be greater than 0.',
    'Phần trăm giảm giá phải từ 0 đến 90.' => 'Discount percentage must be between 0 and 90.',
    'Tùy chọn Đặt ngay không hợp lệ.' => 'Invalid Instant Book option.',
    'Vui lòng nhập ngày và tên ngày lễ.' => 'Please enter the holiday date and name.',
    'Ngày lễ không tồn tại.' => 'The holiday does not exist.',
    'Mã khuyến mãi phải gồm 4–20 chữ cái A–Z hoặc số.' => 'Promo code must be 4–20 letters A–Z or digits.',
    'Vui lòng nhập mô tả khuyến mãi.' => 'Please enter a promotion description.',
    'Loại giảm giá không hợp lệ.' => 'Invalid discount type.',
    'Giá trị giảm không hợp lệ (phần trăm từ 1 đến 100).' => 'Invalid discount value (percentage from 1 to 100).',
    'Điều kiện áp dụng khuyến mãi không hợp lệ.' => 'Invalid promotion conditions.',
    'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.' => 'The end date must be on or after the start date.',
    'Trạng thái khuyến mãi không hợp lệ.' => 'Invalid promotion status.',
    'Mã khuyến mãi đã tồn tại.' => 'This promo code already exists.',
    'Khuyến mãi không tồn tại.' => 'The promotion does not exist.',
    'Không thể đổi mã đã được dùng cho booking.' => 'A code that has been used for a booking cannot be changed.',
    'Mã đã được dùng cho booking nên không thể xóa; hãy tắt mã.' => 'This code has been used for a booking, so it cannot be deleted; deactivate it instead.',
    'Phạm vi thanh toán không hợp lệ.' => 'Invalid payment scope.',
    'Không có quyền xem thanh toán.' => 'You do not have permission to view payments.',
    'Bạn không có quyền cập nhật thanh toán của booking này.' => 'You do not have permission to update the payment for this booking.',
    'Booking này đã được ghi nhận thanh toán trước đó.' => 'A payment has already been recorded for this booking.',
    'Chỉ ghi nhận thanh toán cho booking đã xác nhận hoặc đã hoàn thành.' => 'Payments can only be recorded for confirmed or completed bookings.',
    'Chỉ ghi nhận đã thu tiền từ ngày nhận phòng trở đi.' => 'A payment can only be marked as collected from the check-in date onward.',
    'Vui lòng ghi chú lý do hoàn tiền.' => 'Please add a note with the refund reason.',
    'Chỉ hoàn tiền cho booking đã ghi nhận thanh toán.' => 'Refunds are only possible for bookings with a recorded payment.',
    'Trạng thái thanh toán lọc không hợp lệ.' => 'Invalid payment status filter.',
    // Notification titles
    'Đã ghi nhận thanh toán' => 'Payment recorded',
    'Đã hoàn tiền' => 'Refunded',

    // ---- 075_trust.sql
    'Họ tên trên CCCD cần từ 2 đến 150 ký tự.' => 'The full name on the ID card must be 2 to 150 characters.',
    'Số điện thoại di động Việt Nam không hợp lệ (10 chữ số, bắt đầu bằng 03, 05, 07, 08 hoặc 09).' => 'Invalid Vietnamese mobile number (10 digits starting with 03, 05, 07, 08 or 09).',
    'Số CCCD phải gồm đúng 12 chữ số.' => 'The ID card number must have exactly 12 digits.',
    'Tài khoản không hợp lệ hoặc đã bị khóa.' => 'The account is invalid or locked.',
    'Tài khoản đã được xác minh danh tính.' => 'This account’s identity is already verified.',
    'Bạn đã có một yêu cầu xác minh đang chờ duyệt.' => 'You already have a verification request pending review.',
    'Bộ lọc trạng thái xác minh không hợp lệ.' => 'Invalid verification status filter.',
    'Quyết định xác minh không hợp lệ.' => 'Invalid verification decision.',
    'Vui lòng nhập lý do từ chối (ít nhất 5 ký tự).' => 'Please enter a rejection reason (at least 5 characters).',
    'Ghi chú quyết định tối đa 1000 ký tự.' => 'Decision notes can be up to 1000 characters.',
    'Yêu cầu xác minh không tồn tại.' => 'The verification request does not exist.',
    'Yêu cầu xác minh này đã được xử lý.' => 'This verification request has already been processed.',
    'Admin không thể tự duyệt yêu cầu xác minh của chính mình.' => 'Admins cannot review their own verification request.',
    'Danh sách tài khoản không hợp lệ.' => 'Invalid account list.',
    'Hướng dẫn nhận phòng cần từ 10 đến 3000 ký tự.' => 'Check-in guide must be 10 to 3000 characters.',
    'Mã cửa / hộp khóa tối đa 60 ký tự.' => 'The door / lockbox code can be up to 60 characters.',
    'Tên và mật khẩu Wi-Fi tối đa 100 ký tự.' => 'The Wi-Fi name and password can be up to 100 characters each.',
    'Ghi chú tối đa 2000 ký tự.' => 'Notes can be up to 2000 characters.',
    // Notification title and approval body
    'Xác minh danh tính' => 'Identity verification',
    'Danh tính của bạn đã được xác minh. Huy hiệu “Đã xác minh” hiển thị cạnh tên của bạn.' => 'Your identity has been verified. The “Verified” badge now appears next to your name.',

    // ---- 080_conversation.sql
    'Bạn không tham gia cuộc trò chuyện của booking này.' => 'You are not part of the conversation for this booking.',
    'Booking đã bị từ chối hoặc hủy nên cuộc trò chuyện chỉ còn để xem.' => 'This booking was rejected or cancelled, so the conversation is read-only.',
    'Cuộc trò chuyện đã đóng sau 14 ngày kể từ ngày trả phòng.' => 'The conversation closed 14 days after the check-out date.',
    'Booking ở trạng thái này không nhận tin nhắn.' => 'A booking in this status cannot receive messages.',
    'Tin nhắn cần từ 1 đến 2000 ký tự.' => 'Messages must be 1 to 2000 characters.',
    'Thao tác kiểm duyệt tin nhắn không hợp lệ.' => 'Invalid message moderation action.',
    'Lý do kiểm duyệt cần từ 5 đến 500 ký tự.' => 'Moderation reason must be 5 to 500 characters.',
    'Tin nhắn không tồn tại.' => 'The message does not exist.',
    'Tin nhắn đã ở trạng thái này.' => 'The message is already in this state.',
    'Số ngày nhắc lịch phải từ 0 đến 30.' => 'Reminder days must be between 0 and 30.',
    // Notification titles
    'Tin nhắn mới' => 'New message',
    'Nhắc lịch nhận phòng' => 'Check-in reminder',

    // ---- 085_discovery.sql
    'Khung bản đồ cần đủ bốn cạnh bắc, nam, đông, tây.' => 'The map area needs all four edges: north, south, east and west.',
    'Khung bản đồ không hợp lệ: vĩ độ từ -90 đến 90, kinh độ từ -180 đến 180 và cạnh nam phải thấp hơn cạnh bắc.' => 'Invalid map area: latitude must be -90 to 90, longitude -180 to 180, and the south edge must be below the north edge.',
    'Số kết quả trên bản đồ phải từ 1 đến 100.' => 'The number of map results must be between 1 and 100.',
    'Số khách hoặc khoảng giá không hợp lệ.' => 'Invalid number of guests or price range.',
    'Vui lòng chọn đủ ngày và bảo đảm ngày trả phòng sau ngày nhận phòng.' => 'Please choose both dates and make sure check-out is after check-in.',
    'Tài khoản không hợp lệ để lưu lịch sử xem.' => 'This account cannot save viewing history.',
    'Điều kiện tìm kiếm không hợp lệ để lưu lịch sử.' => 'These search criteria cannot be saved to history.',
    'Tài khoản không hợp lệ để lưu lịch sử tìm kiếm.' => 'This account cannot save search history.',
    'Số gợi ý phải từ 1 đến 24.' => 'The number of suggestions must be between 1 and 24.',
    'Tài khoản không hợp lệ để nhận gợi ý.' => 'This account cannot receive suggestions.',
    'Số chỗ ở đã xem phải từ 1 đến 20.' => 'The number of viewed places must be between 1 and 20.',
    'Tài khoản không hợp lệ để xem lịch sử.' => 'This account cannot view history.',
    'Tài khoản không hợp lệ để xóa lịch sử.' => 'This account cannot clear history.',
    // Recommendation reasons (sp_discovery_recommendations.reason)
    'Bạn đã xem chỗ ở này gần đây' => 'You viewed this place recently',
    'Mức giá gần với lựa chọn của bạn' => 'Priced close to what you usually choose',
    'Được khách đánh giá cao' => 'Highly rated by guests',
    'Nhiều người đang quan tâm' => 'Popular with other travelers',
    'Chỗ ở nổi bật trên Home2Home' => 'Featured place on Home2Home',

    // ---- 090_content.sql
    'Chuyên mục bài viết không hợp lệ.' => 'Invalid article category.',
    'Số bài viết liên quan không hợp lệ.' => 'Invalid number of related articles.',
    'Bộ lọc bài viết không hợp lệ.' => 'Invalid article filter.',
    'Tiêu đề cần từ 5 đến 200 ký tự.' => 'Title must be 5 to 200 characters.',
    'Đường dẫn chỉ gồm chữ thường không dấu, số và dấu gạch ngang.' => 'The URL slug may only contain unaccented lowercase letters, digits and hyphens.',
    'Tóm tắt cần từ 1 đến 500 ký tự.' => 'Summary must be 1 to 500 characters.',
    'Nội dung cần từ 20 đến 50000 ký tự.' => 'Content must be 20 to 50000 characters.',
    'Ảnh bìa không hợp lệ.' => 'Invalid cover image.',
    'Mô tả ảnh bìa tối đa 255 ký tự.' => 'The cover image description can be up to 255 characters.',
    'Trạng thái bài viết không hợp lệ.' => 'Invalid article status.',
    'Bài viết không tồn tại hoặc đã bị xóa.' => 'The article does not exist or has been deleted.',
    'Không thể tạo đường dẫn duy nhất. Hãy nhập đường dẫn khác.' => 'Could not create a unique URL slug. Please enter a different one.',
    'Đường dẫn đã được dùng cho bài viết khác.' => 'This URL slug is already used by another article.',
];
