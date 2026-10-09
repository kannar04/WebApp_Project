param([string]$BaseUrl='http://127.0.0.1:8090')
$ErrorActionPreference='Stop'
$php='C:\xampp\php\php.exe'
$marker=[guid]::NewGuid().ToString('N').Substring(0,16)
$fixturePassword=[guid]::NewGuid().ToString('N')
$fixture=$null

function Request([string]$Path,$Session,[string]$Method='GET',$Body=$null,$Headers=@{}) {
    try {
        $arguments=@{Uri=($BaseUrl+$Path);WebSession=$Session;Method=$Method;UseBasicParsing=$true;Headers=$Headers}
        if ($null -ne $Body) { $arguments.Body=$Body }
        $response=Invoke-WebRequest @arguments
        return @{Status=[int]$response.StatusCode;Content=$response.Content;Path=$response.BaseResponse.ResponseUri.AbsolutePath}
    } catch [Net.WebException] {
        if (!$_.Exception.Response) { throw }
        $response=$_.Exception.Response
        $reader=New-Object IO.StreamReader($response.GetResponseStream())
        try { $content=$reader.ReadToEnd() } finally { $reader.Dispose() }
        return @{Status=[int]$response.StatusCode;Content=$content;Path=$response.ResponseUri.AbsolutePath}
    }
}
function Token([string]$Html) {
    if ($Html -match 'name="_token" value="([^"]+)"') { return $matches[1] }
    if ($Html -match 'name="csrf-token" content="([^"]+)"') { return $matches[1] }
    throw 'No CSRF token in HTML'
}
function Login([string]$Role) {
    $session=New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $next=if($Role -eq 'guest'){'/'}else{'/'+$Role}
    $page=Request ('/login?next='+$next) $session
    $login=Request '/login' $session 'POST' @{_token=(Token $page.Content);email=($fixture.emailPrefix+'-'+$Role+'@home2home.test');password=$fixturePassword}
    if ($login.Status -ne 200 -or $login.Path -ne $next) { throw "$Role login failed" }
    return $session
}

