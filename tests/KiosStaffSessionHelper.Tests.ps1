$ErrorActionPreference = 'Stop'
$modulePath = Join-Path $PSScriptRoot '..\scripts\KiosStaffSessionHelper.psm1'
Import-Module -Name $modulePath -Force -DisableNameChecking -ErrorAction Stop

$script:Passed = 0
$script:Failed = 0

function Assert-KiosTest {
    param([Parameter(Mandatory = $true)][bool]$Condition, [Parameter(Mandatory = $true)][string]$Name)
    if ($Condition) {
        $script:Passed++
        return
    }
    $script:Failed++
    Write-Output ('FAIL ' + $Name)
}

function New-KiosFakeResponse {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][int]$StatusCode,
        [Parameter(Mandatory = $true)][AllowEmptyString()][string]$Content,
        [hashtable]$Headers = @{}
    )
    return [PSCustomObject]@{
        StatusCode = $StatusCode
        Content = $Content
        Headers = $Headers
        BaseResponse = [PSCustomObject]@{
            RequestMessage = [PSCustomObject]@{ RequestUri = $Uri }
        }
    }
}

function New-KiosMockAdapter {
    param([Parameter(Mandatory = $true)][object]$State)
    return {
        param($Request)
        $path = $Request.Uri.AbsolutePath
        $State.Calls.Add(($Request.Method + ' ' + $path))
        if ($Request.Method -eq 'Get' -and $path -eq '/cashier.php') {
            if ($State.Authenticated) {
                $html = '<span class="cashier-session">Authorized demo staff session</span><nav class="station-nav">Cashier desk</nav><input type="hidden" name="_csrf" value="fixture-logout-csrf"><input type="hidden" name="action" value="logout">'
            }
            else {
                $html = '<form><input type="hidden" name="_csrf" value="fixture-login-csrf"><input id="password" name="password" type="password"><button>Sign in to cashier</button></form>'
            }
            return (New-KiosFakeResponse -Uri $Request.Uri -StatusCode 200 -Content $html)
        }
        if ($Request.Method -eq 'Post' -and $path -eq '/cashier.php') {
            if ($Request.Body.action -eq 'login') {
                $State.PasswordFieldPresent = -not [string]::IsNullOrEmpty([string]$Request.Body.password)
                $Request.Body['password'] = $null
                $State.Authenticated = $true
                return (New-KiosFakeResponse -Uri $Request.Uri -StatusCode 303 -Content '' -Headers @{ Location = '/cashier.php' })
            }
            if ($Request.Body.action -eq 'logout') {
                if ($State.FailLogout) {
                    return (New-KiosFakeResponse -Uri $Request.Uri -StatusCode 503 -Content '')
                }
                $State.Authenticated = $false
                return (New-KiosFakeResponse -Uri $Request.Uri -StatusCode 303 -Content '' -Headers @{ Location = '/cashier.php' })
            }
        }
        throw [InvalidOperationException]::new('Unexpected fake request.')
    }.GetNewClosure()
}

$moduleSource = Get-Content -LiteralPath $modulePath -Raw
Assert-KiosTest (-not $moduleSource.Contains('.Cookies.Clear()')) 'cleanup does not call CookieContainer.Clear'
Assert-KiosTest (-not $moduleSource.Contains('BaseResponse.ResponseUri')) 'response URI uses the PowerShell 7 supported property'
Assert-KiosTest ($moduleSource.Contains('BaseResponse.RequestMessage.RequestUri')) 'supported request URI property is present'

$csrfHtml = '<input type="hidden" name="_csrf" value="fixture-token">'
Assert-KiosTest ((Get-KiosCsrfToken -Html $csrfHtml) -eq 'fixture-token') 'CSRF parser extracts the hidden field'
$logoutForm = New-KiosLogoutForm -CsrfToken 'fixture-token'
Assert-KiosTest ($logoutForm.action -eq 'logout' -and $logoutForm._csrf -eq 'fixture-token') 'logout request construction includes action and CSRF'

