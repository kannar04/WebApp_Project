param([string]$BaseUrl = 'http://127.0.0.1:8090', [string]$ScreenshotDirectory = '', [switch]$VisualBaseline, [switch]$VisualUX)
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

function Send-Cdp([string]$Method, [hashtable]$Parameters = @{}) {
    $script:commandId++
    $message = @{id=$script:commandId;method=$Method;params=$Parameters} | ConvertTo-Json -Depth 30 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($message)
    $timeout = New-Object Threading.CancellationTokenSource
    $timeout.CancelAfter(20000)
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
            if ($reply.method -eq 'Runtime.exceptionThrown') {
                $script:javascriptErrors += $reply.params.exceptionDetails.text
            }
            if ($reply.id -eq $script:commandId) {
                if ($reply.error) { throw "CDP $Method failed" }
                return $reply.result
            }
        }
    } finally { $timeout.Dispose() }
}

function Evaluate([string]$Expression) {
    $result = Send-Cdp 'Runtime.evaluate' @{expression=$Expression;awaitPromise=$true;returnByValue=$true}
    if ($result.exceptionDetails) { throw "Browser evaluation failed: $($result.exceptionDetails.text)" }
    return $result.result.value
}

function Navigate([string]$Path) {
    if ($Path -match '^https?://') { $Path=([Uri]$Path).PathAndQuery }
    $targetUri=[Uri]($BaseUrl.TrimEnd('/')+$Path)
    Send-Cdp 'Page.navigate' @{url=$targetUri.AbsoluteUri} | Out-Null
    $expectedPath=ConvertTo-Json $targetUri.AbsolutePath -Compress
    $expectedSearch=ConvertTo-Json $targetUri.Query -Compress
    $expectedHash=ConvertTo-Json $targetUri.Fragment -Compress
    $loaded=$false
    for($attempt=0;$attempt -lt 150;$attempt++) {
        Start-Sleep -Milliseconds 100
        try {
            $loaded=Evaluate "document.readyState==='complete'&&location.pathname===$expectedPath&&location.search===$expectedSearch&&location.hash===$expectedHash"
            if($loaded){break}
        } catch { if($attempt -eq 149){throw} }
    }
    if(!$loaded){throw "Navigation did not finish: $Path"}
    Evaluate "new Promise(resolve => { const deadline=Date.now()+8000; const wait=()=>Array.from(document.images).filter(i=>i.loading!=='lazy').every(i=>i.complete)||Date.now()>deadline ? resolve(true):setTimeout(wait,100);wait();})" | Out-Null
}

function Check-Page([string]$Label) {
    # Off-screen lazy images are not broken until observed. Scroll each into view and retain the original position.
    Evaluate '(async()=>{const previous=scrollY;for(const img of Array.from(document.images).filter(i=>i.loading==="lazy"&&!i.complete)){img.scrollIntoView({block:"center"});await new Promise(resolve=>{const deadline=Date.now()+8000;const wait=()=>img.complete||Date.now()>deadline?resolve(true):setTimeout(wait,100);wait();});}scrollTo(0,previous);return true;})()' | Out-Null
    $metrics = Evaluate "(() => {const ids=Array.from(document.querySelectorAll('[id]')).map(e=>e.id);return {status:performance.getEntriesByType('navigation')[0]?.responseStatus||0,overflow:document.documentElement.scrollWidth>innerWidth+1,broken:Array.from(document.images).filter(i=>!i.complete||i.naturalWidth===0).length,duplicateIds:ids.length-new Set(ids).size,phpError:/Warning:|Fatal error:|Parse error:/.test(document.body.innerText)}})()"
    if ($metrics.status -ne 200 -or $metrics.overflow -or $metrics.broken -or $metrics.duplicateIds -or $metrics.phpError) {
        if ($metrics.overflow) {
            $overflowItems=Evaluate "Array.from(document.querySelectorAll('body *')).filter(e=>{const r=e.getBoundingClientRect();return r.width>0&&(r.right>innerWidth+1||r.left< -1)}).slice(0,12).map(e=>({tag:e.tagName,id:e.id,className:e.className,width:e.getBoundingClientRect().width}))"
            Write-Output ($overflowItems | ConvertTo-Json -Compress)
        }
        throw "$Label failed: $($metrics | ConvertTo-Json -Compress)"
    }
    Write-Output "PASS browser $Label"
}

