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

function Check-FilterResetLoads {
    # Reset is a real page load from every address, repeated clicks too: typed values and errors go, the applied search stays, and it lands on the results.
    $cases=@(
        @{path='/';applied=@{}},
        @{path='/?location=&check_in=&check_out=&guests=1';applied=@{guests='1'}},
        @{path='/?location=Da&check_in=&check_out=&guests=2';applied=@{location='Da';guests='2'}},
        @{path='/?min_price=2000000&max_price=1000000';applied=@{}},
        @{path='/?check_in=2020-01-01&check_out=2020-01-02&location=Da';applied=@{location='Da'}}
    )
    foreach ($case in $cases) {
        Navigate $case.path
        foreach ($click in 1,2) {
            Evaluate "window.__beforeReset=true;const f=document.querySelector('[data-filter-form]');f.elements.min_price.value='2000000';f.elements.max_price.value='1000000';f.elements.max_price.dispatchEvent(new Event('input',{bubbles:true}));f.querySelector('input[name=""amenities[]""]').checked=true;f.elements.sort.value='price_asc';document.querySelector('[data-filter-reset]').click();true" | Out-Null
            Wait-FilterPage "!window.__beforeReset&&document.readyState==='complete'"
            $state=Evaluate "new Promise(function(resolve){setTimeout(function(){const f=document.querySelector('[data-filter-form]');const s=document.querySelector('[data-search-form]');const q=new URLSearchParams(location.search);resolve({hash:location.hash,panel:[f.elements.min_price.value,f.elements.max_price.value,f.elements.sort.value,f.elements.type.value,f.elements.bedrooms.value].join(''),ticked:f.querySelectorAll('input[name=""amenities[]""]:checked').length,error:document.querySelectorAll('[data-filter-error],.is-invalid').length,location:s.elements.location.value,checkIn:s.elements.check_in.value,guests:s.elements.guests.value,top:document.getElementById('listing-results').getBoundingClientRect().top,line:document.querySelector('.site-header').getBoundingClientRect().bottom,advanced:['min_price','max_price','sort','type','bedrooms','amenities[]','page'].filter(function(name){return q.has(name);}).length});},700);})"
            $kept=$state.location -eq [string]$case.applied.location -and $state.checkIn -eq '' -and $state.guests -eq $(if ($case.applied.guests) { $case.applied.guests } else { '1' })
            if ($state.hash -ne '#listing-results' -or $state.panel -ne '' -or $state.ticked -ne 0 -or $state.error -ne 0 -or $state.advanced -ne 0 -or !$kept -or $state.top -lt $state.line) { throw "Reset click $click from $($case.path) left: $($state | ConvertTo-Json -Compress)" }
        }
    }
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

function Check-MenuWithoutScript {
    # Without JS the toggler cannot open the menu, so below 992 px the menu stays open and the toggler goes. With JS nothing changes.
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=375;height=800;deviceScaleFactor=1;mobile=$false} | Out-Null
    $read="(function(){const toggler=document.querySelector('.navbar-toggler');const menu=document.querySelector('.site-header .navbar-collapse');const login=menu.querySelector('a[href*=login]').getBoundingClientRect();return {toggler:getComputedStyle(toggler).display,menu:getComputedStyle(menu).display,header:getComputedStyle(document.querySelector('.site-header')).position,linkShown:login.width>0&&login.height>0,overflow:document.documentElement.scrollWidth>window.innerWidth};})()"
    Send-Cdp 'Emulation.setScriptExecutionDisabled' @{value=$true} | Out-Null
    try {
        Navigate '/'
        $off=Evaluate $read
    } finally { Send-Cdp 'Emulation.setScriptExecutionDisabled' @{value=$false} | Out-Null }
    if ($off.toggler -ne 'none' -or $off.menu -ne 'block' -or $off.header -ne 'static' -or !$off.linkShown -or $off.overflow) { throw "Menu unreachable without JS: $($off | ConvertTo-Json -Compress)" }
    Navigate '/'
    $on=Evaluate $read
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
    if ($on.toggler -eq 'none' -or $on.menu -ne 'none' -or $on.header -ne 'sticky') { throw "Menu changed with JS on: $($on | ConvertTo-Json -Compress)" }
    Write-Output 'PASS browser menu is open and the toggler hidden without JS below 992 px, unchanged with JS'
}

