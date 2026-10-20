USE db_home2home;

INSERT INTO property_types (id, name, description, is_active) VALUES
(1, 'Nguyên căn', 'Toàn bộ chỗ ở dành riêng cho khách', 1),
(2, 'Phòng riêng', 'Phòng ngủ riêng, có thể dùng chung khu vực khác', 1),
(3, 'Phòng chung', 'Không gian ngủ và sinh hoạt chung', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active=VALUES(is_active);

INSERT INTO cancellation_policies (id, name, description, cutoff_hours, early_refund_pct, late_refund_pct, refund_fees, is_active) VALUES
(1, 'Linh hoạt', 'Hoàn 100% nếu hủy trước giờ nhận phòng ít nhất 24 giờ.', 24, 100, 0, 1, 1),
(2, 'Trung bình', 'Hoàn 50% nếu hủy trước giờ nhận phòng ít nhất 120 giờ.', 120, 50, 0, 0, 1),
(3, 'Nghiêm ngặt', 'Không hoàn tiền sau khi booking được xác nhận.', 99999, 0, 0, 0, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), description = VALUES(description), cutoff_hours = VALUES(cutoff_hours), early_refund_pct = VALUES(early_refund_pct), late_refund_pct=VALUES(late_refund_pct), refund_fees=VALUES(refund_fees), is_active=VALUES(is_active);

INSERT INTO amenities (id, name, icon_key, is_active) VALUES
(1, 'Wi-Fi tốc độ cao', 'wifi', 1), (2, 'Bếp', 'kitchen', 1), (3, 'Máy lạnh', 'snowflake', 1),
(4, 'Chỗ đậu xe', 'car', 1), (5, 'Hồ bơi', 'pool', 1), (6, 'Cho phép thú cưng', 'paw', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), icon_key = VALUES(icon_key), is_active=VALUES(is_active);

INSERT INTO users (id, email, password_hash, full_name, phone, avatar_url, status, created_at, updated_at) VALUES
(1, 'admin@home2home.test', '$2y$10$5/lA/dfd2A17bKJpgKNHkevpJNvMvZUIn/FsGUBRlDZixhiwg1emi', 'Quản trị Home2Home', '0900000001', NULL, 'active', NOW(6), NOW(6)),
(2, 'host@home2home.test', '$2y$10$3p1ZaVmv0VHnp7dWeYgm.ecI8rgKaH.dBm1ownKSdTklQU5iahyvu', 'Nguyễn Minh Anh', '0900000002', NULL, 'active', NOW(6), NOW(6)),
(3, 'guest@home2home.test', '$2y$10$7d.HYHfKicF0a7D/tpVMhuzlsTAFNkDAtJr11zvJnLEtmnePpvbnW', 'Lê Hải Yến', '0900000003', NULL, 'active', NOW(6), NOW(6))
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), status = VALUES(status);

INSERT IGNORE INTO user_roles (user_id, role, granted_at) VALUES (1, 'guest', NOW(6)), (1, 'admin', NOW(6)), (2, 'guest', NOW(6)), (2, 'host', NOW(6)), (3, 'guest', NOW(6));

INSERT INTO listings (id, host_id, property_type_id, cancellation_policy_id, title, address, city, country_code, time_zone, description, base_nightly_rate, fee_amount, currency, max_guests, room_count, bedroom_count, bed_count, check_in_time, check_out_time, smoking_allowed, pets_allowed, parties_allowed, moderation_status, is_visible, created_at, updated_at) VALUES
(1, 2, 1, 1, 'Ngôi nhà thông Đà Lạt', 'Phường 8, Đà Lạt, Lâm Đồng', 'Đà Lạt', 'VN', 'Asia/Ho_Chi_Minh', 'Một ngôi nhà ấm áp giữa rừng thông, cách trung tâm Đà Lạt 10 phút. Không gian yên tĩnh, ban công ngập nắng và bếp đầy đủ tiện nghi.', 1250000, 150000, 'VND', 4, 4, 2, 2, '14:00', '11:00', 0, 0, 0, 'approved', 1, NOW(6), NOW(6)),
(2, 2, 1, 2, 'Villa An Nhiên Hội An', 'Cẩm Châu, Hội An, Quảng Nam', 'Hội An', 'VN', 'Asia/Ho_Chi_Minh', 'Villa sáng thoáng với khu vườn riêng, phù hợp cho gia đình và nhóm bạn muốn khám phá phố cổ.', 2100000, 200000, 'VND', 6, 5, 3, 3, '14:00', '11:00', 0, 0, 0, 'approved', 1, NOW(6), NOW(6)),
(3, 2, 2, 1, 'Sea Breeze Homestay', 'An Bàng, Hội An, Quảng Nam', 'Hội An', 'VN', 'Asia/Ho_Chi_Minh', 'Phòng nghỉ cạnh biển, nhiều ánh sáng và chỉ vài bước chân tới bãi cát.', 890000, 100000, 'VND', 2, 1, 1, 1, '14:00', '11:00', 0, 1, 0, 'approved', 1, NOW(6), NOW(6))
ON DUPLICATE KEY UPDATE title=VALUES(title),address=VALUES(address),city=VALUES(city),description=VALUES(description),base_nightly_rate=VALUES(base_nightly_rate),fee_amount=VALUES(fee_amount),updated_at=NOW(6);

