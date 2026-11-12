<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';

function payoutCheck(bool $condition,string $message): void { if(!$condition){throw new RuntimeException($message);} }

$db=Core\Database::connection();
$schema=(string)$db->query('SELECT DATABASE()')->fetchColumn();
$tables=$db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name IN ('host_payout_accounts','host_payouts','host_payout_settings')");
$tables->execute([$schema]);payoutCheck((int)$tables->fetchColumn()===3,'Host payout tables are missing.');

$expected=['DST_sp_host_payout_account_get','LTP_sp_host_payout_account_save','NMT_sp_admin_payout_accounts','NTK_sp_admin_payout_account_decide',
    'NMT_sp_host_payout_summary','NTK_sp_host_payouts','DST_sp_admin_host_payouts','LTP_sp_admin_host_payout_create',
    'NMT_sp_admin_host_payout_mark_paid','NTK_sp_admin_host_payout_totals','DST_sp_host_payout_settings_get',
    'LTP_sp_admin_host_payout_settings_save','NMT_sp_hourly_host_payout_candidates','NTK_sp_host_payout_scheduler_lock'];
$placeholders=implode(',',array_fill(0,count($expected),'?'));
$routines=$db->prepare("SELECT routine_name FROM information_schema.routines WHERE routine_schema=? AND routine_type='PROCEDURE' AND routine_name IN ($placeholders)");
$routines->execute(array_merge([$schema],$expected));
$actual=$routines->fetchAll(PDO::FETCH_COLUMN);sort($actual);sort($expected);payoutCheck($actual===$expected,'Host payout procedures are incomplete.');
$distribution=$db->query("SELECT LEFT(routine_name,3) owner,COUNT(*) total FROM information_schema.routines WHERE routine_schema=DATABASE() AND routine_type='PROCEDURE' AND routine_name REGEXP '^(DST|LTP|NMT|NTK)_sp_' GROUP BY LEFT(routine_name,3) ORDER BY owner")->fetchAll();
payoutCheck(count($distribution)===4&&count(array_unique(array_column($distribution,'total')))===1,'Procedure ownership is not evenly distributed.');

$admin=(int)$db->query("SELECT user_id FROM user_roles WHERE role='admin' ORDER BY user_id LIMIT 1")->fetchColumn();
payoutCheck($admin>0,'An Admin fixture is required.');
$fee=App\Models\HostPayout::feePercent();
payoutCheck((float)$fee>=0&&(float)$fee<=100,'Configured payout fee is invalid.');
$model=new App\Models\HostPayout();
$model->adminAccounts($admin,'');$rows=$model->adminPayouts($admin);$totals=$model->adminTotals($admin);
payoutCheck(isset($totals['pending_count'],$totals['paid_count']),'Admin payout totals are malformed.');
foreach($rows as $row){
    payoutCheck(in_array($row['row_type'],['eligible','payout'],true),'Unknown Admin payout row type.');
    payoutCheck((float)$row['net_amount']>=0,'Negative Host payout detected.');
}
$model->hourlyCandidates($admin);
payoutCheck($model->schedulerLock(true),'Scheduler lock could not be acquired.');
payoutCheck($model->schedulerLock(false),'Scheduler lock could not be released.');
$db->beginTransaction();
try{$model->saveGlobalFee($admin,'12.50');payoutCheck(App\Models\HostPayout::feePercent()==='12.50','Global payout fee update failed.');}
finally{$db->rollBack();}

$host=(int)$db->query("SELECT user_id FROM user_roles WHERE role='host' ORDER BY user_id LIMIT 1")->fetchColumn();
if($host>0){
    $model->account($host);$summary=$model->hostSummary($host);$model->hostPayouts($host);
    payoutCheck(isset($summary['eligible_total'],$summary['pending_total'],$summary['paid_total']),'Host payout summary is malformed.');
}

$verifiedHostStatement=$db->prepare("SELECT u.id,u.full_name FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='host' JOIN identity_verifications v ON v.user_id=u.id AND v.status='approved' WHERE u.id<>? AND u.status='active' AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1");
$verifiedHostStatement->execute([$admin]);$verifiedHost=$verifiedHostStatement->fetch();
if($verifiedHost){
    $db->beginTransaction();
    try{
        $fixture=['bank_id'=>'TEST','bank_name'=>'Payout workflow test','account_no'=>str_pad((string)$verifiedHost['id'],8,'0',STR_PAD_LEFT),'account_name'=>(string)$verifiedHost['full_name']];
        $model->saveAccount((int)$verifiedHost['id'],$fixture);$account=$model->account((int)$verifiedHost['id']);
        payoutCheck($account!==null&&$account['status']==='pending','Saving an account must require a new review.');
        $model->decideAccount((int)$account['id'],$admin,'approved','');$approved=$model->account((int)$verifiedHost['id']);
        payoutCheck($approved!==null&&$approved['status']==='approved','Admin account approval failed.');
    }finally{$db->rollBack();}
}

echo "Host payout checks passed: schema, balanced routines, permissions, configured fee and read models. No payout data changed.\n";