function Check-LoginCarriesBooking {
    # Signed out, the sign-in link takes the chosen stay along: same site, encoded, following the form. After signing in the listing has it back and quotes it.
    Navigate '/listings/1'
    $plain=Evaluate "(function(){const u=new URL(document.querySelector('[data-login-next]').href);return u.pathname+u.search;})()"
    if ($plain -ne '/login') { throw "Sign-in link changed before any date was chosen: $plain" }
    $choose="(function(f){f.elements.check_in.value='2035-07-10';f.elements.check_out.value='CHECKOUT';f.elements.guests.value='2';f.elements.check_out.dispatchEvent(new Event('input',{bubbles:true}));const u=new URL(f.querySelector('[data-login-next]').href);return {sameSite:u.origin===location.origin,path:u.pathname,search:u.search,next:u.searchParams.get('next')};})(document.querySelector('[data-booking-form]'))"
    $first=Evaluate $choose.Replace('CHECKOUT','2035-07-11')
    $link=Evaluate $choose.Replace('CHECKOUT','2035-07-12')
    if (!$link.sameSite -or $link.path -ne '/login' -or $first.next -ne '/listings/1?check_in=2035-07-10&check_out=2035-07-11&guests=2' -or $link.next -ne '/listings/1?check_in=2035-07-10&check_out=2035-07-12&guests=2' -or $link.search -notmatch '^\?next=%2Flistings%2F1%3Fcheck_in%3D') { throw "Sign-in link wrong: $($link | ConvertTo-Json -Compress)" }
    Evaluate "document.querySelector('[data-login-next]').click();true" | Out-Null
    Wait-FilterPage "location.pathname==='/login'&&!!document.querySelector('form [name=password]')"
    Evaluate "(function(){const f=document.querySelector('form [name=password]').form;f.elements.email.value='guest@home2home.test';f.elements.password.value='Password123!';f.requestSubmit();return true;})()" | Out-Null
    Wait-FilterPage "location.pathname==='/listings/1'&&!!document.querySelector('[data-quote] .is-total')"
    $back=Evaluate "(function(){const f=document.querySelector('[data-booking-form]');return {checkIn:f.elements.check_in.value,checkOut:f.elements.check_out.value,guests:f.elements.guests.value,book:!!f.querySelector('button[type=submit]')};})()"
    if ($back.checkIn -ne '2035-07-10' -or $back.checkOut -ne '2035-07-12' -or $back.guests -ne '2' -or !$back.book) { throw "Stay lost through sign-in: $($back | ConvertTo-Json -Compress)" }
    Logout-Role
    Write-Output 'PASS browser sign-in link carries the chosen stay, encoded and same site, and the listing quotes it after sign-in'
}

