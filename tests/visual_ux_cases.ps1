# Browser-only evidence and interactions, called with an owned DB/server by fresh_setup_test.php.
function Get-FilterMetrics {
    Evaluate @'
(() => {
 const form=document.querySelector('[data-filter-form]')||document.querySelector('form.filter-row');
 const controls=Array.from(form.querySelectorAll('select,input:not([type=hidden]):not([type=checkbox])'));
 return controls.map(e=>{const r=e.getBoundingClientRect();const label=e.labels?.[0];const lr=label?.getBoundingClientRect();const css=getComputedStyle(e);return {name:e.name,top:r.top,bottom:r.bottom,left:r.left,right:r.right,height:r.height,label:label?.textContent.trim()||null,labelTop:lr?.top,labelBottom:lr?.bottom,border:css.border,radius:css.borderRadius}});
})()
'@
}

function Test-VisualPages([string]$Phase) {
    # Start from an anonymous browser even if the preceding suite left an Admin session.
    Navigate '/'
    if (Evaluate '!!document.querySelector(''form[action$="/logout"]'')') { Logout-Role }
    $oldScreenshotDirectory=$ScreenshotDirectory
    $script:ScreenshotDirectory=Join-Path $PSScriptRoot "../docs/quality/visual/screenshots/$Phase"
    try {
        $groups=@(
            @{role='public';paths=@('/','/login','/register','/listings/1','/forgot-password')},
            @{role='guest';paths=@('/profile','/bookings','/wishlist','/notifications','/reports')},
            @{role='host';paths=@('/host','/host/listings/create','/host/listings/1/edit','/host/listings/1/availability','/host/reviews')},
            @{role='admin';paths=@('/admin','/admin/users/3/edit','/admin/listings/2/edit','/admin/listings/1/preview','/admin/catalog','/admin/reviews','/admin/reports')}
        )
        if ($Phase -eq 'after') { $groups[1].paths += '/reports?listing=1' }
        foreach ($group in $groups) {
            if ($group.role -ne 'public') { Login-Role $group.role | Out-Null }
            foreach ($width in @(375,768,1024,1440)) {
                Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
                foreach ($path in $group.paths) {
                    Navigate $path; Check-Page "visual-$Phase $($group.role) $path $width"
                    if ($Phase -eq 'after' -and $group.role -eq 'admin' -and $path -eq '/admin' -and $width -eq 375) {
                        $table=Evaluate '(() => {const e=Array.from(document.querySelectorAll(".table-responsive")).find(e=>e.scrollWidth>e.clientWidth);if(!e)return false;e.focus();e.scrollLeft=0;return document.activeElement===e&&e.getAttribute("aria-label")&&e.tabIndex===0;})()'
                        if (!$table) { throw 'Mobile Admin table cannot receive labelled keyboard focus' }
                        Send-Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key='ArrowRight';code='ArrowRight';windowsVirtualKeyCode=39} | Out-Null
                        Send-Cdp 'Input.dispatchKeyEvent' @{type='keyUp';key='ArrowRight';code='ArrowRight';windowsVirtualKeyCode=39} | Out-Null
                        Wait-Visual 'document.activeElement.classList.contains("table-responsive")&&document.activeElement.scrollLeft>0'
                        Evaluate 'document.activeElement.scrollLeft=0;document.activeElement.blur();true' | Out-Null
                        Write-Output 'PASS mobile Admin labelled table actual keyboard scrolling'
                    }
                    if ($path -eq '/') {
                        $metrics=Get-FilterMetrics
                        Write-Output "FILTER-$Phase-$width $($metrics | ConvertTo-Json -Depth 5 -Compress)"
                        Evaluate '(() => {const form=document.querySelector(''[data-filter-form]'')||document.querySelector(''form.filter-row'');scrollTo({top:scrollY+form.getBoundingClientRect().top-document.querySelector(''.site-header'').getBoundingClientRect().height-16,behavior:"instant"});return true;})()' | Out-Null
                        Capture-Page "home-filter-$width"
                    } elseif ($width -eq 375 -or $width -eq 1440) {
                        Evaluate 'scrollTo({top:0,behavior:"instant"});true' | Out-Null
                        # Do not include the demo password note in auth evidence.
                        if ($path -eq '/login') { Evaluate 'document.querySelector(''.demo-note'').style.visibility=''hidden'';true' | Out-Null }
                        $screenPath=$path.Trim('/') -replace '/','-' -replace '[?=&]','-'
                        Capture-Page ($group.role+'-'+$screenPath+'-'+$width)
                    }
                    if ($Phase -eq 'after' -and $width -eq 1440) {
                        $missing=Evaluate 'Array.from(document.querySelectorAll(''input:not([type=hidden]),select,textarea'')).filter(e=>e.getClientRects().length&&!e.labels?.length&&!e.getAttribute("aria-label")&&!e.getAttribute("aria-labelledby")).map(e=>({id:e.id,name:e.name,type:e.type}))'
                        Write-Output "LABELS-$($group.role)-$path $($missing | ConvertTo-Json -Compress)"
                        if (@($missing).Count) { throw "Form control has no accessible label: $path" }
                        $contrasts=Evaluate @'
(() => {
 const luminance=color=>{const c=color.match(/[\d.]+/g).slice(0,3).map(v=>Number(v)/255).map(v=>v<=0.04045?v/12.92:Math.pow((v+0.055)/1.055,2.4));return c[0]*0.2126+c[1]*0.7152+c[2]*0.0722;};
 return Array.from(document.querySelectorAll('#filter-help,#amenities-help,.auth-footer')).map(e=>{let parent=e;let bg='rgba(0, 0, 0, 0)';while(parent){bg=getComputedStyle(parent).backgroundColor;if(bg!=='rgba(0, 0, 0, 0)')break;parent=parent.parentElement;}if(!parent)bg='rgb(255,255,255)';const color=getComputedStyle(e).color,a=luminance(color),b=luminance(bg);return {selector:e.id||e.className,color,background:bg,ratio:(Math.max(a,b)+0.05)/(Math.min(a,b)+0.05)};});
})()
'@
                        Write-Output "CONTRAST-$path $($contrasts | ConvertTo-Json -Compress)"
                        if (@($contrasts | Where-Object {$_.ratio -lt 4.5}).Count) { throw "Small helper/footer text has insufficient contrast: $path" }
                    }
                }
            }
            if ($group.role -ne 'public') { Logout-Role }
        }
    } finally { $script:ScreenshotDirectory=$oldScreenshotDirectory }
}

