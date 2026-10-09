# Skill_Thiết kế giao diện

> Bộ skill tái sử dụng được tổng hợp từ 9 bài học môn Thiết kế giao diện ứng dụng (UI/UX) của UEH.
> Mục tiêu: dùng làm nền kiến thức, checklist và quy trình chuẩn cho các bài phân tích, thiết kế, Figma, wireframe, prototype, usability testing và đồ án UI/UX.

---

## 1. Nền tảng UI/UX

### 1.1. Phân biệt UI và UX
- **UX (User Experience)**: toàn bộ trải nghiệm của người dùng trước, trong và sau khi sử dụng sản phẩm.
- **UI (User Interface)**: lớp giao diện trực quan mà người dùng nhìn, chạm và tương tác: màu, chữ, nút, icon, bố cục.
- **UI là một phần của UX**, không phải hai lĩnh vực độc lập.
- Một sản phẩm tốt cần đồng thời:
  - giải quyết đúng vấn đề;
  - dễ dùng;
  - dễ hiểu;
  - tạo cảm giác tin cậy và hài lòng.

### 1.2. Vòng đời thiết kế
Quy trình chuẩn:

`Research → Ideate → Prototype → Test → Iterate`

Đây là **vòng lặp**, không phải quy trình tuyến tính.

### 1.3. User-Centered Design
Nguyên tắc cốt lõi:
- quyết định dựa trên **bằng chứng từ người dùng thật**;
- hiểu người dùng, tác vụ và bối cảnh sử dụng;
- người dùng tham gia xuyên suốt;
- thiết kế toàn bộ trải nghiệm;
- kiểm thử và lặp lại liên tục.

### 1.4. Các khái niệm nền tảng của Norman
- **Affordance**: vật thể cho phép làm gì.
- **Signifier**: dấu hiệu cho biết người dùng có thể làm gì.
- **Feedback**: phản hồi sau hành động.
- **Mapping**: quan hệ trực quan giữa điều khiển và kết quả.
- **Constraints**: ràng buộc giúp hạn chế thao tác sai.
- **Forcing function**:
  - Interlock
  - Lock-in
  - Lockout

---

## 2. Chọn phương pháp thiết kế

### 2.1. Phân biệt 4 tầng khái niệm
- **Triết lý**: thiết kế cho ai, theo giá trị nào.
- **Phương pháp luận**: tri thức được tạo ra như thế nào.
- **Phương pháp**: quy trình, giai đoạn, sản phẩm bàn giao.
- **Kỹ thuật**: thao tác cụ thể trong một buổi làm việc.

### 2.2. Các phương pháp chính
- UCD / HCD
- Goal-Directed Design (GDD)
- Activity-Centered Design (ACD)
- Contextual Design
- Participatory Design
- Design Thinking
- Lean UX / Agile UX
- PACT

### 2.3. Goal vs Activity vs Task
- **Goal**: điều người dùng thực sự muốn đạt được.
- **Activity**: hoạt động lớn có nhiều bước / nhiều công cụ.
- **Task**: thao tác cụ thể.

Quy tắc quan trọng:
- mục tiêu người dùng không được nhầm với mục tiêu kinh doanh hoặc mục tiêu kỹ thuật;
- nếu hệ thống tối ưu từng task nhưng phá vỡ hoạt động tổng thể, có thể đang mắc lỗi thiết kế theo task.

### 2.4. Goal-Directed Design
Quy trình 6 giai đoạn:
1. Research
2. Modeling
3. Requirements
4. Framework
5. Refinement
6. Support

Deliverables đặc trưng:
- Persona
- Context scenario
- Problem / vision statement
- Interaction framework
- Key path scenario
- Wireframe / mockup

### 2.5. Contextual Design
Quy trình điển hình:
1. Contextual Inquiry
2. Interpretation Session
3. Affinity Diagram
4. Consolidation
5. Visioning
6. Storyboarding
7. User Environment Design
8. Paper Prototype & Iterate

Nguyên tắc Contextual Inquiry:
- Context
- Partnership
- Interpretation
- Focus

