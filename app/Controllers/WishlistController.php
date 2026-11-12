<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Wishlist;
use Core\Auth;
use Core\Controller;
use Core\Csrf;
final class WishlistController extends Controller
{
    public function index(): void {$u=$this->requireAuth();$this->render('listings/wishlist',['title'=>'Chỗ ở yêu thích','listings'=>(new Wishlist())->all((int)$u['id'])]);}
    /** The saved listing ids, for hearts on a page that Back restored. Not requireAuth: a background request must not become the page to return to after sign-in. */
    public function saved(): never
    {
        $user = Auth::user();
        if (!$user) { $this->json(false, 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.', null, [], 401); }
        $this->json(true, 'Đã tải kết quả.', ['ids' => (new Wishlist())->ids((int) $user['id'])]);
    }
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

