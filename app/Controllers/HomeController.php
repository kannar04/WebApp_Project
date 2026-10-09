<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Listing;
use App\Models\Wishlist;
use Core\Auth;
use Core\Controller;
use Core\Session;
final class HomeController extends Controller
{
    public function index(): void
    {
        [$filters, $searchError] = $this->validatedFilters($_GET);
        if ($searchError !== null) { Session::flash('error', $searchError); }
        $listingModel = new Listing();
        $listings = $listingModel->search($filters);
        $favorites = Auth::check() ? (new Wishlist())->ids((int) Auth::id()) : [];
        $this->render('home', ['title'=>'Tìm chỗ ở ấm áp','listings'=>$listings,'types'=>$listingModel->types(),'filters'=>$filters,'favorites'=>$favorites,'searchError'=>$searchError]);
    }

    public function search(): never
    {
        [$filters, $searchError] = $this->validatedFilters($_GET);
        if ($searchError !== null) {
            $this->json(false, $searchError, null, ['dates'=>$searchError], 422);
        }
        $this->json(true, 'Đã tải kết quả.', ['listings'=>(new Listing())->search($filters)]);
    }

    private function validatedFilters(array $input): array
    {
        $filters = $input;
        $checkIn = trim((string) ($input['check_in'] ?? ''));
        $checkOut = trim((string) ($input['check_out'] ?? ''));
        if ($checkIn === '' && $checkOut === '') { return [$filters, null]; }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $checkIn);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $checkOut);
        $valid = $start && $end && $start->format('Y-m-d') === $checkIn && $end->format('Y-m-d') === $checkOut && $start >= new \DateTimeImmutable('today') && $end > $start;
        if ($valid) { return [$filters, null]; }
        unset($filters['check_in'], $filters['check_out']);
        return [$filters, 'Vui lòng chọn đủ ngày và bảo đảm ngày trả phòng sau ngày nhận phòng.'];
    }
}

