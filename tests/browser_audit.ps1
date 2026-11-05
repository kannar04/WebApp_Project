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

function Wait-FilterPage([string]$Condition) {
    for ($attempt=0; $attempt -lt 100; $attempt++) {
        Start-Sleep -Milliseconds 100
        try { if (Evaluate "document.readyState==='complete'&&($Condition)") { return } } catch { if ($attempt -eq 99) { throw } }
    }
    throw "Filter page did not settle: $Condition"
}

function Check-FilterResubmit {
    # Identical filters change only the #fragment: nothing loads, so the button stays usable and the browser still jumps to the results.
    Navigate '/'
    Evaluate "window.h2hSamePage=true;document.querySelector('[data-filter-submit]').click();true" | Out-Null
    Wait-FilterPage "!window.h2hSamePage&&location.hash==='#listing-results'"
    foreach ($attempt in 1,2) {
        $resubmit=Evaluate "(async()=>{window.h2hSamePage=true;scrollTo({top:0,behavior:'instant'});const form=document.querySelector('[data-filter-form]');const button=form.querySelector('[data-filter-submit]');const idle=button.textContent;button.click();await new Promise(r=>setTimeout(r,800));return {same:window.h2hSamePage===true,disabled:button.disabled,busy:form.getAttribute('aria-busy'),guard:form.dataset.submitting||'',kept:button.textContent===idle,scrolled:scrollY>0};})()"
        if (!$resubmit.same -or $resubmit.disabled -or $resubmit.busy -or $resubmit.guard -or !$resubmit.kept -or !$resubmit.scrolled) { throw "Identical filter resubmit $attempt locked the form: $($resubmit | ConvertTo-Json -Compress)" }
    }
    # A real navigation keeps the busy state and the double-submit guard; Back must release it.
    $changed=Evaluate "(()=>{window.h2hSamePage=true;const form=document.querySelector('[data-filter-form]');form.elements.min_price.value='1000';const button=form.querySelector('[data-filter-submit]');button.click();const again=new Event('submit',{cancelable:true});form.dispatchEvent(again);return {busy:form.getAttribute('aria-busy'),disabled:button.disabled,second:again.defaultPrevented};})()"
    if ($changed.busy -ne 'true' -or !$changed.disabled -or !$changed.second) { throw "Changed filter lost its submitting guard: $($changed | ConvertTo-Json -Compress)" }
    Wait-FilterPage "!window.h2hSamePage&&location.search.includes('min_price=1000')"
    if (Evaluate "document.querySelector('[data-filter-submit]').disabled") { throw 'Changed filter left the button disabled after loading' }
    $history=Send-Cdp 'Page.getNavigationHistory'
    Send-Cdp 'Page.navigateToHistoryEntry' @{entryId=$history.entries[$history.currentIndex-1].id} | Out-Null
    Wait-FilterPage "!location.search.includes('min_price=1000')"
    if (Evaluate "(()=>{const f=document.querySelector('[data-filter-form]');return f.dataset.submitting==='true'||f.querySelector('[data-filter-submit]').disabled;})()") { throw 'Browser Back left the filter locked' }
    Write-Output 'PASS browser filter resubmit, changed filter guard and Back'
}

