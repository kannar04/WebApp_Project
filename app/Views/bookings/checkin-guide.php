<?php
// Check-in guide on booking detail. $checkinGuide is only non-null when sp_checkin_guide_for_booking entitles the actor.
$guideOpen = in_array($booking['status'], ['confirmed', 'completed'], true);
?>
<?php if($checkinGuide): ?>
<section class="checkin-guide" aria-labelledby="checkin-guide-title">
    <h2 class="h4" id="checkin-guide-title">Hướng dẫn nhận phòng</h2>
    <p class="text-muted small">Chỉ khách của booking này, Host và Admin xem được. Vui lòng không chia sẻ mã cửa và mật khẩu Wi-Fi.</p>
    <p><?= nl2br(e($checkinGuide['arrival_instructions'])) ?></p>
    <dl class="trust-facts">
        <?php if($checkinGuide['door_code'] !== null): ?><dt>Mã cửa / hộp khóa</dt><dd><span class="trust-secret" id="guide-door-code"><?= e($checkinGuide['door_code']) ?></span> <button class="btn btn-sm btn-ghost" type="button" data-trust-secret-toggle aria-controls="guide-door-code" aria-pressed="true" hidden><span data-trust-secret-label>Ẩn</span><span class="sr-only"> mã cửa</span></button></dd><?php endif; ?>
        <?php if($checkinGuide['wifi_name'] !== null): ?><dt>Tên Wi-Fi</dt><dd><?= e($checkinGuide['wifi_name']) ?></dd><?php endif; ?>
        <?php if($checkinGuide['wifi_password'] !== null): ?><dt>Mật khẩu Wi-Fi</dt><dd><span class="trust-secret" id="guide-wifi-password"><?= e($checkinGuide['wifi_password']) ?></span> <button class="btn btn-sm btn-ghost" type="button" data-trust-secret-toggle aria-controls="guide-wifi-password" aria-pressed="true" hidden><span data-trust-secret-label>Ẩn</span><span class="sr-only"> mật khẩu Wi-Fi</span></button></dd><?php endif; ?>
        <?php if($checkinGuide['note'] !== null): ?><dt>Ghi chú của Host</dt><dd><?= nl2br(e($checkinGuide['note'])) ?></dd><?php endif; ?>
    </dl>
    <p class="text-muted small mb-0 mt-2">Cập nhật lần cuối: <?= e(date('d/m/Y H:i', strtotime((string)$checkinGuide['updated_at']))) ?></p>
</section>
<?php elseif($booking['status'] === 'pending' && $actorId === (int)$booking['guest_id']): ?>
<p class="text-muted">Hướng dẫn nhận phòng (mã cửa, Wi-Fi) sẽ hiển thị tại đây sau khi Host xác nhận booking.</p>
<?php elseif($guideOpen && $actorId === (int)$booking['guest_id']): ?>
<p class="text-muted">Host chưa cung cấp hướng dẫn nhận phòng. Bạn có thể liên hệ Host theo thông tin ở trên.</p>
<?php elseif($guideOpen && $actorId === (int)$booking['host_id']): ?>
<p class="text-muted">Chỗ ở này chưa có hướng dẫn nhận phòng. <a href="<?= e(url('/host/listings/'.(int)$booking['listing_id'].'/checkin-guide')) ?>">Tạo hướng dẫn nhận phòng</a></p>
<?php endif; ?>