try {
    $fixture=& $php tests/http_audit_fixture.php create $marker $fixturePassword | ConvertFrom-Json
    if ($LASTEXITCODE -ne 0 -or !$fixture.listingId) { throw 'Fixture creation failed' }
    $guestSession=Login 'guest'
    $hostSession=Login 'host'
    $adminSession=Login 'admin'
    foreach ($case in @(@('/host',$guestSession,403),@('/admin',$hostSession,403),@('/unknown',$guestSession,404))) {
        $response=Request $case[0] $case[1]
        if ($response.Status -ne $case[2]) { throw "Permission/404 failed: $($case[0])" }
    }
    Write-Output 'PASS HTTP authentication, role permissions and 404'
    $registeredSession=New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $registration=Request '/register' $registeredSession
    $registrationToken=Token $registration.Content
    $response=Request '/register' $registeredSession 'POST' @{_token=$registrationToken;full_name='HTTP registration preserved';email='not-an-email';phone='';password=$fixturePassword}
    if($response.Path -ne '/register' -or !$response.Content.Contains('HTTP registration preserved') -or $response.Content.Contains($fixturePassword)) { throw 'Registration validation did not recover safely' }
    $response=Request '/register' $registeredSession 'POST' @{_token=$registrationToken;full_name='HTTP registered';email=($fixture.emailPrefix+'-registered@home2home.test');phone='';password=$fixturePassword}
    if($response.Status -ne 200 -or $response.Path -ne '/') { throw 'Valid registration did not log in' }
    $profile=Request '/profile' $registeredSession
    $registeredToken=Token $profile.Content
    $response=Request '/profile' $registeredSession 'POST' @{_token=$registeredToken;full_name='HTTP updated profile';phone='0900000000'}
    if($response.Path -ne '/profile' -or !$response.Content.Contains('HTTP updated profile')) { throw 'Profile did not persist through a routine' }
    $response=Request '/profile/become-host' $registeredSession 'POST' @{_token=$registeredToken}
    if($response.Path -ne '/host' -or $response.Status -ne 200) { throw 'Host role enrollment failed' }
    $response=Request '/logout' $registeredSession 'POST' @{_token=$registeredToken}
    $response=Request '/profile' $registeredSession
    if($response.Path -ne '/login') { throw 'Logout left a protected profile accessible' }
    Write-Output 'PASS HTTP registration validation, automatic login, profile update, Host role and logout'

    $anonymous=New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $page=Request '/' $anonymous
    $response=Request ('/api/wishlist/'+$fixture.listingId) $anonymous 'POST' @{_token=(Token $page.Content)} @{Accept='application/json'}
    if ($response.Status -ne 401 -or ($response.Content|ConvertFrom-Json).success) { throw 'Anonymous JSON response failed' }
    $page=Request '/profile' $guestSession
    $guestToken=Token $page.Content
    $response=Request '/api/wishlist/999999999' $guestSession 'POST' @{_token=$guestToken} @{Accept='application/json'}
    if ($response.Status -ne 404) { throw 'Invalid favorite not rejected' }
    $response=Request '/api/wishlist/1' $guestSession 'POST' @{_token='invalid'} @{Accept='application/json'}
    if ($response.Status -ne 419) { throw 'JSON CSRF status failed' }
    Write-Output 'PASS JSON 401/404/419 recovery'

    $response=Request '/api/listings?location%5B%5D=invalid' $guestSession
    if ($response.Status -ne 422) { throw 'Array filter accepted' }
    Write-Output 'PASS malformed search filter validation'

    $hostPage=Request ('/host/listings/'+$fixture.listingId+'/edit') $hostSession
    $hostToken=Token $hostPage.Content
    $response=Request '/host/listings/1/visibility' $hostSession 'POST' @{_token=$hostToken;visible='0'}
    if($response.Status -ne 200 -or $response.Path -ne '/host' -or !$response.Content.Contains('alert-danger')) { throw 'Host non-owner visibility did not recover with an error' }
    $data=@{_token=$hostToken;title=$fixture.title;address='Audit address';city='Audit';description='Fixture for HTTP audit';property_type_id='1';cancellation_policy_id='1';nightly_price='100000';cleaning_fee='10000';max_guests='2';room_count='1';bedroom_count='1';bed_count='1';check_in_time='14:00';check_out_time='11:00';'amenities[]'=$fixture.amenityId}
    $data.title='Preserved input '+$marker
    $data.cleaning_fee='-1'
    $response=Request ('/host/listings/'+$fixture.listingId) $hostSession 'POST' $data
    $state=& $php tests/http_audit_fixture.php inspect $marker | ConvertFrom-Json
    if ($state.title -ne $fixture.title -or !$response.Content.Contains($data.title)) { throw 'Host invalid input changed DB or lost old input' }
    $data.title=$fixture.title
    $data.cleaning_fee='10000'
    $data['amenities[]']='999999999'
    $response=Request ('/host/listings/'+$fixture.listingId) $hostSession 'POST' $data
    $state=& $php tests/http_audit_fixture.php inspect $marker | ConvertFrom-Json
    if ($state.amenity_count -ne 1) { throw 'Host invalid amenities cleared existing data' }
    $data['amenities[]']=$fixture.amenityId
    $data.address='Must roll back'
    $data.nightly_price='175001'
    $cookieHeader=($hostSession.Cookies.GetCookies([Uri]$BaseUrl) | ForEach-Object { $_.Name+'='+$_.Value }) -join '; '
    $curlArguments=@('-s','-L','-b',$cookieHeader)
    foreach($key in $data.Keys) { $curlArguments+=@('-F',($key+'='+$data[$key])) }
    $curlArguments+=@('-F','photo=@README.md;type=image/jpeg',($BaseUrl+'/host/listings/'+$fixture.listingId))
    $uploadResponse=& curl.exe @curlArguments
    if($LASTEXITCODE -ne 0) { throw 'Multipart test request failed' }
    $state=& $php tests/http_audit_fixture.php inspect $marker | ConvertFrom-Json
    if($state.address -ne 'Audit address' -or [decimal]$state.base_nightly_rate -ne 100000 -or $state.amenity_count -ne 1) { throw 'Invalid upload caused a partial listing update' }
    Write-Output 'PASS Host validation, preserved input, relationships and upload rollback'
    $data.address='Audit address'
    $data.nightly_price='100000'
    $curlArguments=@('-s','-L','-b',$cookieHeader)
    foreach($key in $data.Keys) { $curlArguments+=@('-F',($key+'='+$data[$key])) }
    $curlArguments+=@('-F','photo=@public/assets/images/demo/1600585154340-be6161a56a0c.jpg;type=image/jpeg',($BaseUrl+'/host/listings/'+$fixture.listingId))
    $uploadResponse=& curl.exe @curlArguments
    if($LASTEXITCODE -ne 0 -or ($uploadResponse -join '').Contains('SQLSTATE')) { throw 'Valid upload request failed' }
    $state=& $php tests/http_audit_fixture.php inspect $marker | ConvertFrom-Json
    $dashboard=Request '/host' $hostSession
    if($state.moderation_status -ne 'pending' -or $state.photo_count -ne 1 -or $dashboard.Status -ne 200) { throw 'Valid Host save/photo reference failed' }
    Write-Output 'PASS Host valid JPEG upload/save through ownership-checked routines'

    $adminPage=Request '/admin' $adminSession
    $adminToken=Token $adminPage.Content
    foreach ($action in @('approved','rejected','approved')) {
        $response=Request ('/admin/listings/'+$fixture.listingId+'/moderate') $adminSession 'POST' @{_token=$adminToken;action=$action;note='Audit moderation'}
        if ($response.Status -ne 200) { throw 'Moderation HTTP failure' }
    }
    $state=& $php tests/http_audit_fixture.php inspect $marker | ConvertFrom-Json
    if ($state.moderation_status -ne 'approved' -or $state.moderation_events -ne 3) { throw 'Moderation transaction failed' }
    $checkIn=(Get-Date).Date.AddDays(6200).ToString('yyyy-MM-dd')
    $checkOut=(Get-Date).Date.AddDays(6202).ToString('yyyy-MM-dd')
    $response=Request ('/listings/'+$fixture.listingId+'/book') $guestSession 'POST' @{_token=$guestToken;check_in=$checkIn;check_out=$checkOut;guests='2'}
    $state=& $php tests/http_audit_fixture.php inspect $marker | ConvertFrom-Json
    if($response.Status -ne 200 -or $response.Path -ne '/bookings' -or $state.booking_count -ne 1 -or $state.booking_status -ne 'pending') { throw 'Guest booking HTTP create failed' }
    $bookingId=$state.booking_id
    $response=Request ('/listings/'+$fixture.listingId+'/book') $guestSession 'POST' @{_token=$guestToken;check_in=$checkIn;check_out=$checkOut;guests='2'}
    $state=& $php tests/http_audit_fixture.php inspect $marker | ConvertFrom-Json
    if($state.booking_count -ne 1) { throw 'HTTP double submission created overlapping bookings' }
    $response=Request ('/host/bookings/'+$bookingId+'/transition') $hostSession 'POST' @{_token=$hostToken;status='confirmed'}
    $state=& $php tests/http_audit_fixture.php inspect $marker | ConvertFrom-Json
    if($response.Status -ne 200 -or $state.booking_status -ne 'confirmed') { throw 'Host confirmation HTTP failed' }
    $response=Request ('/bookings/'+$bookingId+'/cancel') $guestSession 'POST' @{_token=$guestToken;reason='HTTP audit cancellation'}
    $state=& $php tests/http_audit_fixture.php inspect $marker | ConvertFrom-Json
    if($response.Status -ne 200 -or $response.Path -ne '/bookings' -or $state.booking_status -ne 'cancelled') { throw 'Guest cancellation HTTP failed' }
    Write-Output 'PASS HTTP Guest booking, duplicate-submit guard, Host confirmation and Guest cancellation'
    $adminListingData=@{_token=$adminToken;title=$fixture.title;address='Admin preserved address';city='Audit';description='Fixture for HTTP audit';nightly_price='120000';cleaning_fee='-1';max_guests='2'}
    $response=Request ('/admin/listings/'+$fixture.listingId) $adminSession 'POST' $adminListingData
    $state=& $php tests/http_audit_fixture.php inspect $marker | ConvertFrom-Json
    if ($response.Status -ne 200 -or $response.Path -notlike '/admin/listings/*/edit' -or !$response.Content.Contains('Admin preserved address') -or [decimal]$state.base_nightly_rate -ne 100000) { throw "Admin invalid fee recovery failed (HTTP $($response.Status))" }
    Write-Output 'PASS Admin invalid listing data preserves form and database'
    $response=Request ('/admin/users/'+$fixture.adminId) $adminSession 'POST' @{_token=$adminToken;full_name='HTTP audit admin';email=($fixture.emailPrefix+'-admin@home2home.test');phone='';password='';'roles[]'='guest'}
    if ($response.Status -ne 200 -or $response.Path -ne '/admin') { throw 'Admin self-demotion guard failed' }
    $response=Request ('/admin/users/'+$fixture.guestId) $adminSession 'POST' @{_token=$adminToken;full_name='HTTP audit guest';email=($fixture.emailPrefix+'-admin@home2home.test');phone='';password='';'roles[]'='guest'}
    if ($response.Status -ne 200 -or $response.Path -notlike '/admin/users/*/edit' -or $response.Content.Contains('SQLSTATE')) { throw 'Duplicate email recovery failed' }
    Write-Output 'PASS Admin moderation, self-demotion guard and duplicate email recovery'
    $registeredId=& $php tests/http_audit_fixture.php user-id $marker registered
    if([int]$registeredId -le 0) { throw 'Registered fixture user could not be resolved' }
    $response=Request ('/admin/users/'+$registeredId+'/roles') $adminSession 'POST' @{_token=$adminToken;roles='not-an-array'}
    if($response.Status -ne 200 -or $response.Path -ne '/admin' -or !$response.Content.Contains('alert-danger')) { throw 'Malformed Admin role payload did not recover' }
    foreach($status in @('suspended','active')) {
        $response=Request ('/admin/users/'+$registeredId+'/status') $adminSession 'POST' @{_token=$adminToken;status=$status}
        if($response.Status -ne 200 -or $response.Path -ne '/admin') { throw 'Admin user status update failed' }
    }
    $response=Request ('/admin/users/'+$registeredId+'/roles') $adminSession 'POST' @{_token=$adminToken;'roles[]'='guest'}
    if($response.Status -ne 200 -or $response.Path -ne '/admin') { throw 'Admin role update failed' }
    $response=Request ('/admin/users/'+$registeredId+'/delete') $adminSession 'POST' @{_token=$adminToken}
    $response=Request ('/admin/users/'+$registeredId+'/edit') $adminSession
    if($response.Status -ne 404) { throw 'Soft-deleted user remained available in Admin' }
    Write-Output 'PASS HTTP Admin user status/roles/soft-delete through routines'
} finally {
    & $php tests/http_audit_fixture.php cleanup $marker
}