function Check-BookingRestore {
    # Back fills the dates in without an input event, so the total used to stay blank. One quote per restore, and none extra when dates were quoted on load.
    Navigate '/listings/2'
    Evaluate "(function(){const f=document.querySelector('[data-booking-form]');f.elements.check_in.value='2035-06-10';f.elements.check_out.value='2035-06-12';f.elements.guests.value='2';f.elements.check_out.dispatchEvent(new Event('input',{bubbles:true}));return true;})()" | Out-Null
    Wait-FilterPage "!!document.querySelector('[data-quote] .is-total')"
    Navigate '/wishlist'
    $history=Send-Cdp 'Page.getNavigationHistory'
    Send-Cdp 'Page.navigateToHistoryEntry' @{entryId=$history.entries[$history.currentIndex-1].id} | Out-Null
    Wait-FilterPage "location.pathname==='/listings/2'&&!!document.querySelector('[data-quote] .is-total')"
    $quoteCount="performance.getEntriesByType('resource').filter(function(r){return r.name.includes('/quote?');}).length"
    $restored=Evaluate "new Promise(function(resolve){setTimeout(function(){const f=document.querySelector('[data-booking-form]');resolve({checkIn:f.elements.check_in.value,checkOut:f.elements.check_out.value,guests:f.elements.guests.value,type:performance.getEntriesByType('navigation')[0].type,quotes:$quoteCount});},900);})"
    if ($restored.checkIn -ne '2035-06-10' -or $restored.checkOut -ne '2035-06-12' -or $restored.guests -ne '2' -or $restored.quotes -ne 1) { throw "Back did not quote the restored dates once: $($restored | ConvertTo-Json -Compress)" }
    $events=Evaluate "new Promise(function(resolve){window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:false}));setTimeout(function(){const same=$quoteCount;window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));setTimeout(function(){resolve({same:same,cached:$quoteCount});},900);},900);})"
    if ($events.same -ne 1 -or $events.cached -ne 2) { throw "Pageshow quote count wrong: $($events | ConvertTo-Json -Compress)" }
    Navigate '/listings/2?check_in=2035-06-10&check_out=2035-06-12&guests=2'
    Wait-FilterPage "!!document.querySelector('[data-quote] .is-total')"
    $loaded=Evaluate "new Promise(function(resolve){setTimeout(function(){resolve($quoteCount);},900);})"
    if ($loaded -ne 1) { throw "Dates quoted on load were quoted $loaded times" }
    Write-Output 'PASS browser dates restored on Back are quoted once, a cached page once more, dates quoted on load not again'
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

function Check-HeaderRows {
    # From 1200 px one row in both languages. Below that a role may need two rows, but the actions stay together: no button alone on a row.
    $read="(function(){const middle=function(el){const box=el.getBoundingClientRect();return box.top+box.height/2;};const actions=[...document.querySelectorAll('.site-header .navbar-actions > *')].map(middle);return {spread:Math.max.apply(null,actions)-Math.min.apply(null,actions),linksGap:Math.abs(middle(document.querySelector('.site-header .navbar-nav'))-actions[0]),over:document.documentElement.scrollWidth-window.innerWidth};})()"
    foreach ($language in 1,2) {
        foreach ($width in 992,1199,1200,1440,1920) {
            Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
            Navigate '/'
            $header=Evaluate $read
            if ($header.spread -gt 12 -or $header.over -gt 0 -or ($width -ge 1200 -and $header.linksGap -gt 12)) { throw "Header wraps at $width px: $($header | ConvertTo-Json -Compress)" }
        }
        $current=Evaluate "document.documentElement.lang"
        Evaluate "document.querySelector('.locale-toggle').click();true" | Out-Null
        Wait-FilterPage "document.documentElement.lang!=='$current'&&document.readyState==='complete'"
    }
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
}

function Check-LongTableTitle([string]$Path, [switch]$IfPresent) {
    # One unbroken title must wrap in its cell: the table stays inside its box at desktop width, so the action buttons stay on screen. Put into the real cells, so no fixture rows are needed.
    $fill="(function(){const cells=document.querySelectorAll('td.table-title');for(const cell of cells){(cell.querySelector('strong a, strong, a')||cell).textContent='W'.repeat(200);}let wider=0;for(const cell of cells){const table=cell.closest('table');wider=Math.max(wider,table.getBoundingClientRect().width-table.closest('.table-responsive').getBoundingClientRect().width);}return {found:cells.length,wider:wider,over:document.documentElement.scrollWidth-window.innerWidth};})()"
    Navigate $Path
    $desktop=Evaluate $fill
    if ($desktop.found -lt 1) {
        if ($IfPresent) { Write-Output "SKIP browser long table title on $($Path): no rows"; return }
        throw "No title cell on $Path"
    }
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=375;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
    Navigate $Path
    $phone=Evaluate $fill
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
    if ($desktop.wider -gt 1 -or $desktop.over -gt 0 -or $phone.over -gt 0) { throw "Long title widens the table on $($Path): desktop $($desktop | ConvertTo-Json -Compress), phone $($phone | ConvertTo-Json -Compress)" }
}

function Check-LongPersonCell([string]$Path, [switch]$IfPresent) {
    # One unbroken name or email wraps at its cap: the table stays inside its box at desktop width, and at 375 it scrolls inside itself. Put into the real cells, so no fixture rows are needed.
    $fill="(function(){const people=document.querySelectorAll('.table-person');let wider=0;let widest=0;for(const person of people){person.textContent='W'.repeat(200);}for(const person of people){const table=person.closest('table');wider=Math.max(wider,table.getBoundingClientRect().width-table.closest('.table-responsive').getBoundingClientRect().width);widest=Math.max(widest,person.getBoundingClientRect().width);}return {found:people.length,wider:wider,widest:widest,cap:20*parseFloat(getComputedStyle(document.documentElement).fontSize),over:document.documentElement.scrollWidth-window.innerWidth};})()"
    Navigate $Path
    $desktop=Evaluate $fill
    if ($desktop.found -lt 1) {
        if ($IfPresent) { Write-Output "SKIP browser long name cell on $($Path): no rows"; return }
        throw "No name or email cell on $Path"
    }
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=375;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
    Navigate $Path
    $phone=Evaluate $fill
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
    if ($desktop.wider -gt 1 -or $desktop.over -gt 0 -or $phone.over -gt 0 -or $desktop.widest -gt $desktop.cap -or $phone.widest -gt $phone.cap) { throw "Long name widens the table on $($Path): desktop $($desktop | ConvertTo-Json -Compress), phone $($phone | ConvertTo-Json -Compress)" }
}

function Check-TableTextWhole([string[]]$Paths) {
    # Table action links, ordinary names and emails keep one line and no number or date breaks, at any width in either language: the table scrolls sideways instead.
    $read="(function(){const lineCount=function(range){return new Set(Array.from(range.getClientRects()).filter(function(box){return box.width>0;}).map(function(box){return Math.round(box.top+box.height/2);})).size;};const broken=[];for(const el of document.querySelectorAll('.table-person, td .action-row > a')){const range=document.createRange();range.selectNodeContents(el);if(lineCount(range)>1){broken.push(el.textContent.trim());}}for(const table of document.querySelectorAll('table')){const walker=document.createTreeWalker(table,NodeFilter.SHOW_TEXT);let node;while(node=walker.nextNode()){if(node.parentElement.closest('.sr-only,option,script,style')){continue;}for(const token of node.textContent.matchAll(/\S*\d\S*/g)){const range=document.createRange();range.setStart(node,token.index);range.setEnd(node,token.index+token[0].length);if(lineCount(range)>1){broken.push(token[0]);}}}}return {broken:broken,over:document.documentElement.scrollWidth-window.innerWidth};})()"
    foreach ($language in 1,2) {
        foreach ($width in 320,375,768,992,1024,1440) {
            Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
            foreach ($path in $Paths) {
                Navigate $path
                $whole=Evaluate $read
                if (@($whole.broken).Count -or $whole.over -gt 0) { throw "Table text broken on $path at $width px: $($whole | ConvertTo-Json -Compress)" }
            }
        }
        $current=Evaluate "document.documentElement.lang"
        Evaluate "document.querySelector('.locale-toggle').click();true" | Out-Null
        Wait-FilterPage "document.documentElement.lang!=='$current'&&document.readyState==='complete'"
    }
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
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

function Check-FragmentLanding([string[]]$Paths) {
    # A #fragment target lands below the sticky header, as high as the page can scroll it, on phone, tablet and desktop.
    foreach ($size in @(@(375,667),@(768,900),@(1440,900))) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$size[0];height=$size[1];deviceScaleFactor=1;mobile=$false} | Out-Null
        foreach ($path in $Paths) {
            Navigate $path
            $id=ConvertTo-Json ($path -split '#')[1] -Compress
            $landed=Evaluate "new Promise(function(resolve){setTimeout(function(){resolve({top:document.getElementById($id).getBoundingClientRect().top,line:document.querySelector('.site-header').getBoundingClientRect().bottom,atEnd:scrollY+innerHeight>=document.documentElement.scrollHeight-2});},700);})"
            if ($landed.top -lt $landed.line -or ($landed.top -gt $landed.line+60 -and !$landed.atEnd)) { throw "$path landed badly at $($size[0]) px: $($landed | ConvertTo-Json -Compress)" }
        }
    }
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
}