### 2.6. Activity-Centered Design
- đơn vị phân tích chính là **hoạt động**;
- chấp nhận độ khó nếu hoạt động thực tế đòi hỏi;
- quan tâm hệ sinh thái công cụ;
- tránh chỉ tối ưu từng tác vụ cục bộ.

### 2.7. Participatory Design
- người dùng có thể là **đối tác cùng thiết kế**;
- “design with users” = người dùng cùng tham gia tạo giải pháp, không chỉ bị quan sát hay kiểm thử.

### 2.8. Lean UX
- ưu tiên giả thuyết và học nhanh;
- **MVP** = bản thử tối thiểu đủ để kiểm chứng giả định rủi ro nhất;
- deliverable thường gồm Lean UX Canvas và giả thuyết kiểm chứng được.

### 2.9. PACT
Phân tích 4 thành tố:
- People
- Activities
- Contexts
- Technologies

---

## 3. Nghiên cứu người dùng và phân tích tương tác

### 3.1. Norman’s Seven Stages of Action
0. Goal
1. Plan
2. Specify
3. Perform
4. Perceive
5. Interpret
6. Compare

Hai khoảng cách quan trọng:
- **Gulf of Execution**: không biết phải làm gì / làm thế nào.
- **Gulf of Evaluation**: không hiểu hệ thống vừa phản hồi gì / đã đạt mục tiêu chưa.

### 3.2. Quy trình nghiên cứu → mô hình hóa → flow
Thứ tự nên dùng:

`Interview / Survey → Empathy Map → Persona → Journey Map → User Story → Problem Statement → Card Sorting → Sitemap → Task Flow / User Flow / Wireflow`

Không nên vẽ flow trước rồi mới đi tìm dữ liệu để hợp thức hóa.

### 3.3. Interview
Tránh:
- câu dẫn dắt;
- câu hai ý;
- câu hỏi giả định tương lai;
- hỏi quá sớm về giải pháp.

Kỹ thuật hữu ích:
- Laddering
- 5 Whys
- Critical Incident
- Intentional Silence

### 3.4. Survey
- tránh thiên lệch đồng thuận;
- có thể trộn phát biểu thuận và nghịch chiều;
- tách rõ dữ liệu thái độ và hành vi.

### 3.5. Rohrer Research Method Map
Phân loại theo:
- định tính / định lượng;
- hành vi / thái độ.

Ví dụ:
- phỏng vấn sâu: **định tính + thái độ**.

### 3.6. Persona
Persona phải dựa trên dữ liệu, không được bịa bằng cảm tính.

Các loại thường gặp:
- Primary persona
- Secondary persona
- Negative persona
- Served persona
- Customer persona

Khái niệm liên quan:
- **Elastic user**: hình ảnh “người dùng” bị co giãn tùy tiện để phục vụ lập luận thiết kế.

Persona nên gắn với goal:
- Experience goal
- End goal
- Life goal

### 3.7. Empathy Map
Các vùng thường dùng:
- Says
- Thinks
- Does
- Feels

Giá trị quan trọng:
- nhận ra **khoảng chênh giữa NÓI và LÀM**.

### 3.8. User Journey Map
- có **trục thời gian**;
- thể hiện các bước, hành động, điểm đau, cảm xúc và cơ hội cải thiện.

### 3.9. User Story
Mẫu:

`Là một [đối tượng], tôi muốn [nhu cầu], để [lợi ích / mục tiêu].`

User story tốt:
- nói nhu cầu thay vì chốt sẵn UI;
- đủ cụ thể;
- có bối cảnh / động cơ rõ ràng;
- không gộp nhiều mục tiêu không liên quan.

### 3.10. Problem Statement
Phải phản ánh:
- ai đang gặp vấn đề;
- vấn đề thực sự là gì;
- tại sao nó quan trọng;
- dựa trên dữ liệu nào.

### 3.11. Card Sorting và Information Architecture
- dùng để hiểu cách người dùng nhóm nội dung;
- hỗ trợ dựng sitemap;
- nhãn menu nên dùng **ngôn ngữ theo tác vụ / mental model của người dùng**, không nhất thiết theo cấu trúc tổ chức nội bộ.

