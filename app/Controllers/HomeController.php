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
        $filters = array_intersect_key($input, array_flip(['location', 'check_in', 'check_out', 'guests', 'type', 'min_price', 'max_price', 'bedrooms', 'sort']));
        foreach ($filters as $value) {
            if (!is_scalar($value)) { return [[], 'Bộ lọc tìm kiếm không hợp lệ.']; }
        }
        foreach (['guests', 'type', 'bedrooms'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== ''
                && filter_var($filters[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
                return [[], 'Số khách, loại chỗ ở hoặc số phòng không hợp lệ.'];
            }
        }
        foreach (['min_price', 'max_price'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== ''
                && (!is_numeric($filters[$field]) || (float) $filters[$field] < 0)) {
                return [[], 'Khoảng giá không hợp lệ.'];
            }
        }
        if (!empty($filters['min_price']) && !empty($filters['max_price']) && $filters['min_price'] > $filters['max_price']) {
            return [[], 'Giá tối đa phải lớn hơn hoặc bằng giá tối thiểu.'];
        }
        $checkIn = trim((string) ($filters['check_in'] ?? ''));
        $checkOut = trim((string) ($filters['check_out'] ?? ''));
        if ($checkIn === '' && $checkOut === '') { return [$filters, null]; }
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $checkIn) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $checkOut)) {
            unset($filters['check_in'], $filters['check_out']);
            return [$filters, 'Ngày nhận hoặc trả phòng không hợp lệ.'];
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $checkIn);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $checkOut);
        $valid = $start && $end && $start->format('Y-m-d') === $checkIn && $end->format('Y-m-d') === $checkOut && $start >= new \DateTimeImmutable('today') && $end > $start;
        if ($valid) { return [$filters, null]; }
        unset($filters['check_in'], $filters['check_out']);
        return [$filters, 'Vui lòng chọn đủ ngày và bảo đảm ngày trả phòng sau ngày nhận phòng.'];
    }
}

