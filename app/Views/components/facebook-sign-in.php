<?php
if(!(require dirname(__DIR__,3).'/config/facebook.php')['enabled']){return;}
$facebookReady=(new \App\Services\FacebookAuth())->configured();
?>
<div class="auth-social facebook-sign-in">
    <form method="post" action="<?= e(url('/auth/facebook')) ?>">
        <?= csrf_field() ?>
        <?php if(($returnTarget??'')!==''): ?><input type="hidden" name="next" value="<?= e($returnTarget) ?>"><?php endif; ?>
        <button class="google-auth-button facebook-auth-button" type="submit"<?= !$facebookReady?' disabled aria-describedby="facebook-unavailable"':'' ?>><?php require __DIR__.'/facebook-mark.php'; ?><span><?= e(__('Tiếp tục với Facebook')) ?></span></button>
    </form>
    <?php if(!$facebookReady): ?><p class="auth-google-note" id="facebook-unavailable"><?= e(__('Facebook hiện chưa khả dụng. Bạn vẫn có thể dùng Google hoặc email và mật khẩu.')) ?></p><?php endif; ?>
</div>
