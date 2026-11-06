"""Read-only UI journeys plus demo-account sign-in; never submits bookings or edits data.
Run: python -X utf8 tests/ui_experience.browser.py
Requires a local server at HOME2HOME_TEST_URL (default http://127.0.0.1:8090).
"""
import json
import os
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

BASE = os.environ.get('HOME2HOME_TEST_URL', 'http://127.0.0.1:8090').rstrip('/')
OUT = Path('docs/ui-experience')
OUT.mkdir(parents=True, exist_ok=True)
results = []
failures = []
console_errors = []

def record(name, passed, detail=None):
    results.append({'check': name, 'passed': bool(passed), 'detail': detail})
    if not passed:
        failures.append(name)

def visit(page, path, name, width, screenshot=False, expected_status=200):
    page.set_viewport_size({'width': width, 'height': 1000})
    response = page.goto(BASE + path, wait_until='domcontentloaded')
    page.evaluate('document.fonts.ready')
    page.locator('body').wait_for()
    record(name + ' HTTP', response.status == expected_status, response.status)
    record(name + ' horizontal overflow', page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'))
    record(name + ' primary heading', page.locator('h1').count() == 1)
    if screenshot:
        page.locator('img').evaluate_all("imgs => imgs.forEach(img => img.loading = 'eager')")
        page.wait_for_function('Array.from(document.images).every(img => img.complete)', timeout=15000)
        broken = page.locator('img').evaluate_all('imgs => imgs.filter(i => !i.naturalWidth).map(i => i.src)')
        record(name + ' image loading', not broken, broken)
        page.screenshot(path=str(OUT / (name + '.png')), full_page=page.evaluate('document.body.scrollHeight < 6500'))

with sync_playwright() as p:
    browser = p.chromium.launch()
    context = browser.new_context(locale='en-US', color_scheme='light')
    context.add_cookies([{'name':'h2h_locale','value':'en','url':BASE}])
    page = context.new_page()
    page.on('pageerror', lambda error: console_errors.append(str(error)))
    for width in (1440, 768, 390, 320):
        for path, name in [('/', 'home'), ('/login','login'), ('/listings/1','property'), ('/articles','journal')]:
            visit(page, path, f'{name}-{width}', width, screenshot=width in (1440,390))

    for path, name in [('/register','register'),('/forgot-password','recovery'),('/reset-password','reset'),('/map','map')]:
        visit(page, path, name, 390, expected_status=422 if path == '/reset-password' else 200)

    visit(page, '/', 'interaction-home', 390)
    image_box = page.locator('.listing-card .card-image').first.bounding_box()
    record('Listing photographs retain square proportions', abs(image_box['width'] / image_box['height'] - 1) < .02)
    record('Local display and interface fonts loaded', page.evaluate("document.fonts.check('16px Inter') && document.fonts.check('16px Lora')"))
    page.keyboard.press('Tab')
    record('Keyboard skip link', page.locator('.skip-link').evaluate('(el) => el === document.activeElement'))
    page.locator('.navbar-toggler').click()
    expect(page.locator('#mainNav')).to_be_visible()
    record('Mobile menu expands', page.locator('.navbar-toggler').get_attribute('aria-expanded') == 'true')
    page.locator('[data-theme-toggle]').click()
    expect(page.locator('html')).to_have_attribute('data-theme', 'dark')
    record('Theme control updates accessible state', page.locator('[data-theme-toggle]').get_attribute('aria-pressed') == 'true')
    context.add_cookies([{'name':'h2h_locale','value':'vi','url':BASE}])
    visit(page, '/', 'home-dark-vi', 390, screenshot=True)
    record('Vietnamese page', page.locator('html').get_attribute('lang') == 'vi')
    page.locator('.home-filter summary').focus()
    page.keyboard.press('Enter')
    expect(page.locator('[data-filter-form]')).to_be_visible()
    record('Keyboard opens filters', page.locator('.home-filter').get_attribute('open') is not None)
    page.locator('#location').fill('Hội An')
    page.locator('.search-button').click()
    page.wait_for_load_state('domcontentloaded')
    record('Search submits and retains destination', page.locator('#location').input_value() == 'Hội An' and 'location=' in page.url)
    record('Search keeps advanced filters collapsed', page.locator('.home-filter').get_attribute('open') is None)
    page.goto(BASE + '/?min_price=1000000&max_price=100', wait_until='domcontentloaded')
    record('Invalid filter recovery', page.locator('[data-search-invalid]').count() == 1 and page.locator('.home-filter').get_attribute('open') is not None)

    page.emulate_media(reduced_motion='reduce')
    page.goto(BASE, wait_until='domcontentloaded')
    record('Reduced motion removes transitions', page.locator('.search-button').evaluate('(el) => getComputedStyle(el).transitionDuration') == '0s')
    page.emulate_media(reduced_motion='no-preference')
    page.goto(BASE, wait_until='domcontentloaded')
    record('Discovery is immediately visible without hero animation', page.locator('.home-hero-image').count() == 0 and page.locator('.search-button').is_visible())
    page.evaluate("localStorage.setItem('h2h-theme','light')")
    context.add_cookies([{'name':'h2h_locale','value':'en','url':BASE}])
    page.goto(BASE + '/listings/1', wait_until='domcontentloaded')
    date_fields = page.locator('.booking-box input[type=date]')
    record('Mobile booking dates have readable field width', date_fields.count() == 2 and all(field.bounding_box()['width'] > 250 for field in date_fields.all()))
    page.locator('.mobile-booking-bar a').click()
    record('Mobile reservation links to real form', page.url.endswith('#booking-panel') and page.locator('[data-booking-form]').count() == 1)

    for role, paths in [
        ('guest', [('/profile','profile'),('/wishlist','wishlist'),('/bookings','bookings'),('/messages','messages'),('/notifications','notifications')]),
        ('host', [('/host','host'),('/host/listings/create','host-create'),('/host/reviews','host-reviews')]),
        ('admin',[('/admin','admin'),('/admin/articles','admin-articles'),('/admin/catalog','admin-catalog')]),
    ]:
        role_context = browser.new_context(color_scheme='light')
        role_context.add_cookies([{'name':'h2h_locale','value':'en','url':BASE}])
        role_page = role_context.new_page()
        role_page.on('pageerror', lambda error: console_errors.append(str(error)))
        role_page.goto(BASE + '/login', wait_until='domcontentloaded')
        role_page.locator('#email').fill(role + '@home2home.test')
        role_page.locator('#password').fill('Password123!')
        role_page.locator('form[action$="/login"] button[type=submit]').click()
        role_page.wait_for_load_state('domcontentloaded')
        record(role + ' sign in', '/login' not in role_page.url)
        for path, name in paths:
            for width in (1440, 390):
                visit(role_page, path, f'{name}-{width}', width, screenshot=name in ('host','admin','profile','bookings'))
        role_page.set_viewport_size({'width':1440,'height':1000})
        role_page.locator('#account-menu-button').click()
        expect(role_page.locator('.account-dropdown .dropdown-menu')).to_be_visible()
        role_page.keyboard.press('Escape')
        expect(role_page.locator('.account-dropdown .dropdown-menu')).not_to_be_visible()
        record(role + ' accessible account dropdown', True)
        role_context.close()

    record('No uncaught browser errors', not console_errors, console_errors)
    browser.close()

report = {'passed': len(results)-len(failures), 'failed': len(failures), 'checks': results}
(OUT / 'verification.json').write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding='utf-8')
print(json.dumps({'passed':report['passed'], 'failures':failures, 'consoleErrors':console_errors}, indent=2, ensure_ascii=False))
raise SystemExit(1 if failures else 0)
