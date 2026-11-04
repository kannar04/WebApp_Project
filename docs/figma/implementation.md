# Figma homepage implementation

Source: connected Figma file **Untitled**, page **web**, selected frame
**Public · Home & search** (`145:34448`).

The homepage now uses the frame's Lora/Inter typography, cream and green palette,
520px photographic hero, separate search bar, destination links, property cards,
and journal invitation. The hero and journal photographs were exported from the
actual Figma layers; the reference capture is `home-reference.png`.

The Bootstrap 4.6.2 grid, existing PHP MVC routing, escaping, translation system,
search fields, pagination, and database listings remain connected. Advanced filters
use native HTML details/summary, open automatically for active filters or errors,
and include access to the map. No new JavaScript or frontend framework was added;
existing Bootstrap and application scripts still supply existing interactions.

Shared main/auth layouts load the new typography and CSS theme. This change
implements the selected homepage; the other Figma frames have not been rebuilt.
Real account and listing data replace mockup samples, and the mockup's unverified
rating/stay-count claim is replaced by existing product content.

Validation:

- PHP syntax: homepage, both layouts, translation dictionary passed.
- Browser: homepage returned HTTP 200 at 1440px and 390px; no horizontal overflow
  or broken images. Lora heading font confirmed.
- Browser: destination search submits existing GET fields; filters open and remain
  open for search results; mobile Bootstrap navigation opens; login form renders.
- Browser: Vietnamese dark mode renders without horizontal overflow.
- Captures: `home-1440.png`, `home-390.png`, `home-390-dark-vi.png`.
- `git diff --check` passed.
- Broader source audit reached its 105-route check, then failed because local
  `sp_test_listing_approve` / cleanup routines were unavailable. Its temporary
  listing was identified and removed. The quality mutation suite correctly refused
  to run without an isolated database. Neither suite is reported as passing.