$secureFixture = ConvertTo-SecureString -String 'synthetic-fixture' -AsPlainText -Force
$serializedFixture = ConvertFrom-SecureString -SecureString $secureFixture
$restoredFixture = ConvertTo-KiosSecureString -SerializedValue $serializedFixture
Assert-KiosTest ($restoredFixture.Length -eq $secureFixture.Length) 'serialized SecureString recovery remains decryptable'
$loginForm = New-KiosLoginForm -CsrfToken 'fixture-token' -PasswordSecureString $restoredFixture
Assert-KiosTest ($loginForm.action -eq 'login' -and $loginForm._csrf -eq 'fixture-token' -and -not [string]::IsNullOrEmpty($loginForm.password)) 'login request body is constructed without exposing it'
$loginForm['password'] = $null
$secureFixture.Dispose()
$restoredFixture.Dispose()

$allowedUri = [Uri]'https://kios-demo.joviaaninternational.com/cashier.php'
$allowedResponse = New-KiosFakeResponse -Uri $allowedUri -StatusCode 200 -Content ''
Assert-KiosTest ((Resolve-KiosResponseUri -Response $allowedResponse).Host -eq 'kios-demo.joviaaninternational.com') 'response URI accepts the exact Kios HTTPS host'
$wrongHostRejected = $false
try {
    $wrongResponse = New-KiosFakeResponse -Uri ([Uri]'https://example.invalid/cashier.php') -StatusCode 200 -Content ''
    $null = Resolve-KiosResponseUri -Response $wrongResponse
}
catch {
    $wrongHostRejected = $true
}
Assert-KiosTest $wrongHostRejected 'response URI rejects a different host'

$dryState = [PSCustomObject]@{ Authenticated = $false; FailLogout = $false; PasswordFieldPresent = $false; Calls = [Collections.Generic.List[string]]::new() }
$dryAdapter = New-KiosMockAdapter -State $dryState
$dryPassword = ConvertTo-SecureString -String 'synthetic-dry-run' -AsPlainText -Force
$dryResult = Invoke-KiosStaffSessionDryRun -PasswordSecureString $dryPassword -RequestInvoker $dryAdapter
Assert-KiosTest $dryResult.Success 'dry run completes with the mock adapter'
Assert-KiosTest ($dryResult.LoginRequestConstructed -and $dryResult.LogoutRequestConstructed) 'dry run constructs both request forms'
Assert-KiosTest (-not $dryResult.LoginPostSent -and -not $dryResult.LogoutPostSent) 'dry run sends neither login nor logout POST'
Assert-KiosTest ($dryState.Calls.Count -eq 1 -and $dryState.Calls[0] -eq 'Get /cashier.php') 'dry run performs only the login page GET'
Assert-KiosTest ($dryResult.SessionDiscarded -and $dryResult.Markers -contains 'SESSION_DISCARDED') 'dry run discards the session references'
Assert-KiosTest ($dryResult.Markers -contains 'LOGIN_POST_SENT=NOT_SENT_DRY_RUN' -and $dryResult.Markers -contains 'LOGOUT_POST_SENT=NOT_SENT_DRY_RUN') 'dry run reports POST stages explicitly as not sent'

$loginState = [PSCustomObject]@{ Authenticated = $false; FailLogout = $false; PasswordFieldPresent = $false; Calls = [Collections.Generic.List[string]]::new() }
$loginAdapter = New-KiosMockAdapter -State $loginState
$loginPassword = ConvertTo-SecureString -String 'synthetic-login-test' -AsPlainText -Force
$session = New-KiosStaffWebRequestSession
$loginResult = Invoke-KiosStaffLogin -WebRequestSession $session -PasswordSecureString $loginPassword -RequestInvoker $loginAdapter
Assert-KiosTest $loginResult.Success 'mocked login requires the authenticated page checks'
Assert-KiosTest ($loginState.PasswordFieldPresent) 'mock login POST received a constructed password field'
Assert-KiosTest (($loginResult.Markers -join ',') -eq 'LOGIN_FORM_GET_OK,CSRF_PARSED,LOGIN_POST_SENT,LOGIN_ACCEPTED,AUTHENTICATED_SESSION_CONFIRMED') 'login stages are explicit and ordered'
$logoutResult = Invoke-KiosStaffLogout -WebRequestSession $session -RequestInvoker $loginAdapter
Assert-KiosTest ($logoutResult.Success -and $logoutResult.LogoutConfirmed) 'mocked logout is confirmed by the sign-in gate'
Assert-KiosTest (($logoutResult.Markers -join ',') -eq 'LOGOUT_POST_SENT,LOGOUT_CONFIRMED') 'logout stages are explicit and ordered'
$cookieContainer = $session.Cookies
$discarded = Discard-KiosStaffSessionReferences -WebRequestSessionReference ([ref]$session) -CookieContainerReference ([ref]$cookieContainer)
Assert-KiosTest ($discarded -and $null -eq $session -and $null -eq $cookieContainer) 'session and cookie container are discarded by reference'

