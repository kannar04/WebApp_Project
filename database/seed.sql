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