function Check-SubmitGuards([string]$BookingPath) {
    # Events only, so nothing is posted: the guard must let one submit through, stop the repeat, and release on pageshow.
    Navigate '/reports?listing=2'
    $report=Evaluate "(()=>{const form=document.querySelector('[data-report-form]');const button=form.querySelector('button[type=submit]');const idle=button.textContent;const first=new Event('submit',{cancelable:true});form.dispatchEvent(first);const second=new Event('submit',{cancelable:true});form.dispatchEvent(second);const locked=button.disabled;window.dispatchEvent(new Event('pageshow'));return {first:first.defaultPrevented,second:second.defaultPrevented,locked,released:!button.disabled&&!form.dataset.submitting,label:button.textContent===idle};})()"
    if ($report.first -or !$report.second -or !$report.locked -or !$report.released -or !$report.label) { throw "Report submit guard failed: $($report | ConvertTo-Json -Compress)" }
    Write-Output 'PASS browser report form submit guard and release'
    if (!$BookingPath) { Write-Output 'SKIP browser message guard: no guest booking'; return }
    Navigate $BookingPath
    if (!(Evaluate "!!document.querySelector('[data-message-form]')")) { Write-Output 'SKIP browser message guard: first booking has a closed thread'; return }
    $shortcut="f.elements.body.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',ctrlKey:true,bubbles:true,cancelable:true}))"
    $message=Evaluate "(()=>{const f=document.querySelector('[data-message-form]');const b=f.querySelector('button[type=submit]');let sent=0;f.addEventListener('submit',e=>{if(!e.defaultPrevented){sent++;}e.preventDefault();});f.elements.body.value='guard check';$shortcut;$shortcut;b.click();const once=sent;const locked=b.disabled;window.dispatchEvent(new Event('pageshow'));const released=!b.disabled&&!f.dataset.sending;$shortcut;return {once,locked,released,retried:sent,kept:f.elements.body.value};})()"
    if ($message.once -ne 1 -or !$message.locked -or !$message.released -or $message.retried -ne 2 -or $message.kept -ne 'guard check') { throw "Message sending guard failed: $($message | ConvertTo-Json -Compress)" }
    Write-Output 'PASS browser message guard covers button and Ctrl+Enter, released for retry with the text kept'
}

function Check-SubmitStopRelease([string]$Selector, [string]$Path, [string]$Fill) {
    # Pressing Stop fires no event, so the search and booking buttons must come back on the timer. Events only, so nothing is sent.
    Navigate $Path
    $selectorJson=ConvertTo-Json $Selector -Compress
    $release=Evaluate "(function(){const f=document.querySelector($selectorJson);$Fill;const b=f.querySelector('button[type=submit]');const idle=b.textContent;const realTimeout=window.setTimeout;const timers=[];window.setTimeout=function(callback,delay,form){if(delay===20000){timers.push({callback,form});return 0;}return realTimeout(callback,delay,form);};const send=function(){const e=new Event('submit',{cancelable:true});f.dispatchEvent(e);return e.defaultPrevented;};const first=send();const second=send();window.setTimeout=realTimeout;const locked=b.disabled&&f.dataset.submitting==='true';const armed=timers.length;timers[0].callback(timers[0].form);const released=!b.disabled&&!f.dataset.submitting&&!f.hasAttribute('aria-busy')&&b.textContent===idle;return {first,second,locked,armed,released};})()"
    if ($release.first -or !$release.second -or !$release.locked -or $release.armed -ne 1 -or !$release.released) { throw "Submit guard has no timer release on $Path`: $($release | ConvertTo-Json -Compress)" }
}

function Check-BookingRefusal {
    # The promo code does not exist, so nothing is booked: the form comes back filled and the quote panel matches it.
    Navigate '/listings/1'
    Evaluate "(function(){const f=document.querySelector('[data-booking-form]');f.elements.check_in.value='2035-05-10';f.elements.check_out.value='2035-05-12';f.elements.guests.value='2';f.elements.promo_code.value='NOSUCHCODE';f.querySelector('button[type=submit]').click();return true;})()" | Out-Null
    Wait-FilterPage "!!document.querySelector('main .alert-danger')&&!!document.querySelector('[data-quote] .is-total')"
    $kept=Evaluate "(function(){const f=document.querySelector('[data-booking-form]');return {path:location.pathname,checkIn:f.elements.check_in.value,checkOut:f.elements.check_out.value,guests:f.elements.guests.value,promo:f.elements.promo_code.value,invalid:f.querySelector('[data-promo-feedback]').classList.contains('is-invalid'),ready:!f.querySelector('button[type=submit]').disabled};})()"
    if ($kept.path -ne '/listings/1' -or $kept.checkIn -ne '2035-05-10' -or $kept.checkOut -ne '2035-05-12' -or $kept.guests -ne '2' -or $kept.promo -ne 'NOSUCHCODE' -or !$kept.invalid -or !$kept.ready) { throw "Refused booking lost its input or quote: $($kept | ConvertTo-Json -Compress)" }
    Write-Output 'PASS browser refused booking keeps its input and quotes it again on load'
}

