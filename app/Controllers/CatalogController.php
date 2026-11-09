<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Catalog;
use Core\Controller;
use Core\Csrf;
use Core\Session;

final class CatalogController extends Controller
{
    public function index(): void
    {
        $user=$this->requireRole('admin'); $model=new Catalog();
        $this->render('admin/catalog',['title'=>'Danh mục chỗ ở và tiện nghi','catalogs'=>['type'=>$model->all((int)$user['id'],'type'),'amenity'=>$model->all((int)$user['id'],'amenity')],'old'=>Session::pullOld('catalog')]);
    }
    public function save(): never
    {
        Csrf::ensure(); $user=$this->requireRole('admin');
        try {
            $kind=$_POST['kind']??''; $name=$_POST['name']??''; $detail=$_POST['detail']??'';
            $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if ($id===false || !is_string($kind) || !is_string($name) || !is_string($detail) || !mb_check_encoding($name.$detail,'UTF-8') || mb_strlen($name)>80 || mb_strlen($detail)>5000) { throw new \DomainException('Thông tin danh mục không hợp lệ.'); }
            (new Catalog())->save((int)$user['id'],$kind,$id,$name,$detail,($_POST['active']??'')==='1'); Session::flash('success','Đã lưu danh mục. Mục ngừng sử dụng vẫn được giữ trên các tin đã đăng.');
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); $this->keepInput(); }
        catch (\PDOException $exception) { Session::flash('error','Không thể lưu. Tên danh mục có thể đã tồn tại.'); $this->keepInput(); }
        $this->redirect('/admin/catalog');
    }
    private function keepInput(): void
    {
        Session::old(['kind'=>input_text($_POST,'kind'),'id'=>(int)filter_var($_POST['id']??0,FILTER_VALIDATE_INT),'name'=>mb_substr(input_text($_POST,'name'),0,80),
            'detail'=>mb_substr(input_text($_POST,'detail'),0,5000),'active'=>($_POST['active']??'')==='1'],'catalog');
    }
}
