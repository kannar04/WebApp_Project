<?php
$old=$old??[];
$form=['id'=>0,'code'=>'','description'=>'','discount_type'=>'percent','discount_value'=>'','max_discount_amount'=>'','min_nights'=>'','min_subtotal'=>'','valid_from'=>date('Y-m-d'),'valid_to'=>date('Y-m-d',strtotime('+30 days')),'usage_limit'=>'','is_active'=>1];
if($editing){ foreach(array_keys($form) as $key){ if(array_key_exists($key,$editing)){ $form[$key]=$editing[$key]??''; } } }
foreach(['discount_value','max_discount_amount','min_subtotal'] as $key){ if($form[$key]!==''&&$form[$key]!==null&&str_contains((string)$form[$key],'.')){ $form[$key]=rtrim(rtrim((string)$form[$key],'0'),'.'); } }
if($old){ foreach(array_keys($form) as $key){ if(array_key_exists($key,$old)){ $form[$key]=$old[$key]; } } }
$isEditing=(int)$form['id']>0;
$codeLocked=$isEditing&&(int)($editing['booking_count']??0)>0;
$today=date('Y-m-d');
?>
<section class="page-section"><div class="container">
<div class="section-title"><div><p class="eyebrow">QUẢN TRỊ GIÁ</p><h1>Mã khuyến mãi</h1><p>Giảm trên tiền phòng sau giảm giá theo thời gian ở; phụ phí không được giảm. Hiệu lực tính theo ngày đặt (giờ Việt Nam).</p></div><a class="btn btn-ghost text-nowrap" href="<?= e(url('/admin')) ?>">Quay lại quản trị</a></div>
<form class="surface-card mb-4 promo-admin-form" method="post" action="<?= e(url('/admin/promotions')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
<h2><?= $isEditing?'Sửa mã '.e($form['code']):'Tạo mã mới' ?></h2>
<div class="form-row"><div class="form-group col-md-4"><label for="promo-code">Mã (4–20 chữ cái A–Z hoặc số)</label><input class="form-control promo-input" id="promo-code" name="code" maxlength="20" pattern="[A-Za-z0-9]{4,20}" value="<?= e($form['code']) ?>" <?= $codeLocked?'readonly aria-describedby="promo-code-help"':'' ?> required><?php if($codeLocked): ?><small class="form-text text-muted" id="promo-code-help">Mã đã được dùng cho booking nên không thể đổi.</small><?php endif; ?></div>
<div class="form-group col-md-8"><label for="promo-description">Mô tả cho khách</label><input class="form-control" id="promo-description" name="description" maxlength="255" value="<?= e($form['description']) ?>" required></div></div>
<div class="form-row"><div class="form-group col-sm-6 col-md-4"><label for="promo-type">Loại giảm</label><select class="form-control" id="promo-type" name="discount_type"><option value="percent" <?= $form['discount_type']==='percent'?'selected':'' ?>>Phần trăm (%)</option><option value="fixed" <?= $form['discount_type']==='fixed'?'selected':'' ?>>Số tiền cố định (đ)</option></select></div>
<div class="form-group col-sm-6 col-md-4"><label for="promo-value">Giá trị giảm</label><input class="form-control" id="promo-value" name="discount_value" type="number" min="0.01" max="9999999999" step="0.01" value="<?= e($form['discount_value']) ?>" required></div>
<div class="form-group col-sm-6 col-md-4"><label for="promo-max">Giảm tối đa (đ, chỉ cho %)</label><input class="form-control" id="promo-max" name="max_discount_amount" type="number" min="1" max="9999999999" step="1" value="<?= e($form['max_discount_amount']) ?>" placeholder="Không giới hạn"></div>
<div class="form-group col-sm-6 col-md-4"><label for="promo-min-nights">Số đêm tối thiểu</label><input class="form-control" id="promo-min-nights" name="min_nights" type="number" min="1" max="365" step="1" value="<?= e($form['min_nights']) ?>" placeholder="Không yêu cầu"></div>
<div class="form-group col-sm-6 col-md-4"><label for="promo-min-subtotal">Tiền phòng tối thiểu (đ)</label><input class="form-control" id="promo-min-subtotal" name="min_subtotal" type="number" min="0" max="9999999999" step="1" value="<?= e($form['min_subtotal']) ?>" placeholder="Không yêu cầu"></div>
<div class="form-group col-sm-6 col-md-4"><label for="promo-limit">Tổng lượt dùng tối đa</label><input class="form-control" id="promo-limit" name="usage_limit" type="number" min="1" max="1000000" step="1" value="<?= e($form['usage_limit']) ?>" placeholder="Không giới hạn"></div>
<div class="form-group col-sm-6 col-md-4"><label for="promo-from">Áp dụng từ ngày</label><input class="form-control" id="promo-from" name="valid_from" type="date" value="<?= e($form['valid_from']) ?>" required></div>
<div class="form-group col-sm-6 col-md-4"><label for="promo-to">Đến hết ngày</label><input class="form-control" id="promo-to" name="valid_to" type="date" value="<?= e($form['valid_to']) ?>" required></div>
<div class="form-group col-md-4 align-self-end"><div class="custom-control custom-checkbox mb-2"><input class="custom-control-input" type="checkbox" id="promo-active" name="is_active" value="1" <?= !empty($form['is_active'])?'checked':'' ?>><label class="custom-control-label" for="promo-active">Đang bật</label></div></div></div>
<p class="text-muted small">Lượt dùng được tính cho booking chờ duyệt, đã xác nhận hoặc hoàn thành; booking bị từ chối/hủy trả lại lượt.</p>
<div class="action-row"><button class="btn btn-primary" type="submit"><?= $isEditing?'Lưu thay đổi':'Tạo mã' ?></button><?php if($isEditing): ?><a class="btn btn-ghost" href="<?= e(url('/admin/promotions')) ?>">Hủy sửa</a><?php endif; ?></div></form>
<section class="surface-card"><h2>Danh sách mã (<?= count($promotions) ?>)</h2>
<?php if(!$promotions): ?><div class="empty-state"><h3>Chưa có mã khuyến mãi</h3><p>Tạo mã đầu tiên bằng biểu mẫu phía trên.</p></div><?php else: ?>
<div class="table-responsive" tabindex="0" role="region" aria-label="Danh sách mã khuyến mãi"><table class="table promo-table"><thead><tr><th>Mã</th><th>Giảm</th><th>Điều kiện</th><th>Hiệu lực</th><th>Lượt dùng</th><th>Thao tác</th></tr></thead><tbody>
<?php foreach($promotions as $promotion):
    if(!$promotion['is_active']){ $state=['status-cancelled','Đã tắt']; }
    elseif($promotion['valid_from']>$today){ $state=['status-pending','Chưa bắt đầu']; }
    elseif($promotion['valid_to']<$today){ $state=['status-rejected','Hết hạn']; }
    elseif($promotion['usage_limit']!==null&&(int)$promotion['used_count']>=(int)$promotion['usage_limit']){ $state=['status-rejected','Hết lượt']; }
    else{ $state=['status-approved','Đang áp dụng']; }
    $conditions=[];
    if($promotion['min_nights']!==null){ $conditions[]='Từ '.(int)$promotion['min_nights'].' đêm'; }
    if($promotion['min_subtotal']!==null){ $conditions[]='Tiền phòng từ '.number_format((float)$promotion['min_subtotal'],0,',','.').'đ'; } ?>
