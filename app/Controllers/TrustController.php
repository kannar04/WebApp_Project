<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Listing;
use App\Models\Trust;
use Core\Controller;
use Core\Csrf;
use Core\Session;

/** Nice-to-have trust: identity verification, Admin review and Host check-in guides. */
final class TrustController extends Controller
{
    private const GUIDE_LIMITS = ['arrival_instructions' => 3000, 'door_code' => 60, 'wifi_name' => 100, 'wifi_password' => 100, 'note' => 2000];

    public function submitVerification(): never
    {
        Csrf::ensure();
        $user = $this->requireAuth();
        $name = $this->postedText('full_name_on_card');
        $phone = $this->postedText('phone');
        $idNumber = $this->postedText('id_number');
        try {
            if ($name === null || $phone === null || $idNumber === null || mb_strlen($name) > 150 || strlen($phone) > 20 || strlen($idNumber) > 20) {
                throw new \DomainException('Thông tin xác minh không hợp lệ hoặc vượt giới hạn độ dài.');
            }
            (new Trust())->submitVerification((int) $user['id'], $name, $phone, $idNumber);
            Session::pull('_verification_old');
            Session::flash('success', 'Đã gửi yêu cầu xác minh. Admin sẽ xem xét và thông báo kết quả cho bạn.');
        } catch (\Throwable $exception) {
            // The CCCD number is never kept in the session for re-display.
            Session::put('_verification_old', ['full_name_on_card' => (string) $name, 'phone' => (string) $phone]);
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : 'Không thể gửi yêu cầu xác minh. Vui lòng thử lại.');
        }
        $this->redirect('/profile#verification');
    }

    public function adminIndex(): void
    {
        $admin = $this->requireRole('admin');
        $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'pending';
        if (!in_array($status, ['', 'pending', 'approved', 'rejected'], true)) {
            $status = 'pending';
            Session::flash('error', 'Bộ lọc trạng thái xác minh không hợp lệ.');
        }
        $query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 254) : '';
        $this->render('admin/verifications', ['title' => 'Xác minh danh tính',
            'verifications' => (new Trust())->adminVerifications((int) $admin['id'], $status, $query), 'status' => $status, 'query' => $query]);
    }

    public function adminDecide(string $id): never
    {
        Csrf::ensure();
        $admin = $this->requireRole('admin');
        $decision = is_string($_POST['decision'] ?? null) ? $_POST['decision'] : '';
        $reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
        try {
            if (mb_strlen($reason) > 1000) { throw new \DomainException('Ghi chú quyết định tối đa 1000 ký tự.'); }
            (new Trust())->decideVerification((int) $id, (int) $admin['id'], $decision, $reason);
            Session::flash('success', $decision === 'approved' ? 'Đã duyệt xác minh danh tính; số CCCD đã được che.' : 'Đã từ chối yêu cầu xác minh; số CCCD đã được che.');
        } catch (\Throwable $exception) {
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : 'Không thể lưu quyết định. Vui lòng thử lại.');
        }
        $this->redirect('/admin/verifications');
    }

    public function guideForm(string $id): void
    {
        $user = $this->requireRole('host');
        $listing = (new Listing())->findOwned((int) $id, (int) $user['id']);
        if (!$listing) { http_response_code(404); $this->render('errors/404', ['title' => 'Không tìm thấy chỗ ở']); return; }
        $this->render('host/checkin-guide', ['title' => 'Hướng dẫn nhận phòng', 'listing' => $listing,
            'guide' => (new Trust())->guideForHost((int) $id, (int) $user['id']), 'old' => Session::pull('_checkin_guide_old', [])]);
    }

    public function guideSave(string $id): never
    {
        Csrf::ensure();
        $user = $this->requireRole('host');
        if (!(new Listing())->findOwned((int) $id, (int) $user['id'])) {
            Session::flash('error', 'Không có quyền quản lý chỗ ở.');
            $this->redirect('/host');
        }
        $data = []; $valid = true;
        foreach (self::GUIDE_LIMITS as $field => $limit) {
            $value = $this->postedText($field);
            if ($value === null || mb_strlen($value) > $limit) { $valid = false; }
            $data[$field] = (string) $value;
        }
        try {
            if (!$valid) { throw new \DomainException('Một số trường không hợp lệ hoặc vượt giới hạn độ dài.'); }
            (new Trust())->saveGuide((int) $id, (int) $user['id'], $data);
            Session::flash('success', 'Đã lưu hướng dẫn nhận phòng. Khách chỉ thấy sau khi booking được xác nhận.');
        } catch (\Throwable $exception) {
            Session::put('_checkin_guide_old', $data);
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : 'Không thể lưu hướng dẫn. Vui lòng thử lại.');
        }
        $this->redirect('/host/listings/'.(int) $id.'/checkin-guide');
    }

    /** Missing fields become empty strings; arrays and other non-strings are rejected as null. */
    private function postedText(string $field): ?string
    {
        $value = $_POST[$field] ?? '';
        return is_string($value) ? trim($value) : null;
    }
}
