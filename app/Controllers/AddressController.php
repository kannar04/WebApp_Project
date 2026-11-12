<?php
declare(strict_types=1);
namespace App\Controllers;

final class AddressController extends \Core\Controller
{
    public function search(): never
    {
        $address = trim(input_text($_GET,'q'));
        if (mb_strlen($address)<3 || mb_strlen($address)>255) { $this->json(false,'Nhập địa chỉ từ 3 đến 255 ký tự.',null,[],422); }
        $last = (int)($_SESSION['_address_search_at']??0);
        if (time()-$last<2) { $this->json(false,'Vui lòng chờ một chút trước khi tìm lại.',null,[],429); }
        $_SESSION['_address_search_at']=time();
        session_write_close();
        try { $this->json(true,'',['places'=>(new \App\Services\AddressSearch())->search($address)]); }
        catch (\DomainException $exception) { $this->json(false,$exception->getMessage(),null,[],503); }
    }
}
