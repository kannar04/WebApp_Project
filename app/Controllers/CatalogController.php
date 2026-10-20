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
        $this->render('admin/catalog',['title'=>'Danh mục chỗ ở và tiện nghi','catalogs'=>['type'=>$model->all((int)$user['id'],'type'),'amenity'=>$model->all((int)$user['id'],'amenity')]]);
    }
    public function save(): never
    {
        Csrf::ensure(); $user=$this->requireRole('admin');
        try {
            $kind=$_POST['kind']??''; $name=$_POST['name']??''; $detail=$_POST['detail']??'';
            $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if ($id===false || !is_string($kind) || !is_string($name) || !is_string($detail) || mb_strlen($name)>80 || mb_strlen($detail)>5000) { throw new \DomainException('Thông tin danh mục không hợp lệ.'); }
            (new Catalog())->save((int)$user['id'],$kind,$id,$name,$detail,($_POST['active']??'')==='1'); Session::flash('success','Đã lưu danh mục. Ngừng sử dụng là xóa mềm, không mất dữ liệu tin cũ.');
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        catch (\PDOException $exception) { Session::flash('error','Không thể lưu. Tên danh mục có thể đã tồn tại.'); }
        $this->redirect('/admin/catalog');
    }
}