function Test-FilterJourneys {
    foreach ($width in @(375,768,1024,1440)) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
        Navigate '/'
        $metrics=Get-FilterMetrics
        if ($metrics.Count -ne 5 -or ($metrics | Where-Object {!$_.label}).Count) { throw 'Missing visible filter labels' }
        foreach ($control in $metrics) {
            if ([math]::Abs($control.height-44) -gt 1 -or $control.left -lt 0 -or $control.right -gt $width) { throw "Filter size/overflow failed: $width" }
            if ($control.labelBottom -ge $control.top) { throw 'Filter label overlaps its control' }
        }
        if ($width -ge 992 -and (($metrics.top | Measure-Object -Maximum).Maximum-($metrics.top | Measure-Object -Minimum).Minimum) -gt 1) { throw "Desktop filter controls misaligned: $width" }
        if ($width -eq 768 -and ([math]::Abs($metrics[0].top-$metrics[1].top) -gt 1 -or [math]::Abs($metrics[2].top-$metrics[3].top) -gt 1)) { throw 'Tablet filter row alignment failed' }
        if ($width -eq 375 -and (($metrics.left | Select-Object -Unique).Count -ne 1)) { throw 'Mobile filter columns misaligned' }
        if (($metrics.border | Select-Object -Unique).Count -ne 1 -or ($metrics.radius | Select-Object -Unique).Count -ne 1) { throw 'Filter border/radius inconsistent' }
        $checkboxes=Evaluate @'
(() => {const form=document.querySelector('[data-filter-form]');const boxes=Array.from(form.querySelectorAll('[type=checkbox]'));return {count:boxes.length,labels:boxes.every(e=>e.labels.length===1&&e.labels[0].textContent.trim()),overlap:boxes.some((e,i)=>boxes.slice(i+1).some(other=>{const a=e.labels[0].getBoundingClientRect(),b=other.labels[0].getBoundingClientRect();return a.left<b.right&&a.right>b.left&&a.top<b.bottom&&a.bottom>b.top}))};})()
'@
        if ($checkboxes.count -ne 6 -or !$checkboxes.labels -or $checkboxes.overlap) { throw 'Seed amenity labels/grid failed' }
        Check-Page "aligned filter $width"
        Write-Output "FILTER-after-$width $($metrics | ConvertTo-Json -Depth 5 -Compress)"
    }

    Navigate '/'
    if ((Get-ResultIds).Count -ne 3) { throw 'Default search results wrong' }
    Set-FilterValues @{type='2'}
    Submit-Filter
    Assert-ResultIds @(3) 'only property type'
    # Real browser reload retains the chosen type and real results.
    Evaluate 'document.documentElement.dataset.uxNavigationGuard=''1'';true' | Out-Null
    Send-Cdp 'Page.reload' | Out-Null
    Wait-Visual '!document.documentElement.dataset.uxNavigationGuard&&document.readyState==="complete"'
    if ((Evaluate 'document.querySelector(''[data-filter-form]'').elements.type.value') -ne '2') { throw 'Reload lost selected type' }
    Assert-ResultIds @(3) 'refresh type filter'

    Navigate '/'
    Set-FilterValues @{min_price='1000000';max_price='1500000'}
    Submit-Filter
    Assert-ResultIds @(1) 'only price range'

    Navigate '/'
    Set-FilterValues @{min_price='2000000';max_price='0.0'}
    Submit-Filter
    Assert-ResultIds @(2) 'zero price upper bound preserves existing SP meaning'

    Navigate '/'
    Set-FilterValues @{min_price='2100000';max_price='890000'}
    $invalid=Evaluate '(() => {const f=document.querySelector(''[data-filter-form]'');f.querySelector(''[data-filter-submit]'').click();return {valid:f.checkValidity(),focus:document.activeElement.id,disabled:f.querySelector(''[data-filter-submit]'').disabled,min:f.elements.min_price.value,max:f.elements.max_price.value,feedback:!!f.querySelector(''.invalid-feedback'')};})()'
    if ($invalid.valid -or $invalid.disabled -or $invalid.min -ne '2100000' -or $invalid.max -ne '890000' -or $invalid.focus -ne 'max-price') { throw 'Invalid client price range did not retain input/focus/retry' }
    Write-Output 'PASS visual invalid price range: native submit blocked, values retained, max price focused'
    Set-FilterValues @{max_price='2200000'}
    Submit-Filter
    Assert-ResultIds @(2) 'correct invalid price and retry'

    # Server-side validation independent from JS; no silent unfiltered results or lost context.
    Navigate '/?location=H%E1%BB%99i+An&type=1&sort=price_desc&min_price=2100000&max_price=890000&amenities%5B%5D=1'
    $serverInvalid=Evaluate '(() => {const f=document.querySelector(''[data-filter-form]'');return {location:f.elements.location.value,type:f.elements.type.value,sort:f.elements.sort.value,min:f.elements.min_price.value,max:f.elements.max_price.value,selected:f.querySelectorAll(''[type=checkbox]:checked'').length,error:!!document.querySelector(''[data-filter-error]''),results:document.querySelectorAll(''.listing-card'').length,summary:!!document.querySelector(''[data-results-summary]'')};})()'
    if (!$serverInvalid.error -or $serverInvalid.results -ne 0 -or $serverInvalid.summary -or $serverInvalid.min -ne '2100000' -or $serverInvalid.max -ne '890000' -or $serverInvalid.selected -ne 1 -or $serverInvalid.sort -ne 'price_desc' -or $serverInvalid.type -ne '1') { throw 'Server-invalid filters erased values or displayed false results' }
    Write-Output 'PASS visual server validation: field error, entered filters retained, no unfiltered result fallback'

    Navigate '/'
    Set-FilterValues @{amenities=@(1,2)}
    Submit-Filter
    Assert-ResultIds @(1,2) 'all selected amenities'
    $historyUrl=Evaluate 'location.href'
    Evaluate 'document.querySelector(''.listing-card a'').click();true' | Out-Null
    Wait-Visual 'location.pathname.startsWith("/listings/")&&document.readyState==="complete"'
    Check-Page 'filtered result to detail'
    $history=Send-Cdp 'Page.getNavigationHistory'
    Send-Cdp 'Page.navigateToHistoryEntry' @{entryId=$history.entries[$history.currentIndex-1].id} | Out-Null
    $expectedUrl=ConvertTo-Json $historyUrl -Compress
    Wait-Visual "location.href===$expectedUrl&&document.readyState==='complete'"
    if (!(Evaluate 'document.querySelectorAll(''[data-filter-form] [type=checkbox]:checked'').length===2&&!document.querySelector(''[data-filter-submit]'').disabled')) { throw 'Browser Back lost filters or left submit disabled' }
    Assert-ResultIds @(1,2) 'detail and browser Back'

    Navigate '/'
    Set-FilterValues @{min_price='99999999'}
    Submit-Filter
    if (!(Evaluate '!!document.querySelector(''.empty-state'')&&document.querySelectorAll(''.listing-card'').length===0&&!document.querySelector(''[data-filter-error]'')')) { throw 'Valid empty search confused with validation error' }
    Evaluate 'document.querySelector(''[data-filter-reset]'').click();true' | Out-Null
    Wait-Visual 'document.readyState==="complete"&&!new URLSearchParams(location.search).has("min_price")'
    Assert-ResultIds @(1,2,3) 'empty and reset advanced filters'

    Navigate '/?location=H%E1%BB%99i+An&check_in=2035-06-10&check_out=2035-06-12&guests=2&sort=price_desc&type=1&amenities%5B%5D=1&min_price=1000000&page=2'
    Evaluate 'document.querySelector(''[data-filter-reset]'').click();true' | Out-Null
    Wait-Visual 'document.readyState==="complete"&&!new URLSearchParams(location.search).has("type")'
    $retained=Evaluate '(() => {const p=new URLSearchParams(location.search);return {location:p.get("location"),start:p.get("check_in"),end:p.get("check_out"),guests:p.get("guests"),advanced:["type","sort","amenities[]","min_price","page"].some(k=>p.has(k))};})()'
    if (!$retained.location -or $retained.start -ne '2035-06-10' -or $retained.end -ne '2035-06-12' -or $retained.guests -ne '2' -or $retained.advanced) { throw 'Reset changed main search context or kept advanced filters/page' }
    Assert-ResultIds @(2,3) 'reset preserves location/dates/guests'

    # Native select Enter opens its popup; exercise implicit submission from a text/number input.
    Navigate '/'
    Set-FilterValues @{min_price='2000000'}
    Evaluate 'document.querySelector(''#min-price'').focus();document.documentElement.dataset.uxNavigationGuard=''1'';true' | Out-Null
    Send-Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key='Enter';code='Enter';windowsVirtualKeyCode=13;text="`r"} | Out-Null
    Send-Cdp 'Input.dispatchKeyEvent' @{type='keyUp';key='Enter';code='Enter';windowsVirtualKeyCode=13} | Out-Null
    Wait-Visual '!document.documentElement.dataset.uxNavigationGuard&&document.readyState==="complete"'
    Assert-ResultIds @(2) 'real keyboard Enter submission'

    Navigate '/'
    Evaluate 'document.querySelector(''#type-filter'').focus();true' | Out-Null
    $tabSequence=@('sort-filter','min-price','max-price','bedrooms-filter','filter-amenity-2')
    foreach ($expected in $tabSequence) {
        Send-Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key='Tab';code='Tab';windowsVirtualKeyCode=9} | Out-Null
        Send-Cdp 'Input.dispatchKeyEvent' @{type='keyUp';key='Tab';code='Tab';windowsVirtualKeyCode=9} | Out-Null
        if ((Evaluate 'document.activeElement.id') -ne $expected) { throw "Filter keyboard order failed at $expected" }
        if ($expected -eq 'sort-filter' -and !(Evaluate 'getComputedStyle(document.activeElement).outlineStyle==="solid"&&parseFloat(getComputedStyle(document.activeElement).outlineWidth)>=2')) { throw 'Keyboard focused filter has no visible outline' }
    }
    Send-Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key=' ';code='Space';windowsVirtualKeyCode=32} | Out-Null
    Send-Cdp 'Input.dispatchKeyEvent' @{type='keyUp';key=' ';code='Space';windowsVirtualKeyCode=32} | Out-Null
    if (!(Evaluate 'document.activeElement.checked')) { throw 'Keyboard Space did not toggle amenity' }
    Write-Output 'PASS visual keyboard order and Space checkbox toggle'
    foreach ($width in @(375,768)) {
        Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
        Navigate '/'
        Evaluate 'document.querySelector(''.navbar-toggler'').focus();true' | Out-Null
        Send-Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key='Enter';code='Enter';windowsVirtualKeyCode=13;text="`r"} | Out-Null
        Send-Cdp 'Input.dispatchKeyEvent' @{type='keyUp';key='Enter';code='Enter';windowsVirtualKeyCode=13} | Out-Null
        Wait-Visual 'document.querySelector(''.navbar-toggler'').getAttribute("aria-expanded")==="true"&&document.querySelector(''#mainNav'').classList.contains("show")'
        Check-Page "keyboard opened mobile navbar $width"
        Evaluate 'document.querySelector(''.navbar-toggler'').click();true' | Out-Null
        Wait-Visual 'document.querySelector(''.navbar-toggler'').getAttribute("aria-expanded")==="false"&&!document.querySelector(''#mainNav'').classList.contains("show")'
    }
    Write-Output 'PASS mobile navbar actual keyboard Enter/open/close'
    Navigate '/'
}

