<?php
$hostContactReady = !empty($contactStatus['email_verified']) && !empty($contactStatus['phone_verified']);
$hostIdentityReady = ($verification['status'] ?? '') === 'approved' && !empty($verification['is_current']);
?>
<div class="mt-3" data-host-application>
<p class="small"><?= e(__('Để làm Host, bạn cần xác minh email, số điện thoại và được Admin duyệt danh tính.')) ?></p>
<?php if (($hostRequest['status'] ?? '') === 'pending'): ?>
<p class="alert alert-info" role="status"><?= e(__('Your host request is awaiting admin approval.')) ?></p>
<?php else: ?>
<?php if (($hostRequest['status'] ?? '') === 'rejected'): ?><p class="alert alert-warning"><?= e(__('Host request rejected:')) ?> <?= e($hostRequest['reason']) ?></p><?php endif; ?>
<?php if (!$hostContactReady): ?>
<a class="btn btn-outline-primary btn-block" href="#contact-verification"><?= e(__('Xác minh email và số điện thoại')) ?></a>
<?php elseif (!$hostIdentityReady): ?>
<a class="btn btn-outline-primary btn-block" href="#verification"><?= e(($verification['status'] ?? '') === 'pending' ? __('Đăng ký Host: đang chờ duyệt danh tính') : __('Đăng ký làm Host: xác minh danh tính')) ?></a>
<?php else: ?>
<form method="post" action="<?= e(url('/profile/become-host')) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-block" type="submit"><?= e(__('Submit host request')) ?> →</button></form>
<?php endif; ?>
<?php endif; ?>
</div>
