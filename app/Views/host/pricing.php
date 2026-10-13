<?php
$old=$old??[];
$fields=[];
foreach(['weekend_nightly_rate','holiday_nightly_rate','weekly_discount_pct','monthly_discount_pct'] as $field){
    $stored=$settings[$field]??null;
    $fields[$field]=array_key_exists($field,$old)?(string)$old[$field]:($stored===null?'':rtrim(rtrim((string)$stored,'0'),'.'));
}
$instantChecked=array_key_exists('instant_book',$old)?!empty($old['instant_book']):!empty($settings['instant_book']);
$base=(float)$settings['base_nightly_rate'];
?>
<section class="page-section"><div class="container narrow">
<div class="section-title"><div><p class="eyebrow"><?= e(__('GIÁ & ĐẶT CHỖ')) ?></p><h1><?= e($settings['title']) ?></h1><p><?= e(__('Giá cơ bản {price}đ/đêm · phụ phí {fee}đ.', ['price' => number_format($base,0,',','.'), 'fee' => number_format((float)$settings['fee_amount'],0,',','.')])) ?> <?= e(__('Lưu trang này không gửi tin duyệt lại.')) ?></p></div><a class="btn btn-ghost text-nowrap" href="<?= e(url('/host')) ?>"><?= e(__('Quay lại')) ?></a></div>
<form class="surface-card pricing-form" method="post" action="<?= e(url('/host/listings/'.(int)$settings['listing_id'].'/pricing')) ?>"><?= csrf_field() ?>
<h2><?= e(__('Giá theo ngày')) ?></h2><p class="text-muted"><?= e(__('Để trống nếu muốn dùng giá cơ bản. Đêm thứ Sáu và thứ Bảy là đêm cuối tuần; giá ngày lễ được ưu tiên nếu đêm đó trùng ngày lễ.')) ?></p>
<div class="form-row"><div class="form-group col-md-6"><label for="weekend_nightly_rate"><?= e(__('Giá đêm cuối tuần (đ)')) ?></label><input class="form-control" id="weekend_nightly_rate" name="weekend_nightly_rate" type="number" min="1" max="9999999999" step="1" inputmode="numeric" value="<?= e($fields['weekend_nightly_rate']) ?>" placeholder="<?= e(number_format($base,0,'','')) ?>"></div>
<div class="form-group col-md-6"><label for="holiday_nightly_rate"><?= e(__('Giá đêm ngày lễ (đ)')) ?></label><input class="form-control" id="holiday_nightly_rate" name="holiday_nightly_rate" type="number" min="1" max="9999999999" step="1" inputmode="numeric" value="<?= e($fields['holiday_nightly_rate']) ?>" placeholder="<?= e(number_format($base,0,'','')) ?>"></div></div>
<h2 class="mt-3"><?= e(__('Giảm giá theo thời gian ở')) ?></h2><p class="text-muted"><?= e(__('Áp dụng trên tiền phòng. Từ 28 đêm dùng mức theo tháng thay cho mức theo tuần (không cộng dồn).')) ?></p>
<div class="form-row"><div class="form-group col-md-6"><label for="weekly_discount_pct"><?= e(__('Giảm khi ở từ 7 đêm (%)')) ?></label><input class="form-control" id="weekly_discount_pct" name="weekly_discount_pct" type="number" min="0" max="90" step="0.5" inputmode="decimal" value="<?= e($fields['weekly_discount_pct']) ?>" placeholder="0"></div>
<div class="form-group col-md-6"><label for="monthly_discount_pct"><?= e(__('Giảm khi ở từ 28 đêm (%)')) ?></label><input class="form-control" id="monthly_discount_pct" name="monthly_discount_pct" type="number" min="0" max="90" step="0.5" inputmode="decimal" value="<?= e($fields['monthly_discount_pct']) ?>" placeholder="0"></div></div>
<h2 class="mt-3"><?= e(__('Đặt ngay (Instant Book)')) ?></h2>
<div class="custom-control custom-switch mb-2"><input class="custom-control-input" type="checkbox" id="instant_book" name="instant_book" value="1" <?= $instantChecked?'checked':'' ?>><label class="custom-control-label" for="instant_book"><?= e(__('Cho phép khách đặt ngay, không cần tôi duyệt')) ?></label></div>
<p class="text-muted small"><?= e(__('Booking được xác nhận ngay nếu các đêm còn trống; ngày đã chặn hoặc đã có booking vẫn bị từ chối tự động. Bạn vẫn nhận thông báo cho mỗi booking.')) ?></p>
<div class="action-row"><button class="btn btn-primary" type="submit"><?= e(__('Lưu giá & tùy chọn')) ?></button><a class="btn btn-ghost" href="<?= e(url('/host/listings/'.(int)$settings['listing_id'].'/edit')) ?>"><?= e(__('Chỉnh sửa tin')) ?></a></div>
</form>
<section class="surface-card mt-4"><h2><?= e(__('Ngày lễ sắp tới')) ?></h2><p class="text-muted"><?= e(__('Admin quản lý danh sách ngày lễ. Giá ngày lễ chỉ áp dụng khi bạn đặt giá ở trên.')) ?></p>
<?php if(!$holidays): ?><p><?= e(__('Chưa có ngày lễ sắp tới.')) ?></p><?php else: ?><ul class="holiday-chips"><?php foreach($holidays as $holiday): ?><li><strong><?= e(date('d/m/Y',strtotime($holiday['holiday_date']))) ?></strong> <?= e(__($holiday['name'])) ?></li><?php endforeach; ?></ul><?php endif; ?></section>
</div></section>
