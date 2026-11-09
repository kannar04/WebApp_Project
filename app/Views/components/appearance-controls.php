<?php $switchLocale=\Core\I18n::locale()==='en'?'vi':'en'; ?>
<form class="locale-switch" method="post" action="<?= e(url('/locale')) ?>">
    <?= csrf_field() ?><input type="hidden" name="return_to" value="<?= e(\Core\I18n::returnPath()) ?>">
    <button class="btn btn-ghost locale-toggle" type="submit" name="locale" value="<?= $switchLocale ?>" lang="<?= $switchLocale ?>" title="<?= $switchLocale==='en'?'Switch to English':'Chuyển sang tiếng Việt' ?>">
        <span aria-hidden="true"><?= $switchLocale==='en'?'EN':'VI' ?></span><span class="sr-only"><?= $switchLocale==='en'?'English':'Tiếng Việt' ?></span>
    </button>
</form>
<button class="btn btn-ghost theme-toggle" type="button" data-theme-toggle data-label-dark-on="<?= e(__('Bật chế độ tối')) ?>" data-label-dark-off="<?= e(__('Tắt chế độ tối')) ?>" aria-pressed="false" title="<?= e(__('Bật chế độ tối')) ?>">
    <svg class="theme-icon theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    <svg class="theme-icon theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4.5"/><path d="M12 1.5v2.5M12 20v2.5M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M1.5 12H4M20 12h2.5M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg>
    <span class="theme-toggle-label" data-theme-label><?= e(__('Bật chế độ tối')) ?></span>
</button>
