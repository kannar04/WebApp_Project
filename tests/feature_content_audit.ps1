param([string]$BaseUrl = 'http://127.0.0.1:8105', [string]$ScreenshotDirectory = '')
# Dark mode browser audit (headless Chrome over CDP): theme before first paint, toggle + persistence,
# prefers-color-scheme fallback, no JS errors, no horizontal overflow and in-page WCAG contrast on main pages.
$ErrorActionPreference = 'Stop'
$chromePath = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$profilePath = Join-Path $env:TEMP ('home2home-audit-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $profilePath | Out-Null
$listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, 0)
$listener.Start()
$debugPort = $listener.LocalEndpoint.Port
$listener.Stop()
$debugUrl = "http://127.0.0.1:$debugPort"
$chromeProcess = Start-Process -FilePath $chromePath -WindowStyle Hidden -PassThru -ArgumentList @('--headless=new', '--no-first-run', '--no-default-browser-check', "--remote-debugging-port=$debugPort", "--user-data-dir=$profilePath", 'about:blank')
$socket = New-Object System.Net.WebSockets.ClientWebSocket
$script:commandId = 0
$script:javascriptErrors = @()
$script:categoryCounts = @{}
$script:checkedPages = 0

# Injected before any page script on every navigation. No arrow functions by project convention.
$probeSource = @'
function installThemeProbe() {
    var original = Element.prototype.setAttribute;
    var probe = {firstSetAt: null, bodyExisted: null, value: null};
    window.__h2hThemeProbe = probe;
    function recordThemeAttribute(name, value) {
        if (probe.firstSetAt === null && name === 'data-theme' && this === document.documentElement) {
            probe.firstSetAt = performance.now();
            probe.bodyExisted = !!document.body;
            probe.value = String(value);
        }
        return original.apply(this, arguments);
    }
    Element.prototype.setAttribute = recordThemeAttribute;
    function parse(value) {
        var match = /rgba?\(([^)]+)\)/.exec(value || '');
        if (!match) { return null; }
        var parts = match[1].split(/[\s,\/]+/);
        var numbers = [];
        for (var i = 0; i < parts.length; i++) { if (parts[i] !== '') { numbers.push(parseFloat(parts[i])); } }
        return {r: numbers[0], g: numbers[1], b: numbers[2], a: numbers.length > 3 ? numbers[3] : 1};
    }
    function over(top, bottom) {
        var alpha = top.a + bottom.a * (1 - top.a);
        if (alpha === 0) { return {r: 0, g: 0, b: 0, a: 0}; }
        return {
            r: (top.r * top.a + bottom.r * bottom.a * (1 - top.a)) / alpha,
            g: (top.g * top.a + bottom.g * bottom.a * (1 - top.a)) / alpha,
            b: (top.b * top.a + bottom.b * bottom.a * (1 - top.a)) / alpha,
            a: alpha
        };
    }
    function channel(value) {
        var v = value / 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    }
    function luminance(color) { return 0.2126 * channel(color.r) + 0.7152 * channel(color.g) + 0.0722 * channel(color.b); }
    function ratio(first, second) {
        var a = luminance(first);
        var b = luminance(second);
        return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    }
    var controlSelector = 'input, select, textarea, .form-control, .custom-select, .custom-control-label';
    function background(element) {
        var layers = [];
        var node = element;
        while (node && node.nodeType === 1) {
            var style = getComputedStyle(node);
            if (style.backgroundImage !== 'none' && !node.matches(controlSelector)) { return null; }
            var color = parse(style.backgroundColor);
            if (color && color.a > 0) {
                layers.push(color);
                if (color.a >= 1) { break; }
            }
            node = node.parentElement;
        }
        var result = {r: 255, g: 255, b: 255, a: 1};
        if (document.documentElement.getAttribute('data-theme') === 'dark') { result = {r: 18, g: 24, b: 20, a: 1}; }
        for (var j = layers.length - 1; j >= 0; j--) { result = over(layers[j], result); }
        return result;
    }
    function opacity(element) {
        var value = 1;
        var node = element;
        while (node && node.nodeType === 1) { value *= parseFloat(getComputedStyle(node).opacity); node = node.parentElement; }
        return value;
    }
    function visible(element) {
        var rect = element.getBoundingClientRect();
        if (rect.width < 2 || rect.height < 2) { return false; }
        var style = getComputedStyle(element);
        return style.visibility === 'visible' && style.display !== 'none';
    }
    function category(element) {
        if (element.closest('.site-footer, .auth-footer')) { return 'footer'; }
        if (element.closest('.site-header')) { return 'navbar'; }
        if (element.closest('.alert')) { return 'alert'; }
        if (element.closest('.btn, button')) { return 'button'; }
        if (element.closest('input, select, textarea, .form-control, .custom-select, .input-group-text, .custom-control-label')) { return 'form'; }
        if (element.closest('a')) { return 'link'; }
        if (element.closest('table')) { return 'table'; }
        if (element.closest('.text-muted, small, .article-meta, .card-meta, .results-summary, .form-text')) { return 'muted'; }
        if (element.closest('.surface-card, .article-card, .card, .empty-state, .booking-card, .booking-box, .profile-card, .listing-card')) { return 'card'; }
        return 'body';
    }
    function describe(element) {
        var name = element.tagName.toLowerCase();
        if (element.id) { name += '#' + element.id; }
        if (typeof element.className === 'string' && element.className.trim() !== '') { name += '.' + element.className.trim().split(/\s+/).join('.'); }
        return name;
    }
    function ownText(element) {
        for (var i = 0; i < element.childNodes.length; i++) {
            var node = element.childNodes[i];
            if (node.nodeType === 3 && node.nodeValue.trim() !== '') { return true; }
        }
        return false;
    }
    function rgb(color) { return 'rgb(' + Math.round(color.r) + ',' + Math.round(color.g) + ',' + Math.round(color.b) + ')'; }
    function check(minimumText, minimumBoundary) {
        var failures = [];
        var counts = {};
        var elements = document.body.querySelectorAll('*');
        for (var i = 0; i < elements.length; i++) {
            var element = elements[i];
            var tag = element.tagName;
            if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'OPTION' || tag === 'NOSCRIPT' || tag === 'TEMPLATE') { continue; }
            var isControl = element.matches('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), select, textarea');
            if (!isControl && !ownText(element)) { continue; }
            if (!visible(element)) { continue; }
            if (element.matches(':disabled') || element.closest('.disabled, [aria-disabled="true"]')) { continue; }
            var back = background(element);
            if (!back) { continue; }
            var style = getComputedStyle(element);
            var fore = parse(style.color);
            if (!fore) { continue; }
            fore.a = fore.a * opacity(element);
            var value = ratio(over(fore, back), back);
            var kind = category(element);
            counts[kind] = (counts[kind] || 0) + 1;
            if (value < minimumText) {
                failures.push({kind: kind, element: describe(element), text: String(element.value || element.textContent).trim().slice(0, 40), ratio: Math.round(value * 100) / 100, color: style.color, background: rgb(back)});
            }
            if (isControl && parseFloat(style.borderTopWidth) > 0 && element.parentElement) {
                var outer = background(element.parentElement);
                var border = parse(style.borderTopColor);
                if (outer && border) {
                    var boundary = Math.max(ratio(over(border, outer), outer), ratio(back, outer));
                    counts.boundary = (counts.boundary || 0) + 1;
                    if (boundary < minimumBoundary) {
                        failures.push({kind: 'boundary', element: describe(element), text: '', ratio: Math.round(boundary * 100) / 100, color: style.borderTopColor, background: rgb(outer)});
                    }
                }
            }
        }
        var labels = document.querySelectorAll('.custom-control-label');
        for (var k = 0; k < labels.length; k++) {
            if (!visible(labels[k])) { continue; }
            var box = getComputedStyle(labels[k], '::before');
            var edge = parse(box.borderTopColor);
            var behind = background(labels[k]);
            if (!edge || !behind || parseFloat(box.borderTopWidth) === 0) { continue; }
            var fill = parse(box.backgroundColor) || behind;
            var edgeRatio = Math.max(ratio(over(edge, behind), behind), ratio(over(fill, behind), behind));
            counts.boundary = (counts.boundary || 0) + 1;
            if (edgeRatio < minimumBoundary) {
                failures.push({kind: 'boundary', element: describe(labels[k]) + '::before', text: '', ratio: Math.round(edgeRatio * 100) / 100, color: box.borderTopColor, background: rgb(behind)});
            }
        }
        var invertedImages = 0;
        var images = document.images;
        for (var m = 0; m < images.length; m++) { if (getComputedStyle(images[m]).filter !== 'none') { invertedImages++; } }
        return {failures: failures.slice(0, 12), failureCount: failures.length, counts: counts, invertedImages: invertedImages};
    }
    window.__h2hAudit = {check: check};
}
installThemeProbe();
'@

