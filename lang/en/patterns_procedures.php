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

    // ---- 030_atomic_writes.sql: sp_admin_listing_soft_delete refusal and Guest notification
    'Không thể gỡ chỗ ở: còn {count} booking đã xác nhận chưa kết thúc. Hãy liên hệ Host và khách để xử lý trước. Có thể ẩn tin để ngừng nhận yêu cầu mới.' => 'This listing cannot be taken down: {count} confirmed booking(s) have not finished. Contact the Host and guest to resolve them first. You can hide the listing to stop new requests.',
    'Yêu cầu đặt chỗ #{id} cho “{listing}” đã bị từ chối vì chỗ ở đã được gỡ khỏi hệ thống.' => 'Booking request #{id} for “{listing}” was rejected because the listing has been taken down.',
    'Yêu cầu đặt chỗ #{id} cho “{listing}” đã bị từ chối vì chỗ ở không được duyệt.' => 'Booking request #{id} for “{listing}” was rejected because the listing was not approved.',

    // ---- 030_atomic_writes.sql: Host notifications about a rejected or removed listing
    'Tin “{listing}” đã bị Admin từ chối. Lý do: {reason}' => 'Admin rejected your listing “{listing}”. Reason: {reason}',
    'Admin đã gỡ chỗ ở “{listing}” khỏi hệ thống.' => 'Admin took your listing “{listing}” down.',

    // ---- 020_booking.sql: sp_booking_cancel Guest notification (now e.g. 1.500.000đ / 100; the second entry keeps older rows stored as 1500000.00 / 100.00)
    'Booking #{id} đã hủy. Khoản thanh toán đã được hoàn trước đó nên không có khoản hoàn thêm.' => 'Booking #{id} was cancelled. The payment was already refunded, so no further refund is due.',
    'Booking #{id} đã hủy. Theo chính sách hủy, khoản đã thanh toán không được hoàn.' => 'Booking #{id} was cancelled. Under the cancellation policy, the payment is not refunded.',
    'Booking #{id} đã hủy khi đã thanh toán. Cần hoàn {amount}đ cho khách theo chính sách ({percent}%).' => 'Booking #{id} was cancelled after payment. Refund {amount}đ to the guest under the policy ({percent}%).',
    'Booking #{id} đã hủy khi đã thanh toán. Theo chính sách hủy, khoản hoàn là 0đ nên không cần hoàn tiền.' => 'Booking #{id} was cancelled after payment. Under the cancellation policy the refund is 0đ, so nothing needs refunding.',
    'Booking #{id} đã hủy.' => 'Booking #{id} was cancelled.',
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
    'Booking #{id} đã được thanh toán bằng giao dịch khác nên chuyển khoản {amount}đ (mã {reference}) đã được đóng. Nếu khoản này đã vào tài khoản, Admin sẽ hoàn lại cho bạn. Ghi chú: {reason}' => 'Booking #{id} was paid by another transaction, so the bank transfer of {amount}đ (reference {reference}) was closed. If this money reached the account, an Admin will return it to you. Note: {reason}',
    'Đã đóng chuyển khoản trùng vì booking đã được thanh toán bằng giao dịch khác; nếu khoản này đã vào tài khoản, Admin sẽ hoàn lại cho khách. Ghi chú: {reason}' => 'Duplicate bank transfer closed because the booking was paid by another transaction; if this money reached the account, an Admin will return it to the guest. Note: {reason}',
    'Booking #{id} đã hủy nhưng VNPay vẫn nhận {amount}đ. Admin sẽ hoàn lại khoản này cho bạn.' => 'Booking #{id} is cancelled but VNPay still took {amount}đ. An Admin will return this money to you.',
    'Chỗ ở của booking #{id} đã bị gỡ nhưng VNPay vẫn nhận {amount}đ. Admin sẽ hoàn lại khoản này cho bạn.' => 'The listing of booking #{id} was taken down but VNPay still took {amount}đ. An Admin will return this money to you.',
    'Booking #{id} đã hủy nhưng đã nhận chuyển khoản {amount}đ (mã {reference}). Admin sẽ hoàn lại khoản này cho bạn.' => 'Booking #{id} is cancelled but a bank transfer of {amount}đ (reference {reference}) arrived. An Admin will return this money to you.',
    'Chỗ ở của booking #{id} đã bị gỡ nhưng đã nhận chuyển khoản {amount}đ (mã {reference}). Admin sẽ hoàn lại khoản này cho bạn.' => 'The listing of booking #{id} was taken down but a bank transfer of {amount}đ (reference {reference}) arrived. An Admin will return this money to you.',
    'Booking #{id} đã thanh toán thành công qua VNPay {amount}đ.' => 'Booking #{id} was paid via VNPay: {amount}đ.',
    'Booking #{id} đã hủy nhưng vẫn nhận {amount}đ qua chuyển khoản (mã {reference}). Vui lòng hoàn tiền cho khách.' => 'Booking #{id} was cancelled but still received {amount}đ by bank transfer (reference {reference}). Please refund the guest.',
    'Booking #{id} đã hủy nhưng vẫn nhận {amount}đ qua VNPay (mã {reference}). Vui lòng hoàn tiền cho khách.' => 'Booking #{id} was cancelled but still received {amount}đ via VNPay (reference {reference}). Please refund the guest.',
    'Booking #{id} đã được thanh toán trước đó nên khoản VNPay {amount}đ (mã {reference}) là thanh toán trùng. Admin sẽ hoàn lại khoản này cho bạn.' => 'Booking #{id} was already paid, so the VNPay payment of {amount}đ (reference {reference}) is a duplicate. An admin will refund it to you.',
    'Khoản VNPay {amount}đ (mã {reference}) của booking #{id} đã được hoàn lại. Booking vẫn được giữ là đã thanh toán.' => 'The VNPay payment of {amount}đ (reference {reference}) on booking #{id} was refunded. The booking stays paid.',
    'Khoản VNPay {amount}đ (mã {reference}) của booking #{id} đã được hoàn lại. Khoản thanh toán chính của booking cũng đã được hoàn trước đó.' => 'The VNPay payment of {amount}đ (reference {reference}) on booking #{id} was refunded. The main payment on this booking was refunded earlier too.',
    'Đã hoàn khoản thanh toán trùng: {reason}' => 'Duplicate payment refunded: {reason}',
    'Booking #{id} thuộc chỗ ở đã gỡ nhưng vẫn nhận {amount}đ qua chuyển khoản (mã {reference}). Vui lòng hoàn tiền cho khách.' => 'Booking #{id} belongs to a taken-down listing but still received {amount}đ by bank transfer (reference {reference}). Please refund the guest.',
    'Booking #{id} thuộc chỗ ở đã gỡ nhưng vẫn nhận {amount}đ qua VNPay (mã {reference}). Vui lòng hoàn tiền cho khách.' => 'Booking #{id} belongs to a taken-down listing but still received {amount}đ via VNPay (reference {reference}). Please refund the guest.',
    'Booking #{id} nhận thêm {amount}đ qua VNPay (mã {reference}) sau khi đã thanh toán. Vui lòng hoàn tiền cho khách.' => 'Booking #{id} received another {amount}đ via VNPay (reference {reference}) after it was already paid. Please refund the guest.',
];