$failedLogoutState = [PSCustomObject]@{ Authenticated = $false; FailLogout = $true; PasswordFieldPresent = $false; Calls = [Collections.Generic.List[string]]::new() }
$failedLogoutAdapter = New-KiosMockAdapter -State $failedLogoutState
$scopePassword = ConvertTo-SecureString -String 'synthetic-scope-test' -AsPlainText -Force
$scopeSession = Invoke-KiosStaffSessionScope -PasswordSecureString $scopePassword -Workflow { param($s) 'WORKFLOW_OK' } -RequestInvoker $failedLogoutAdapter
Assert-KiosTest $scopeSession.LoginResult.Success 'login acceptance remains recorded when logout later fails'
Assert-KiosTest (-not $scopeSession.LogoutResult.Success -and $scopeSession.LogoutResult.Failure.Stage -eq 'LOGOUT_POST_SENT') 'logout failure reports its own exact stage'
Assert-KiosTest ($scopeSession.FinalStatus -eq 'LOGIN_ACCEPTED_LOGOUT_FAILED') 'cleanup failure does not overwrite login success'
Assert-KiosTest $scopeSession.SessionDiscarded 'session references are discarded even when logout fails'

$badHomeState = [PSCustomObject]@{ Authenticated = $false; FailLogout = $false; PasswordFieldPresent = $false; Calls = [Collections.Generic.List[string]]::new(); OmitStaffNavigation = $true }
$badHomeAdapter = {
    param($Request)
    $path = $Request.Uri.AbsolutePath
    if ($Request.Method -eq 'Get' -and $path -eq '/cashier.php') {
        if ($badHomeState.Authenticated) {
            return (New-KiosFakeResponse -Uri $Request.Uri -StatusCode 200 -Content '<span>Authorized demo staff session</span>')
        }
        return (New-KiosFakeResponse -Uri $Request.Uri -StatusCode 200 -Content '<form><input type="hidden" name="_csrf" value="fixture-csrf"><input name="password"><p>Sign in to cashier</p></form>')
    }
    if ($Request.Method -eq 'Post') {
        $badHomeState.Authenticated = $true
        $Request.Body['password'] = $null
        return (New-KiosFakeResponse -Uri $Request.Uri -StatusCode 303 -Content '' -Headers @{ Location = '/cashier.php' })
    }
}.GetNewClosure()
$badHomePassword = ConvertTo-SecureString -String 'synthetic-bad-home' -AsPlainText -Force
$badHomeSession = New-KiosStaffWebRequestSession
$badHomeResult = Invoke-KiosStaffLogin -WebRequestSession $badHomeSession -PasswordSecureString $badHomePassword -RequestInvoker $badHomeAdapter
Assert-KiosTest (-not $badHomeResult.Success -and $badHomeResult.Failure.Stage -eq 'AUTHENTICATED_SESSION_CONFIRMED') 'HTTP 200 alone does not establish authenticated staff navigation'
$badHomeCookies = $badHomeSession.Cookies
$null = Discard-KiosStaffSessionReferences -WebRequestSessionReference ([ref]$badHomeSession) -CookieContainerReference ([ref]$badHomeCookies)

Write-Output ('Passed: ' + $script:Passed)
Write-Output ('Failed: ' + $script:Failed)
if ($script:Failed -ne 0) {
    exit 1
}
exit 0
