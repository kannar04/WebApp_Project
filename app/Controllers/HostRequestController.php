<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\HostRequest;
use Core\Csrf;
use Core\Session;
final class HostRequestController extends \Core\Controller
{
    public function index(): void {
        $admin=$this->requireRole('admin');
        $pagination=\Core\Pagination::from((new HostRequest())->all((int)$admin['id']),$_GET['page']??1);
        $this->render('admin/host-requests',['title'=>__('Host requests'),'requests'=>$pagination['rows'],'page'=>$pagination['page'],'pages'=>$pagination['pages']]);
    }
    public function decide(string $id): never {
        Csrf::ensure(); $admin=$this->requireRole('admin');
        try {(new HostRequest())->decide((int)$admin['id'],(int)$id,input_text($_POST,'decision'),trim(input_text($_POST,'reason'))); Session::flash('success','Host request updated.');}
        catch (\DomainException $e) {Session::flash('error',$e->getMessage());}
        $this->redirect('/admin/host-requests');
    }
}
