<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Services\DebugVerification;
use App\Models\Trust;
use Core\Csrf;
use Core\Session;

final class DebugVerificationController extends \Core\Controller
{
    public function update(): never
    {
        if (!DebugVerification::available()) { http_response_code(404);exit; }
        Csrf::ensure();
        $user=$this->requireAuth();
        $action=input_text($_POST,'action');
        if ($action==='toggle') {
            unset($_SESSION['_debug_verification']);
            if (($_POST['enabled']??'')==='1') {
                $_SESSION['_debug_verification']=['enabled'=>true,'user_id'=>(int)$user['id']];
            }
        } elseif (in_array($action,['phone','identity'],true) && DebugVerification::enabled()) {
            $phone=Trust::normalizePhone((string)$user['phone']);
            if (!preg_match('/^0[35789][0-9]{8}$/D',$phone)) {
                Session::flash('error','Lưu số điện thoại hợp lệ trong hồ sơ trước khi dùng nút debug.');
            } else {
                $_SESSION['_debug_verification']['user_id']=(int)$user['id'];
                $_SESSION['_debug_verification'][$action]=$phone;
                Session::flash('success','Đã mô phỏng xác minh trong phiên debug. Không thay đổi dữ liệu xác minh thật.');
            }
        } else { http_response_code(422);exit; }
        $this->redirect('/profile#debug-verification');
    }
}
