<?php $googleReady=(new \App\Services\GoogleAuth())->configured(); ?>
<div class="auth-social">
    <form method="post" action="<?= e(url('/auth/google')) ?>">
        <?= csrf_field() ?>
        <?php if(($returnTarget??'')!==''): ?><input type="hidden" name="next" value="<?= e($returnTarget) ?>"><?php endif; ?>
        <button class="google-auth-button" type="submit"<?= !$googleReady ? ' disabled aria-describedby="google-unavailable"' : '' ?>><?php require __DIR__.'/google-mark.php'; ?><span><?= e(__('Tiếp tục với Google')) ?></span></button>
    </form>
    <?php if(!$googleReady): ?><p class="auth-google-note" id="google-unavailable"><?= e(__('Google hiện chưa khả dụng. Bạn vẫn có thể dùng email và mật khẩu.')) ?></p><?php endif; ?>
</div>
<?php require __DIR__.'/facebook-sign-in.php'; ?>
<div class="auth-divider"><span><?= e(__('hoặc dùng email')) ?></span></div>