function Send-Cdp([string]$Method, [hashtable]$Parameters = @{}) {
    $script:commandId++
    $message = @{id=$script:commandId;method=$Method;params=$Parameters} | ConvertTo-Json -Depth 30 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($message)
    $timeout = New-Object Threading.CancellationTokenSource
    $timeout.CancelAfter(30000)
    try {
        $socket.SendAsync([ArraySegment[byte]]::new($bytes), [Net.WebSockets.WebSocketMessageType]::Text, $true, $timeout.Token).GetAwaiter().GetResult() | Out-Null
        while ($true) {
            $stream = New-Object IO.MemoryStream
            do {
                $buffer = New-Object byte[] 65536
                $received = $socket.ReceiveAsync([ArraySegment[byte]]::new($buffer), $timeout.Token).GetAwaiter().GetResult()
                $stream.Write($buffer, 0, $received.Count)
            } while (!$received.EndOfMessage)
            $reply = [Text.Encoding]::UTF8.GetString($stream.ToArray()) | ConvertFrom-Json
            $stream.Dispose()
            if ($reply.method -eq 'Runtime.exceptionThrown') { $script:javascriptErrors += $reply.params.exceptionDetails.text }
            if ($reply.method -eq 'Runtime.consoleAPICalled' -and $reply.params.type -eq 'error') { $script:javascriptErrors += 'console.error' }
            if ($reply.id -eq $script:commandId) {
                if ($reply.error) { throw "CDP $Method failed: $($reply.error.message)" }
                return $reply.result
            }
        }
    } finally { $timeout.Dispose() }
}

