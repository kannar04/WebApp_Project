<?php
$verificationOld = $verificationOld ?? [];
$verificationLabels = ['pending' => __('Đang chờ duyệt'), 'approved' => __('Đã xác minh'), 'rejected' => __('Bị từ chối')];
$verificationState = $verification['status'] ?? null;
?>
<section class="surface-card mt-4" id="verification" aria-labelledby="verification-title">
<div class="section-title compact"><div><h2 id="verification-title"><?= e(__('Xác minh danh tính')) ?></h2><p><?= e(__('Xác minh bằng số điện thoại và CCCD để hiển thị huy hiệu “✓ Đã xác minh” cạnh tên của bạn.')) ?></p></div><?php if($verificationState): ?><span class="status status-<?= e($verificationState) ?>"><?= e($verificationLabels[$verificationState] ?? $verificationState) ?></span><?php endif; ?></div>
<?php if($verificationState === 'approved' || $verificationState === 'pending'): ?>
<p><?= e($verificationState === 'approved' ? __('Danh tính của bạn đã được Admin xác minh. Huy hiệu hiển thị trên hồ sơ, trang chỗ ở và booking.') : __('Yêu cầu đang chờ Admin xem xét. Bạn sẽ nhận thông báo khi có kết quả.')) ?></p>
<dl class="trust-facts"><dt><?= e(__('Họ tên trên CCCD')) ?></dt><dd><?= e($verification['full_name_on_card']) ?></dd><dt><?= e(__('Số điện thoại')) ?></dt><dd><?= e($verification['phone']) ?></dd><dt><?= e(__('Số CCCD')) ?></dt><dd class="trust-masked"><?= e($verification['id_number_masked']) ?></dd><dt><?= e($verificationState === 'approved' ? __('Ngày xác minh') : __('Ngày gửi')) ?></dt><dd><?= e(date('d/m/Y H:i', strtotime((string)($verificationState === 'approved' ? $verification['reviewed_at'] : $verification['created_at'])))) ?></dd></dl>
<?php else: ?>
<?php if($verificationState === 'rejected'): ?><div class="alert alert-warning" role="status"><strong><?= e(__('Yêu cầu trước chưa được chấp nhận.')) ?></strong> <?= e(__('Lý do:')) ?> <?= e($verification['decision_reason']) ?> <?= e(__('Vui lòng kiểm tra lại thông tin và gửi lại.')) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('/profile/verification')) ?>"><?= csrf_field() ?>
<div class="form-group"><label for="verification-name"><?= e(__('Họ và tên đúng như trên CCCD')) ?></label><input class="form-control" id="verification-name" name="full_name_on_card" value="<?= e($verificationOld['full_name_on_card'] ?? $user['full_name']) ?>" minlength="2" maxlength="150" autocomplete="name" required></div>
<div class="form-row"><div class="form-group col-md-6"><label for="verification-phone"><?= e(__('Số điện thoại di động')) ?></label><input class="form-control" id="verification-phone" name="phone" type="tel" inputmode="tel" value="<?= e($verificationOld['phone'] ?? $user['phone']) ?>" maxlength="20" autocomplete="tel" aria-describedby="verification-phone-help" required><small class="form-text text-muted" id="verification-phone-help"><?= e(__('10 chữ số, ví dụ 0912 345 678 hoặc +84 912 345 678.')) ?> <?= e(__('Khi được duyệt, số này thay cho số điện thoại liên hệ trong hồ sơ và booking.')) ?></small></div>
<div class="form-group col-md-6"><label for="verification-id-number"><?= e(__('Số CCCD (12 chữ số)')) ?></label><input class="form-control" id="verification-id-number" name="id_number" inputmode="numeric" minlength="12" maxlength="20" autocomplete="off" aria-describedby="verification-id-help" required><small class="form-text text-muted" id="verification-id-help"><?= e(__('Chỉ Admin xem số đầy đủ khi duyệt; sau khi có kết quả, hệ thống chỉ giữ 4 số cuối.')) ?></small></div></div>
<button class="btn btn-primary" type="submit"><?= e($verificationState === 'rejected' ? __('Gửi lại yêu cầu xác minh') : __('Gửi yêu cầu xác minh')) ?></button>
</form>
<?php endif; ?>
</section>
