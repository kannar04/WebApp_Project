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
        Csrf::ensure();
        $locale=(string)($_POST['locale']??'');
        $returnTo=I18n::safeReturnPath($_POST['return_to']??'/');
        if (!in_array($locale,I18n::LOCALES,true)) {
            Session::flash('error','Ngôn ngữ không được hỗ trợ.');
            $this->redirect($returnTo);
        }
        I18n::remember($locale);
        $this->redirect($returnTo);
    }
}
