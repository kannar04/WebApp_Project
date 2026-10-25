<?php
declare(strict_types=1);

/*
 * Templates for procedure text built with CONCAT(...) (notifications, promo messages, recommendation reasons).
 * Each template equals the FINAL string the procedure stores, with every variable part as a {slot}.
 * Listing titles, people's names, cities and search text are user content: they stay inside their slot as they are.
 * Order matters: the first matching template wins, so specific templates come before generic ones.
 */
return [
    // ---- 020_booking.sql: sp_booking_create notifications
    'Đặt ngay thành công: booking #{id} đã được tự động xác nhận.' => 'Instant Book: booking #{id} has been confirmed automatically.',
    'Bạn có booking Đặt ngay mới cho “{listing}” (#{id}), đã được tự động xác nhận.' => 'You have a new Instant Book booking for “{listing}” (#{id}); it was confirmed automatically.',
    'Bạn có yêu cầu đặt chỗ mới cho “{listing}”.' => 'You have a new booking request for “{listing}”.',

    // ---- 020_booking.sql: sp_booking_transition Host notification (the status is the raw code)
    'Booking #{id} chuyển sang confirmed.' => 'Booking #{id} is now confirmed.',
    'Booking #{id} chuyển sang rejected.' => 'Booking #{id} is now rejected.',
    'Booking #{id} chuyển sang completed.' => 'Booking #{id} is now completed.',
    'Booking #{id} chuyển sang {status}.' => 'Booking #{id} changed to {status}.',

    // ---- 020_booking.sql: sp_booking_cancel Guest notification (now e.g. 1.500.000đ / 100; the second entry keeps older rows stored as 1500000.00 / 100.00)
    'Booking #{id} đã hủy. Mức hoàn: {amount}đ ({percent}%).' => 'Booking #{id} was cancelled. Refund: {amount}đ ({percent}%).',
    'Booking #{id} đã hủy. Mức hoàn: {amount} ({percent}%).' => 'Booking #{id} was cancelled. Refund: {amount} ({percent}%).',

    // ---- 070_booking_extras.sql: promo code messages (quote message and strict SIGNAL)
    'Mã khuyến mãi chưa đến ngày áp dụng (từ {date}).' => 'This promo code is not valid yet (valid from {date}).',
    'Không đủ điều kiện: mã chỉ áp dụng cho kỳ ở từ {nights} đêm.' => 'Not eligible: this code requires a stay of at least {nights} night(s).',
    'Không đủ điều kiện: tiền phòng tối thiểu {amount}đ.' => 'Not eligible: the minimum room subtotal is {amount}đ.',

    // ---- 070_booking_extras.sql: payment notifications
    'Booking #{id} đã được ghi nhận thanh toán {amount}đ khi nhận phòng.' => 'Booking #{id}: a payment of {amount}đ was recorded at check-in.',
    'Booking #{id} đã được ghi nhận hoàn tiền {amount}đ.' => 'Booking #{id}: a refund of {amount}đ was recorded.',

    // ---- 075_trust.sql: identity rejection notification (the reason is the Admin's own text)
    'Yêu cầu xác minh danh tính chưa được chấp nhận. Lý do: {reason} Bạn có thể gửi lại trong trang Tài khoản.' => 'Your identity verification request was not approved. Reason: {reason} You can submit it again on the Account page.',

    // ---- 080_conversation.sql: new message and check-in reminder notifications
    '{sender} đã gửi tin nhắn về booking #{id} · “{listing}”.' => '{sender} sent a message about booking #{id} · “{listing}”.',
    'Nhắc lịch: bạn nhận phòng “{listing}” vào {date} từ {time}. Mở booking #{id} để xem địa chỉ và liên hệ Host.' => 'Reminder: you check in at “{listing}” on {date} from {time}. Open booking #{id} to see the address and contact the Host.',
    'Khách {guest} sẽ nhận phòng “{listing}” vào {date} từ {time} (booking #{id}, {count} khách).' => 'Guest {guest} will check in at “{listing}” on {date} from {time} (booking #{id}, guests: {count}).',

    // ---- 085_discovery.sql: recommendation reasons (city, search text and property type come from data)
    'Vì bạn đã xem chỗ ở tại {city}' => 'Because you viewed places in {city}',
    'Vì bạn đã tìm kiếm “{query}”' => 'Because you searched for “{query}”',
    'Cùng loại “{type}” bạn quan tâm' => 'Same property type you like: “{type}”',

    // ---- 095_online_payment.sql: payment notifications (amount e.g. 1.500.000, reference e.g. H2H12XAB3CD)
    'Booking #{id}: khách báo đã chuyển {amount}đ với nội dung {reference}. Vui lòng kiểm tra tài khoản và xác nhận.' => 'Booking #{id}: the guest reported transferring {amount}đ with reference {reference}. Please check the account and confirm.',
    'Booking #{id} đã được xác nhận thanh toán chuyển khoản {amount}đ (mã {reference}).' => 'Bank transfer payment of {amount}đ confirmed for booking #{id} (reference {reference}).',
    'Chưa xác nhận được chuyển khoản cho booking #{id} (mã {reference}). Lý do: {reason}' => 'The bank transfer for booking #{id} (reference {reference}) could not be confirmed. Reason: {reason}',
    'Booking #{id} đã thanh toán thành công qua VNPay {amount}đ.' => 'Booking #{id} was paid via VNPay: {amount}đ.',
    'Booking #{id} đã hủy nhưng vẫn nhận {amount}đ qua VNPay (mã {reference}). Vui lòng hoàn tiền cho khách.' => 'Booking #{id} was cancelled but still received {amount}đ via VNPay (reference {reference}). Please refund the guest.',
    'Booking #{id} nhận thêm {amount}đ qua VNPay (mã {reference}) sau khi đã thanh toán. Vui lòng hoàn tiền cho khách.' => 'Booking #{id} received another {amount}đ via VNPay (reference {reference}) after it was already paid. Please refund the guest.',
];
