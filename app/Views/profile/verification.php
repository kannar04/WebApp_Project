<?php
$verificationOld = $verificationOld ?? [];
$verificationLabels = ['pending' => 'Đang chờ duyệt', 'approved' => 'Đã xác minh', 'rejected' => 'Bị từ chối'];
$verificationState = $verification['status'] ?? null;
?>
<section class="surface-card mt-4" id="verification" aria-labelledby="verification-title">
<div class="section-title compact"><div><h2 id="verification-title">Xác minh danh tính</h2><p>Xác minh bằng số điện thoại và CCCD để hiển thị huy hiệu “✓ Đã xác minh” cạnh tên của bạn.</p></div><?php if($verificationState): ?><span class="status status-<?= e($verificationState) ?>"><?= e($verificationLabels[$verificationState] ?? $verificationState) ?></span><?php endif; ?></div>
<?php if($verificationState === 'approved' || $verificationState === 'pending'): ?>
<p><?= $verificationState === 'approved' ? 'Danh tính của bạn đã được Admin xác minh. Huy hiệu hiển thị trên hồ sơ, trang chỗ ở và booking.' : 'Yêu cầu đang chờ Admin xem xét. Bạn sẽ nhận thông báo khi có kết quả.' ?></p>
<dl class="trust-facts"><dt>Họ tên trên CCCD</dt><dd><?= e($verification['full_name_on_card']) ?></dd><dt>Số điện thoại</dt><dd><?= e($verification['phone']) ?></dd><dt>Số CCCD</dt><dd class="trust-masked"><?= e($verification['id_number_masked']) ?></dd><dt><?= $verificationState === 'approved' ? 'Ngày xác minh' : 'Ngày gửi' ?></dt><dd><?= e(date('d/m/Y H:i', strtotime((string)($verificationState === 'approved' ? $verification['reviewed_at'] : $verification['created_at'])))) ?></dd></dl>
<?php else: ?>
<?php if($verificationState === 'rejected'): ?><div class="alert alert-warning" role="status"><strong>Yêu cầu trước chưa được chấp nhận.</strong> Lý do: <?= e($verification['decision_reason']) ?> Vui lòng kiểm tra lại thông tin và gửi lại.</div><?php endif; ?>
<form method="post" action="<?= e(url('/profile/verification')) ?>"><?= csrf_field() ?>
<div class="form-group"><label for="verification-name">Họ và tên đúng như trên CCCD</label><input class="form-control" id="verification-name" name="full_name_on_card" value="<?= e($verificationOld['full_name_on_card'] ?? $user['full_name']) ?>" minlength="2" maxlength="150" autocomplete="name" required></div>
<div class="form-row"><div class="form-group col-md-6"><label for="verification-phone">Số điện thoại di động</label><input class="form-control" id="verification-phone" name="phone" type="tel" inputmode="tel" value="<?= e($verificationOld['phone'] ?? $user['phone']) ?>" maxlength="20" autocomplete="tel" aria-describedby="verification-phone-help" required><small class="form-text text-muted" id="verification-phone-help">10 chữ số, ví dụ 0912 345 678 hoặc +84 912 345 678.</small></div>
<div class="form-group col-md-6"><label for="verification-id-number">Số CCCD (12 chữ số)</label><input class="form-control" id="verification-id-number" name="id_number" inputmode="numeric" minlength="12" maxlength="20" autocomplete="off" aria-describedby="verification-id-help" required><small class="form-text text-muted" id="verification-id-help">Chỉ Admin xem số đầy đủ khi duyệt; sau khi có kết quả, hệ thống chỉ giữ 4 số cuối.</small></div></div>
<button class="btn btn-primary" type="submit"><?= $verificationState === 'rejected' ? 'Gửi lại yêu cầu xác minh' : 'Gửi yêu cầu xác minh' ?></button>
</form>
<?php endif; ?>
</section>