function Evaluate([string]$Expression) {
    $result = Send-Cdp 'Runtime.evaluate' @{expression=$Expression;awaitPromise=$true;returnByValue=$true}
    if ($result.exceptionDetails) { throw "Browser evaluation failed: $($result.exceptionDetails.text) $($result.exceptionDetails.exception.description)" }
    return $result.result.value
}

function Set-Width([int]$Width) {
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$Width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
}

function Navigate([string]$Path) {
    $targetUri = [Uri]($BaseUrl.TrimEnd('/') + $Path)
    Send-Cdp 'Page.navigate' @{url=$targetUri.AbsoluteUri} | Out-Null
    $expectedPath = ConvertTo-Json $targetUri.AbsolutePath -Compress
    $loaded = $false
    for ($attempt = 0; $attempt -lt 150; $attempt++) {
        Start-Sleep -Milliseconds 100
        try {
            $loaded = Evaluate "document.readyState==='complete'&&location.pathname===$expectedPath"
            if ($loaded) { break }
        } catch { if ($attempt -eq 149) { throw } }
    }
    if (!$loaded) { throw "Navigation did not finish: $Path" }
}

function Assert-ThemeBeforePaint([string]$Label, [string]$Expected) {
    $probe = Evaluate @'
function readThemeProbe() {
    var probe = window.__h2hThemeProbe || {};
    var paints = performance.getEntriesByType('paint');
    var firstPaint = paints.length ? paints[0].startTime : null;
    return {value: probe.value, bodyExisted: probe.bodyExisted, setAt: probe.firstSetAt, firstPaint: firstPaint,
        attribute: document.documentElement.getAttribute('data-theme'), scheme: getComputedStyle(document.documentElement).colorScheme};
}
readThemeProbe()
'@
    if ($null -eq $probe.setAt -or $probe.bodyExisted -or $probe.value -ne $Expected -or $probe.attribute -ne $Expected) {
        throw "$Label theme was not applied in <head> before the body: $($probe | ConvertTo-Json -Compress)"
    }
    if ($null -ne $probe.firstPaint -and $probe.setAt -gt $probe.firstPaint) { throw "$Label theme applied after first paint: $($probe | ConvertTo-Json -Compress)" }
    if ($Expected -eq 'dark' -and $probe.scheme -ne 'dark') { throw "$Label color-scheme is not dark" }
}

