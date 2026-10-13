<?php $dayLabels=['available'=>__('Còn trống'),'blocked'=>__('Đã chặn'),'pending'=>__('Chờ duyệt'),'confirmed'=>__('Đã đặt'),'past'=>__('Đã qua')]; ?>
<section class="mt-4" aria-label="<?= e(__('Lịch khả dụng')) ?>">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <a class="btn btn-ghost" href="<?= e(url($calendarPath.'?month='.$calendar['previous'].'#availability-calendar')) ?>" aria-label="<?= e(__('Tháng trước')) ?>">←</a>
        <h2 class="h5 mb-0"><?= e(__('Lịch tháng {month}',['month'=>$calendar['month']])) ?></h2>
        <a class="btn btn-ghost" href="<?= e(url($calendarPath.'?month='.$calendar['next'].'#availability-calendar')) ?>" aria-label="<?= e(__('Tháng sau')) ?>">→</a>
    </div>
    <p class="text-muted small"><?= e(__('Mỗi ô là một đêm lưu trú; ngày trả phòng không chiếm đêm đó. Chờ duyệt cũng tạm giữ lịch. Lịch có thể thay đổi đến lúc đặt/xác nhận.')) ?></p>
    <div class="calendar-grid" id="availability-calendar">
        <?php foreach([__('T2'),__('T3'),__('T4'),__('T5'),__('T6'),__('T7'),__('CN')] as $weekday): ?><strong class="text-center"><?= e($weekday) ?></strong><?php endforeach; ?>
        <?php for($i=0;$i<$calendar['padding'];$i++): ?><span aria-hidden="true"></span><?php endfor; ?>
        <?php foreach($calendarDays as $day): ?>
        <div class="calendar-day calendar-<?= e($day['day_status']) ?>" data-date="<?= e($day['stay_date']) ?>" data-status="<?= e($day['day_status']) ?>">
            <time datetime="<?= e($day['stay_date']) ?>"><?= (int)substr($day['stay_date'],8,2) ?></time>
            <small><?= e($dayLabels[$day['day_status']]??$day['day_status']) ?></small>
            <?php if($day['block_reason']): ?><small><?= e($day['block_reason']) ?></small><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>
