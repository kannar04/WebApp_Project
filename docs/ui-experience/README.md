# UI and UX upgrade

The upgrade extends the supplied cream-and-forest Figma theme across discovery, property details, authentication, the journal, account pages, and host/admin workspaces. It adds responsive navigation, a mobile booking shortcut, clearer form and filter states, consistent dark surfaces, and CSS motion with reduced-motion support.

The implementation uses PHP, HTML, CSS, and the existing Bootstrap components and application scripts. Shared assets now load locally. WOFF2 compression reduces the seven font files from approximately 1.8 MB to 591 KB; the accompanying OFL licenses are preserved.

Travel search hierarchy and editorial photography were informed by [Airbnb](https://www.airbnb.com/) and [Mr & Mrs Smith](https://www.mrandmrssmith.com/). Existing Figma imagery and application listing content remain the visual source material.

## Preview and evidence

- [Desktop homepage](home-1440.png) and [mobile homepage](home-390.png)
- [Vietnamese dark theme](home-dark-vi.png)
- [Sign-in](login-1440.png) and [mobile sign-in](login-390.png)
- [Property detail](property-1440.png) and [mobile property detail](property-390.png)
- [Travel journal](journal-1440.png)
- [Guest profile](profile-1440.png) and [bookings](bookings-1440.png)
- [Host workspace](host-1440.png) and [admin workspace](admin-1440.png)
- [Machine-readable browser results](verification.json)

## Checks

Latest run: **170 checks passed, 0 failed**, with no uncaught browser JavaScript errors. All 15 changed PHP templates and translation files passed syntax checks.

The Chromium suite checks public pages at 1440, 768, 390, and 320 pixels; guest, host, and admin journeys at desktop and mobile sizes; image loading; headings; document overflow; mobile menus; account dropdown keyboard behavior; skip navigation; locale and theme state; search persistence and invalid filters; font loading; card aspect ratios; mobile date field widths; the reservation anchor; and reduced motion. PHP syntax checks cover all changed templates and translation files.

These checks validate presentation and existing navigation. They do not establish full accessibility conformance, cross-browser compatibility, or exercise payment/booking/admin mutations.

Run with a local seeded database and server:

```powershell
python -X utf8 tests/ui_experience.browser.py
```

Python Playwright and its Chromium browser must be installed. Test tooling is not a runtime application dependency.