### 3.12. Flow
- **Task Flow**: một đường đi chính cho một nhiệm vụ.
- **User Flow**: có điểm vào, quyết định, nhiều nhánh và cả đường thất bại.
- **Wireflow**: kết hợp flow và wireframe.

---

## 4. Ideation – từ vấn đề đến phương án

### 4.1. Không nhảy thẳng vào Figma
Trước khi lên ý tưởng cần:
- hình thoi thứ nhất của Double Diamond đã khép lại;
- problem statement đã được chốt.

### 4.2. Double Diamond
4 pha:
- Discover
- Define
- Develop
- Deliver

Ideate nằm ở:
- **nửa phân kỳ của hình thoi thứ hai**.

### 4.3. Divergence và Convergence
**Phân kỳ**:
- mở rộng không gian phương án;
- ưu tiên số lượng và độ đa dạng;
- hoãn phê phán;
- chấp nhận ý tưởng táo bạo.

**Hội tụ**:
- chọn phương án theo tiêu chí;
- phê phán có cấu trúc;
- đánh giá tính bảo vệ được của lựa chọn.

Không chạy cả hai cùng lúc.

### 4.4. Competitive Audit
Cần:
- đối thủ trực tiếp;
- đối thủ gián tiếp;
- tiêu chí rõ ràng;
- bằng chứng;
- thang đánh giá.

Đối thủ gián tiếp có thể là:
- workaround hiện tại;
- Excel;
- sổ tay;
- cách làm thủ công khác.

### 4.5. How Might We
Câu HMW tốt:
- không quá rộng;
- không quá hẹp;
- không cài sẵn giải pháp;
- tập trung vào mục tiêu / nhu cầu.

Nếu chỉ nghĩ ra được 1–2 giải pháp gần giống nhau:
- HMW thường **quá hẹp**.

### 4.6. Crazy Eights
- 8 ô
- 8 phút
- vẽ nhanh
- ưu tiên biến thể

Dùng bút đầu to để:
- ép người vẽ ở mức trừu tượng;
- tránh sa vào chi tiết giao diện.

Ưu điểm so với brainstorming nói miệng:
- làm song song;
- giảm production blocking;
- giảm evaluation apprehension;
- giảm social loafing.

### 4.7. Parking Lot cho ràng buộc
“Bãi đỗ ràng buộc” dùng để:
- ghi các lo ngại về khả thi;
- không làm ngắt pha phân kỳ;
- đưa sang pha hội tụ để xử lý.

### 4.8. Solution Sketch
Thường gồm 3 khung.

Quy tắc:
- tự giải thích được khi không có tác giả bên cạnh;
- có mũi tên / hành động giữa các khung;
- thiếu hành động chuyển tiếp = phương án đang thiếu bước.

Khác wireframe:
- Solution sketch trả lời **“phương án này là gì và có đáng theo đuổi không?”**
- Wireframe trả lời **“màn hình gồm gì và bố trí ra sao?”**

### 4.9. Decision Log
Ghi lại:
- phương án được chọn;
- phương án bị loại;
- lý do;
- bằng chứng;
- giả định cần kiểm chứng;
- chi tiết nào được tái sử dụng từ phương án khác.

Trường **“giả định cần kiểm chứng”** dùng để:
- biến niềm tin chưa có bằng chứng thành câu hỏi / kịch bản usability test.

---

## 5. Nguyên tắc thiết kế giao diện

### 5.1. Ba tầng nguyên tắc
#### Tầng tri giác
- Gestalt
- Visual hierarchy
- Balance
- Contrast
- Rhythm

#### Tầng nhận thức
- Affordance
- Signifier
- Mapping
- Feedback
- Constraints

#### Tầng đánh giá
- Nielsen 10 Heuristics
- Consistency & Standards

---

## 6. Gestalt và Visual Hierarchy

