<?php if(!\Core\Auth::check()): ?><div class="container mt-3"><div class="alert alert-info" role="status"><?= e(__('Bạn đang xem trước chuyến đi. Bạn có thể xem ảnh, ngày trống và chi phí trước khi đăng nhập để đặt phòng.')) ?></div></div><?php endif; ?>
<?php require_once dirname(__DIR__).'/components/ui-icons.php'; ?>
<section class="page-section booking-review"><div class="container">
    <a class="review-back" href="<?= e(url(\App\Services\TripContext::path((int)$listing['id'],$trip))) ?>"><?= ui_icon('arrow') ?> <?= e(__('Quay lại chỗ ở')) ?></a>
    <h1><?= e(__('Xem lại chuyến đi của bạn')) ?></h1>
    <p class="text-muted mb-4"><?= e(__('Kiểm tra thông tin bên dưới trước khi xác nhận.')) ?></p>
    <div class="row"><div class="col-lg-7 pr-lg-5">
        <div class="review-property"><img src="<?= e(image_url($listing['photos'][0]['image_url']??null)) ?>" width="160" height="120" alt=""><div><p class="listing-location"><?= e(__($listing['property_type'])) ?> · <?= e($listing['city']) ?></p><h2><?= e($listing['title']) ?></h2><p><?= e(__('{type} do {host} đón tiếp',['type'=>__($listing['property_type']),'host'=>$listing['host_name']])) ?></p></div></div>
        <div class="review-section"><h2><?= e(__('Thông tin chuyến đi')) ?></h2><dl class="review-facts"><dt><?= e(__('Nhận phòng')) ?></dt><dd><?= e($trip['check_in']??'') ?></dd><dt><?= e(__('Trả phòng')) ?></dt><dd><?= e($trip['check_out']??'') ?></dd><dt><?= e(__('Số khách')) ?></dt><dd><?= e($trip['guests']??1) ?></dd></dl><a href="<?= e(url(\App\Services\TripContext::path((int)$listing['id'],$trip)).'#booking-panel') ?>"><?= e(__('Thay đổi ngày hoặc số khách')) ?></a></div>
        <div class="review-section"><h2><?= e(__('Nội quy và chính sách')) ?></h2><p><?= e(__($listing['cancellation_name'])) ?>: <?= e(__($listing['cancellation_description'])) ?></p><p><?= e(__('Nhận phòng từ {time}',['time'=>substr($listing['check_in_time'],0,5)])) ?> · <?= e(__('Trả phòng trước {time}',['time'=>substr($listing['check_out_time'],0,5)])) ?></p><p><?= e($listing['allows_pets']?__('Cho phép thú cưng'):__('Không cho phép thú cưng')) ?> · <?= e($listing['allows_smoking']?__('Cho phép hút thuốc'):__('Không cho phép hút thuốc')) ?> · <?= e($listing['allows_parties']?__('Cho phép tiệc'):__('Không cho phép tiệc')) ?></p></div>
    </div><div class="col-lg-5"><aside class="surface-card review-total">
        <h2><?= e(__('Chi tiết giá')) ?></h2>
        <?php if($error): ?><div class="alert alert-danger" role="alert"><?= e(__($error)) ?></div><a class="btn btn-primary btn-block" href="<?= e(url(\App\Services\TripContext::path((int)$listing['id'],$trip)).'#booking-panel') ?>"><?= e(__('Thay đổi ngày hoặc số khách')) ?></a>
        <?php else: ?>
        <div class="review-breakdown"><?php foreach($quote['breakdown'] as $row): ?><div class="quote-row <?= $row['kind']==='total'?'review-grand-total':'' ?>"><span><?= e($row['label']) ?></span><strong><?= e(\App\Services\BookingService::money((float)$row['amount'])) ?></strong></div><?php endforeach; ?></div>
        <?php if(!empty($quote['promo']['message'])): ?><p class="alert alert-info"><?= e($quote['promo']['message']) ?></p><?php endif; ?>
        <p><?= e($quote['instant_book']?__('Chỗ ở này xác nhận ngay sau khi bạn đặt.'):__('Host cần chấp nhận yêu cầu trước khi chuyến đi được xác nhận.')) ?></p>
        <p class="text-muted small"><?= e(\App\Models\Payment::onlineConfigured()?__('Thanh toán khi nhận phòng, hoặc thanh toán online sau khi booking được xác nhận.'):__('Thanh toán trực tiếp cho Host khi nhận phòng.')) ?></p>
        <?php if(\Core\Auth::check() && (empty($contactStatus['email_verified']) || empty($contactStatus['phone_verified']))): ?>
        <div class="alert alert-warning" role="status"><?= e(__('Bạn chưa xác minh số điện thoại hoặc email. Hãy xác minh trong hồ sơ trước khi đặt phòng.')) ?></div><a class="btn btn-primary btn-block" href="<?= e(url('/profile#contact-verification')) ?>"><?= e(__('Xác minh thông tin liên hệ')) ?></a>
        <?php elseif(\Core\Auth::check()): ?>
        <form method="post" action="<?= e(url('/listings/'.$listing['id'].'/book')) ?>">
            <?= csrf_field() ?><input type="hidden" name="review_token" value="<?= e($reviewToken) ?>">
            <?php foreach($trip as $key=>$value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
            <button class="btn btn-primary btn-block" type="submit"><?= e($quote['instant_book']?__('Xác nhận đặt chỗ'):__('Gửi yêu cầu đặt chỗ')) ?> <?= ui_icon('arrow') ?></button>
        </form>
        <?php else: ?><a class="btn btn-primary btn-block" href="<?= e(url('/login?'.http_build_query(['next'=>\App\Services\TripContext::path((int)$listing['id'],$trip,true)]))) ?>"><?= e(__('Đăng nhập để tiếp tục')) ?> <?= ui_icon('arrow') ?></a><p class="small text-muted mt-3"><?= e(__('Ngày và số khách của bạn sẽ được giữ lại.')) ?></p><?php endif; ?>
        <?php endif; ?>
    </aside></div></div>
</div></section>
