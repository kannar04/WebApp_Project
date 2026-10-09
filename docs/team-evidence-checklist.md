# Checklist minh chứng nhóm

Không tạo minh chứng giả. Thành viên thực hiện và chụp màn hình từ dịch vụ thật.

- [ ] Repository GitHub có quyền truy cập cho 5 thành viên.
- [ ] Branch `main`, `develop`, `feature/*`, `fix/*` theo phân công thật.
- [ ] Mỗi thành viên có commit nhỏ, message rõ chức năng.
- [ ] Pull Request có mô tả, ảnh/test evidence và reviewer khác tác giả.
- [ ] Merge history thể hiện review; không force push ghi đè lịch sử.
- [ ] Shared MySQL hosting có account riêng/quyền tối thiểu cho thành viên.
- [ ] `.env`, credential, API key và dữ liệu cá nhân không nằm trong Git.
- [ ] Board Microsoft Teams/GitHub Projects có assignee, trạng thái, deadline.
- [ ] Screenshot: access, branch, commit graph, PR review, merge và project board.
- [ ] Screenshot database hosting/access, đã che password/token.
- [ ] Screenshot test PASS và màn hình ứng dụng thật.
- [ ] Phân công khớp commit/PR, không gán việc không có bằng chứng.

Gợi ý branch: `feature/auth`, `feature/listings`, `feature/booking`, `feature/profile`, `feature/admin`, `fix/...`. Không push/merge tự động khi nhóm chưa cho phép.