### 6.1. Gestalt
Các nguyên tắc chính:
- Prägnanz
- Proximity
- Similarity
- Closure
- Continuity
- Common region / enclosure
- Connectedness
- Common fate

Nguyên tắc quan trọng:
- người dùng luôn nhóm các phần tử;
- nhiệm vụ của designer là làm cho họ nhóm **đúng như ý đồ thiết kế**.

### 6.2. Proximity
- gần nhau = cùng nhóm;
- khoảng cách phải tạo được phân cấp nhóm;
- mọi khoảng cách bằng nhau có thể phá grouping.

### 6.3. Continuity
- căn lề nhất quán giúp mắt di chuyển mượt;
- căn lề lộn xộn làm mắt liên tục đổi hướng.

### 6.4. Common Region
- các phần tử chung trong một khung thường được nhóm mạnh hơn cả khác biệt màu.

### 6.5. Visual Hierarchy
Các yếu tố tăng trọng lượng thị giác:
- kích thước lớn;
- độ đậm cao;
- màu tối;
- bão hòa cao;
- tương phản mạnh;
- vị trí nổi bật;
- ít khoảng trắng bao quanh.

### 6.6. Squint Test
Nheo mắt / làm mờ màn hình để kiểm tra:
- phần tử nào nổi bật thật sự;
- thứ tự ưu tiên thị giác;
- CTA có đủ nổi không.

---

## 7. Nielsen’s 10 Heuristics

1. Visibility of system status
2. Match between system and real world
3. User control and freedom
4. Consistency and standards
5. Error prevention
6. Recognition rather than recall
7. Flexibility and efficiency of use
8. Aesthetic and minimalist design
9. Help users recognize, diagnose and recover from errors
10. Help and documentation

### 7.1. H8 – Aesthetic and Minimalist Design
Không có nghĩa là:
- giao diện trắng;
- ít chữ bằng mọi giá;
- chỉ một CTA.

Ý đúng:
- loại bỏ thông tin không liên quan;
- vì mỗi đơn vị thông tin thừa cạnh tranh với thông tin thiết yếu.

### 7.2. Severity Rating 0–4
- 0: không phải vấn đề
- 1: cosmetic
- 2: minor usability problem
- 3: major usability problem
- 4: usability catastrophe — phải sửa trước phát hành

---

## 8. Consistency, Convention và Jakob’s Law

### 8.1. Jakob’s Law
Người dùng dành phần lớn thời gian trên các sản phẩm khác, nên họ mong sản phẩm mới hoạt động giống những gì họ đã quen.

### 8.2. Chỉ phá quy ước khi
- quy ước hiện tại gây vấn đề đo được;
- phương án mới rõ ràng tốt hơn;
- đã kiểm thử với người dùng và chứng minh cải thiện.

Không phá quy ước chỉ vì:
- “nhàm chán”;
- “muốn khác biệt”.

### 8.3. Satisficing – Krug
Người dùng thường:
- không tối ưu tất cả lựa chọn;
- chọn **phương án hợp lý đầu tiên** mà họ nhìn thấy.

### 8.4. 7 ± 2
Không dùng để giới hạn menu vì:
- nghiên cứu gốc nói về **working memory**;
- menu là nội dung người dùng **quét bằng mắt**, không phải ghi nhớ toàn bộ.

---

## 9. Màu sắc

### 9.1. Color Models
- RGB: màn hình / Figma
- CMYK: in ấn
- HEX: giao tiếp dev / style guide
- RGB/RGBA: tính toán / transparency
- HSL: điều chỉnh hue, saturation, lightness
- HSB/HSV: color picker

### 9.2. Hue, Saturation, Lightness
- Hue: sắc độ / vị trí trên bánh xe màu
- Saturation: độ bão hòa
- Lightness: độ sáng hình học

### 9.3. Primitive vs Semantic Color
**Primitive color**:
- đặt tên theo giá trị
- ví dụ: `Blue/600`, `Gray/900`

**Semantic color**:
- đặt tên theo vai trò
- ví dụ: `Action/Primary`, `Text/Primary`, `Feedback/Error`