function Check-BookingDateWidth {
    # Below 145 px a date input clips the year, as it did at 375 (137 px) and 1024 (117 px).
    foreach ($width in @(375,768,1024,1440)) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
        Navigate '/listings/1'
        $narrowest=Evaluate "Math.min(document.querySelector('#check_in').getBoundingClientRect().width,document.querySelector('#check_out').getBoundingClientRect().width)"
        if ($narrowest -lt 145) { throw "Booking date input is $narrowest px wide at $width" }
    }
    Write-Output 'PASS browser booking date inputs show the whole year at 375, 768, 1024 and 1440'
}

function Check-AdminFilterCarry {
    # Submitting one dashboard form used to wipe the other's filter.
    Navigate '/admin?q=guest&booking_status=pending'
    Evaluate "(function(){document.querySelector('#booking-query').value='Da Lat';document.querySelector('#booking-query').form.requestSubmit();return true;})()" | Out-Null
    Wait-FilterPage "(function(){const s=new URLSearchParams(location.search);return s.get('q')==='guest'&&s.get('booking_q')==='Da Lat'&&s.get('booking_status')==='pending'&&document.querySelector('#admin-user-search').value==='guest'&&document.querySelector('#booking-query').value==='Da Lat';})()"
    Evaluate "(function(){document.querySelector('#admin-user-search').value='host';document.querySelector('#admin-user-search').form.requestSubmit();return true;})()" | Out-Null
    Wait-FilterPage "(function(){const s=new URLSearchParams(location.search);return s.get('q')==='host'&&s.get('booking_q')==='Da Lat'&&s.get('booking_status')==='pending'&&document.querySelector('#booking-status').value==='pending';})()"
    Write-Output 'PASS browser admin user search and booking filter keep each other'
}

function Check-LongText([string]$Path, [string]$Selector, [switch]$IfPresent) {
    # A pasted URL in a title or description must wrap at 375 instead of widening the page. Put into the real elements, so no fixture rows are needed.
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=375;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
    Navigate $Path
    $selectorJson=ConvertTo-Json $Selector -Compress
    $long=Evaluate "(function(){const found=document.querySelectorAll($selectorJson);for(const el of found){el.textContent='https://example.com/'+'a'.repeat(105);}const root=document.documentElement;return {found:found.length,over:root.scrollWidth-root.clientWidth};})()"
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
    if ($long.found -lt 1 -and !$IfPresent) { throw "No $Selector on $Path to test long text with" }
    if ($long.over -gt 0) { throw "Long text widens $Path by $($long.over) px at 375" }
}

function Check-LongReport {
    # The same through a real report, which /reports then lists and /admin/reports gets checked with.
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=375;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
    Navigate '/reports?listing=2'
    Evaluate "(function(){const f=document.querySelector('[data-report-form]');f.elements.category.value='other';f.elements.description.value='https://example.com/'+'a'.repeat(105);f.requestSubmit();return true;})()" | Out-Null
    Wait-FilterPage "!document.querySelector('[data-report-form]')&&!!document.querySelector('article.surface-card')&&document.documentElement.scrollWidth<=document.documentElement.clientWidth"
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
}

function Check-FlashReveal {
    # A refused save redirects to /profile#personal: the result sits on screen below the navbar with focus, while a plain fragment link keeps its scroll.
    foreach ($width in @(375,1440)) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
        Navigate '/profile'
        Evaluate "(function(){const f=document.querySelector('#personal').closest('section,form').querySelector('[name=full_name]').form;f.elements.full_name.required=false;f.elements.full_name.value='';f.submit();return true;})()" | Out-Null
        Wait-FilterPage "location.hash==='#personal'&&(function(){const a=document.querySelector('[data-flash]');if(!a||document.activeElement!==a||a.nextElementSibling!==document.querySelector('#personal')){return false;}const r=a.getBoundingClientRect();return r.top>=document.querySelector('.site-header').getBoundingClientRect().bottom-1&&r.bottom<=innerHeight;})()"
        Navigate '/wishlist'
        Navigate '/profile#verification'
        Wait-FilterPage "scrollY>100&&!document.querySelector('[data-flash]')&&document.activeElement===document.body"
    }
    Write-Output 'PASS browser result message shown and focused after a fragment redirect, plain fragment scroll kept'
}

