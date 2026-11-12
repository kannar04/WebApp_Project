<?php require __DIR__.'/debug-verification.php'; ?>
<section class="surface-card mt-4" id="contact-verification"><h2><?= e(__('Xác minh email và số điện thoại')) ?></h2>
<p><?= e(__('Bạn cần xác minh email, sau đó xác minh số điện thoại qua SMS để đặt phòng.')) ?></p>
<p>Email: <?= e($user['email']) ?> ? <strong><?= e(!empty($contactStatus['email_verified'])?__('Đã xác minh'):__('Chưa xác minh')) ?></strong></p>
<?php if(empty($contactStatus['email_verified'])): ?><form method="post" action="<?= e(url('/profile/verify-email')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= e(__('Gửi mã xác minh email')) ?></button></form>
<?php else: ?>
<p><?= e(__('Số điện thoại')) ?>: <?= e($user['phone']?:__('Chưa thêm số điện thoại')) ?> ? <strong><?= e(!empty($contactStatus['phone_verified'])?__('Đã xác minh'):__('Chưa xác minh')) ?></strong></p>
<?php if(empty($contactStatus['phone_verified'])): ?>
<p><a href="#personal"><?= e(__('Thêm hoặc sửa số điện thoại rồi lưu hồ sơ')) ?></a></p>
<form method="post" action="<?= e(url('/profile/phone/request')) ?>"><?= csrf_field() ?><button class="btn btn-outline-primary mb-3" type="submit"><?= e(__('Nhận mã SMS')) ?></button></form>
<form method="post" action="<?= e(url('/profile/phone/confirm')) ?>"><?= csrf_field() ?><label for="sms-code"><?= e(__('Mã xác minh SMS')) ?></label><input class="form-control mb-2" id="sms-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{4,10}" maxlength="10" required><button class="btn btn-primary" type="submit"><?= e(__('Xác minh số điện thoại')) ?></button></form>
<?php endif; endif; ?>
</section>
