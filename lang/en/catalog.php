<?php
// Seeded lookup values from database/seed.sql that the interface shows as labels. Views print them with
// e(__($row['name'])); names an Admin adds later have no entry and stay as entered.
return [
    // property_types: name, description
    'Nguyên căn' => 'Entire place',
    'Toàn bộ chỗ ở dành riêng cho khách' => 'The whole place is reserved for guests',
    'Phòng riêng' => 'Private room',
    'Phòng ngủ riêng, có thể dùng chung khu vực khác' => 'A private bedroom; other areas may be shared',
    'Phòng chung' => 'Shared room',
    'Không gian ngủ và sinh hoạt chung' => 'Shared sleeping and living space',

    // cancellation_policies: name, description (also copied into each booking's policy snapshot)
    'Linh hoạt' => 'Flexible',
    'Hoàn 100% nếu hủy trước giờ nhận phòng ít nhất 24 giờ.' => '100% refund if cancelled at least 24 hours before check-in.',
    'Trung bình' => 'Moderate',
    'Hoàn 50% nếu hủy trước giờ nhận phòng ít nhất 120 giờ.' => '50% refund if cancelled at least 120 hours before check-in.',
    'Nghiêm ngặt' => 'Strict',
    'Không hoàn tiền sau khi booking được xác nhận.' => 'No refund once the booking is confirmed.',

    // amenities: name
    'Wi-Fi tốc độ cao' => 'High-speed Wi-Fi',
    'Bếp' => 'Kitchen',
    'Máy lạnh' => 'Air conditioning',
    'Chỗ đậu xe' => 'Parking',
    'Hồ bơi' => 'Pool',
    'Cho phép thú cưng' => 'Pets allowed',

    // public_holidays: demo Vietnamese public holidays (name)
    'Tết Dương lịch' => "New Year's Day",
    'Tết Nguyên Đán (29 Tết)' => "Lunar New Year (New Year's Eve)",
    'Tết Nguyên Đán (Mùng 1)' => 'Lunar New Year (Day 1)',
    'Tết Nguyên Đán (Mùng 2)' => 'Lunar New Year (Day 2)',
    'Tết Nguyên Đán (Mùng 3)' => 'Lunar New Year (Day 3)',
    'Tết Nguyên Đán (Mùng 4)' => 'Lunar New Year (Day 4)',
    'Giỗ Tổ Hùng Vương' => 'Hung Kings Commemoration Day',
    'Ngày Giải phóng miền Nam' => 'Reunification Day',
    'Quốc tế Lao động' => 'International Labor Day',
    'Quốc khánh (nghỉ liền kề)' => 'National Day (adjacent day off)',
    'Quốc khánh' => 'National Day',
];