Lợi ích khi làm dark mode:
- chỉ cần đổi primitive mà semantic token trỏ tới;
- không sửa từng màn hình.

### 9.4. Design Token
- giá trị thiết kế có tên;
- dùng chung giữa thiết kế và mã nguồn.

### 9.5. Quy tắc 60–30–10
- 60%: nền trung tính
- 30%: bề mặt / thẻ / thanh điều hướng
- 10%: màu nhấn / hành động chính

---

## 10. Contrast và WCAG

### 10.1. Contrast Ratio
Khoảng:
- 1:1 đến 21:1

### 10.2. WCAG 1.4.3
Chữ thường:
- tối thiểu **4.5:1** ở mức AA

Lưu ý:
- không làm tròn 4.48 thành 4.5 để coi là đạt;
- 4.48 vẫn **không đạt**.

### 10.3. Exemption
Ví dụ:
- text trong control disabled / inactive.

### 10.4. Relative Luminance
Được dùng để tính contrast ratio.

Linearization:
- nếu `c <= 0.04045` → `c / 12.92`
- ngược lại → `((c + 0.055) / 1.055)^2.4`

### 10.5. Chữ trên ảnh
- đo ở vùng **sáng nhất / bất lợi nhất** phía sau chữ;
- nếu chưa đạt:
  - overlay tối;
  - gradient;
  - nền riêng cho chữ.

---

## 11. Typography

### 11.1. Type System
Cần xác định:
- typeface
- type scale
- font size
- font weight
- line-height
- letter spacing

### 11.2. Font tiếng Việt
- kiểm tra đầy đủ dấu;
- ưu tiên font hỗ trợ Unicode tốt;
- kiểm tra rendering thực tế.

### 11.3. Text Style trong Figma
Không tạo style theo màu chữ nếu cấu trúc chữ giống nhau.

Ví dụ:
- `Heading/H1`

Màu quản lý riêng bằng color style/token.

---

## 12. Grid, spacing, iconography, image

### 12.1. 8pt Grid
Dùng để:
- spacing nhất quán;
- căn chỉnh;
- dễ handoff.

### 12.2. Layout Grid
- multi-column guide cho màn hình lớn;
- gutter / margin rõ ràng.

### 12.3. Icon
Cần nhất quán:
- stroke
- style
- kích thước
- nghĩa
- vùng chạm

### 12.4. Touch Target
Ví dụ:
- WCAG 2.5.8: mức tối thiểu 24×24 CSS px trong điều kiện áp dụng;
- Apple khuyến nghị khoảng 44×44 pt;
- Material thường dùng khoảng 48×48 dp.

Icon 24×24 có thể đạt mức tối thiểu về target theo tiêu chí nhất định nhưng vẫn thấp hơn khuyến nghị nền tảng.

### 12.5. Hình ảnh
- nên thống nhất vài aspect ratio;
- dùng Fill để ảnh lấp đầy khung nhưng không méo;
- tránh trộn lẫn phong cách minh họa / ảnh chụp tùy tiện.

---

## 13. Wireframing

### 13.1. Không bắt đầu từ frame trắng
Nếu đã có solution sketch:
- mở lại sketch;
- giữ traceability;
- chỉ quyết định những gì sketch chưa quyết.

### 13.2. Fidelity
Ba chiều độc lập:
- Visual fidelity
- Content fidelity
- Interaction fidelity

Low / Mid / High fidelity không phải một trục đơn giản duy nhất.

### 13.3. Wireframe cần làm rõ
- bố cục;
- thông tin;
- nhóm chức năng;
- điều hướng;
- thứ tự ưu tiên;
- trạng thái chính.

Không nên trang trí sớm bằng:
- màu;
- shadow;
- corner radius;
- ảnh đẹp;
- icon chi tiết.

---

## 14. Component Design

### 14.1. Component trong Figma
Khái niệm:
- Main Component
- Instance
- Variant
- Component Property

### 14.2. Quy tắc đặt tên
- nhất quán;
- có hierarchy;
- dễ map sang code.

