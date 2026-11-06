# Home2Home interface

Home2Home helps travelers discover and book stays in Vietnam, while hosts manage properties and administrators manage the marketplace. Preserve real listing data, booking forms, authorization, validation, localization, and existing routes when editing presentation.

## Visual direction

Use the supplied Figma design's warm cream and forest-green palette with an editorial travel treatment: generous photographs, restrained Lora headings, readable Inter interface text, and clear destination/date/guest search controls. Terracotta is an accent, not a second primary action color. Avoid decorative dashboard clutter, oversized statistics, and unrelated gradients.

The guest hierarchy is search → relevant homes → property facts and total → review → explicit confirmation. A compact introduction replaces the campaign hero; photographs lead the results. Guest screens prioritize exploration; host and admin screens prioritize navigation and readable records. Dark mode uses neutral charcoal surfaces, warm off-white text, and restrained sage accents for actions and focus. Elevated surfaces are slightly lighter than the canvas; avoid green-tinted page backgrounds. Both English and Vietnamese must fit without clipping.

The homepage search uses one transparent, rounded outline around the entire row: a long destination input, compact inline calendar controls, guests, and an icon-only submit button. There are no internal separators or visible stacked field labels. On small screens, calendar icons retain accessible names and native date pickers. During submission, the magnifier becomes a rotating ring; reduced motion shows a static loading ring.

## Implementation

- PHP templates, semantic HTML, Bootstrap 4.6.2 grid/utilities/components, and CSS. Use small vanilla JavaScript enhancements for destination suggestions, automatic loading, browsing restoration, and state-aware booking actions. Keep PHP GET forms and pagination usable without JavaScript; no frontend framework is introduced.
- Shared document assets, appearance controls, icons, and workspace navigation live in `app/Views/components/`.
- `public/assets/css/experience.css` contains the theme tokens and cross-page styling. `figma.css` retains the earlier homepage implementation; `experience.css` loads after it. `focused.css` is the final layer for the guest journey; workspace and editorial styles remain in `experience.css`.
- Inter, Lora, Bootstrap, and jQuery are served locally. Font and vendor licenses accompany their files.
- Use real links, accessible names, keyboard focus states, and native controls. Tables may scroll inside their containers; the page must not overflow at 320px.

## Motion

Discovery content is immediately visible without a hero entrance. Motion is reserved for control feedback and existing photo hover states. Image hover zooms and button feedback are subtle; saved-state feedback belongs to the existing favorite interaction. Respect `prefers-reduced-motion` by removing animations and transitions. Do not add autoplay carousels, scroll interception, or entrance animations that hide important content.

## Validation

Run `python -X utf8 tests/ui_experience.browser.py` against a local instance with seeded demo accounts. Set `HOME2HOME_TEST_URL` to override `http://127.0.0.1:8090`. The suite reads public and authenticated screens and submits sign-in/search only; it does not submit bookings or administrative changes. See `docs/ui-experience/` for screenshots and the latest report.

## Focused journey implementation

Discovery uses real inventory-backed city suggestions, optional dates, room-type choices, automatic six-home batches, and browser Back restoration. The main heading is centered; redundant introductory/result copy is removed. Filter pills use white surfaces with outlined selected states, with charcoal surfaces in dark mode.

The shared `stay-card.php` component shows a rounded square photo, a top-left favorite control, title, and base nightly price. The grid uses six desktop columns, three tablet columns and two mobile columns. Property details end with public host information and up to ten related stays, using the same card in a native horizontal rail with previous/next controls. Related stays prioritize city and property type, honor valid trip filters, and exclude the current property.

Mobile navigation keeps Explore, Saved, and Trips available except where a property booking bar owns the bottom action area. Quotes update that bar; a read-only review precedes explicit confirmation. Login and registration preserve validated return context. See [implementation and verification](docs/ui-experience/focused/README.md) and [FE–BE handoff](docs/focused-experience-plan.md).
