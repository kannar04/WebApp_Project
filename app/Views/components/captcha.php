<?php
try{$captchaId=\App\Services\Captcha::issue($captchaAction);}
catch(\DomainException $exception){$captchaId=null;}
?>
<div class="form-group captcha-field" data-captcha data-refresh-url="<?= e(url('/captcha/refresh')) ?>" data-success-text="<?= e(__('Đã đổi mã CAPTCHA.')) ?>" data-error-text="<?= e(__(\App\Services\Captcha::UNAVAILABLE)) ?>">
    <?php if($captchaId!==null): ?>
    <label for="captcha-<?= e($captchaId) ?>"><?= e(__('Mã xác nhận')) ?></label>
    <div class="captcha-challenge">
        <div class="captcha-image-frame"><img data-captcha-image src="<?= e(url('/captcha/image?id='.$captchaId)) ?>" width="220" height="72" alt="<?= e(__('Ảnh mã CAPTCHA gồm 6 ký tự')) ?>"></div>
        <button class="captcha-refresh" type="button" data-captcha-refresh aria-label="<?= e(__('Đổi ảnh CAPTCHA')) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M6.1 7a7 7 0 0 1 11.6-1L20 9M4 15l2.3 3A7 7 0 0 0 17.9 17"/></svg>
            <span><?= e(__('Đổi mã')) ?></span>
        </button>
    </div>
    <input type="hidden" name="captcha_id" value="<?= e($captchaId) ?>" data-captcha-id>
    <input class="form-control captcha-answer" id="captcha-<?= e($captchaId) ?>" name="captcha_answer" type="text" maxlength="6" minlength="6" placeholder="<?= e(__('Nhập 6 ký tự trong ảnh')) ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-describedby="captcha-help-<?= e($captchaId) ?>" required>
    <small id="captcha-help-<?= e($captchaId) ?>"><?= e(__('Nhập 6 ký tự trong ảnh, không phân biệt hoa thường.')) ?></small>
    <p class="small" data-captcha-status role="status" aria-live="polite"></p>
    <noscript><small><?= e(__('Tải lại trang để lấy ảnh CAPTCHA mới.')) ?></small></noscript>
    <?php else: ?><p class="small" role="alert"><?= e(__(\App\Services\Captcha::UNAVAILABLE)) ?></p><?php endif; ?>
</div>