function Check-MessageFlash([string]$BookingPath) {
    # A message result used to land thousands of pixels above the thread. This one is refused, so nothing is stored: the result, the draft and the form must all be on screen.
    if (!$BookingPath) { Write-Output 'SKIP browser message result landing: no guest booking'; return }
    foreach ($size in @(@(375,700),@(1440,900))) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$size[0];height=$size[1];deviceScaleFactor=1;mobile=$false} | Out-Null
        Navigate $BookingPath
        if (!(Evaluate "!!document.querySelector('[data-message-form]')")) { Write-Output 'SKIP browser message result landing: first booking has a closed thread'; return }
        Evaluate "(function(){const b=document.querySelector('#message-body');b.removeAttribute('maxlength');b.value='x'.repeat(2001);b.form.submit();return true;})()" | Out-Null
        Wait-FilterPage "location.hash==='#booking-messages'&&(function(){const a=document.querySelector('[data-flash]');const b=document.querySelector('#message-body');if(!a||!b||document.activeElement!==a||b.value.length!==2001||!document.querySelector('#booking-messages').contains(a)){return false;}const line=document.querySelector('.site-header').getBoundingClientRect().bottom-1;const r=a.getBoundingClientRect();const s=b.getBoundingClientRect();return r.top>=line&&r.bottom<=innerHeight&&s.top>=line&&s.bottom<=innerHeight;})()"
    }
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
    Write-Output 'PASS browser refused message lands on the thread with its result and draft on screen at 375 and 1440'
}

function Send-DoubleClick([string]$Selector) {
    # A real double click through the browser's input pipeline, the way a mouse sends it.
    $selectorJson=ConvertTo-Json $Selector -Compress
    $point=Evaluate "(function(){const b=document.querySelector($selectorJson);b.scrollIntoView({block:'center',behavior:'instant'});const r=b.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2};})()"
    foreach ($count in 1,2) {
        foreach ($type in 'mousePressed','mouseReleased') { Send-Cdp 'Input.dispatchMouseEvent' @{type=$type;x=$point.x;y=$point.y;button='left';clickCount=$count} | Out-Null }
    }
}

