# Focused home-sharing UX audit

Date: 2026-11-05. Scope: discovery → results → property → quote → sign-in return. This is an implementation audit with product-fit observations, not a user research study. Working assumption: “focused experience” means helping guests find and book the right home with minimal distraction. No application code changed during this audit.

## Verdict

The visual system is coherent and suitable for a calm accommodation product. Product-specific implementation integrity is a partial pass: it looks like an editorial holiday-stay marketplace, but important booking context is lost and shared-living information is underdeveloped. Retain the palette, typography, and imagery treatment; prioritize continuity and decision information over more decoration.

The previous 170 checks establish specific layout and interaction behavior, not successful task completion or user satisfaction. One test even requires filters to remain expanded after search; that behavior passes technically while harming task focus.

## Audit health

These are directional reviewer scores, not a compliance certification or measured conversion score.

| Dimension | Score | Evidence |
|---|---:|---|
| Accessibility | 2/4 | Labels, focus styles, skip link, and reduced motion are present; repeated entry of trip details is a significant cognitive burden. |
| Performance | 2/4 | Local WOFF2 and lazy listing images help; hero PNG is 1,298,331 bytes and homepage font transfers total about 493 KB. No mobile network timing measured. |
| Theming | 3/4 | Consistent light/dark tokens; three layered stylesheets and some hard-coded colors complicate future maintenance. |
| Responsive design | 3/4 | Prior viewport checks pass; current mobile search action sits at the fold and some targets are small. Physical touch and text zoom were not tested. |
| Implementation integrity | 2/4 | Coherent appearance, but search context resets, filters dominate results, and promotional copy overstates available review evidence. |
| **Total** | **12/20** | **Acceptable technical foundation; significant work needed on the focused journey.** |

## Prioritized findings

### 1. [P1] Trip context disappears between steps

Location: `app/Views/home.php:104`, `app/Views/listings/show.php:2`, `app/Controllers/AuthController.php:12`. Category: implementation integrity/accessibility.

Reproduction: search Đà Lạt for November 30–December 3, 2026, two guests; open the returned listing. Both dates become empty and guests becomes one. Enter those details again, choose Log in to book, and sign in: the app returns to the correct listing but again clears the dates and resets guests to one.