<tr><td><strong class="promo-code"><?= e($promotion['code']) ?></strong><br><small><?= e($promotion['description']) ?></small></td>
<td><?php if($promotion['discount_type']==='percent'): ?><?= e(\App\Services\BookingService::percent((float)$promotion['discount_value'])) ?><?php if($promotion['max_discount_amount']!==null): ?><br><small>tối đa <?= number_format((float)$promotion['max_discount_amount'],0,',','.') ?>đ</small><?php endif; ?><?php else: ?><?= number_format((float)$promotion['discount_value'],0,',','.') ?>đ<?php endif; ?></td>
<td><?= $conditions?e(implode(' · ',$conditions)):'<span class="text-muted">Không</span>' ?></td>
<td><?= e(date('d/m/Y',strtotime($promotion['valid_from']))) ?> – <?= e(date('d/m/Y',strtotime($promotion['valid_to']))) ?><br><span class="status <?= $state[0] ?>"><?= $state[1] ?></span></td>
<td><?= (int)$promotion['used_count'] ?> / <?= $promotion['usage_limit']===null?'∞':(int)$promotion['usage_limit'] ?></td>
<td><div class="action-row"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/promotions?edit='.(int)$promotion['id'])) ?>">Sửa</a>
<form method="post" action="<?= e(url('/admin/promotions/'.(int)$promotion['id'].'/toggle')) ?>"><?= csrf_field() ?><input type="hidden" name="active" value="<?= $promotion['is_active']?0:1 ?>"><button class="btn btn-sm btn-ghost"><?= $promotion['is_active']?'Tắt':'Bật' ?></button></form>
<?php if((int)$promotion['booking_count']===0): ?><form method="post" action="<?= e(url('/admin/promotions/'.(int)$promotion['id'].'/delete')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" data-confirm="Xóa vĩnh viễn mã <?= e($promotion['code']) ?>? Mã chưa được dùng cho booking nào.">Xóa</button></form><?php endif; ?></div></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</div></section>