function Wait-Images {
    Evaluate @'
function waitForImages(resolve) {
    var deadline = Date.now() + 8000;
    function wait() {
        var images = Array.prototype.slice.call(document.images);
        var done = true;
        for (var i = 0; i < images.length; i++) { if (images[i].loading !== 'lazy' && !images[i].complete) { done = false; } }
        if (done || Date.now() > deadline) { resolve(true); } else { setTimeout(wait, 100); }
    }
    wait();
}
new Promise(waitForImages)
'@ | Out-Null
}

function Capture([string]$Name) {
    if (!$ScreenshotDirectory) { return }
    New-Item -ItemType Directory -Force -Path $ScreenshotDirectory | Out-Null
    Evaluate @'
async function eagerLoadImages() {
    var images = Array.prototype.slice.call(document.images);
    for (var i = 0; i < images.length; i++) { images[i].loading = 'eager'; }
    function settle(resolve) { setTimeout(resolve, 400); }
    await new Promise(settle);
    return true;
}
eagerLoadImages()
'@ | Out-Null
    $height = [Math]::Min([int](Evaluate 'Math.ceil(document.documentElement.scrollHeight)'), 6000)
    $width = [int](Evaluate 'window.innerWidth')
    $capture = Send-Cdp 'Page.captureScreenshot' @{format='png';captureBeyondViewport=$true;clip=@{x=0;y=0;width=$width;height=$height;scale=1}}
    [IO.File]::WriteAllBytes((Join-Path $ScreenshotDirectory ($Name + '.png')), [Convert]::FromBase64String($capture.data))
}

function Get-BrowserAuditPages([string]$RoleLabel) {
    # Reads the role page lists of tests/browser_audit.ps1 ("foreach ($path in @(...))" followed by Check-Page "<Role> ...").
    $pages = @()
    $source = Join-Path $PSScriptRoot 'browser_audit.ps1'
    if (!(Test-Path $source)) { return $pages }
    $lines = [IO.File]::ReadAllLines($source)
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -notmatch 'foreach \(\$path in @\((.+?)\)\)') { continue }
        $list = $Matches[1]
        $context = $lines[$i..([Math]::Min($i + 2, $lines.Count - 1))] -join ' '
        if ($context -notmatch ('Check-Page "' + $RoleLabel + ' ')) { continue }
        foreach ($match in [regex]::Matches($list, "'(/[^']*)'")) { $pages += $match.Groups[1].Value }
    }
    return $pages
}

