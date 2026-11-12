<h1><?= e(__('Xác minh email')) ?></h1>
<?php if($challenge): ?>
<p><?= e(__('Nhập mã 6 chữ số đã gửi tới email:')) ?> <strong><?= e($challenge['email']) ?></strong></p>
<p><?= e(__('Mã có hiệu lực 10 phút, tối đa 5 lần nhập sai. Kiểm tra cả thư rác nếu chưa nhận được.')) ?></p>
<form method="post" action="<?= e(url('/verify-email')) ?>"><?= csrf_field() ?>
<label for="email-code"><?= e(__('Mã xác minh email')) ?></label><input class="form-control mb-3" id="email-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
<button class="btn btn-primary btn-block" type="submit"><?= e(__('Xác minh email')) ?></button></form>
<form class="mt-3" method="post" action="<?= e(url('/verify-email/resend')) ?>"><?= csrf_field() ?><button class="btn btn-outline-primary btn-block" type="submit"><?= e(__('Gửi lại mã email (sau 60 giây)')) ?></button></form>
<?php endif; ?>
<a class="btn btn-ghost" href="<?= e(url(\Core\Auth::check()?'/profile#contact-verification':'/register')) ?>"><?= e(__('Quay lại')) ?></a>