### 14.3. State Matrix
Nên bao gồm:
- Default
- Hover
- Pressed
- Focus
- Disabled
- Error
- Loading
- Selected

### 14.4. Design System tối thiểu
- Design tokens
- Components
- Documentation
- Stress test

---

## 15. Auto Layout và Constraints

### 15.1. Auto Layout
Dùng cho layout theo dòng chảy:
- list
- card
- button row
- form

Tương đồng với cách layout trong CSS flexbox ở nhiều trường hợp.

### 15.2. Constraints
Dùng để neo vị trí:
- nút nổi
- badge
- tab bar
- phần tử absolute

Ví dụ:
- Right + Bottom
- Left & Right
- Center
- Scale

### 15.3. Responsive Strategy
- Fluid
- Adaptive
- Hybrid

Hybrid thường thực tế nhất:
- co giãn trong khoảng;
- đổi bố cục tại breakpoint.

---

## 16. Prototype

### 16.1. Phân biệt
- Wireframe = Structure
- Mockup = Visual appearance
- Prototype = Behavior

### 16.2. Prototype là một câu hỏi
Prototype được tạo ra để kiểm chứng một câu hỏi trước khi build thật.

### 16.3. Figma Prototype
Có thể dùng:
- Trigger
- Action
- Animation
- Overlay
- Smart Animate

### 16.4. Smart Animate
Cần layer matching tốt:
- tên layer nhất quán;
- cấu trúc layer tương thích;
- hierarchy phù hợp.

---

## 17. Micro-interactions

Khung:

`Trigger → Rules → Feedback → Loops`

### 17.1. Feedback tốt
Sau hành động nên có:
1. phản hồi tức thì;
2. trạng thái chờ nếu cần;
3. kết quả rõ ràng.

### 17.2. Feedforward
Gợi ý cho người dùng biết:
- điều gì sẽ xảy ra trước khi họ thực hiện.

### 17.3. Error & Edge States
Prototype nên cân nhắc:
- loading
- empty
- error
- success
- disabled
- conflict
- exception

---

## 18. Evaluation & Usability Testing

### 18.1. Formative vs Summative
**Formative**:
- cái gì sai?
- vì sao?
- dùng trong quá trình thiết kế.

**Summative**:
- thiết kế tốt tới mức nào?
- cần số liệu đáng tin cậy hơn.

### 18.2. Ba chiều phân loại phương pháp
- Expert inspection vs empirical with users
- Qualitative vs quantitative
- Behavior vs attitude

### 18.3. Heuristic Evaluation
Quy trình:
- chuyên gia đánh giá độc lập;
- tổng hợp findings;
- gán heuristic;
- severity 0–4;
- ưu tiên sửa.

### 18.4. Usability Test Planning
Cần xác định:
- mục tiêu;
- người tham gia;
- task;
- script;
- dữ liệu cần thu.

### 18.5. Think-Aloud
- không dẫn dắt;
- không giải thích hộ;
- tách quan sát khỏi diễn giải.

### 18.6. Metrics
Có thể dùng:
- Task completion
- Completion rate
- Time on task
- SEQ
- SUS

### 18.7. A/B Testing
Cần hiểu:
- giả thuyết;
- biến độc lập / biến phụ thuộc;
- sample size;
- significance;
- tránh kết luận từ mẫu quá nhỏ.

### 18.8. Compare Alternatives
- comparative usability test
- weighted decision matrix
- sensitivity analysis

### 18.9. Iterate
Quy trình:
- tổng hợp issue;
- ưu tiên;
- sửa;
- ghi bằng chứng;
- so sánh v1 → v2.

---

## 19. Các lỗi tư duy cần tránh

- Vẽ UI trước khi hiểu vấn đề.
- Bịa persona.
- Nhầm goal với task.
- Cài sẵn giải pháp trong HMW.
- Chọn ý tưởng vì “nhóm thấy thích”.
- Dùng dot voting như bằng chứng cuối cùng.
- Trang trí high-fi khi structure chưa đúng.
- Dùng màu đẹp thay cho hierarchy.
- Chỉ kiểm thử happy path.
- Dùng 5 người rồi kết luận định lượng chắc chắn.
- Dùng 7±2 để giới hạn số item menu.
- Nhầm affordance với signifier.
- Bắt người dùng học thuật ngữ nội bộ của tổ chức.
- Chỉ tối ưu từng task mà bỏ qua activity tổng thể.
- Không ghi decision log.
- Không kiểm tra failure path.