function Check-CalendarArrows([string]$Path) {
    # After a month change the month name and both arrows are on screen below the header, ready for the next tap, on a phone too.
    foreach ($size in @(@(375,667),@(1440,900))) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$size[0];height=$size[1];deviceScaleFactor=1;mobile=$false} | Out-Null
        Navigate $Path
        $month=Evaluate "new URL(document.querySelectorAll('#availability-calendar > div:first-child a')[1].href).searchParams.get('month')"
        Evaluate "document.querySelectorAll('#availability-calendar > div:first-child a')[1].click();true" | Out-Null
        Wait-FilterPage "new URLSearchParams(location.search).get('month')==='$month'&&location.hash==='#availability-calendar'"
        $seen=Evaluate "new Promise(function(resolve){setTimeout(function(){const line=document.querySelector('.site-header').getBoundingClientRect().bottom;resolve(Array.from(document.querySelectorAll('#availability-calendar > div:first-child > *')).map(function(item){const box=item.getBoundingClientRect();return box.top>=line&&box.bottom<=innerHeight;}));},700);})"
        if (@($seen).Count -ne 3 -or @($seen) -contains $false) { throw "Month name or arrows off screen on $Path at $($size[0]) px: $($seen -join ',')" }
    }
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
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

function Press-Enter {
    # A real key press: only this reaches the browser's implicit form submit.
    Send-Cdp 'Input.dispatchKeyEvent' @{type='rawKeyDown';key='Enter';code='Enter';windowsVirtualKeyCode=13;nativeVirtualKeyCode=13} | Out-Null
    Send-Cdp 'Input.dispatchKeyEvent' @{type='char';key='Enter';code='Enter';text="`r";unmodifiedText="`r";windowsVirtualKeyCode=13;nativeVirtualKeyCode=13} | Out-Null
    Send-Cdp 'Input.dispatchKeyEvent' @{type='keyUp';key='Enter';code='Enter';windowsVirtualKeyCode=13;nativeVirtualKeyCode=13} | Out-Null
    Start-Sleep -Milliseconds 150
}

