<?php
declare(strict_types=1);
namespace App\Controllers;
use Core\Controller;
use Core\Csrf;
use Core\I18n;
use Core\Session;

final class LocaleController extends Controller
{
    /** Switches the interface language (cookie only, no account change) and returns to the same page. */
    public function change(): never
    {
        $returnTo=I18n::safeReturnPath($_POST['return_to']??'/');
        // A page that sends no Referer (the reset form) gets a stale switch back to itself too.
        Csrf::ensure($returnTo);
        $locale=input_text($_POST,'locale');
        if (!in_array($locale,I18n::LOCALES,true)) {
            Session::flash('error','Ngôn ngữ không được hỗ trợ.');
            $this->redirect($returnTo);
        }
        I18n::remember($locale);
        $this->redirect($returnTo);
    }
}