function Check-DarkPage([string]$Path, [int]$Width, [string]$Label) {
    Navigate $Path
    Wait-Images
    Assert-ThemeBeforePaint "$Label $Path $Width" 'dark'
    $result = Evaluate 'window.__h2hAudit.check(4.5, 3)'
    $overflow = Evaluate 'document.documentElement.scrollWidth > window.innerWidth + 1'
    if ($overflow) { throw "$Label $Path $Width has horizontal overflow in dark mode" }
    if ($result.invertedImages -gt 0) { throw "$Label $Path $Width applies filters to images" }
    if ($result.failureCount -gt 0) { throw "$Label $Path $Width dark contrast failures: $($result.failures | ConvertTo-Json -Compress -Depth 5)" }
    foreach ($property in $result.counts.PSObject.Properties) {
        $script:categoryCounts[$property.Name] = [int]$script:categoryCounts[$property.Name] + [int]$property.Value
    }
    $script:checkedPages++
    if ($ScreenshotDirectory) {
        $name = ($Label + $Path) -replace '[^A-Za-z0-9]+', '-'
        Capture ("$name-$Width-dark")
        Evaluate "document.documentElement.setAttribute('data-theme','light');true" | Out-Null
        Capture ("$name-$Width-light")
        Evaluate "document.documentElement.setAttribute('data-theme','dark');true" | Out-Null
    }
}

function Login-Role([string]$Role) {
    $roleJson = ConvertTo-Json $Role -Compress
    return Evaluate @"
async function loginRole() {
    var form = await fetch('/login');
    var page = new DOMParser().parseFromString(await form.text(), 'text/html');
    var token = page.querySelector('[name=_token]');
    if (!token) { throw new Error('Login form unavailable'); }
    var login = await fetch('/login', {method: 'POST', body: new URLSearchParams({_token: token.value, email: $roleJson + '@home2home.test', password: 'Password123!'})});
    return new URL(login.url).pathname;
}
loginRole()
"@
}

function Logout-Role {
    Evaluate @'
async function logoutRole() {
    var token = document.querySelector('meta[name=csrf-token]');
    if (token) { await fetch('/logout', {method: 'POST', body: new URLSearchParams({_token: token.content})}); }
    return true;
}
logoutRole()
'@ | Out-Null
}

function Toggle-State {
    return Evaluate @'
function readToggleState() {
    var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-theme-toggle]'));
    var shown = null;
    for (var i = 0; i < buttons.length; i++) { var r = buttons[i].getBoundingClientRect(); if (r.width > 0 && r.height > 0) { shown = buttons[i]; } }
    var stored = null;
    try { stored = localStorage.getItem('h2h-theme'); } catch (error) { stored = 'blocked'; }
    var label = shown ? shown.querySelector('[data-theme-label]') : null;
    return {theme: document.documentElement.getAttribute('data-theme'), stored: stored, visible: !!shown,
        pressed: shown ? shown.getAttribute('aria-pressed') : null,
        name: !label ? null : (label.textContent === 'T\u1eaft ch\u1ebf \u0111\u1ed9 t\u1ed1i' ? 'off-dark' : (label.textContent === 'B\u1eadt ch\u1ebf \u0111\u1ed9 t\u1ed1i' ? 'on-dark' : label.textContent)),
        background: getComputedStyle(document.body).backgroundColor};
}
readToggleState()
'@
}

function Click-Toggle {
    Evaluate @'
function clickVisibleToggle() {
    var buttons = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < buttons.length; i++) {
        var r = buttons[i].getBoundingClientRect();
        if (r.width > 0 && r.height > 0) { buttons[i].click(); return true; }
    }
    throw new Error('No visible theme toggle');
}
clickVisibleToggle()
'@ | Out-Null
}

function Assert-ToggleState([string]$Label, [string]$Theme) {
    $state = Toggle-State
    $expectedName = if ($Theme -eq 'dark') { 'off-dark' } else { 'on-dark' }
    $expectedPressed = if ($Theme -eq 'dark') { 'true' } else { 'false' }
    if ($state.theme -ne $Theme -or !$state.visible -or $state.pressed -ne $expectedPressed -or $state.name -ne $expectedName) {
        throw "$Label toggle state mismatch: $($state | ConvertTo-Json -Compress)"
    }
    return $state
}