function Wait-Visual([string]$Predicate) {
    for ($attempt=0;$attempt -lt 150;$attempt++) {
        Start-Sleep -Milliseconds 100
        try { if (Evaluate $Predicate) { return } } catch { if ($attempt -eq 149) { throw } }
    }
    throw 'Visual interaction navigation timed out'
}

function Set-FilterValues([hashtable]$Values) {
    $json=ConvertTo-Json $Values -Depth 5 -Compress
    Evaluate "(() => {const form=document.querySelector('[data-filter-form]');const values=$json;for(const [name,value] of Object.entries(values)){if(name==='amenities'){form.querySelectorAll('[type=checkbox]').forEach(e=>{if(e.checked!==value.includes(Number(e.value)))e.click();});}else{const field=form.elements.namedItem(name);field.value=String(value);field.dispatchEvent(new Event('input',{bubbles:true}));}}return true;})()" | Out-Null
}

function Submit-Filter {
    $submitting=Evaluate '(() => {const f=document.querySelector(''[data-filter-form]'');document.documentElement.dataset.uxNavigationGuard=''1'';f.querySelector(''[data-filter-submit]'').click();return {busy:f.getAttribute("aria-busy"),disabled:f.querySelector(''[data-filter-submit]'').disabled};})()'
    if ($submitting.busy -ne 'true' -or !$submitting.disabled) { throw 'Search has no submitting feedback' }
    Wait-Visual '!document.documentElement.dataset.uxNavigationGuard&&document.readyState==="complete"'
}

function Get-ResultIds {
    @(Evaluate 'Array.from(document.querySelectorAll(''.listing-card a.card-image-wrap'')).map(a=>Number(new URL(a.href).pathname.split("/").pop()))')
}

function Assert-ResultIds([int[]]$Expected,[string]$Case) {
    $actual=@(Get-ResultIds | Sort-Object)
    $wanted=@($Expected | Sort-Object)
    if (($actual -join ',') -ne ($wanted -join ',')) { throw "Filter $Case failed: expected $($wanted -join ',') actual $($actual -join ',')" }
    Check-Page $Case
    Write-Output "PASS visual filter journey: $Case"
}