function Login-Role([string]$Role) {
    $roleJson = ConvertTo-Json $Role -Compress
    $result = Evaluate "(async()=>{const response=await fetch('/login?next='+($roleJson==='guest'?'/':'/'+$roleJson));const html=await response.text();const page=new DOMParser().parseFromString(html,'text/html');const token=page.querySelector('[name=_token]')?.value;if(!token)throw Error('Login form unavailable');const login=await fetch('/login',{method:'POST',body:new URLSearchParams({_token:token,email:$roleJson+'@home2home.test',password:'Password123!'})});return new URL(login.url).pathname;})()"
    return $result
}

function Logout-Role {
    Evaluate "(async()=>{const token=document.querySelector('meta[name=csrf-token]').content;await fetch('/logout',{method:'POST',body:new URLSearchParams({_token:token})});return true;})()" | Out-Null
}

function Capture-Page([string]$Name) {
    if (!$ScreenshotDirectory) { return }
    New-Item -ItemType Directory -Force -Path $ScreenshotDirectory | Out-Null
    if ($Name -like 'host-calendar-*') { Evaluate 'document.querySelector(''#availability-calendar'').closest(''section'').scrollIntoView();true' | Out-Null }
    $captureParameters=@{format='png';captureBeyondViewport=$false}
    if ($Name -like 'home-filter-*') {
        $clip=Evaluate '(() => {const form=document.querySelector(''[data-filter-form]'')||document.querySelector(''form.filter-row'');return {x:0,y:scrollY,width:innerWidth,height:Math.max(innerHeight,form.getBoundingClientRect().height+120),scale:1};})()'
        $captureParameters.captureBeyondViewport=$true
        $captureParameters.clip=@{x=$clip.x;y=$clip.y;width=$clip.width;height=$clip.height;scale=1}
    }
    $capture=Send-Cdp 'Page.captureScreenshot' $captureParameters
    [IO.File]::WriteAllBytes((Join-Path $ScreenshotDirectory ($Name+'.png')), [Convert]::FromBase64String($capture.data))
}

function Check-CalendarNavigation([string]$Path) {
    Navigate $Path
    $next=Evaluate 'document.querySelector(''#availability-calendar'').closest(''section'').querySelectorAll(''a'')[1].getAttribute(''href'')'
    $nextUri=if ($next -match '^https?://') { [Uri]$next } else { [Uri]($BaseUrl.TrimEnd('/')+$next) }
    $month=$nextUri.Query -replace '^\?month=',''
    Evaluate 'document.querySelector(''#availability-calendar'').closest(''section'').querySelectorAll(''a'')[1].click();true' | Out-Null
    Start-Sleep -Milliseconds 700
    $expected=ConvertTo-Json $month -Compress
    if (!(Evaluate "document.querySelector('.calendar-day')?.dataset.date.startsWith($expected)")) { throw "Calendar navigation failed: $Path" }
    Check-Page "calendar next-month $Path"
}