try {
    $target = $null
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        try { $target = Invoke-RestMethod "$debugUrl/json/new?about:blank" -Method Put; break } catch { Start-Sleep -Milliseconds 100 }
    }
    if (!$target) { throw 'Chrome debugging endpoint unavailable' }
    $socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl, [Threading.CancellationToken]::None).GetAwaiter().GetResult() | Out-Null
    Send-Cdp 'Page.enable' | Out-Null
    Send-Cdp 'Runtime.enable' | Out-Null
    Send-Cdp 'Page.addScriptToEvaluateOnNewDocument' @{source=$probeSource} | Out-Null
    Set-Width 1440

    # 1. No stored choice: prefers-color-scheme decides, and the attribute exists before the body.
    Navigate '/'
    Evaluate "localStorage.removeItem('h2h-theme');true" | Out-Null
    Send-Cdp 'Emulation.setEmulatedMedia' @{features=@(@{name='prefers-color-scheme';value='dark'})} | Out-Null
    Navigate '/articles'
    Assert-ThemeBeforePaint 'system dark preference' 'dark'
    Send-Cdp 'Emulation.setEmulatedMedia' @{features=@(@{name='prefers-color-scheme';value='light'})} | Out-Null
    Navigate '/articles'
    Assert-ThemeBeforePaint 'system light preference' 'light'
    Write-Output 'PASS theme follows prefers-color-scheme when no choice is stored, applied in <head> before first paint'

    # 2. Desktop toggle: immediate switch without reload, persisted across navigation, accessible name and aria-pressed.
    $before = Assert-ToggleState 'desktop initial' 'light'
    Evaluate 'window.__h2hNoReload = true; true' | Out-Null
    Click-Toggle
    $after = Assert-ToggleState 'desktop after click' 'dark'
    if ($after.stored -ne 'dark' -or $after.background -eq $before.background -or !(Evaluate 'window.__h2hNoReload === true')) { throw 'Desktop toggle did not switch immediately and persist' }
    Navigate '/login'
    Assert-ThemeBeforePaint 'persisted dark on auth layout' 'dark'
    Assert-ToggleState 'auth layout' 'dark' | Out-Null
    Click-Toggle
    Assert-ToggleState 'auth layout after click' 'light' | Out-Null
    Navigate '/'
    Assert-ThemeBeforePaint 'persisted light after auth toggle' 'light'
    Write-Output 'PASS desktop and auth-layout toggle switch immediately, update name/aria-pressed and persist across navigation'

    # 3. Mobile: the toggle lives inside the collapsed menu.
    Set-Width 375
    Navigate '/'
    $menuHidden = Evaluate "!!document.querySelector('#mainNav [data-theme-toggle]') && document.querySelector('#mainNav [data-theme-toggle]').getBoundingClientRect().width === 0"
    if (!$menuHidden) { throw 'Mobile toggle should be inside the collapsed navigation menu' }
    Evaluate "document.querySelector('.navbar-toggler').click();true" | Out-Null
    Start-Sleep -Milliseconds 600
    Click-Toggle
    Assert-ToggleState 'mobile menu' 'dark' | Out-Null
    Navigate '/articles'
    Assert-ThemeBeforePaint 'mobile persisted dark' 'dark'
    Write-Output 'PASS mobile menu toggle works and persists'

    # 4. Dark mode on every main page at 375 and 1440: contrast, overflow, no image filters.
    $public = @('/', '/listings/1', '/articles', '/articles?category=guide', '/articles/cam-nang-2-ngay-o-da-lat', '/articles/khong-ton-tai', '/login', '/register', '/forgot-password')
    $roles = [ordered]@{
        guest = @('/profile', '/bookings', '/wishlist', '/notifications', '/reports')
        host = @('/host', '/host/reviews', '/host/listings/create', '/host/listings/1/edit', '/host/listings/1/availability')
        admin = @('/admin', '/admin/users/3/edit', '/admin/listings/2/edit', '/admin/listings/1/preview', '/admin/catalog', '/admin/reviews', '/admin/reports', '/admin/articles', '/admin/articles/create', '/admin/articles/1/edit', '/admin/articles/1/preview')
    }
    # Dark mode spans the whole UI: every role page listed in browser_audit.ps1 (including other features' pages) is checked too.
    $roleLabels = @{ guest = 'Guest'; host = 'Host'; admin = 'Admin' }
    $sharedPages = 0
    foreach ($role in @('guest', 'host', 'admin')) {
        foreach ($page in (Get-BrowserAuditPages $roleLabels[$role])) {
            if ($page -eq '/' -or $roles[$role] -contains $page) { continue }
            $roles[$role] += $page
            $sharedPages++
        }
    }
    # Self-test of the in-page checker: a deliberately unreadable element must be reported.
    Navigate '/articles'
    $canary = Evaluate @'