function Check-BookingEnter {
    # Enter on instant-book listing 3 used to confirm a stay from the promo box. Every submit is stopped here, so nothing is booked.
    Navigate '/listings/3'
    Evaluate "(function(){const f=document.querySelector('[data-booking-form]');window.h2hSubmits=0;window.h2hQuotes=0;const realFetch=window.fetch;window.fetch=function(url,options){if(String(url).includes('/quote?')){window.h2hQuotes++;}return realFetch(url,options);};f.addEventListener('submit',function(e){window.h2hSubmits++;e.preventDefault();});f.elements.check_in.value='2035-07-10';f.elements.check_out.value='2035-07-12';f.elements.promo_code.value='CHAOHE10';return true;})()" | Out-Null
    foreach ($field in 'promo_code','check_in','check_out','guests') {
        Evaluate "(function(){document.querySelector('[data-booking-form]').elements.$field.focus();window.h2hQuotes=0;return true;})()" | Out-Null
        Press-Enter
        Start-Sleep -Milliseconds 500
        $after=Evaluate "({submits:window.h2hSubmits,quotes:window.h2hQuotes,total:!!document.querySelector('[data-quote] .is-total'),promo:document.querySelector('[data-promo-feedback]').classList.contains('is-valid')})"
        if ($after.submits -ne 0 -or $after.quotes -ne 1 -or !$after.total -or !$after.promo) { throw "Enter in $field did not just quote: $($after | ConvertTo-Json -Compress)" }
    }
    Evaluate "document.querySelector('[data-booking-form] button[type=submit]').focus();true" | Out-Null
    Press-Enter
    if ((Evaluate "window.h2hSubmits") -ne 1) { throw 'Enter on the focused booking button did not submit' }
    Navigate '/listings/3'
    $click=Evaluate "(function(){const f=document.querySelector('[data-booking-form]');let submits=0;f.addEventListener('submit',function(e){submits++;e.preventDefault();});f.elements.check_in.value='2035-07-10';f.elements.check_out.value='2035-07-12';f.querySelector('button[type=submit]').click();return submits;})()"
    if ($click -ne 1) { throw 'Click on the booking button did not submit' }
    # With JS off nothing can quote, but Enter still must not book: a disabled default button stops it.
    Send-Cdp 'Emulation.setScriptExecutionDisabled' @{value=$true} | Out-Null
    Navigate '/listings/3'
    $noScript=Evaluate "(function(){const f=document.querySelector('[data-booking-form]');const first=f.querySelector('button[type=submit]');return first.disabled&&first.hidden&&f.querySelectorAll('button[type=submit]').length===2;})()"
    Send-Cdp 'Emulation.setScriptExecutionDisabled' @{value=$false} | Out-Null
    if (!$noScript) { throw 'Booking form has no disabled default button with JS off' }
    if (Evaluate "document.querySelector('[data-booking-form] noscript button')===null&&document.querySelectorAll('[data-booking-form] button[type=submit]').length!==1") { throw 'Default button leaked into the page with JS on' }
    Write-Output 'PASS browser Enter in a booking field quotes and never books, the focused button and a click still submit'
}