try {
    $target = $null
    for ($attempt=0; $attempt -lt 40; $attempt++) {
        try { $target=Invoke-RestMethod "$debugUrl/json/new?about:blank" -Method Put; break } catch { Start-Sleep -Milliseconds 100 }
    }
    if (!$target) { throw 'Chrome debugging endpoint unavailable' }
    $socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl, [Threading.CancellationToken]::None).GetAwaiter().GetResult() | Out-Null
    Send-Cdp 'Page.enable' | Out-Null
    Send-Cdp 'Runtime.enable' | Out-Null
    if ($VisualBaseline -or $VisualUX) { . (Join-Path $PSScriptRoot 'visual_ux_cases.ps1') }
    if ($VisualBaseline) { Test-VisualPages 'before'; return }
    if ($VisualUX) { Test-FilterJourneys }
    foreach ($width in @(375,768,1024,1440)) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null | Out-Null
        Navigate '/'
        Check-Page "home $width"
        if ($width -eq 375 -or $width -eq 1440) { Capture-Page "guest-home-$width" }
        Navigate '/listings/2'
        Check-Page "detail $width"
    }
    $syntax = Evaluate "(async()=>{new Function(await(await fetch('/assets/js/app.js')).text());return true;})()"
    if (!$syntax) { throw 'JavaScript syntax check failed' }
    Write-Output 'PASS JavaScript syntax parsed by Chrome V8'
    $cssCheck = Evaluate "(async()=>{const source=await(await fetch('/assets/css/app.css')).text();const declarations=Array.from(source.matchAll(/(?:[{;])\s*([-\w]+)\s*:\s*([^;}]+)/g));return {count:declarations.length,invalid:declarations.filter(match=>!match[1].startsWith('--')&&!CSS.supports(match[1],match[2].replace(/\s*!important\s*$/i,'').trim())).map(match=>match[1])};})()"
    if ($cssCheck.count -eq 0 -or $cssCheck.invalid.Count) { throw "CSS validation failed: $($cssCheck | ConvertTo-Json -Compress)" }
    Write-Output 'PASS CSS declarations supported by Chrome'

    $quote = Evaluate "(async()=>{const form=document.querySelector('[data-booking-form]');form.elements.check_in.value='2035-02-10';form.elements.check_out.value='2035-02-12';form.elements.guests.value='2';form.elements.check_out.dispatchEvent(new Event('input',{bubbles:true}));await new Promise(r=>setTimeout(r,900));return document.querySelector('[data-quote]').innerText.includes('4.400.000');})()"
    if (!$quote) { throw 'Live browser AJAX quote failed' }
    Write-Output 'PASS browser live AJAX quote'

    $race = Evaluate "(async()=>{const originalFetch=window.fetch;const form=document.querySelector('[data-booking-form]');const panel=form.querySelector('[data-quote]');try{window.fetch=url=>new Promise(resolve=>{const early=url.includes('2035-03-10');setTimeout(()=>resolve({ok:true,json:async()=>({success:true,data:{nights:2,nightly_price:1,fee:0,total:early?111:222}})}),early?1000:50)});form.elements.check_in.value='2035-03-10';form.elements.check_out.value='2035-03-12';form.elements.check_out.dispatchEvent(new Event('input'));await new Promise(r=>setTimeout(r,350));form.elements.check_in.value='2035-03-20';form.elements.check_out.value='2035-03-22';form.elements.check_out.dispatchEvent(new Event('input'));await new Promise(r=>setTimeout(r,1300));return panel.innerText.includes('222')&&!panel.innerText.includes('111');}finally{window.fetch=originalFetch;}})()"
    if (!$race) { throw 'Stale quote response overwrote new dates' }
    Write-Output 'PASS browser slow-response ordering'
    $quoteRecovery = Evaluate "(async()=>{const form=document.querySelector('[data-booking-form]');const panel=form.querySelector('[data-quote]');form.elements.check_in.value='2035-02-12';form.elements.check_out.value='2035-02-10';form.elements.check_out.dispatchEvent(new Event('input'));await new Promise(r=>setTimeout(r,700));if(!panel.classList.contains('text-danger'))return false;form.elements.check_out.value='2035-02-14';form.elements.check_out.dispatchEvent(new Event('input'));await new Promise(r=>setTimeout(r,700));if(panel.classList.contains('text-danger')||!panel.innerText.includes('4.400.000'))return false;form.elements.check_in.value='';form.elements.check_in.dispatchEvent(new Event('input'));return !panel.querySelector('strong')&&!panel.innerText.includes('4.400.000');})()"
    if (!$quoteRecovery) { throw 'Quote error recovery/reset failed' }
    Write-Output 'PASS quote error recovery and clearing stale totals'

    Navigate '/login'
    Check-Page 'login'
    if ((Login-Role 'guest') -ne '/') { throw 'Guest login redirect failed' }
    foreach ($width in @(375,768,1024,1440)) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
        foreach ($path in @('/profile','/bookings','/wishlist','/notifications','/reports','/')) { Navigate $path; Check-Page "Guest $path $width" }
    }
    $favorite = Evaluate "(async()=>{const button=document.querySelector('[data-favorite]');const before=button.classList.contains('is-saved');button.click();await new Promise(r=>setTimeout(r,500));const changed=button.classList.contains('is-saved')!==before;button.click();await new Promise(r=>setTimeout(r,500));return changed&&button.classList.contains('is-saved')===before&&!button.disabled;})()"
    if (!$favorite) { throw 'Favorite toggle failed' }
    Write-Output 'PASS browser favorite toggle and restore'
    Navigate '/bookings'
    $bookingPath=Evaluate 'document.querySelector(''a[href*="/bookings/"]'')?.getAttribute(''href'')'
    if ($bookingPath) {
        foreach ($width in @(375,1440)) {
            Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
            Navigate $bookingPath; Check-Page "Guest booking-detail $width"; Capture-Page "guest-booking-$width"
        }
    }
    Check-CalendarNavigation '/listings/1'
    Logout-Role
    if ((Login-Role 'host') -ne '/host') { throw 'Host login redirect failed' }
    foreach ($width in @(375,768,1024,1440)) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
        foreach ($path in @('/host','/host/reviews','/host/listings/1/edit','/host/listings/1/availability')) { Navigate $path; Check-Page "Host $path $width" }
        if ($width -eq 375 -or $width -eq 1440) { Capture-Page "host-calendar-$width" }
    }
    Check-CalendarNavigation '/host/listings/1/availability'
    Logout-Role
    if ((Login-Role 'admin') -ne '/admin') { throw 'Admin login redirect failed' }
    foreach ($width in @(375,768,1024,1440)) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
        foreach ($path in @('/admin','/admin/users/3/edit','/admin/listings/2/edit','/admin/listings/1/preview','/admin/catalog','/admin/reviews','/admin/reports')) {
            Navigate $path; Check-Page "Admin $path $width"
            if ($path -eq '/admin' -and $width -eq 375) {
                $scrollable=Evaluate 'Array.from(document.querySelectorAll(''.table-responsive'')).filter(e=>e.scrollWidth>e.clientWidth).every(e=>{e.scrollLeft=50;const ok=e.scrollLeft>0;e.scrollLeft=0;return ok})'
                if (!$scrollable) { throw 'Mobile Admin table cannot be scrolled horizontally' }
                Write-Output 'PASS mobile Admin table scrolling without page overflow'
            }
            if ($path -eq '/admin' -and ($width -eq 375 -or $width -eq 1440)) { Capture-Page "admin-dashboard-$width" }
        }
    }
    Check-CalendarNavigation '/admin/listings/1/preview'
    if ($VisualUX) { Test-VisualPages 'after' }
    if ($script:javascriptErrors.Count) { throw "Browser reported $($script:javascriptErrors.Count) JavaScript exceptions" }
    Write-Output 'PASS no browser JavaScript exceptions'
} finally {
    try { Send-Cdp 'Browser.close' | Out-Null } catch {}
    $socket.Dispose()
    if (!$chromeProcess.HasExited) { Stop-Process -Id $chromeProcess.Id -ErrorAction SilentlyContinue }
    Write-Output "Browser profile retained for inspection: $profilePath"
}
