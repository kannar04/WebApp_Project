<?php
// Check-in guide on booking detail. $checkinGuide is only non-null when sp_checkin_guide_for_booking entitles the actor; the routine also decides codes_state (none/open/upcoming/ended).
$guideOpen = in_array($booking['status'], ['confirmed', 'completed'], true);
?>
<?php if($checkinGuide): ?>
<section class="checkin-guide" aria-labelledby="checkin-guide-title">
    <h2 class="h4" id="checkin-guide-title"><?= e(__('Hướng dẫn nhận phòng')) ?></h2>
    <p class="text-muted small"><?= e(__('Chỉ khách của booking này, Host và Admin xem được. Vui lòng không chia sẻ mã cửa và mật khẩu Wi-Fi.')) ?></p>
    <p><?= nl2br(e($checkinGuide['arrival_instructions'])) ?></p>
    <dl class="trust-facts">
        <?php if($checkinGuide['door_code'] !== null): ?><dt><?= e(__('Mã cửa / hộp khóa')) ?></dt><dd><span class="trust-secret" id="guide-door-code"><?= e($checkinGuide['door_code']) ?></span> <button class="btn btn-sm btn-ghost" type="button" data-trust-secret-toggle aria-controls="guide-door-code" aria-pressed="true" hidden><span data-trust-secret-label><?= e(__('Ẩn')) ?></span><span class="sr-only"> <?= e(__('mã cửa')) ?></span></button></dd><?php endif; ?>
        <?php if($checkinGuide['wifi_name'] !== null): ?><dt><?= e(__('Tên Wi-Fi')) ?></dt><dd><?= e($checkinGuide['wifi_name']) ?></dd><?php endif; ?>
        <?php if($checkinGuide['wifi_password'] !== null): ?><dt><?= e(__('Mật khẩu Wi-Fi')) ?></dt><dd><span class="trust-secret" id="guide-wifi-password"><?= e($checkinGuide['wifi_password']) ?></span> <button class="btn btn-sm btn-ghost" type="button" data-trust-secret-toggle aria-controls="guide-wifi-password" aria-pressed="true" hidden><span data-trust-secret-label><?= e(__('Ẩn')) ?></span><span class="sr-only"> <?= e(__('mật khẩu Wi-Fi')) ?></span></button></dd><?php endif; ?>
        <?php if($checkinGuide['note'] !== null): ?><dt><?= e(__('Ghi chú của Host')) ?></dt><dd><?= nl2br(e($checkinGuide['note'])) ?></dd><?php endif; ?>
    </dl>
    <?php if($checkinGuide['codes_state'] === 'upcoming'): ?><p class="mb-0" id="guide-codes-state"><?= e(__('Mã cửa và Wi-Fi sẽ hiển thị từ ngày {date}, 1 ngày trước ngày nhận phòng.', ['date'=>display_date($checkinGuide['codes_from'])])) ?></p><?php elseif($checkinGuide['codes_state'] === 'ended'): ?><p class="mb-0" id="guide-codes-state"><?= e(__('Mã cửa và Wi-Fi không còn hiển thị vì kỳ ở đã kết thúc.')) ?></p><?php endif; ?>
    <p class="text-muted small mb-0 mt-2"><?= e(__('Cập nhật lần cuối: {time}', ['time'=>date('d/m/Y H:i', strtotime((string)$checkinGuide['updated_at']))])) ?></p>
</section>
<?php elseif($booking['status'] === 'pending' && $actorId === (int)$booking['guest_id']): ?>
<p class="text-muted"><?= e(__('Hướng dẫn nhận phòng sẽ hiển thị tại đây sau khi Host xác nhận booking; mã cửa và Wi-Fi hiển thị từ 1 ngày trước ngày nhận phòng.')) ?></p>
<?php elseif($guideOpen && $actorId === (int)$booking['guest_id']): ?>
<p class="text-muted"><?= e(__('Host chưa cung cấp hướng dẫn nhận phòng. Bạn có thể liên hệ Host theo thông tin ở trên.')) ?></p>
<?php elseif($guideOpen && $actorId === (int)$booking['host_id']): ?>
<p class="text-muted"><?= e(__('Chỗ ở này chưa có hướng dẫn nhận phòng.')) ?> <a href="<?= e(url('/host/listings/'.(int)$booking['listing_id'].'/checkin-guide')) ?>"><?= e(__('Tạo hướng dẫn nhận phòng')) ?></a></p>
<?php endif; ?>