function Check-PostGuardEvents {
    # Events only, so nothing is posted. The timer is captured to release the form without waiting for it.
    Navigate '/profile'
    $generic=Evaluate "(function(){const f=document.querySelector('form[action`$=`"/profile/password`"]');const send=function(){const e=new Event('submit',{cancelable:true,bubbles:true});f.dispatchEvent(e);return e.defaultPrevented;};const realTimeout=window.setTimeout;const timers=[];window.setTimeout=function(callback,delay,form){timers.push({callback,delay,form});return 0;};const first=send();const second=send();window.setTimeout=realTimeout;const locked=f.dataset.submitting==='true'&&f.getAttribute('aria-busy')==='true';const buttons=f.querySelectorAll('button:disabled').length;timers[0].callback(timers[0].form);const timerReleased=!f.dataset.submitting&&!f.hasAttribute('aria-busy');const third=send();const stillLocked=send();window.dispatchEvent(new Event('pageshow'));const shown=!f.dataset.submitting;const cancel=function(e){e.preventDefault();};f.addEventListener('submit',cancel);const cancelled=send();const notLockedByCancel=!f.dataset.submitting;f.removeEventListener('submit',cancel);const search=document.createElement('form');search.setAttribute('method','get');document.body.append(search);const getEvent=new Event('submit',{cancelable:true,bubbles:true});search.dispatchEvent(getEvent);const getTouched=!!search.dataset.submitting||getEvent.defaultPrevented;search.remove();const open=document.createElement('form');open.setAttribute('method','post');open.setAttribute('target','_blank');document.body.append(open);const openEvent=new Event('submit',{cancelable:true,bubbles:true});open.dispatchEvent(openEvent);const openTouched=!!open.dataset.submitting;open.remove();return {first,second,locked,buttons,delay:timers[0].delay,timerReleased,third,stillLocked,shown,cancelled,notLockedByCancel,getTouched,openTouched};})()"
    if ($generic.first -or !$generic.second -or !$generic.locked -or $generic.buttons -or $generic.delay -ne 20000 -or !$generic.timerReleased -or $generic.third -or !$generic.stillLocked -or !$generic.shown -or !$generic.cancelled -or !$generic.notLockedByCancel -or $generic.getTouched -or $generic.openTouched) { throw "Generic POST guard failed: $($generic | ConvertTo-Json -Compress)" }
    # The forms with their own guard must keep working when the event also reaches the document.
    Navigate '/reports?listing=2'
    $own=Evaluate "(function(){const f=document.querySelector('[data-report-form]');const b=f.querySelector('button[type=submit]');const idle=b.textContent;const send=function(){const e=new Event('submit',{cancelable:true,bubbles:true});f.dispatchEvent(e);return e.defaultPrevented;};const first=send();const second=send();window.dispatchEvent(new Event('pageshow'));return {first,second,released:!b.disabled&&!f.dataset.submitting&&b.textContent===idle};})()"
    if ($own.first -or !$own.second -or !$own.released) { throw "Report form lost its own guard: $($own | ConvertTo-Json -Compress)" }
    Write-Output 'PASS browser generic POST guard lets one through, swallows the repeat, releases on timer and pageshow, leaves GET, new-window and cancelled submits alone'
}

function Check-RegisterDoubleClick {
    # Used to end on "session expired" although the account had been created.
    Navigate '/register'
    $script:doubleClickEmail='audit-double-'+[guid]::NewGuid().ToString('N').Substring(0,12)+'@home2home.test'
    Evaluate "(function(){const f=document.querySelector('form[action`$=`"/register`"]');f.elements.full_name.value='Audit double click';f.elements.email.value='$script:doubleClickEmail';f.elements.phone.value='0912345678';f.elements.password.value='Audit!'+Math.random().toString(36).slice(2,12);return true;})()" | Out-Null
    Send-DoubleClick 'form[action$="/register"] button'
    Wait-FilterPage "location.pathname!=='/register'&&!!document.querySelector('meta[name=csrf-token]')"
    if (!(Evaluate "!!document.querySelector('form[action`$=`"/logout`"]')&&!document.querySelector('.alert-danger')")) { throw 'Double click on register did not end signed in' }
    Logout-Role
    Write-Output 'PASS browser real double click on register signs in once'
}

function Check-AdminCreateDoubleClick {
    # Used to report failure for the account the first click had just created.
    Navigate '/admin'
    $email='audit-double-'+[guid]::NewGuid().ToString('N').Substring(0,12)+'@home2home.test'
    Evaluate "(function(){const f=document.querySelector('form[action`$=`"/admin/users`"]');f.elements.full_name.value='Audit double click';f.elements.email.value='$email';f.elements.password.value='Audit!'+Math.random().toString(36).slice(2,12);window.h2hSamePage=true;return true;})()" | Out-Null
    Send-DoubleClick 'form[action$="/admin/users"] button'
    Wait-FilterPage "!window.h2hSamePage&&location.pathname==='/admin'&&!!document.querySelector('[data-flash]')"
    if (!(Evaluate "!!document.querySelector('.alert-success')&&!document.querySelector('.alert-danger')")) { throw 'Double click on Create Guest reported a failure' }
    # Both fixture accounts go away again.
    Navigate '/admin?q=audit-double-'
    $removed=Evaluate "(async function(){const forms=Array.from(document.querySelectorAll('form[action`$=`"/delete`"][action*=`"/admin/users/`"]'));for(const f of forms){await fetch(f.getAttribute('action'),{method:'POST',body:new URLSearchParams({_token:f.elements._token.value})});}return forms.length;})()"
    if ($removed -ne 2) { throw "Expected two double click fixture accounts, found $removed" }
    Write-Output 'PASS browser real double click on Create Guest reports success, fixtures removed'
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
    Check-FilterResubmit
    Check-SubmitStopRelease '[data-search-form]' '/' "f.elements.location.value='Stop test'"
    Check-SubmitStopRelease '[data-filter-form]' '/' "f.elements.min_price.value='7000'"
    Write-Output 'PASS browser search and filter guards release on the timer after Stop'
    Check-RegisterDoubleClick

    Navigate '/login'
    Check-Page 'login'
    if ((Login-Role 'guest') -ne '/') { throw 'Guest login redirect failed' }
    foreach ($width in @(375,768,1024,1440)) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
        foreach ($path in @('/profile','/bookings','/wishlist','/notifications','/reports','/messages','/map','/')) { Navigate $path; Check-Page "Guest $path $width" }
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
    Check-SubmitGuards $bookingPath
    Check-SubmitStopRelease '[data-booking-form]' '/listings/1' "f.elements.check_in.value='2035-05-10';f.elements.check_out.value='2035-05-12'"
    Write-Output 'PASS browser booking guard releases on the timer after Stop'
    Check-BookingRefusal
    Check-LongText '/' '.card-body h3 a'
    Check-LongText '/listings/1' '.detail-heading h1, .detail-heading p, .detail-page .col-lg-8 p'
    Check-LongText '/bookings' '.booking-info h2, .booking-info p' -IfPresent
    Check-LongText '/profile' '.trip-row strong' -IfPresent
    Check-LongReport
    Write-Output 'PASS browser long unbroken text wraps at 375 on Guest pages'
    Check-BookingDateWidth
    Check-FlashReveal
    Check-MessageFlash $bookingPath
    Check-PostGuardEvents
    Check-CalendarNavigation '/listings/1'
    Logout-Role
    if ((Login-Role 'host') -ne '/host') { throw 'Host login redirect failed' }
    foreach ($width in @(375,768,1024,1440)) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
        foreach ($path in @('/host','/host/reviews','/host/listings/1/edit','/host/listings/1/checkin-guide','/messages','/host/listings/1/pricing','/host/listings/1/availability')) { Navigate $path; Check-Page "Host $path $width" }
        if ($width -eq 375 -or $width -eq 1440) { Capture-Page "host-calendar-$width" }
    }
    Navigate '/listings/1'
    $own=Evaluate "(function(){const link=document.querySelector('.booking-box a[href$=`"/host/listings/1/edit`"]');return {form:!!document.querySelector('[data-booking-form]'),note:!!document.querySelector('[data-own-listing]'),link:!!link,price:!!document.querySelector('.booking-price'),calendar:!!document.querySelector('#availability-calendar')};})()"
    if ($own.form -or !$own.note -or !$own.link -or !$own.price -or !$own.calendar) { throw "Own listing page wrong for the Host: $($own | ConvertTo-Json -Compress)" }
    Write-Output 'PASS browser Host sees a note and manage link instead of the booking form on their own listing'
    Check-LongText '/host/listings/1/availability' '.section-title h1, .section-title p'
    Write-Output 'PASS browser long unbroken text wraps at 375 on Host pages'
    Check-CalendarNavigation '/host/listings/1/availability'
    Logout-Role
    if ((Login-Role 'admin') -ne '/admin') { throw 'Admin login redirect failed' }
    foreach ($width in @(375,768,1024,1440)) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
        foreach ($path in @('/admin','/admin/users/3/edit','/admin/listings/2/edit','/admin/listings/1/preview','/admin/catalog','/admin/reviews','/admin/reports','/admin/articles','/admin/articles/create','/admin/articles/1/edit','/admin/articles/1/preview','/articles','/articles/cam-nang-2-ngay-o-da-lat','/admin/holidays','/admin/promotions','/admin/payments','/admin/verifications','/admin/messages','/map')) {
            Navigate $path; Check-Page "Admin $path $width"
            if ($path -eq '/admin' -and $width -eq 375) {
                $scrollable=Evaluate 'Array.from(document.querySelectorAll(''.table-responsive'')).filter(e=>e.scrollWidth>e.clientWidth).every(e=>{e.scrollLeft=50;const ok=e.scrollLeft>0;e.scrollLeft=0;return ok})'
                if (!$scrollable) { throw 'Mobile Admin table cannot be scrolled horizontally' }
                Write-Output 'PASS mobile Admin table scrolling without page overflow'
            }
            if ($path -eq '/admin' -and ($width -eq 375 -or $width -eq 1440)) { Capture-Page "admin-dashboard-$width" }
        }
    }
    Check-LongText '/admin' 'table tbody td:nth-child(2)'
    Check-LongText '/admin/reports' 'article.surface-card h2'
    Write-Output 'PASS browser long unbroken text wraps at 375 on Admin pages, wide tables scroll inside themselves'
    Check-AdminFilterCarry
    Check-AdminCreateDoubleClick
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
