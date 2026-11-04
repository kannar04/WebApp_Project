"""Optional browser checks: Python Playwright and installed Google Chrome required.

Run: python tests/schema-canvas.browser.py
Uses an isolated headless browser; never changes the user's saved layout.
"""
import json
import tempfile
from pathlib import Path
from playwright.sync_api import sync_playwright

url = (Path(__file__).resolve().parent.parent / 'database-schema.html').as_uri()
with sync_playwright() as playwright:
    browser = playwright.chromium.launch(channel='chrome', headless=True)
    context = browser.new_context(viewport={'width': 1440, 'height': 960}, has_touch=True)
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.goto(url)
    page.locator('#toggle-physics').click()
    assert page.locator('#toggle-physics').get_attribute('aria-pressed') == 'false'
    page.locator('#node-list button[data-name="bookings"]').click()
    card = page.locator('.canvas-node[data-name="bookings"]')

    def position():
        return card.evaluate('(el)=>({x:parseFloat(el.style.left),y:parseFloat(el.style.top)})')

    before = position()
    box = card.locator('.node-fields').bounding_box()
    scale = page.locator('#canvas-world').evaluate('(el)=>new DOMMatrix(getComputedStyle(el).transform).a')
    page.mouse.move(box['x'] + 70, box['y'] + 35)
    page.mouse.down()
    assert 'held' in card.get_attribute('class')
    page.mouse.move(box['x'] + 130, box['y'] + 65, steps=8)
    page.mouse.up()
    after = position()
    assert abs(after['x'] - before['x'] - 60 / scale) < .02
    assert abs(after['y'] - before['y'] - 30 / scale) < .02
    assert 'held' not in card.get_attribute('class')

    card.locator('.pin-node').click()
    pinned = position()
    all_before = page.locator('.canvas-node').evaluate_all('(els)=>els.map(el=>[el.style.left,el.style.top])')
    page.locator('#toggle-physics').click()
    page.wait_for_timeout(900)
    assert position() == pinned
    all_after = page.locator('.canvas-node').evaluate_all('(els)=>els.map(el=>[el.style.left,el.style.top])')
    assert all_before != all_after, 'Graph should move when resumed'
    page.locator('#toggle-physics').click()
    card.locator('.details-node').click()
    assert page.locator('#schema-dialog').is_visible()
    assert 'bigint unsigned' in page.locator('#dialog-body').inner_text()
    assert 'datetime(6)' in page.locator('#dialog-body').inner_text()
    page.locator('#dialog-body a[href="#table-users"]').click()
    assert page.locator('#dialog-title').inner_text() == 'users'
    page.locator('#close-details').click()

    page.locator('#node-list button[data-name="bookings"]').click()
    box = card.locator('.node-fields').bounding_box()
    world_before = page.locator('#canvas-world').get_attribute('style')
    pinned_before = position()
    page.keyboard.down('Space')
    page.mouse.move(box['x'] + 50, box['y'] + 25)
    page.mouse.down()
    page.mouse.move(box['x'] + 100, box['y'] + 45, steps=6)
    page.mouse.up()
    page.keyboard.up('Space')
    assert position() == pinned_before, 'Space-drag should pan without moving the card'
    assert page.locator('#canvas-world').get_attribute('style') != world_before
    zoom_before = page.locator('#zoom-value').inner_text()
    page.mouse.wheel(0, -100)
    page.wait_for_timeout(100)
    assert page.locator('#zoom-value').inner_text() != zoom_before

    page.locator('#canvas-search').fill('nothingmatcheszz')
    assert page.locator('#canvas-results').inner_text() == '0 of 22 tables'
    page.locator('#canvas-search').fill('')
    page.locator('#open-notes').click()
    assert page.locator('#scope').is_visible()
    assert 'MySQL 8.4' in page.locator('#schema').inner_text()
    page.locator('#back-to-canvas').click()
    page.wait_for_timeout(400)
    saved = position()
    page.reload()
    assert position() == saved
    assert card.locator('.pin-node').get_attribute('aria-pressed') == 'true'
    assert page.locator('#toggle-physics').get_attribute('aria-pressed') == 'false'

    page.set_viewport_size({'width': 390, 'height': 844})
    page.locator('#toggle-tables').click()
    assert page.locator('.workspace-sidebar').is_visible()
    page.locator('#node-list button[data-name="users"]').click()
    assert not page.locator('.workspace-sidebar').is_visible()
    cdp = context.new_cdp_session(page)

    def touch(kind, points):
        cdp.send('Input.dispatchTouchEvent', {
            'type': kind,
            'touchPoints': [{'x': x, 'y': y, 'id': i} for i, (x, y) in enumerate(points)]
        })

    zoom_before = page.locator('#zoom-value').inner_text()
    touch('touchStart', [(150, 300)])
    touch('touchStart', [(150, 300), (230, 300)])
    touch('touchMove', [(100, 300), (280, 300)])
    touch('touchEnd', [])
    assert page.locator('#zoom-value').inner_text() != zoom_before, 'Pinch should zoom'
    assert page.locator('.canvas-node.held').count() == 0
    page.set_viewport_size({'width': 1440, 'height': 960})
    page.locator('#fit-canvas').click()
    screenshot = Path(tempfile.gettempdir()) / 'home2home-mysql-canvas.png'
    page.screenshot(path=str(screenshot))

    reduced = browser.new_context(reduced_motion='reduce')
    reduced_page = reduced.new_page()
    reduced_page.goto(url)
    assert reduced_page.locator('#toggle-physics').get_attribute('aria-pressed') == 'false'
    assert not errors, errors
    print(json.dumps({'checks': 'drag, live motion, pin, pan, zoom, details, search, persistence, mobile, pinch, reduced motion', 'result': 'passed', 'page_errors': errors, 'screenshot': str(screenshot)}))
    browser.close()