function Check-SeveralActions([string]$Path, [string]$Selector, [string]$Field, [string[]]$Actions, [switch]$IfPresent) {
    # Enter in a form with several actions must pick none of them; each button still sends its own name and value. Every submit is stopped here, so nothing is saved.
    Navigate $Path
    $selectorJson=ConvertTo-Json $Selector -Compress
    if (!(Evaluate "!!document.querySelector($selectorJson)")) {
        if ($IfPresent) { Write-Output "SKIP browser several actions on $($Path): no such form"; return }
        throw "No $Selector on $Path"
    }
    $fieldJson=ConvertTo-Json $Field -Compress
    Evaluate "(function(){const f=document.querySelector($selectorJson);window.h2hSent=[];window.confirm=function(){return true;};f.addEventListener('submit',function(e){e.preventDefault();e.stopImmediatePropagation();const n=e.submitter;window.h2hSent.push(n?n.name+'='+n.value:'none');},true);f.querySelector($fieldJson).focus();return true;})()" | Out-Null
    Press-Enter
    if ((Evaluate "window.h2hSent.length") -ne 0) { throw "Enter chose an action on $($Path): $(Evaluate 'window.h2hSent.join()')" }
    $sent=Evaluate "(function(){window.h2hSent=[];const f=document.querySelector($selectorJson);f.noValidate=true;for(const b of f.querySelectorAll('button[type=submit],button:not([type])')){b.click();}return window.h2hSent.join();})()"
    if ($sent -ne ($Actions -join ',')) { throw "Buttons on $Path sent $sent, expected $($Actions -join ',')" }
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
    $generic=Evaluate "(function(){const f=document.querySelector('form[action`$=`"/profile/password`"]');const send=function(){const e=new Event('submit',{cancelable:true,bubbles:true});f.dispatchEvent(e);return e.defaultPrevented;};const realTimeout=window.setTimeout;const timers=[];window.setTimeout=function(callback,delay,form){timers.push({callback,delay,form});return 0;};const first=send();const second=send();window.setTimeout=realTimeout;const locked=f.dataset.submitting==='true'&&f.getAttribute('aria-busy')==='true';const buttons=f.querySelectorAll('button:disabled').length;const busyStyle=getComputedStyle(f.querySelector('button'));const busyShown=busyStyle.cursor==='progress'&&Number(busyStyle.opacity)<1;timers[0].callback(timers[0].form);const timerReleased=!f.dataset.submitting&&!f.hasAttribute('aria-busy');const third=send();const stillLocked=send();window.dispatchEvent(new Event('pageshow'));const shown=!f.dataset.submitting;const cancel=function(e){e.preventDefault();};f.addEventListener('submit',cancel);const cancelled=send();const notLockedByCancel=!f.dataset.submitting;f.removeEventListener('submit',cancel);const search=document.createElement('form');search.setAttribute('method','get');document.body.append(search);const getEvent=new Event('submit',{cancelable:true,bubbles:true});search.dispatchEvent(getEvent);const getTouched=!!search.dataset.submitting||getEvent.defaultPrevented;search.remove();const open=document.createElement('form');open.setAttribute('method','post');open.setAttribute('target','_blank');document.body.append(open);const openEvent=new Event('submit',{cancelable:true,bubbles:true});open.dispatchEvent(openEvent);const openTouched=!!open.dataset.submitting;open.remove();return {first,second,locked,buttons,busyShown,delay:timers[0].delay,timerReleased,third,stillLocked,shown,cancelled,notLockedByCancel,getTouched,openTouched};})()"
    if ($generic.first -or !$generic.second -or !$generic.locked -or $generic.buttons -or !$generic.busyShown -or $generic.delay -ne 20000 -or !$generic.timerReleased -or $generic.third -or !$generic.stillLocked -or !$generic.shown -or !$generic.cancelled -or !$generic.notLockedByCancel -or $generic.getTouched -or $generic.openTouched) { throw "Generic POST guard failed: $($generic | ConvertTo-Json -Compress)" }
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
    Check-FilterResetLoads
    Write-Output 'PASS browser filter reset is a real load from every address and lands on the results'
    Check-SubmitStopRelease '[data-search-form]' '/' "f.elements.location.value='Stop test'"
    Check-SubmitStopRelease '[data-filter-form]' '/' "f.elements.min_price.value='7000'"
    Write-Output 'PASS browser search and filter guards release on the timer after Stop'
    Check-RegisterDoubleClick

    Check-LoginCarriesBooking
    Check-MenuWithoutScript
    Navigate '/login'
    Check-Page 'login'
    if ((Login-Role 'guest') -ne '/') { throw 'Guest login redirect failed' }
    Check-HeaderRows
    Write-Output 'PASS browser header keeps one row from 1200 px and its actions together below that, in both languages'
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
    Check-BookingRestore
    Check-BookingEnter
    Check-LongText '/' '.card-body h3 a'
    Check-LongText '/listings/1' '.detail-heading h1, .detail-heading p, .detail-page .col-lg-8 p'
    Check-LongText '/bookings' '.booking-info h2, .booking-info p' -IfPresent
    Check-LongText '/profile' '.trip-row strong' -IfPresent
    Check-LongReport
    Write-Output 'PASS browser long unbroken text wraps at 375 on Guest pages'
    Check-BookingDateWidth
    Check-FlashReveal
    Check-MessageFlash $bookingPath
    Check-FragmentLanding @('/#listing-results','/profile#personal','/profile#verification','/profile#discovery-history','/listings/1#availability-calendar')
    if ($bookingPath) { Check-FragmentLanding @($bookingPath+'#booking-messages') }
    Check-CalendarArrows '/listings/1'
    Write-Output 'PASS browser in-app #fragment targets land below the sticky header, month name and arrows stay on screen after a month change'
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
    Check-SeveralActions '/host/listings/1/availability' 'form[action*="/availability"]' '[name=date]' @('open=0','open=1')
    Write-Output 'PASS browser Enter picks no action on the Host availability form'
    Check-LongText '/host/listings/1/availability' '.section-title h1, .section-title p'
    Check-LongTableTitle '/host'
    Check-LongPersonCell '/host' -IfPresent
    Check-TableTextWhole @('/host')
    Check-HeaderRows
    Write-Output 'PASS browser a long unbroken title wraps in its cell on the Host tables'
    Write-Output 'PASS browser long unbroken text wraps at 375 on Host pages'
    Check-CalendarNavigation '/host/listings/1/availability'
    Check-FragmentLanding @('/host#trusted-host')
    Check-CalendarArrows '/host/listings/1/availability'
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
    Check-LongTableTitle '/admin'
    Check-HeaderRows
    Check-LongTableTitle '/admin/payments' -IfPresent
    Check-LongTableTitle '/admin/articles' -IfPresent
    Write-Output 'PASS browser a long unbroken title wraps in its cell on the Admin tables'
    Check-LongPersonCell '/admin'
    Check-LongPersonCell '/admin/payments' -IfPresent
    Check-TableTextWhole @('/admin','/admin/payments','/admin/promotions')
    Write-Output 'PASS browser long names and emails wrap at their cap, ordinary ones, action links, numbers and dates stay whole from 320 to 1440 px in both languages'
    Check-LongText '/admin/reports' 'article.surface-card h2'
    Write-Output 'PASS browser long unbroken text wraps at 375 on Admin pages, wide tables scroll inside themselves'
    Check-SeveralActions '/admin' 'form.moderation-form' '[name=note]' @('action=approved','action=rejected')
    Check-SeveralActions '/admin/verifications' 'form[action*="/decide"]' '[name=reason]' @('decision=approved','decision=rejected') -IfPresent
    Check-SeveralActions '/admin/articles/create' 'form.article-form' '[name=title]' @('intent=draft','intent=publish')
    Check-SeveralActions '/admin/catalog' 'form[action$="/admin/catalog"]:has(button[name=active])' 'input[name=name]' @('=','active=0')
    # A textarea keeps its new lines, and a form with one action still sends on Enter.
    Navigate '/admin/articles/create'
    Evaluate "(function(){const f=document.querySelector('form.article-form');window.h2hSent=0;f.addEventListener('submit',function(e){window.h2hSent++;e.preventDefault();e.stopImmediatePropagation();},true);f.elements.excerpt.focus();f.elements.excerpt.value='';return true;})()" | Out-Null
    Press-Enter
    $textarea=Evaluate "(function(){return {value:document.querySelector('form.article-form').elements.excerpt.value,sent:window.h2hSent};})()"
    if ($textarea.value -ne "`n" -or $textarea.sent -ne 0) { throw "Textarea Enter wrong: $($textarea | ConvertTo-Json -Compress)" }
    Navigate '/admin'
    Evaluate "(function(){const f=document.querySelector('#booking-query').form;window.h2hSent=0;f.addEventListener('submit',function(e){window.h2hSent++;e.preventDefault();e.stopImmediatePropagation();},true);document.querySelector('#booking-query').focus();return true;})()" | Out-Null
    Press-Enter
    if ((Evaluate "window.h2hSent") -ne 1) { throw 'Enter stopped working in a one-action form' }
    Write-Output 'PASS browser Enter picks no action in forms with several, each button sends its own value, textarea new lines and one-action forms unchanged'
    Check-AdminFilterCarry
    Check-AdminCreateDoubleClick
    Check-CalendarNavigation '/admin/listings/1/preview'
    Check-CalendarArrows '/admin/listings/1/preview'
    if ($VisualUX) { Test-VisualPages 'after' }
    if ($script:javascriptErrors.Count) { throw "Browser reported $($script:javascriptErrors.Count) JavaScript exceptions" }
    Write-Output 'PASS no browser JavaScript exceptions'
} finally {
    try { Send-Cdp 'Browser.close' | Out-Null } catch {}
    $socket.Dispose()
    if (!$chromeProcess.HasExited) { Stop-Process -Id $chromeProcess.Id -ErrorAction SilentlyContinue }
    Write-Output "Browser profile retained for inspection: $profilePath"
}