INSERT INTO listing_photos (id, listing_id, image_url, alt_text, sort_order, created_at) VALUES
(1, 1, 'https://images.unsplash.com/photo-1449158743715-0a90ebb6d2d8?auto=format&fit=crop&w=1200&q=85', 'Ngôi nhà gỗ giữa rừng thông', 1, NOW(6)),
(2, 1, 'https://images.unsplash.com/photo-1600566753086-00f18fb6b3ea?auto=format&fit=crop&w=1200&q=85', 'Phòng khách nhiều ánh sáng', 2, NOW(6)),
(3, 1, 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=1200&q=85', 'Không gian bên ngoài ngôi nhà', 3, NOW(6)),
(4, 2, 'https://images.unsplash.com/photo-1600607687920-4e2a09cf159d?auto=format&fit=crop&w=1200&q=85', 'Phòng khách villa An Nhiên', 1, NOW(6)),
(5, 3, 'https://images.unsplash.com/photo-1499793983690-e29da59ef1c2?auto=format&fit=crop&w=1200&q=85', 'Homestay bên biển', 1, NOW(6))
ON DUPLICATE KEY UPDATE image_url = VALUES(image_url), alt_text = VALUES(alt_text);

INSERT IGNORE INTO listing_amenities (listing_id, amenity_id) VALUES
(1,1),(1,2),(1,3),(1,4),(2,1),(2,2),(2,3),(2,4),(2,5),(3,1),(3,3),(3,6);

-- Nice-to-have booking extras: flexible pricing, promo codes, Instant Book, payment status
-- Listing 1 deliberately has no rules: fresh/booking tests assert its 2-night total of 2.650.000đ.
-- Listing 2 only gets stay-length discounts so short-stay browser quotes stay at 4.400.000đ.
INSERT IGNORE INTO listing_booking_settings (listing_id, weekend_nightly_rate, holiday_nightly_rate, weekly_discount_pct, monthly_discount_pct, instant_book, created_at, updated_at) VALUES
(2, NULL, NULL, 8, 15, 0, NOW(6), NOW(6)),
(3, 1090000, 1290000, 10, 20, 1, NOW(6), NOW(6));

-- Demo Vietnamese public holidays for the next ~12 months; an Admin must confirm them before relying on them.
INSERT IGNORE INTO public_holidays (id, holiday_date, name, is_demo, created_by, created_at, updated_at) VALUES
(1, '2027-01-01', 'Tết Dương lịch', 1, NULL, NOW(6), NOW(6)),
(2, '2027-02-05', 'Tết Nguyên Đán (29 Tết)', 1, NULL, NOW(6), NOW(6)),
(3, '2027-02-06', 'Tết Nguyên Đán (Mùng 1)', 1, NULL, NOW(6), NOW(6)),
(4, '2027-02-07', 'Tết Nguyên Đán (Mùng 2)', 1, NULL, NOW(6), NOW(6)),
(5, '2027-02-08', 'Tết Nguyên Đán (Mùng 3)', 1, NULL, NOW(6), NOW(6)),
(6, '2027-02-09', 'Tết Nguyên Đán (Mùng 4)', 1, NULL, NOW(6), NOW(6)),
(7, '2027-04-16', 'Giỗ Tổ Hùng Vương', 1, NULL, NOW(6), NOW(6)),
(8, '2027-04-30', 'Ngày Giải phóng miền Nam', 1, NULL, NOW(6), NOW(6)),
(9, '2027-05-01', 'Quốc tế Lao động', 1, NULL, NOW(6), NOW(6)),
(10, '2027-09-01', 'Quốc khánh (nghỉ liền kề)', 1, NULL, NOW(6), NOW(6)),
(11, '2027-09-02', 'Quốc khánh', 1, NULL, NOW(6), NOW(6));

INSERT IGNORE INTO promotions (id, code, description, discount_type, discount_value, max_discount_amount, min_nights, min_subtotal, valid_from, valid_to, usage_limit, is_active, created_by, created_at, updated_at) VALUES
(1, 'CHAOHE10', 'Chào hè: giảm 10% tiền phòng, tối đa 500.000đ.', 'percent', 10, 500000, NULL, NULL, '2026-10-01', '2027-12-31', 500, 1, NULL, NOW(6), NOW(6));

-- Nice-to-have trust: identity verification, trusted Host badge, check-in guide
INSERT IGNORE INTO listing_checkin_guides (listing_id, arrival_instructions, door_code, wifi_name, wifi_password, note, updated_at) VALUES
(1, 'Đi theo đường Hoàng Hoa Thám, rẽ vào hẻm có biển "Ngôi nhà thông". Chìa khóa nằm trong hộp khóa màu xám cạnh cổng gỗ, đỗ xe trong sân bên trái.', '2468', 'NhaThong_5G', 'dalat2468', 'Vui lòng giữ yên lặng sau 22:00 và tắt lò sưởi khi ra ngoài.', NOW(6));

-- Nice-to-have conversation: booking messages, check-in reminders

-- Nice-to-have discovery: map search, recommendations
UPDATE listings SET latitude=11.958300, longitude=108.445100 WHERE id=1 AND latitude IS NULL AND longitude IS NULL;
UPDATE listings SET latitude=15.884500, longitude=108.350200 WHERE id=2 AND latitude IS NULL AND longitude IS NULL;
UPDATE listings SET latitude=15.913600, longitude=108.340200 WHERE id=3 AND latitude IS NULL AND longitude IS NULL;

-- Nice-to-have content: articles and travel guides
INSERT INTO articles (id, author_id, category, title, slug, excerpt, body, cover_image_url, cover_alt, status, published_at, created_at, updated_at, deleted_at) VALUES
(1, 1, 'guide', 'Cẩm nang 2 ngày ở Đà Lạt', 'cam-nang-2-ngay-o-da-lat',
'Lịch trình gợi ý cho hai ngày thong thả ở Đà Lạt: sáng săn mây, chiều dạo rừng thông và tối thưởng thức đặc sản chợ đêm.',
'Đà Lạt hợp với những chuyến đi ngắn: khí hậu mát quanh năm, khoảng cách giữa các điểm tham quan không quá xa và có rất nhiều homestay ấm cúng ven rừng thông.

## Ngày 1: phố núi và rừng thông

Buổi sáng, hãy dạo quanh hồ Xuân Hương và ghé chợ Đà Lạt để thử bánh căn, sữa đậu nành nóng. Sau bữa trưa, bạn có thể đi bộ nhẹ ở đồi thông hoặc ghé một quán cà phê nhìn ra thung lũng.

Buổi tối, chợ đêm có nhiều món nướng và đồ len. Nhiệt độ có thể xuống dưới 15 độ C, nên mang theo áo khoác mỏng và khăn quàng.

## Ngày 2: săn mây và vườn hoa

Nếu muốn ngắm mây, hãy dậy từ khoảng 4 giờ 30 và di chuyển sớm tới các đồi ngoại ô. Buổi chiều phù hợp để thăm vườn hoa, làng hoa Vạn Thành hoặc một trang trại dâu.

## Gợi ý chọn chỗ ở

Chọn chỗ ở cách trung tâm 10 đến 15 phút để vừa yên tĩnh vừa thuận tiện di chuyển. Kiểm tra giờ nhận phòng, chỗ đậu xe và chính sách hủy trước khi gửi yêu cầu đặt chỗ.',
'/assets/images/demo/1449158743715-0a90ebb6d2d8.jpg', 'Ngôi nhà gỗ giữa rừng thông ở Đà Lạt', 'published', '2026-09-05 08:00:00.000000', '2026-09-04 20:00:00.000000', '2026-09-05 08:00:00.000000', NULL),
(2, 1, 'guide', 'Đi Hội An mùa nào đẹp?', 'di-hoi-an-mua-nao-dep',
'Từ mùa khô nắng đẹp đến mùa mưa yên ả, đây là cách chọn thời điểm phù hợp để khám phá phố cổ và biển An Bàng.',
'Hội An đẹp quanh năm, nhưng mỗi mùa mang một trải nghiệm khác nhau. Biết trước thời tiết giúp bạn chọn lịch trình và chỗ ở phù hợp hơn.

## Tháng 2 đến tháng 4: dễ chịu nhất

Trời ít mưa, nắng nhẹ và không quá nóng. Đây là thời điểm lý tưởng để đạp xe qua làng rau Trà Quế, dạo phố cổ và ngắm đèn lồng buổi tối.

## Tháng 5 đến tháng 8: mùa biển

Nắng nhiều, rất hợp để tắm biển An Bàng hay Cửa Đại. Nên đi phố cổ vào sáng sớm hoặc chiều muộn và chọn chỗ ở có máy lạnh.

## Tháng 9 đến tháng 12: mùa mưa

Có thể có mưa lớn và ngập cục bộ ở khu phố cổ. Bù lại, giá phòng thường mềm hơn và phố xá yên tĩnh. Hãy theo dõi dự báo thời tiết và ưu tiên chính sách hủy linh hoạt.',
'/assets/images/demo/1499793983690-e29da59ef1c2.jpg', 'Bãi biển cát trắng gần Hội An', 'published', '2026-09-18 09:30:00.000000', '2026-09-17 21:00:00.000000', '2026-09-18 09:30:00.000000', NULL),
(3, 1, 'guide', 'Mẹo đặt homestay an toàn trên Home2Home', 'meo-dat-homestay-an-toan-tren-home2home',
'Những bước đơn giản giúp bạn kiểm tra thông tin chỗ ở, hiểu rõ chính sách hủy và báo cáo nội dung đáng ngờ trước khi đặt.',
'Một chuyến đi suôn sẻ bắt đầu từ việc chọn đúng chỗ ở. Dưới đây là vài thói quen nhỏ giúp bạn đặt homestay an tâm hơn.

## Đọc kỹ trang chỗ ở

Xem hết ảnh, mô tả, tiện nghi và nội quy. Kiểm tra số khách tối đa, giờ nhận phòng và trả phòng để tránh phát sinh ngoài ý muốn.

## Tham khảo đánh giá thật

Đánh giá trên Home2Home chỉ đến từ khách đã hoàn thành kỳ nghỉ. Hãy đọc cả phản hồi của Host để hiểu cách họ xử lý vấn đề.

## Hiểu chính sách hủy

Mỗi chỗ ở có chính sách hủy riêng: Linh hoạt, Trung bình hoặc Nghiêm ngặt. Tổng tiền và mức hoàn tiền được máy chủ tính lại khi bạn gửi yêu cầu.

## Cảnh giác với yêu cầu bất thường

Không chia sẻ mật khẩu tài khoản và không chuyển tiền qua kênh riêng khi được người lạ yêu cầu. Nếu thấy thông tin sai sự thật hoặc dấu hiệu lừa đảo, hãy dùng nút Báo cáo trên trang chỗ ở.',
'/assets/images/demo/1600566753086-00f18fb6b3ea.jpg', 'Phòng khách homestay nhiều ánh sáng', 'published', '2026-09-28 10:00:00.000000', '2026-09-27 16:00:00.000000', '2026-09-28 10:00:00.000000', NULL),
(4, 1, 'news', 'Home2Home ra mắt mục Cẩm nang', 'home2home-ra-mat-muc-cam-nang',
'Mục Cẩm nang mới tổng hợp tin tức nền tảng và kinh nghiệm du lịch giúp bạn lên kế hoạch cho chuyến đi tiếp theo.',
'Home2Home chính thức ra mắt mục Cẩm nang, nơi đội ngũ vận hành chia sẻ tin tức về nền tảng cùng các bài hướng dẫn du lịch thực tế.

## Có gì trong Cẩm nang?

Tin tức giúp bạn cập nhật thay đổi mới trên Home2Home. Cẩm nang du lịch gợi ý lịch trình, thời điểm đẹp và kinh nghiệm chọn chỗ ở tại các điểm đến quen thuộc.

## Cùng nhau hoàn thiện

Nếu bạn muốn đọc thêm về một điểm đến nào đó, hãy cho chúng tôi biết. Các bài viết mới sẽ được cập nhật thường xuyên.',
'/assets/images/demo/1600585154340-be6161a56a0c.jpg', 'Không gian bên ngoài một ngôi nhà nghỉ dưỡng', 'published', '2026-10-06 08:00:00.000000', '2026-10-05 18:00:00.000000', '2026-10-06 08:00:00.000000', NULL)
ON DUPLICATE KEY UPDATE category=VALUES(category), title=VALUES(title), excerpt=VALUES(excerpt), body=VALUES(body), cover_image_url=VALUES(cover_image_url), cover_alt=VALUES(cover_alt);
