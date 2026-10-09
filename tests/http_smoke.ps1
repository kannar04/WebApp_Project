param([string]$BaseUrl = $(if ($env:HOME2HOME_TEST_URL) { $env:HOME2HOME_TEST_URL } else { 'http://127.0.0.1:8090' }))
$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$webSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession

$indexResponse = Invoke-WebRequest -Uri "$baseUrl/" -WebSession $webSession -UseBasicParsing
$detailResponse = Invoke-WebRequest -Uri "$baseUrl/listings/1" -WebSession $webSession -UseBasicParsing
$quoteResponse = Invoke-WebRequest -Uri "$baseUrl/api/listings/1/quote?check_in=2035-01-10&check_out=2035-01-12&guests=2" -WebSession $webSession -UseBasicParsing
$quote = $quoteResponse.Content | ConvertFrom-Json

if ($indexResponse.StatusCode -ne 200 -or -not $indexResponse.Content.Contains('Sea Breeze Homestay')) { throw 'Home page failed' }
if ($detailResponse.StatusCode -ne 200 -or -not $detailResponse.Content.Contains('booking-box')) { throw 'Listing detail failed' }
if (-not $quote.success -or $quote.data.total -ne 2650000) { throw 'Quote endpoint failed' }

Write-Output 'PASS HTTP smoke: home, detail, JSON quote'

