<?php
// Templates for runtime text that PHP code builds by concatenation (flash messages, page titles, validation errors).
// Each {slot} captures the variable part; a captured value that is itself a dictionary key (a status, a category,
// a field label) is translated too. Specific templates come first; the ones that start or end with a slot come last.
return [
    // BookingController::create / ::cancel
    'Đặt ngay thành công! Booking #{id} đã được xác nhận. Bạn thanh toán khi nhận phòng.' => 'Booked instantly! Booking #{id} is confirmed. You pay at check-in.',
    'Yêu cầu đặt chỗ #{id} đã được gửi tới Host.' => 'Booking request #{id} has been sent to the Host.',
    'Đã hủy booking. Mức hoàn dự kiến: {percent}%.' => 'Booking cancelled. Expected refund: {percent}%.',

    // BookingExtrasController: holidays, promo codes, payments
    'Đã lưu ngày lễ {date}. Giá ngày lễ áp dụng cho báo giá mới.' => 'Holiday {date} saved. Holiday prices apply to new quotes.',
    'Đã lưu mã khuyến mãi {code}.' => 'Promo code {code} saved.',
    'Đã ghi nhận hoàn tiền cho booking #{id}.' => 'Refund recorded for booking #{id}.',
    'Đã ghi nhận thanh toán khi nhận phòng cho booking #{id}.' => 'Payment at check-in recorded for booking #{id}.',

    // MessageController::send (limit = BookingMessage::MAX_LENGTH)
    'Tin nhắn cần từ 1 đến {max} ký tự.' => 'Messages must be 1 to {max} characters.',

    // Page titles: AdminController/AdminArticleController previews, ArticleController category pages
    'Xem trước: {title}' => 'Preview: {title}',
    '{category} · Cẩm nang' => '{category} · Guides',

    // Core\Validator::required — {field} is a label such as "Họ tên" or "Mật khẩu"
    '{field} là bắt buộc.' => '{field} is required.',
];
