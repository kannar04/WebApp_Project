$ErrorActionPreference='Stop'
$previousPort=$env:DB_PORT
$previousDebug=$env:APP_DEBUG
$server=$null
try {
    # Only this child server receives the unavailable port; the normal server/DB is untouched.
    $env:DB_PORT='1'
    $env:APP_DEBUG='false'
    $server=Start-Process 'C:\xampp\php\php.exe' -WindowStyle Hidden -PassThru -ArgumentList @('-S','127.0.0.1:8092','-t','public','public/router.php')
    Start-Sleep -Milliseconds 800
    foreach($path in @('/','/api/listings')) {
        $accept=if($path -like '/api/*'){'application/json'}else{'text/html'}
        $caught=$false
        try {
            Invoke-WebRequest ('http://127.0.0.1:8092'+$path) -UseBasicParsing -Headers @{Accept=$accept} | Out-Null
        } catch [Net.WebException] {
            $response=$_.Exception.Response
            if(!$response){throw}
            $reader=New-Object IO.StreamReader($response.GetResponseStream())
            try {$body=$reader.ReadToEnd()} finally {$reader.Dispose()}
            if([int]$response.StatusCode -ne 500 -or $body -match 'SQLSTATE|Stack trace|password|Fatal error') {
                throw 'Unsafe database failure response'
            }
            if($path -like '/api/*') {
                if(($body|ConvertFrom-Json).success){throw 'Incorrect JSON error'}
            } elseif($body -notmatch 'href=') {
                throw 'Missing HTML recovery link'
            }
            $caught=$true
        }
        if(!$caught){throw 'Outage unexpectedly returned a successful response'}
        Write-Output "PASS controlled database outage: $path returns safe 500"
    }
} finally {
    $env:DB_PORT=$previousPort
    $env:APP_DEBUG=$previousDebug
    if($server -and !$server.HasExited){Stop-Process -Id $server.Id}
}
