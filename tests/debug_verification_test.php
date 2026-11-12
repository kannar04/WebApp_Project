<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/core/bootstrap.php';
use App\Services\DebugVerification;
function debugCheck(bool $ok,string $message): void {if(!$ok){throw new RuntimeException($message);}}
putenv('APP_ENV=local');putenv('DEBUG_VERIFICATION_ENABLED=true');$_SERVER['REMOTE_ADDR']='127.0.0.1';
Core\Session::start();
$_SESSION['_debug_verification']=['enabled'=>true,'user_id'=>999,'phone'=>'0912345678'];
debugCheck(DebugVerification::verified('phone',999),'Local override must work.');
debugCheck(!DebugVerification::verified('phone',998),'Override must be bound to the current user.');
putenv('APP_ENV=production');debugCheck(!DebugVerification::available()&&!DebugVerification::verified('phone',999),'Production must reject bypass.');
putenv('APP_ENV=local');$_SERVER['REMOTE_ADDR']='26.45.152.122';debugCheck(!DebugVerification::available(),'Non-loopback clients must be rejected.');
$_SERVER['REMOTE_ADDR']='127.0.0.1';putenv('DEBUG_VERIFICATION_ENABLED=false');debugCheck(!DebugVerification::available(),'Config off must reject bypass.');
putenv('DEBUG_VERIFICATION_ENABLED=true');unset($_SESSION['_debug_verification']);
$users=new App\Models\User();$testUser=null;
foreach($users->all() as $user){if(preg_match('/^0[35789][0-9]{8}$/D',App\Models\Trust::normalizePhone((string)$user['phone']))){$testUser=$users->findWithRoles((int)$user['id']);break;}}
debugCheck($testUser!==null,'Local fixture requires an account with a saved phone.');
debugCheck(Core\Auth::signIn((int)$testUser['id']),'Local test session must authenticate.');
$id=(int)$testUser['id'];$phone=App\Models\Trust::normalizePhone((string)$testUser['phone']);
$contacts=new App\Models\ContactVerification();$trust=new App\Models\Trust();
$beforeContact=$contacts->status($id);$beforeIdentity=$trust->verificationStatus($id);
$_SESSION['_debug_verification']=['enabled'=>true,'user_id'=>$id,'phone'=>$phone,'identity'=>$phone];
debugCheck((bool)$contacts->status($id)['phone_verified']&&(bool)$contacts->status($id)['email_verified'],'Phone debug must satisfy the booking contact gate.');
debugCheck($trust->verificationStatus($id)['status']==='approved','CCCD debug must show approval.');
debugCheck((bool)$trust->badges([$id],$id)[$id]['is_verified'],'Own badge must reflect the simulation.');
ob_start();(new App\Controllers\ProfileController())->show();$html=ob_get_clean();
debugCheck(str_contains($html,'Debug: duyệt CCCD')&&str_contains($html,'Debug: xác minh email và SĐT'),'Enabled profile must render both buttons.');
unset($_SESSION['_debug_verification']);
debugCheck($contacts->status($id)===$beforeContact,'Disabling debug must restore real contact state.');
debugCheck($trust->verificationStatus($id)===$beforeIdentity,'Disabling debug must restore real CCCD state.');
ob_start();(new App\Controllers\ProfileController())->show();$html=ob_get_clean();
debugCheck(!str_contains($html,'Debug: duyệt CCCD'),'Disabled toggle must hide bypass buttons.');
Core\Auth::logout();
echo "Debug verification checks passed: gating, user isolation, UI, simulation and restoration. No verification data changed.\n";