Impact: guests must repeatedly enter information, can accidentally price/book the wrong party size, and lose confidence that the app understands their trip. Preserve validated trip context through listing navigation and authentication, and restore it before calculating a fresh quote. Do not preserve an outdated price as authoritative. This relates to [WCAG 2.2 Redundant Entry](https://www.w3.org/WAI/WCAG22/Understanding/redundant-entry.html), which calls for previously supplied information in the same process to be populated or selectable unless an exception applies.

Suggested follow-up: `$impeccable harden`.

### 2. [P1] Successful search puts advanced filters before results

Location: `app/Views/home.php:39`. Category: implementation integrity/responsive.

Any active search condition opens the complete advanced-filter panel. At 390×844, after the search and anchor navigation, the first matching card begins approximately 1,645 px below the viewport top. Guests get a lengthy form when they expect homes.

Keep advanced filters collapsed after a valid basic search; show applied filters as a compact summary, with explicit edit and clear actions. Expand the relevant controls for errors. Update the existing browser assertion that currently enforces the problematic behavior.

Suggested follow-up: `$impeccable distill`.

### 3. [P2] The initial mobile hierarchy favors the campaign over the task

Location: `public/assets/css/experience.css:70`, `app/Views/home.php:11`. Category: responsive/product fit.

At 390×844, the search button begins at document y=819 and the first home at y=1,222. The page looks inviting, but the actual search action is only partially in the initial viewport. Desktop cards also begin around y=1,001.

Use a shorter mobile hero, keep the complete search control visible earlier, and show a compact search/results header once a search is active. Retain the larger editorial hero for first-time inspiration if that remains a product objective.

Suggested follow-up: `$impeccable layout`.

### 4. [P2] Shared living is difficult to evaluate

Location: `app/Views/home.php:49`, `app/Views/home.php:107`, `app/Views/listings/show.php:2`, `app/Services/ConversationService.php:9`. Category: implementation integrity/product fit.

Room type exists, but it is inside advanced filters and secondary card text. The examined detail page gives a host name, conditional trust badges, amenities and house rules, but no dedicated host introduction or pre-booking contact action. The messaging implementation is tied to bookings. There are no explicit fields in the examined listing presentation explaining which spaces are shared, whether the host lives there, or whether the bathroom is private.

If sharing a home with other people is the core promise, make “Entire home / Private room / Shared room” prominent and add truthful host and privacy information before amenities. A pre-booking contact feature needs deliberate backend permissions and abuse controls; it is not just a new button. If the product is chiefly entire-home rentals, this is a positioning decision rather than a launch blocker. Never invent verification, response times, or host attributes.

Suggested follow-up: `$impeccable shape`, then `$impeccable clarify`.

### 5. [P2] Price and action do not reflect the chosen trip

Location: `app/Views/listings/show.php:2`, `app/Views/listings/show.php:5`. Category: implementation integrity.

The verified three-night quote is 3,900,000đ, including a 150,000đ cleaning fee. The mobile bar continues to show 1,250,000đ/night and “Choose your dates” after valid dates have already produced the quote. Listing cards also show the base nightly price after date-specific search. The quote breakdown itself is clear and should be retained.

After dates are selected, show the current trip total and a context-appropriate action. Before dates, label the nightly amount as a starting price if variable rates apply. Keep cancellation and booking-confirmation expectations next to the decision.

Suggested follow-up: `$impeccable clarify` and `$impeccable harden`.

### 6. [P2] Copy claims more social proof than the displayed data supports

Location: `app/Views/home.php:38`, `app/Views/listings/show.php:2`. Category: implementation integrity.

The heading says “Loved by guests. Chosen for their character.” while the visible cards show “New” and the examined property has no reviews. The rationale for editorial selection is not visible. “The total is confirmed again by the server” also exposes implementation terminology at a moment when guests need plain reassurance.

Use neutral discovery language until review or curation data substantiates stronger claims. Explain when the amount is finalized and when payment happens in guest language. Retain actual review counts and conditional verification states.

Suggested follow-up: `$impeccable clarify`.

### 7. [P2] First-view assets can be lighter

Location: `public/assets/images/figma/home-hero.png`, `public/assets/css/fonts.css`. Category: performance.

The hero is approximately 1.3 MB; the observed homepage loads five font files totaling about 493 KB including transfer overhead. These are measured asset costs, not a measured slow-load claim. Convert photography to appropriately sized WebP/AVIF variants with a fallback and consider reducing font weights or using carefully subsetted fonts that retain Vietnamese glyphs. Measure on a mobile network before setting budgets.

Suggested follow-up: `$impeccable optimize`.

### 8. [P3] Destination links are small for thumb use

Location: `public/assets/css/experience.css:94`. Category: responsive.

The five destination links are 32 px tall and approximately 33–51 px wide on mobile. Larger hit areas would be more comfortable. This is not reported as a WCAG AA target-size failure: [SC 2.5.8](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html) uses a 24×24 CSS-pixel minimum with exceptions, and these measured links exceed that minimum.

Suggested follow-up: `$impeccable adapt`.

## Preserve

- Warm palette, editorial imagery, and consistent light/dark appearance.
- Clear primary-button treatment and restrained motion with reduced-motion support.
- Semantic forms, validation, skip navigation, and visible focus styles.
- Mobile booking shortcut, with improved state-aware labeling.
- Honest empty-review states and conditional trust badges.
- Clear itemized quote and existing cancellation information.

Sample computed contrast: muted text on page 4.73:1, white primary-button text 6.41:1, and dark-theme token pair 8.64:1. These samples do not establish full accessibility conformance.

## Recommended focused journey

Destination + dates + guests → relevant homes with privacy/type and honest pricing → host, living arrangements, rules and reviews → review total → authenticate without losing progress → confirm request or booking.

Keep travel guides as secondary exploration. Do not add more motion or promotional blocks before fixing continuity. Home sharing does not require a social feed; it requires clear expectations about the home and people.

Order: `$impeccable harden` → `$impeccable distill` → `$impeccable shape`/`$impeccable clarify` → `$impeccable optimize` → `$impeccable adapt` → `$impeccable polish`. These can be addressed individually or together; rerun `$impeccable audit` after changes.

## Evidence and limits

- [Raw browser evidence](evidence.json)
- [Initial mobile viewport](home-390.png)
- [Initial desktop viewport](home-1440.png)
- [After successful search](search-390.png)
- [Booking form and unchanged mobile action](booking-390.png)
- Fresh Chromium inspection at 390×844 and 1440×900; read-only search/quote plus seeded demo sign-in. No booking, payment, message, or administrative mutation submitted.
- Deterministic detector: `impeccable detect --json app/Views` returned `[]`, exit 0. This is no detected rule violations, not evidence of a good end-to-end journey.
- Prior screenshots and 170-check report were consulted; that broad suite was not rerun for this audit.
- No measured conversion, interviews, screen-reader session, physical touch device, full contrast crawl, text-zoom audit, cross-browser validation, or throttled performance measurement. Findings about trust and category fit are informed design judgments, not observed user abandonment.
- Severity count: P0=0, P1=2, P2=5, P3=1.
