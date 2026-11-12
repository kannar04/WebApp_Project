<?php if(\App\Services\DebugVerification::available()): ?>
<section class="surface-card mt-4" id="debug-verification">
<h2><?= e(__('Debug xác minh (local)')) ?></h2>
<p><?= e(__('Mô phỏng cho tài khoản hiện tại trong phiên này. Tắt debug hoặc đăng xuất để trở lại trạng thái thật; không lưu CCCD giả và không gửi SMS.')) ?></p>
<form method="post" action="<?= e(url('/profile/debug-verification')) ?>"><?= csrf_field() ?>
<label><input type="checkbox" name="enabled" value="1" <?= \App\Services\DebugVerification::enabled()?'checked':'' ?>> <?= e(__('Bật các nút xác minh debug')) ?></label>
<button class="btn btn-outline-primary ml-2" type="submit" name="action" value="toggle"><?= e(__('Lưu chế độ debug')) ?></button>
</form>
<?php if(\App\Services\DebugVerification::enabled()): ?>
<p class="small"><?= e(__('Use both debug buttons to test eligibility, then submit a host request. An admin must approve it separately, including in debug mode.')) ?></p>
<form class="action-row mt-3" method="post" action="<?= e(url('/profile/debug-verification')) ?>"><?= csrf_field() ?>
<button class="btn btn-outline-primary" type="submit" name="action" value="phone"><?= e(__('Debug: xác minh email và SĐT')) ?></button>
<button class="btn btn-outline-primary" type="submit" name="action" value="identity"><?= e(__('Debug: duyệt CCCD')) ?></button>
</form>
<?php endif; ?>
</section>
<?php endif; ?>