function contrastCanary() {
    var probe = document.createElement('p');
    probe.textContent = 'contrast canary';
    probe.style.color = '#4a4a4a';
    probe.style.backgroundColor = '#3a3a3a';
    document.querySelector('main').appendChild(probe);
    var result = window.__h2hAudit.check(4.5, 3);
    probe.remove();
    var found = false;
    for (var i = 0; i < result.failures.length; i++) { if (result.failures[i].text === 'contrast canary') { found = true; } }
    return found;
}
contrastCanary()
'@
    if (!$canary) { throw 'Contrast checker did not detect a known low-contrast element' }
    foreach ($width in @(375, 1440)) {
        Set-Width $width
        foreach ($path in $public) { Check-DarkPage $path $width 'public' }
    }
    Write-Output "PASS dark public pages at 375/1440: contrast >=4.5:1 text, >=3:1 control boundaries, no overflow"
    foreach ($role in $roles.Keys) {
        Set-Width 1440
        Navigate '/'
        $landing = Login-Role $role
        if ($landing -notlike '/*') { throw "$role login failed" }
        foreach ($width in @(375, 1440)) {
            Set-Width $width
            foreach ($path in $roles[$role]) { Check-DarkPage $path $width $role }
            if ($role -eq 'guest') {
                Navigate '/bookings'
                $bookingPath = Evaluate "document.querySelector('a[href*=\'/bookings/\']') ? new URL(document.querySelector('a[href*=\'/bookings/\']').href).pathname : ''"
                if ($bookingPath) { Check-DarkPage $bookingPath $width $role }
            }
        }
        Set-Width 375
        Navigate $roles[$role][0]
        Evaluate "document.querySelector('.navbar-toggler').click();true" | Out-Null
        Start-Sleep -Milliseconds 600
        $menu = Evaluate 'window.__h2hAudit.check(4.5, 3)'
        if ($menu.failureCount -gt 0) { throw "$role open mobile menu contrast failures: $($menu.failures | ConvertTo-Json -Compress -Depth 5)" }
        Logout-Role
        Write-Output "PASS dark $role pages at 375/1440 including the open mobile menu ($($roles[$role].Count) pages)"
    }
    foreach ($kind in @('body', 'muted', 'link', 'button', 'form', 'table', 'alert', 'card', 'navbar', 'footer', 'boundary')) {
        if ([int]$script:categoryCounts[$kind] -lt 1) { throw "Contrast audit never measured category: $kind" }
    }
    Write-Output "PASS contrast measured for body, muted, links, buttons, forms, tables, alerts, cards, navbar, footer and control boundaries on $($script:checkedPages) page views ($sharedPages role pages taken from browser_audit.ps1)"
    if ($script:javascriptErrors.Count) { throw "Browser reported $($script:javascriptErrors.Count) JavaScript errors: $($script:javascriptErrors -join '; ')" }
    Write-Output 'PASS dark mode audit: no JavaScript errors'
} finally {
    try { Send-Cdp 'Browser.close' | Out-Null } catch {}
    $socket.Dispose()
    if (!$chromeProcess.HasExited) { Stop-Process -Id $chromeProcess.Id -ErrorAction SilentlyContinue }
    Write-Output "Browser profile retained for inspection: $profilePath"
}