---

## 20. Quy trình mặc định khi áp dụng Skill_Thiết kế giao diện

Khi xử lý một bài tập hoặc đồ án UI/UX, ưu tiên theo thứ tự:

1. Xác định bối cảnh bài toán.
2. Chọn phương pháp phù hợp.
3. Thu thập bằng chứng người dùng.
4. Mô hình hóa persona / empathy / journey.
5. Viết user story và problem statement.
6. Dựng IA và flow.
7. Competitive audit.
8. Viết HMW.
9. Diverge bằng Crazy Eights hoặc kỹ thuật phù hợp.
10. Converge có tiêu chí.
11. Ghi decision log.
12. Tạo solution sketch.
13. Chuyển sang wireframe.
14. Áp dụng Gestalt, hierarchy, Nielsen, Norman.
15. Xây style guide: color, typography, grid, icon.
16. Tạo component và token.
17. Dùng Auto Layout / Constraints / responsive strategy.
18. Dựng prototype.
19. Thêm micro-interaction và trạng thái lỗi.
20. Heuristic review.
21. Usability test.
22. Ghi issue + severity.
23. Iterate v1 → v2.

---

## 21. Quy tắc sử dụng skill trong các bài sau

Khi người dùng yêu cầu:
- “Dùng Skill_Thiết kế giao diện”
- “Dùng skill UIUX”
- “Phân tích theo bộ skill giao diện”

thì mặc định:
- ưu tiên thuật ngữ và cách làm trong bộ skill này;
- dùng Figma/FigJam terminology;
- không nhảy thẳng vào giao diện nếu chưa đủ dữ liệu;
- nếu bài yêu cầu đáp án trắc nghiệm, trả lời ngắn gọn và ưu tiên chính xác;
- nếu bài yêu cầu prompt cho AI/Figma, chuyển các nguyên tắc thành checklist và constraint cụ thể;
- nếu bài yêu cầu đồ án, giữ traceability từ research → decision → UI → test.

---

## 22. Checklist nhanh

### Research
- [ ] Có bằng chứng người dùng?
- [ ] Persona dựa trên dữ liệu?
- [ ] Goal rõ?
- [ ] Pain point thật?

### Define
- [ ] User story hợp lệ?
- [ ] Problem statement rõ?
- [ ] IA / sitemap hợp lý?
- [ ] Flow có failure path?

### Ideate
- [ ] HMW không cài sẵn giải pháp?
- [ ] Có divergence đủ rộng?
- [ ] Có convergence theo tiêu chí?
- [ ] Có decision log?

### Wireframe
- [ ] Có trace từ solution sketch?
- [ ] Chưa trang trí quá sớm?
- [ ] Hierarchy rõ?
- [ ] Grouping đúng Gestalt?

### Visual
- [ ] Contrast đạt WCAG?
- [ ] Color token rõ primitive / semantic?
- [ ] Type scale nhất quán?
- [ ] Spacing theo system?
- [ ] Icon / touch target ổn?

### Component
- [ ] Dùng instance/variant/property hợp lý?
- [ ] State đủ?
- [ ] Naming nhất quán?
- [ ] Auto Layout đúng chỗ?
- [ ] Constraints đúng chỗ?

### Prototype
- [ ] Có feedback tức thì?
- [ ] Có loading?
- [ ] Có success/error?
- [ ] Có micro-interaction đúng mục tiêu?

### Test
- [ ] Heuristic review?
- [ ] Severity 0–4?
- [ ] Think-aloud không dẫn dắt?
- [ ] Có metric phù hợp?
- [ ] Có ghi bằng chứng v1 → v2?

---

# End of Skill_Thiết kế giao diện
