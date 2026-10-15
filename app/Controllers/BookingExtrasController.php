<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\BookingExtras;
use Core\Controller;
use Core\Csrf;
use Core\Session;

/** Host pricing rules, Admin holidays/promotions/payments, and recording pay-at-check-in payments. */
final class BookingExtrasController extends Controller
{
    private const GENERIC_ERROR = 'Không thể hoàn tất thao tác. Vui lòng thử lại.';

    public function hostPricing(string $id): void
    {
        $user = $this->requireRole('host');
        try { $settings = (new BookingExtras())->hostSettings((int) $id, (int) $user['id']); }
        catch (\DomainException $exception) {
            http_response_code(404); $this->render('errors/404', ['title'=>'Không tìm thấy chỗ ở']); return;
        }
        $this->render('host/pricing', ['title'=>'Giá & đặt chỗ', 'settings'=>$settings, 'old'=>Session::pullOld(),
            'holidays'=>(new BookingExtras())->upcomingHolidays(8)]);
    }

    public function hostPricingSave(string $id): never
    {
        Csrf::ensure(); $user = $this->requireRole('host');
        $input = ['weekend_nightly_rate'=>$this->text('weekend_nightly_rate', 20), 'holiday_nightly_rate'=>$this->text('holiday_nightly_rate', 20),
            'weekly_discount_pct'=>$this->text('weekly_discount_pct', 10), 'monthly_discount_pct'=>$this->text('monthly_discount_pct', 10),
            'instant_book'=>($_POST['instant_book'] ?? '') === '1'];
        try {
            $weekend = $this->optionalNumber($input['weekend_nightly_rate'], 0.01, 9999999999.99, 'Giá cuối tuần phải là số lớn hơn 0.');
            $holiday = $this->optionalNumber($input['holiday_nightly_rate'], 0.01, 9999999999.99, 'Giá ngày lễ phải là số lớn hơn 0.');
            $weekly = $this->optionalNumber($input['weekly_discount_pct'], 0, 90, 'Giảm giá theo tuần phải từ 0 đến 90%.');
            $monthly = $this->optionalNumber($input['monthly_discount_pct'], 0, 90, 'Giảm giá theo tháng phải từ 0 đến 90%.');
            (new BookingExtras())->saveHostSettings((int) $id, (int) $user['id'], $weekend, $holiday, $weekly, $monthly, $input['instant_book']);
            Session::flash('success', 'Đã lưu giá & tùy chọn đặt chỗ. Thay đổi áp dụng cho báo giá mới, không cần Admin duyệt lại tin.');
        } catch (\Throwable $exception) {
            Session::old($input);
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : self::GENERIC_ERROR);
        }
        $this->redirect('/host/listings/'.(int) $id.'/pricing');
    }

    public function hostMarkPaid(string $id): never
    {
        Csrf::ensure(); $user = $this->requireRole('host');
        $this->markPaid((int) $id, (int) $user['id'], false);
        $this->redirect(($_POST['return_to'] ?? '') === 'detail' ? '/bookings/'.(int) $id : '/host');
    }

    public function adminHolidays(): void
    {
        $user = $this->requireRole('admin');
        $this->render('admin/holidays', ['title'=>'Ngày lễ', 'holidays'=>(new BookingExtras())->holidays((int) $user['id']), 'old'=>Session::pullOld()]);
    }

    public function adminHolidaySave(): never
    {
        Csrf::ensure(); $user = $this->requireRole('admin');
        $input = ['holiday_date'=>$this->text('holiday_date', 10), 'name'=>$this->text('name', 200)];
        try {
            $date = $this->date($input['holiday_date'], 'Ngày lễ không hợp lệ.');
            if ($input['name'] === '' || mb_strlen($input['name']) > 120) { throw new \DomainException('Tên ngày lễ cần từ 1 đến 120 ký tự.'); }
            (new BookingExtras())->saveHoliday((int) $user['id'], $date, $input['name']);
            Session::flash('success', 'Đã lưu ngày lễ '.date('d/m/Y', (int) strtotime($date)).'. Giá ngày lễ áp dụng cho báo giá mới.');
        } catch (\Throwable $exception) {
            Session::old($input);
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : self::GENERIC_ERROR);
        }
        $this->redirect('/admin/holidays');
    }

    public function adminHolidayDelete(string $id): never
    {
        Csrf::ensure(); $user = $this->requireRole('admin');
        try {
            (new BookingExtras())->deleteHoliday((int) $user['id'], (int) $id);
            Session::flash('success', 'Đã xóa ngày lễ. Booking đã đặt vẫn giữ giá đã lưu.');
        } catch (\Throwable $exception) {
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : self::GENERIC_ERROR);
        }
        $this->redirect('/admin/holidays');
    }

    public function adminPromotions(): void
    {
        $user = $this->requireRole('admin');
        $promotions = (new BookingExtras())->promotions((int) $user['id']);
        $editId = (int) filter_var($_GET['edit'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['default'=>0, 'min_range'=>1]]);
        $editing = null;
        foreach ($promotions as $promotion) { if ((int) $promotion['id'] === $editId) { $editing = $promotion; } }
        $this->render('admin/promotions', ['title'=>'Mã khuyến mãi', 'promotions'=>$promotions, 'editing'=>$editing, 'old'=>Session::pullOld()]);
    }

    public function adminPromotionSave(): never
    {
        Csrf::ensure(); $user = $this->requireRole('admin');
        $input = ['id'=>$this->text('id', 20), 'code'=>strtoupper($this->text('code', 40)), 'description'=>$this->text('description', 300),
            'discount_type'=>$this->text('discount_type', 10), 'discount_value'=>$this->text('discount_value', 20),
            'max_discount_amount'=>$this->text('max_discount_amount', 20), 'min_nights'=>$this->text('min_nights', 10),
            'min_subtotal'=>$this->text('min_subtotal', 20), 'valid_from'=>$this->text('valid_from', 10), 'valid_to'=>$this->text('valid_to', 10),
            'usage_limit'=>$this->text('usage_limit', 10), 'is_active'=>($_POST['is_active'] ?? '') === '1'];
        $promotionId = ctype_digit($input['id']) ? (int) $input['id'] : 0;
        $returnPath = $promotionId > 0 ? '/admin/promotions?edit='.$promotionId : '/admin/promotions';
        try {
            if (!preg_match('/^[A-Z0-9]{4,20}$/D', $input['code'])) { throw new \DomainException('Mã khuyến mãi phải gồm 4–20 chữ cái A–Z hoặc số.'); }
            if ($input['description'] === '' || mb_strlen($input['description']) > 255) { throw new \DomainException('Mô tả khuyến mãi cần từ 1 đến 255 ký tự.'); }
            if (!in_array($input['discount_type'], ['percent', 'fixed'], true)) { throw new \DomainException('Loại giảm giá không hợp lệ.'); }
            $data = ['code'=>$input['code'], 'description'=>$input['description'], 'type'=>$input['discount_type'],
                'value'=>$this->optionalNumber($input['discount_value'], 0.01, 9999999999.99, 'Giá trị giảm phải lớn hơn 0.'),
                'max'=>$this->optionalNumber($input['max_discount_amount'], 1, 9999999999.99, 'Mức giảm tối đa phải lớn hơn 0.'),
                'min_nights'=>$this->optionalInteger($input['min_nights'], 1, 365, 'Số đêm tối thiểu phải từ 1 đến 365.'),
                'min_subtotal'=>$this->optionalNumber($input['min_subtotal'], 0, 9999999999.99, 'Tiền phòng tối thiểu không hợp lệ.'),
                'from'=>$this->date($input['valid_from'], 'Ngày bắt đầu không hợp lệ.'), 'to'=>$this->date($input['valid_to'], 'Ngày kết thúc không hợp lệ.'),
                'limit'=>$this->optionalInteger($input['usage_limit'], 1, 1000000, 'Giới hạn lượt dùng phải từ 1 trở lên.'), 'active'=>$input['is_active']];
            if ($data['value'] === null) { throw new \DomainException('Vui lòng nhập giá trị giảm.'); }
            (new BookingExtras())->savePromotion((int) $user['id'], $promotionId, $data);
            Session::flash('success', 'Đã lưu mã khuyến mãi '.$data['code'].'.');
            $returnPath = '/admin/promotions';
        } catch (\Throwable $exception) {
            Session::old($input);
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : self::GENERIC_ERROR);
        }
        $this->redirect($returnPath);
    }

    public function adminPromotionToggle(string $id): never
    {
        Csrf::ensure(); $user = $this->requireRole('admin');
        $active = ($_POST['active'] ?? '') === '1';
        try {
            (new BookingExtras())->togglePromotion((int) $user['id'], (int) $id, $active);
            Session::flash('success', $active ? 'Đã bật lại mã khuyến mãi.' : 'Đã tắt mã khuyến mãi. Booking đã dùng mã vẫn giữ giá đã lưu.');
        } catch (\Throwable $exception) {
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : self::GENERIC_ERROR);
        }
        $this->redirect('/admin/promotions');
    }

    public function adminPromotionDelete(string $id): never
    {
        Csrf::ensure(); $user = $this->requireRole('admin');
        try {
            (new BookingExtras())->deletePromotion((int) $user['id'], (int) $id);
            Session::flash('success', 'Đã xóa mã khuyến mãi chưa từng được dùng.');
        } catch (\Throwable $exception) {
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : self::GENERIC_ERROR);
        }
        $this->redirect('/admin/promotions');
    }

    public function adminPayments(): void
    {
        $user = $this->requireRole('admin');
        $status = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['unpaid', 'paid', 'refunded', 'not_required'], true) ? $_GET['status'] : '';
        $query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 254) : '';
        $model = new BookingExtras();
        $this->render('admin/payments', ['title'=>'Thanh toán', 'payments'=>$model->adminPayments((int) $user['id'], $status, $query),
            'totals'=>$model->adminPaymentTotals((int) $user['id']), 'status'=>$status, 'query'=>$query,
            'transfers'=>(new \App\Models\Payment())->adminTransfers((int) $user['id'])]);
    }

    public function adminPaymentPaid(string $id): never
    {
        Csrf::ensure(); $user = $this->requireRole('admin');
        $this->markPaid((int) $id, (int) $user['id'], true);
        $this->redirect($this->adminReturnPath((int) $id));
    }

    public function adminPaymentRefund(string $id): never
    {
        Csrf::ensure(); $user = $this->requireRole('admin');
        $note = $this->text('note', 600);
        try {
            if ($note === '' || mb_strlen($note) > 500) { throw new \DomainException('Vui lòng ghi chú lý do hoàn tiền (tối đa 500 ký tự).'); }
            (new BookingExtras())->refund((int) $id, (int) $user['id'], $note);
            Session::flash('success', 'Đã ghi nhận hoàn tiền cho booking #'.(int) $id.'.');
        } catch (\Throwable $exception) {
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : self::GENERIC_ERROR);
        }
        $this->redirect($this->adminReturnPath((int) $id));
    }

    private function markPaid(int $bookingId, int $actorId, bool $asAdmin): void
    {
        $note = $this->text('note', 600);
        try {
            if (mb_strlen($note) > 500) { throw new \DomainException('Ghi chú tối đa 500 ký tự.'); }
            (new BookingExtras())->markPaid($bookingId, $actorId, $asAdmin, $note);
            Session::flash('success', 'Đã ghi nhận thanh toán khi nhận phòng cho booking #'.$bookingId.'.');
        } catch (\Throwable $exception) {
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : self::GENERIC_ERROR);
        }
    }

    private function adminReturnPath(int $bookingId): string
    {
        return ($_POST['return_to'] ?? '') === 'detail' ? '/bookings/'.$bookingId : '/admin/payments';
    }

    /** Trimmed POST string; over-long values are cut at $limit + 1 so the length check still fails. */
    private function text(string $field, int $limit): string
    {
        $value = $_POST[$field] ?? '';
        return is_string($value) ? mb_substr(trim($value), 0, $limit + 1) : '';
    }

    private function optionalNumber(string $value, float $minimum, float $maximum, string $message): ?string
    {
        if ($value === '') { return null; }
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/D', $value) || (float) $value < $minimum || (float) $value > $maximum) { throw new \DomainException($message); }
        return $value;
    }

    private function optionalInteger(string $value, int $minimum, int $maximum, string $message): ?int
    {
        if ($value === '') { return null; }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$minimum, 'max_range'=>$maximum]]);
        if ($number === false) { throw new \DomainException($message); }
        return $number;
    }

    private function date(string $value, string $message): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) { throw new \DomainException($message); }
        return $value;
    }
}
