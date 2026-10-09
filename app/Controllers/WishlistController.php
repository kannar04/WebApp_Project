<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Wishlist;
use Core\Controller;
use Core\Csrf;
final class WishlistController extends Controller
{
    public function index(): void {$u=$this->requireAuth();$this->render('listings/wishlist',['title'=>'Chỗ ở yêu thích','listings'=>(new Wishlist())->all((int)$u['id'])]);}
    public function toggle(string $id): never
    {
        Csrf::ensure();
        $user = $this->requireAuth();
        try {
            $saved = (new Wishlist())->toggle((int) $user['id'], (int) $id);
            $this->json(true, $saved ? 'Đã lưu vào yêu thích.' : 'Đã bỏ khỏi yêu thích.', ['saved' => $saved]);
        } catch (\DomainException $exception) {
            $this->json(false, $exception->getMessage(), null, [], 404);
        }
    }
}

