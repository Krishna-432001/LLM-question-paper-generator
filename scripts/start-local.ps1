<#
.SYNOPSIS
    Start ExamForge on this PC.

.DESCRIPTION
    1. Checks everything ExamForge needs and says how to fix what is missing.
    2. Updates the database (php artisan migrate --force). Safe to repeat:
       only changes that have not been applied yet are run; nothing is deleted.
    3. Adds the starting data (languages, exams, prompts) the first time only,
       when the database has no languages yet.
    4. Opens three windows: the API, the background worker and the web app.
       Their process IDs are saved in scripts\.local-pids.json for stop-local.
    5. Waits until the API and the web app answer, opens the browser at
       http://localhost:3000 and prints the address for a phone or tablet.

    Stop everything with scripts\stop-local.bat.

    Written for Windows PowerShell 5.1: no &&, no ternary, no ?? operators,
    and ASCII only, because 5.1 misreads a UTF-8 file that has no BOM.

.PARAMETER ProjectRoot
    The ExamForge folder (the one that contains apps\api and apps\web).
    Defaults to the parent of the folder this script is in.

.PARAMETER DryRun
    Print every step without starting or changing anything.

.PARAMETER CheckOnly
    Only check the prerequisites and print the report.

.PARAMETER NoBrowser
    Do not open the browser at the end.

.EXAMPLE
    scripts\start-local.bat
.EXAMPLE
    scripts\start-local.bat -CheckOnly
#>
[CmdletBinding()]
param(
    [string] $ProjectRoot,
    [switch] $DryRun,
    [switch] $CheckOnly,
    [switch] $NoBrowser
)

$ErrorActionPreference = 'Stop'

# --------------------------------------------------------------- settings

$ApiPort = 8000
$WebPort = 3000
$ApiReadyTimeoutSeconds = 90
# The first Next.js compile after a fresh install can take a few minutes.
$WebReadyTimeoutSeconds = 240
$QueueNames = 'critical,ingest,ocr,structure,embed,generate,validate,translate,pdf,plan,predict,maintenance'
$MinPhp = [version] '8.2.0'
$MinNode = [version] '20.9.0'

$ApiWindowTitle = 'ExamForge API (port 8000)'
$WorkerWindowTitle = 'ExamForge worker (background jobs)'
$WebWindowTitle = 'ExamForge web (port 3000)'

if (-not $ProjectRoot) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}
if (-not (Test-Path -LiteralPath $ProjectRoot -PathType Container)) {
    Write-Host "Project folder not found: $ProjectRoot" -ForegroundColor Red
    exit 1
}
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).ProviderPath
$ApiDir = Join-Path $ProjectRoot 'apps\api'
$WebDir = Join-Path $ProjectRoot 'apps\web'
$EnvFile = Join-Path $ApiDir '.env'
$PidFile = Join-Path $ProjectRoot 'scripts\.local-pids.json'
$WindowsPowerShell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

# Filled in by the checks and used by the start-up steps.
$script:Results = New-Object System.Collections.ArrayList
$script:EnvValues = @{}
$script:PhpExe = $null
$script:NpmCmd = $null
$script:DbReady = $false
$script:LanguageCount = $null     # $null = unknown, -1 = no languages table yet
$script:Running = $null           # processes from a previous start that are still alive
$script:StalePidFile = $false
$script:PhpHasRedisExt = $false

# ---------------------------------------------------------------- output

function Write-Section {
    param([string] $Text)
    Write-Host ''
    Write-Host $Text -ForegroundColor Cyan
}

function Write-Step {
    param([string] $Text)
    Write-Host "==> $Text" -ForegroundColor Cyan
}

function Write-DryRun {
    param([string] $Text)
    Write-Host "[dry-run] $Text" -ForegroundColor Magenta
}

function Add-Check {
    param(
        [ValidateSet('OK', 'WARN', 'FAIL', 'INFO')] [string] $Status,
        [string] $Name,
        [string] $Detail = '',
        [string[]] $Fix = @()
    )

    $null = $script:Results.Add([pscustomobject]@{
        Status = $Status; Name = $Name; Detail = $Detail; Fix = $Fix
    })

    $color = 'Gray'
    if ($Status -eq 'OK') { $color = 'Green' }
    elseif ($Status -eq 'WARN') { $color = 'Yellow' }
    elseif ($Status -eq 'FAIL') { $color = 'Red' }

    $line = '  [{0,-4}] {1}' -f $Status, $Name
    if ($Detail) { $line = "$line - $Detail" }
    Write-Host $line -ForegroundColor $color

    $label = 'Tip:'
    if ($Status -eq 'FAIL') { $label = 'Fix:' }
    $first = $true
    foreach ($f in $Fix) {
        if ($first) {
            Write-Host "         $label $f"
            $first = $false
        } else {
            Write-Host "              $f"
        }
    }
}

function Get-FailCount {
    return @($script:Results | Where-Object { $_.Status -eq 'FAIL' }).Count
}

# ---------------------------------------------------------- .env reading

# Mirrors how Laravel (vlucas/phpdotenv) reads apps/api/.env closely enough
# for these checks: comments, "double" and 'single' quotes, inline comments
# after unquoted values, ${VAR} references, and duplicate keys where the
# LAST one wins.
function ConvertFrom-DotEnvValue {
    param([string] $Raw, [hashtable] $Known)

    if ($Raw.StartsWith('"')) {
        $sb = New-Object System.Text.StringBuilder
        $i = 1
        while ($i -lt $Raw.Length) {
            $c = $Raw[$i]
            if ($c -eq [char] '\' -and ($i + 1) -lt $Raw.Length) {
                $n = [string] $Raw[$i + 1]
                switch -CaseSensitive ($n) {
                    'n' { [void] $sb.Append("`n") }
                    'r' { [void] $sb.Append("`r") }
                    't' { [void] $sb.Append("`t") }
                    '"' { [void] $sb.Append('"') }
                    '\' { [void] $sb.Append('\') }
                    '$' { [void] $sb.Append('$') }
                    default { [void] $sb.Append('\').Append($n) }
                }
                $i += 2
                continue
            }
            if ($c -eq [char] '"') { break }
            [void] $sb.Append($c)
            $i++
        }
        return Expand-DotEnvReferences -Value $sb.ToString() -Known $Known
    }

    if ($Raw.StartsWith("'")) {
        $end = $Raw.IndexOf("'", 1)
        if ($end -lt 0) { return $Raw.Substring(1) }
        return $Raw.Substring(1, $end - 1)
    }

    # Unquoted: a # at the start, or after whitespace, begins a comment.
    if ($Raw.StartsWith('#')) { return '' }
    $value = ($Raw -replace '\s+#.*$', '').Trim()
    return Expand-DotEnvReferences -Value $value -Known $Known
}

function Expand-DotEnvReferences {
    param([string] $Value, [hashtable] $Known)

    if ($Value -notmatch '\$\{') { return $Value }
    $evaluator = {
        param($m)
        $name = $m.Groups[1].Value
        $fromProcess = [Environment]::GetEnvironmentVariable($name, 'Process')
        if ($null -ne $fromProcess) { return $fromProcess }
        if ($Known.ContainsKey($name)) { return [string] $Known[$name] }
        return ''
    }
    return [regex]::Replace($Value, '\$\{([A-Za-z_][A-Za-z0-9_.]*)\}', $evaluator)
}

function Read-DotEnv {
    param([string] $Path)

    $values = @{}
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $values }

    foreach ($raw in [System.IO.File]::ReadAllLines($Path)) {
        $line = $raw.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) { continue }
        if ($line -notmatch '^(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$') { continue }
        $key = $Matches[1]
        $rest = $Matches[2]
        # Later duplicates overwrite earlier ones, exactly as in Laravel.
        $values[$key] = ConvertFrom-DotEnvValue -Raw $rest -Known $values
    }
    return $values
}

# Value Laravel would see for env('NAME', default). A real environment
# variable wins over .env, as it does in Laravel. An empty value or the word
# null falls back to the default here: every caller needs a usable value.
function Get-Setting {
    param([string] $Name, [string] $Default = '')

    $value = [Environment]::GetEnvironmentVariable($Name, 'Process')
    if ($null -eq $value -and $script:EnvValues.ContainsKey($Name)) {
        $value = [string] $script:EnvValues[$Name]
    }
    if ($null -eq $value) { return $Default }
    $value = $value.Trim()
    if ($value -eq '' -or $value -eq 'null' -or $value -eq '(null)') { return $Default }
    return $value
}

# ------------------------------------------------------- native commands

# Windows PowerShell 5.1 turns stderr lines from a native program into
# errors, which ErrorActionPreference=Stop would make fatal. Every native
# call goes through one of these two helpers instead.
function Invoke-NativeCapture {
    param(
        [string] $FilePath,
        [string[]] $Arguments = @(),
        [string] $WorkingDirectory = $ProjectRoot
    )

    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $lines = @()
    $code = -1
    Push-Location -LiteralPath $WorkingDirectory
    try {
        $output = & $FilePath @Arguments 2>&1 | ForEach-Object { "$_" }
        $code = $LASTEXITCODE
        if ($output) { $lines = @($output) }
    } catch {
        $lines = @($_.Exception.Message)
    } finally {
        Pop-Location
        $ErrorActionPreference = $previous
    }
    return [pscustomobject]@{ ExitCode = $code; Output = $lines }
}

function Invoke-NativeStreamed {
    param(
        [string] $FilePath,
        [string[]] $Arguments = @(),
        [string] $WorkingDirectory = $ProjectRoot
    )

    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    Push-Location -LiteralPath $WorkingDirectory
    try {
        & $FilePath @Arguments 2>&1 | ForEach-Object { Write-Host "    $_" }
        return $LASTEXITCODE
    } finally {
        Pop-Location
        $ErrorActionPreference = $previous
    }
}

# A setting such as PDFTOTEXT_BIN may be a full path or a bare command name.
function Resolve-Binary {
    param([string] $Configured)

    if (-not $Configured) { return $null }
    if ($Configured -match '[\\/]') {
        foreach ($candidate in @($Configured, "$Configured.exe")) {
            if (Test-Path -LiteralPath $candidate -PathType Leaf) {
                return (Resolve-Path -LiteralPath $candidate).ProviderPath
            }
        }
        return $null
    }
    $cmd = Get-Command $Configured -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($cmd) { return $cmd.Source }
    return $null
}

# Places Poppler usually ends up on Windows when it is not on PATH.
function Find-PopplerCandidates {
    param([string] $ExeName)

    $patterns = @(
        "C:\poppler*\Library\bin\$ExeName",
        "C:\poppler*\*\Library\bin\$ExeName",
        "C:\poppler*\bin\$ExeName",
        "$env:ProgramFiles\poppler*\Library\bin\$ExeName",
        "$env:ProgramFiles\poppler*\*\Library\bin\$ExeName",
        "$env:ProgramFiles\poppler*\bin\$ExeName",
        "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\*oppler*\*\Library\bin\$ExeName",
        "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\*oppler*\Library\bin\$ExeName",
        "$env:USERPROFILE\scoop\apps\poppler\current\bin\$ExeName",
        "$env:ProgramData\chocolatey\lib\poppler\tools\*\bin\$ExeName",
        "$env:USERPROFILE\Downloads\poppler*\Library\bin\$ExeName",
        "$env:USERPROFILE\Downloads\poppler*\*\Library\bin\$ExeName"
    )
    $found = @()
    foreach ($pattern in $patterns) {
        $hits = Resolve-Path -Path $pattern -ErrorAction SilentlyContinue
        foreach ($hit in $hits) { $found += $hit.ProviderPath }
    }
    return @($found | Select-Object -Unique)
}

function ConvertTo-EnvPath {
    param([string] $Path)
    return ($Path -replace '\\', '/')
}

# ------------------------------------------------------- network helpers

function Test-TcpPort {
    param([string] $HostName, [int] $Port, [int] $TimeoutMs = 1500)

    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $async = $client.BeginConnect($HostName, $Port, $null, $null)
        if (-not $async.AsyncWaitHandle.WaitOne($TimeoutMs)) { return $false }
        $client.EndConnect($async)
        return $true
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

# Plain GET without the system proxy. Returns $null when nothing answers,
# otherwise the status code and body, including for 4xx/5xx answers.
function Invoke-LocalHttp {
    param([string] $Url, [int] $TimeoutMs = 5000)

    $response = $null
    try {
        $request = [System.Net.HttpWebRequest]::Create($Url)
        $request.Method = 'GET'
        $request.Timeout = $TimeoutMs
        $request.ReadWriteTimeout = $TimeoutMs
        $request.Proxy = $null
        $request.AllowAutoRedirect = $false
        $response = $request.GetResponse()
    } catch {
        $ex = $_.Exception
        while ($ex -and -not ($ex -is [System.Net.WebException])) { $ex = $ex.InnerException }
        if ($ex -and $ex.Response) {
            $response = $ex.Response
        } else {
            return $null
        }
    }

    try {
        $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
        $body = $reader.ReadToEnd()
        $reader.Close()
        return [pscustomobject]@{ Status = [int] $response.StatusCode; Body = $body }
    } catch {
        return [pscustomobject]@{ Status = [int] $response.StatusCode; Body = '' }
    } finally {
        $response.Close()
    }
}

function Get-PortOwner {
    param([int] $Port)

    $conn = $null
    try {
        $conn = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction Stop | Select-Object -First 1
    } catch {
        $conn = $null
    }
    if (-not $conn) {
        if (Test-TcpPort -HostName '127.0.0.1' -Port $Port -TimeoutMs 500) {
            return [pscustomobject]@{ ProcessId = 0; Name = 'an unknown program' }
        }
        return $null
    }
    $name = 'an unknown program'
    $proc = Get-Process -Id $conn.OwningProcess -ErrorAction SilentlyContinue
    if ($proc) { $name = "$($proc.ProcessName).exe" }
    return [pscustomobject]@{ ProcessId = [int] $conn.OwningProcess; Name = $name }
}

# This PC's IPv4 addresses that a phone on the same network can reach:
# adapters that are up and have a default gateway (Wi-Fi or Ethernet to the
# router). Uses .NET directly: Get-NetIPConfiguration takes ~30 s on some PCs.
function Get-LanAddresses {
    $withGateway = @()
    $other = @()
    try {
        $adapters = [System.Net.NetworkInformation.NetworkInterface]::GetAllNetworkInterfaces()
    } catch {
        return @()
    }
    foreach ($nic in $adapters) {
        if ($nic.OperationalStatus -ne [System.Net.NetworkInformation.OperationalStatus]::Up) { continue }
        $type = [string] $nic.NetworkInterfaceType
        if ($type -eq 'Loopback' -or $type -eq 'Tunnel') { continue }
        $props = $nic.GetIPProperties()
        $hasGateway = $false
        foreach ($g in $props.GatewayAddresses) {
            if ($g.Address.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork -and
                $g.Address.ToString() -ne '0.0.0.0') { $hasGateway = $true }
        }
        foreach ($u in $props.UnicastAddresses) {
            if ($u.Address.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork) { continue }
            $ip = $u.Address.ToString()
            if ($ip -like '127.*' -or $ip -like '169.254.*') { continue }
            if ($hasGateway) { $withGateway += $ip } else { $other += $ip }
        }
    }
    if ($withGateway.Count -gt 0) { return @($withGateway | Select-Object -Unique) }
    return @($other | Select-Object -Unique)
}

# ------------------------------------------------------ process tracking

function Get-ProcessStartTicks {
    param([int] $ProcessId)
    try {
        $p = Get-Process -Id $ProcessId -ErrorAction Stop
        return [string] $p.StartTime.ToUniversalTime().Ticks
    } catch {
        return $null
    }
}

# Windows reuses process IDs, so a recorded ID only counts as "ours" when
# the process behind it also started at the recorded time.
function Test-SameStart {
    param([string] $A, [string] $B)
    if (-not $A -or -not $B) { return $false }
    return ([math]::Abs([int64] $A - [int64] $B) -lt 10000000)
}

function New-ProcessEntry {
    param([string] $Role, [string] $Title, [int] $ProcessId)

    $name = ''
    $proc = Get-Process -Id $ProcessId -ErrorAction SilentlyContinue
    if ($proc) { $name = "$($proc.ProcessName).exe" }
    return [pscustomobject]@{
        role      = $Role
        title     = $Title
        pid       = $ProcessId
        name      = $name
        startTime = Get-ProcessStartTicks -ProcessId $ProcessId
    }
}

function Save-PidFile {
    param([object[]] $Entries)

    $state = [ordered]@{
        startedAt   = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz')
        projectRoot = $ProjectRoot
        processes   = @($Entries)
    }
    $json = ConvertTo-Json -InputObject $state -Depth 5
    [System.IO.File]::WriteAllText($PidFile, $json, (New-Object System.Text.UTF8Encoding($false)))
}

function Get-RunningFromPidFile {
    if (-not (Test-Path -LiteralPath $PidFile -PathType Leaf)) { return $null }

    $state = $null
    try {
        $state = Get-Content -LiteralPath $PidFile -Raw | ConvertFrom-Json
    } catch {
        return [pscustomobject]@{ StartedAt = ''; Alive = @() }
    }
    $alive = @()
    foreach ($entry in @($state.processes)) {
        if (-not $entry -or -not $entry.pid) { continue }
        $ticks = Get-ProcessStartTicks -ProcessId ([int] $entry.pid)
        if (Test-SameStart -A $ticks -B ([string] $entry.startTime)) { $alive += $entry }
    }
    return [pscustomobject]@{ StartedAt = [string] $state.startedAt; Alive = $alive }
}

# Does a service window still have its program running inside it? The
# windows stay open (-NoExit) after a crash so the error can be read, so the
# window process alone says nothing.
function Test-WindowHasProgram {
    param([int] $WindowProcessId)
    try {
        $kids = @(Get-CimInstance -ClassName Win32_Process -Filter "ParentProcessId = $WindowProcessId" -ErrorAction Stop |
            Where-Object { $_.Name -ne 'conhost.exe' })
        return ($kids.Count -gt 0)
    } catch {
        return $true   # cannot tell; assume it is still running
    }
}

# -------------------------------------------------------------- checks

function Test-ProjectFiles {
    if (-not (Test-Path -LiteralPath (Join-Path $ApiDir 'artisan') -PathType Leaf)) {
        Add-Check FAIL 'ExamForge folder' "apps\api\artisan not found under $ProjectRoot" @(
            'Run this script from the scripts folder inside ExamForge, or pass -ProjectRoot "C:\path\to\ExamForge".'
        )
        return $false
    }
    Add-Check OK 'ExamForge folder' $ProjectRoot

    if (-not (Test-Path -LiteralPath $EnvFile -PathType Leaf)) {
        Add-Check FAIL 'Settings file apps\api\.env' 'not found' @(
            'Copy apps\api\.env.example to apps\api\.env, then fill it in as docs\LOCAL-SETUP.md (step 3) explains.'
        )
        return $true
    }
    $script:EnvValues = Read-DotEnv -Path $EnvFile
    Add-Check OK 'Settings file apps\api\.env' "$($script:EnvValues.Count) settings read"

    if (-not (Get-Setting 'APP_KEY')) {
        Add-Check FAIL 'App key (APP_KEY)' 'empty' @(
            'Open PowerShell in apps\api and run:  php artisan key:generate'
        )
    }
    return $true
}

function Test-Php {
    $configured = 'php'
    $php = Resolve-Binary -Configured $configured
    if (-not $php) {
        $xampp = 'C:\xampp\php\php.exe'
        if (Test-Path -LiteralPath $xampp -PathType Leaf) {
            $php = $xampp
            Add-Check WARN 'PHP on PATH' "php is not on PATH; using $xampp" @(
                'Optional: add C:\xampp\php to your PATH so "php" works in any window.'
            )
        } else {
            Add-Check FAIL 'PHP' 'php was not found' @(
                'Install PHP 8.2 or newer (XAMPP is the easiest), then add its folder (C:\xampp\php) to PATH.',
                'See docs\LOCAL-SETUP.md, step 1.'
            )
            return
        }
    }

    $code = "echo PHP_VERSION, '|', (int) extension_loaded('pdo_pgsql'), (int) extension_loaded('mbstring'), (int) extension_loaded('openssl'), (int) extension_loaded('fileinfo'), (int) extension_loaded('redis'), '|', php_ini_loaded_file();"
    $probe = Invoke-NativeCapture -FilePath $php -Arguments @('-r', $code)
    $line = @($probe.Output | Where-Object { $_ -match '^\d+\.\d+\.\d+[^|]*\|[01]{5}\|' }) | Select-Object -Last 1
    if (-not $line) {
        Add-Check FAIL 'PHP' "$php did not run: $(($probe.Output | Select-Object -First 3) -join ' ')" @(
            'Reinstall PHP 8.2+ or fix the php.ini error shown above.'
        )
        return
    }
    $parts = $line.Split('|')
    $versionText = $parts[0]
    $ext = $parts[1]
    $ini = $parts[2]
    if (-not $ini) { $ini = 'php.ini (none loaded)' }
    $script:PhpExe = $php
    $script:PhpHasRedisExt = ($ext[4] -eq [char] '1')

    $version = [version] (($versionText -split '[^0-9.]')[0])
    if ($version -lt $MinPhp) {
        Add-Check FAIL 'PHP' "version $versionText at $php; ExamForge needs $MinPhp or newer" @(
            'Install PHP 8.2 or newer (XAMPP 8.2) and put it first on PATH.'
        )
    } else {
        Add-Check OK 'PHP' "$versionText ($php)"
    }

    $names = @('pdo_pgsql', 'mbstring', 'openssl', 'fileinfo')
    $missing = @()
    for ($i = 0; $i -lt $names.Count; $i++) {
        if ($ext[$i] -ne [char] '1') { $missing += $names[$i] }
    }
    if ($missing.Count -gt 0) {
        $lines = @("Open $ini in Notepad. For each of these lines, remove the ; at the start:")
        foreach ($m in $missing) { $lines += "  extension=$m" }
        if ($missing -contains 'pdo_pgsql') { $lines += '  extension=pgsql' }
        $lines += 'Save the file, then run this again.'
        Add-Check FAIL 'PHP extensions' "missing: $($missing -join ', ')" $lines
    } else {
        Add-Check OK 'PHP extensions' 'pdo_pgsql, mbstring, openssl, fileinfo'
    }

    if (Test-Path -LiteralPath (Join-Path $ApiDir 'vendor\autoload.php') -PathType Leaf) {
        Add-Check OK 'API packages (apps\api\vendor)' 'installed'
    } else {
        Add-Check FAIL 'API packages (apps\api\vendor)' 'not installed' @(
            'Open PowerShell in apps\api and run:  composer install'
        )
    }
}

function Test-Node {
    $node = Resolve-Binary -Configured 'node'
    if (-not $node) {
        Add-Check FAIL 'Node.js' 'node was not found' @(
            'Install Node.js 22 LTS from https://nodejs.org, then open a NEW window and run this again.'
        )
        return
    }
    $probe = Invoke-NativeCapture -FilePath $node -Arguments @('--version')
    $text = (@($probe.Output) -join '').Trim()
    $version = $null
    if ($text -match 'v?(\d+\.\d+\.\d+)') { $version = [version] $Matches[1] }
    if (-not $version) {
        Add-Check FAIL 'Node.js' "could not read the version from $node" @('Reinstall Node.js 22 LTS from https://nodejs.org')
    } elseif ($version -lt $MinNode) {
        Add-Check FAIL 'Node.js' "$text is too old; the web app needs $MinNode or newer" @(
            'Install Node.js 22 LTS from https://nodejs.org'
        )
    } else {
        Add-Check OK 'Node.js' "$text ($node)"
    }

    $npm = Resolve-Binary -Configured 'npm.cmd'
    if (-not $npm) {
        $besideNode = Join-Path (Split-Path -Parent $node) 'npm.cmd'
        if (Test-Path -LiteralPath $besideNode -PathType Leaf) { $npm = $besideNode }
    }
    if ($npm) {
        $script:NpmCmd = $npm
        Add-Check OK 'npm' $npm
    } else {
        Add-Check FAIL 'npm' 'npm.cmd was not found' @('Reinstall Node.js 22 LTS from https://nodejs.org (npm comes with it).')
    }

    if (Test-Path -LiteralPath (Join-Path $WebDir 'node_modules\next\package.json') -PathType Leaf) {
        Add-Check OK 'Web packages (apps\web\node_modules)' 'installed'
    } else {
        Add-Check FAIL 'Web packages (apps\web\node_modules)' 'not installed' @(
            'Open PowerShell in apps\web and run:  npm install'
        )
    }
}

# Logs in with the .env credentials through PHP's own PostgreSQL driver and
# counts languages. Read-only. Credentials travel in environment variables,
# not on the command line, so they never show up in a process list.
function Get-DbSettings {
    # The password is taken as written (not trimmed); Laravel's own defaults
    # apply to everything that is missing.
    $password = ''
    if ($script:EnvValues.ContainsKey('DB_PASSWORD')) { $password = [string] $script:EnvValues['DB_PASSWORD'] }
    $fromProcess = [Environment]::GetEnvironmentVariable('DB_PASSWORD', 'Process')
    if ($null -ne $fromProcess) { $password = $fromProcess }
    if ($password -eq 'null' -or $password -eq '(null)') { $password = '' }

    return [pscustomobject]@{
        DbHost   = Get-Setting 'DB_HOST' '127.0.0.1'
        DbPort   = Get-Setting 'DB_PORT' '5432'
        Database = Get-Setting 'DB_DATABASE' 'laravel'
        User     = Get-Setting 'DB_USERNAME' 'root'
        Password = $password
    }
}

function Invoke-DatabaseProbe {
    param([object] $Settings)

    $code = @'
try {
    $pdo = new PDO('pgsql:host=' . getenv('EF_PROBE_HOST') . ';port=' . getenv('EF_PROBE_PORT') . ';dbname=' . getenv('EF_PROBE_DB') . ';connect_timeout=5', getenv('EF_PROBE_USER'), (string) getenv('EF_PROBE_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    echo 'LOGIN_FAILED|', str_replace([chr(13), chr(10)], ' ', $e->getMessage());
    exit(0);
}
if (! $pdo->query('select to_regclass(\'languages\') is not null')->fetchColumn()) {
    echo 'NO_TABLE|';
    exit(0);
}
echo 'COUNT|', $pdo->query('select count(*) from languages')->fetchColumn();
'@
    # One line, and no double quotes anywhere above: Windows PowerShell 5.1
    # mangles double quotes inside arguments it passes to native programs.
    $code = $code -replace '\r?\n', ' '

    $names = @('EF_PROBE_HOST', 'EF_PROBE_PORT', 'EF_PROBE_DB', 'EF_PROBE_USER', 'EF_PROBE_PASS')
    $values = @($Settings.DbHost, $Settings.DbPort, $Settings.Database, $Settings.User, $Settings.Password)
    try {
        for ($i = 0; $i -lt $names.Count; $i++) {
            [Environment]::SetEnvironmentVariable($names[$i], $values[$i], 'Process')
        }
        $probe = Invoke-NativeCapture -FilePath $script:PhpExe -Arguments @('-r', $code)
    } finally {
        foreach ($n in $names) { [Environment]::SetEnvironmentVariable($n, $null, 'Process') }
    }
    $line = @($probe.Output | Where-Object { $_ -match '^(LOGIN_FAILED|NO_TABLE|COUNT)\|' }) | Select-Object -Last 1
    if (-not $line) {
        return [pscustomobject]@{ Kind = 'ERROR'; Value = (($probe.Output | Select-Object -First 3) -join ' ') }
    }
    $idx = $line.IndexOf('|')
    return [pscustomobject]@{ Kind = $line.Substring(0, $idx); Value = $line.Substring($idx + 1) }
}

function Test-Database {
    $connection = Get-Setting 'DB_CONNECTION' 'sqlite'
    if ($connection -ne 'pgsql') {
        Add-Check FAIL 'Database type' "DB_CONNECTION=$connection" @(
            'ExamForge needs PostgreSQL. In apps\api\.env set DB_CONNECTION=pgsql (see docs\LOCAL-SETUP.md, step 3).'
        )
        return
    }

    $db = Get-DbSettings
    $dbHost = $db.DbHost
    $dbPort = $db.DbPort
    $database = $db.Database
    $user = $db.User

    if (-not (Test-TcpPort -HostName $dbHost -Port ([int] $dbPort))) {
        $fix = @()
        $service = Get-Service -Name 'postgresql*' -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($service) {
            $fix += "PostgreSQL is installed but its service '$($service.Name)' is $($service.Status)."
            $fix += "Start it: press Win+R, type services.msc, press Enter, right-click $($service.Name), choose Start."
        } else {
            $fix += 'Install PostgreSQL 18 and run apps\api\database\setup.sql (docs\LOCAL-SETUP.md, steps 1 and 2).'
        }
        Add-Check FAIL 'PostgreSQL' "nothing is listening on ${dbHost}:$dbPort" $fix
        return
    }
    Add-Check OK 'PostgreSQL' "listening on ${dbHost}:$dbPort"

    if (-not $script:PhpExe) { return }
    $probe = Invoke-DatabaseProbe -Settings $db
    if ($probe.Kind -eq 'LOGIN_FAILED') {
        $fix = @()
        if ($probe.Value -match 'password authentication failed') {
            $fix += "DB_PASSWORD in apps\api\.env does not match the password of the '$user' database user."
            $fix += 'Use the password you put in apps\api\database\setup.sql.'
        } elseif ($probe.Value -match 'does not exist') {
            $fix += 'The database or user has not been created yet. Run apps\api\database\setup.sql (docs\LOCAL-SETUP.md, step 2).'
        } elseif ($probe.Value -match 'could not find driver') {
            $fix += 'Enable extension=pdo_pgsql in php.ini (see the PHP extensions line above).'
        } else {
            $fix += 'Check DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD in apps\api\.env.'
        }
        Add-Check FAIL 'Database login' "could not log in to '$database' as '$user': $($probe.Value)" $fix
        return
    }
    if ($probe.Kind -eq 'ERROR') {
        Add-Check FAIL 'Database login' "the check itself failed: $($probe.Value)" @(
            'Check DB_* in apps\api\.env and that pdo_pgsql is enabled in php.ini.'
        )
        return
    }

    $script:DbReady = $true
    if ($probe.Kind -eq 'NO_TABLE') {
        $script:LanguageCount = -1
        Add-Check INFO 'Database login' "logged in to '$database' as '$user'; it is empty, so tables and starting data will be created"
    } else {
        $script:LanguageCount = [int] $probe.Value
        if ($script:LanguageCount -eq 0) {
            Add-Check INFO 'Database login' "logged in to '$database' as '$user'; no languages yet, so starting data will be added"
        } else {
            Add-Check OK 'Database login' "logged in to '$database' as '$user'; $($script:LanguageCount) languages present"
        }
    }
}

function Test-Redis {
    $users = @()
    $defaults = @{ QUEUE_CONNECTION = 'database'; CACHE_STORE = 'database'; SESSION_DRIVER = 'database' }
    foreach ($key in @('QUEUE_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER')) {
        if ((Get-Setting $key $defaults[$key]) -eq 'redis') { $users += "$key=redis" }
    }
    if ($users.Count -eq 0) {
        Add-Check INFO 'Redis' "not needed (QUEUE_CONNECTION=$(Get-Setting 'QUEUE_CONNECTION' 'database'))"
        return
    }

    $redisHost = Get-Setting 'REDIS_HOST' '127.0.0.1'
    $redisPort = Get-Setting 'REDIS_PORT' '6379'
    if (Test-TcpPort -HostName $redisHost -Port ([int] $redisPort)) {
        Add-Check OK 'Redis' "listening on ${redisHost}:$redisPort (used by $($users -join ', '))"
    } else {
        $fix = @()
        $service = Get-Service -Name 'Memurai*', 'Redis*' -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($service) {
            $fix += "Redis/Memurai is installed but its service '$($service.Name)' is $($service.Status)."
            $fix += "Start it: press Win+R, type services.msc, press Enter, right-click $($service.Name), choose Start."
        } else {
            $fix += 'Install Memurai (a Redis for Windows): https://www.memurai.com/get-memurai'
        }
        $fix += 'Or run without Redis: in apps\api\.env set QUEUE_CONNECTION=database and CACHE_STORE=database.'
        Add-Check FAIL 'Redis' "nothing is listening on ${redisHost}:$redisPort ($($users -join ', '))" $fix
    }

    $client = Get-Setting 'REDIS_CLIENT' 'phpredis'
    if ($client -eq 'phpredis' -and $script:PhpExe -and -not $script:PhpHasRedisExt) {
        Add-Check FAIL 'Redis client' 'REDIS_CLIENT is phpredis but PHP has no redis extension' @(
            'In apps\api\.env set REDIS_CLIENT=predis (it is already installed with the API packages).'
        )
    }
}

function Test-PdfTools {
    $configured = Get-Setting 'PDFTOTEXT_BIN' 'pdftotext'
    $bin = Resolve-Binary -Configured $configured
    if ($bin) {
        $probe = Invoke-NativeCapture -FilePath $bin -Arguments @('-v')
        $banner = @($probe.Output | Where-Object { $_ -match 'pdftotext' }) | Select-Object -First 1
        if ($banner) {
            Add-Check OK 'pdftotext (reads PDF books)' "$($banner.Trim()) ($bin)"
        } else {
            Add-Check FAIL 'pdftotext (reads PDF books)' "$bin did not answer to -v" @(
                'Install Poppler again (docs\LOCAL-SETUP.md, step 1) and point PDFTOTEXT_BIN at its pdftotext.exe.'
            )
        }
    } else {
        $fix = @()
        $candidates = @(Find-PopplerCandidates -ExeName 'pdftotext.exe')
        $gitCopy = Join-Path $env:ProgramFiles 'Git\mingw64\bin\pdftotext.exe'
        if ($candidates.Count -gt 0) {
            $fix += "Poppler is on this PC. Add this line at the end of apps\api\.env:"
            $fix += "  PDFTOTEXT_BIN=`"$(ConvertTo-EnvPath $candidates[0])`""
            $pdftoppm = Join-Path (Split-Path -Parent $candidates[0]) 'pdftoppm.exe'
            if (Test-Path -LiteralPath $pdftoppm -PathType Leaf) {
                $fix += "  PDFTOPPM_BIN=`"$(ConvertTo-EnvPath $pdftoppm)`""
            }
        } else {
            $fix += 'Install Poppler: download the latest Release zip from'
            $fix += '  https://github.com/oschwartz10612/poppler-windows/releases'
            $fix += 'unzip it to C:\poppler, then add at the end of apps\api\.env:'
            $fix += '  PDFTOTEXT_BIN="C:/poppler/Library/bin/pdftotext.exe"'
            $fix += '  PDFTOPPM_BIN="C:/poppler/Library/bin/pdftoppm.exe"'
            if (Test-Path -LiteralPath $gitCopy -PathType Leaf) {
                $fix += "Stopgap: Git ships an older xpdf pdftotext at $gitCopy."
                $fix += "  It also works: PDFTOTEXT_BIN=`"$(ConvertTo-EnvPath $gitCopy)`""
            }
        }
        Add-Check FAIL 'pdftotext (reads PDF books)' "'$configured' was not found" $fix
    }

    $ocr = Get-Setting 'OCR_DRIVER' 'fake'
    if ($ocr -ne 'tesseract') {
        Add-Check INFO 'OCR for scanned books' "OCR_DRIVER=$ocr (scanned pages without a text layer are not read)"
        return
    }

    $tesseract = Resolve-Binary -Configured (Get-Setting 'TESSERACT_BIN' 'tesseract')
    if (-not $tesseract) {
        Add-Check WARN 'Tesseract (scanned books)' "'$(Get-Setting 'TESSERACT_BIN' 'tesseract')' was not found" @(
            'Only needed for scanned books. Run scripts\install-deps.ps1 as administrator,',
            'or set OCR_DRIVER=fake in apps\api\.env to turn OCR off.'
        )
    } else {
        $tessdata = Join-Path (Split-Path -Parent $tesseract) 'tessdata'
        if ($env:TESSDATA_PREFIX) { $tessdata = $env:TESSDATA_PREFIX }
        if (Test-Path -LiteralPath (Join-Path $tessdata 'tam.traineddata') -PathType Leaf) {
            Add-Check OK 'Tesseract (scanned books)' "$tesseract, Tamil data present"
        } else {
            Add-Check WARN 'Tesseract (scanned books)' "no Tamil data (tam.traineddata) in $tessdata" @(
                'Scanned Tamil pages would come out as nonsense. Run scripts\install-deps.ps1 as administrator.'
            )
        }
    }

    $ppmConfigured = Get-Setting 'PDFTOPPM_BIN' 'pdftoppm'
    if (Resolve-Binary -Configured $ppmConfigured) {
        Add-Check OK 'pdftoppm (page images for OCR)' (Resolve-Binary -Configured $ppmConfigured)
    } else {
        $fix = @('Comes with Poppler. Add at the end of apps\api\.env, matching where Poppler is:')
        $candidates = @(Find-PopplerCandidates -ExeName 'pdftoppm.exe')
        if ($candidates.Count -gt 0) {
            $fix += "  PDFTOPPM_BIN=`"$(ConvertTo-EnvPath $candidates[0])`""
        } else {
            $fix += '  PDFTOPPM_BIN="C:/poppler/Library/bin/pdftoppm.exe"'
        }
        Add-Check WARN 'pdftoppm (page images for OCR)' "'$ppmConfigured' was not found; scanned books cannot be read" $fix
    }
}

function ConvertTo-OllamaName {
    param([string] $Name)
    $n = $Name.Trim().ToLowerInvariant()
    $n = $n -replace '^registry\.ollama\.ai/library/', ''
    if ($n -notmatch ':') { $n = "${n}:latest" }
    return $n
}

function Test-Ai {
    $driver = Get-Setting 'AI_DRIVER' 'fake'
    $embedDriver = Get-Setting 'EMBEDDING_DRIVER' 'fake'

    if ($driver -eq 'fake') {
        Add-Check WARN 'AI model' 'AI_DRIVER=fake: questions are placeholders, not real questions' @(
            'In apps\api\.env set AI_DRIVER=ollama (free, runs on this PC) or AI_DRIVER=claude (needs an API key).',
            'See "Switching between Ollama and Claude" in docs\LOCAL-SETUP.md.'
        )
    } elseif ($driver -eq 'claude') {
        if (Get-Setting 'ANTHROPIC_API_KEY') {
            Add-Check OK 'AI model' 'AI_DRIVER=claude, ANTHROPIC_API_KEY is set (not tested here)'
        } else {
            Add-Check FAIL 'AI model' 'AI_DRIVER=claude but ANTHROPIC_API_KEY is empty' @(
                'Put your key in apps\api\.env:  ANTHROPIC_API_KEY=sk-ant-...',
                'Or use the free local model: AI_DRIVER=ollama.'
            )
        }
    } elseif ($driver -eq 'openai') {
        if (Get-Setting 'OPENAI_API_KEY') {
            Add-Check OK 'AI model' 'AI_DRIVER=openai, OPENAI_API_KEY is set (not tested here)'
        } else {
            Add-Check FAIL 'AI model' 'AI_DRIVER=openai but OPENAI_API_KEY is empty' @('Set OPENAI_API_KEY in apps\api\.env, or use AI_DRIVER=ollama.')
        }
    } elseif ($driver -ne 'ollama') {
        Add-Check FAIL 'AI model' "AI_DRIVER=$driver is not a known driver" @('Use one of: ollama, claude, fake.')
    }

    if ($embedDriver -eq 'fake') {
        $tip = @()
        if ($driver -eq 'ollama') {
            $tip = @('For better matching of questions to book passages set, in apps\api\.env:',
                '  EMBEDDING_DRIVER=ollama', '  EMBEDDING_MODEL=bge-m3')
        }
        $status = 'INFO'
        if ($driver -eq 'ollama') { $status = 'WARN' }
        Add-Check $status 'Embeddings' 'EMBEDDING_DRIVER=fake (search by meaning is off; keyword search still works)' $tip
    } elseif ($embedDriver -ne 'ollama') {
        Add-Check INFO 'Embeddings' "EMBEDDING_DRIVER=$embedDriver"
    }

    if ($driver -ne 'ollama' -and $embedDriver -ne 'ollama') { return }

    # ---- Ollama
    $baseUrl = (Get-Setting 'OLLAMA_BASE_URL' 'http://127.0.0.1:11434').TrimEnd('/')
    $wanted = @()
    if ($driver -eq 'ollama') {
        $main = Get-Setting 'OLLAMA_MODEL' 'gemma3:12b'
        $wanted += $main
        foreach ($tier in @('OLLAMA_MODEL_PREMIUM', 'OLLAMA_MODEL_MID', 'OLLAMA_MODEL_CHEAP')) {
            $m = Get-Setting $tier ''
            if ($m) { $wanted += $m }
        }
    }
    if ($embedDriver -eq 'ollama') {
        $embedModel = Get-Setting 'EMBEDDING_MODEL' ''
        if (-not $embedModel) {
            Add-Check FAIL 'Embedding model' 'EMBEDDING_DRIVER=ollama but EMBEDDING_MODEL is not set' @(
                'In apps\api\.env set EMBEDDING_MODEL=bge-m3 and EMBEDDING_DIMENSIONS=1024.'
            )
        } else {
            $wanted += $embedModel
            $dims = Get-Setting 'EMBEDDING_DIMENSIONS' '1024'
            if ($dims -ne '1024') {
                Add-Check WARN 'Embedding size' "EMBEDDING_DIMENSIONS=$dims; the search index stores 1024" @(
                    'Use EMBEDDING_DIMENSIONS=1024 with bge-m3.'
                )
            }
        }
    }
    $wanted = @($wanted | Where-Object { $_ } | Select-Object -Unique)

    $tags = Invoke-LocalHttp -Url "$baseUrl/api/tags" -TimeoutMs 5000
    if (-not $tags -or $tags.Status -ne 200) {
        $detail = "not reachable at $baseUrl"
        if ($tags) { $detail = "$baseUrl/api/tags answered HTTP $($tags.Status)" }
        $fix = @()
        $installed = (Resolve-Binary -Configured 'ollama') -or (Test-Path -LiteralPath (Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama.exe'))
        if ($installed) {
            $fix += 'Start Ollama: open the Start menu, type Ollama, press Enter. Wait for the llama icon near the clock.'
        } else {
            $fix += 'Install Ollama from https://ollama.com/download (Windows), then start it from the Start menu.'
        }
        $fix += 'Then download the models (in a new PowerShell window):'
        foreach ($m in $wanted) { $fix += "  ollama pull $m" }
        Add-Check FAIL 'Ollama' $detail $fix
        return
    }

    $models = @()
    try {
        $parsed = $tags.Body | ConvertFrom-Json
        foreach ($m in @($parsed.models)) {
            if ($m.name) { $models += ConvertTo-OllamaName $m.name }
            if ($m.model) { $models += ConvertTo-OllamaName $m.model }
        }
    } catch {
        $models = @()
    }
    Add-Check OK 'Ollama' "running at $baseUrl ($(@($models | Select-Object -Unique).Count) models downloaded)"

    $missing = @()
    foreach ($m in $wanted) {
        if ($models -notcontains (ConvertTo-OllamaName $m)) { $missing += $m }
    }
    if ($missing.Count -eq 0) {
        Add-Check OK 'Ollama models' ($wanted -join ', ')
    } else {
        $fix = @('Open a new PowerShell window and run:')
        foreach ($m in $missing) { $fix += "  ollama pull $m" }
        $fix += 'Each download is several GB and only needed once.'
        Add-Check FAIL 'Ollama models' "not downloaded yet: $($missing -join ', ')" $fix
    }
}

function Test-PortsAndState {
    $script:Running = Get-RunningFromPidFile
    if ($script:Running -and $script:Running.Alive.Count -gt 0) {
        Add-Check INFO 'Already running' "ExamForge was started at $($script:Running.StartedAt) and is still running"
        return
    }
    if ($script:Running) {
        $script:StalePidFile = $true
        $script:Running = $null
    }

    foreach ($pair in @(@($ApiPort, 'API'), @($WebPort, 'web app'))) {
        $port = [int] $pair[0]
        $owner = Get-PortOwner -Port $port
        if ($owner) {
            $who = $owner.Name
            if ($owner.ProcessId) { $who = "$($owner.Name) (PID $($owner.ProcessId))" }
            Add-Check FAIL "Port $port ($($pair[1]))" "already in use by $who" @(
                'If that is an old ExamForge window, close it (or run scripts\stop-local.bat).',
                'Otherwise close the program using the port, or restart the PC.'
            )
        } else {
            Add-Check OK "Port $port ($($pair[1]))" 'free'
        }
    }
}

function Invoke-AllChecks {
    Write-Section 'Checking prerequisites'
    if (-not (Test-ProjectFiles)) { return }
    Test-Php
    Test-Node
    Test-Database
    Test-Redis
    Test-PdfTools
    Test-Ai
    Test-PortsAndState
}

function Write-CheckSummary {
    $ok = @($script:Results | Where-Object { $_.Status -eq 'OK' }).Count
    $warn = @($script:Results | Where-Object { $_.Status -eq 'WARN' }).Count
    $fail = Get-FailCount
    Write-Host ''
    $color = 'Green'
    if ($warn -gt 0) { $color = 'Yellow' }
    if ($fail -gt 0) { $color = 'Red' }
    Write-Host "Summary: $ok OK, $warn warning(s), $fail problem(s) to fix." -ForegroundColor $color
}

# ------------------------------------------------------------ start-up

function Get-PendingMigrationNote {
    $status = Invoke-NativeCapture -FilePath $script:PhpExe -Arguments @('artisan', 'migrate:status', '--no-interaction', '--no-ansi') -WorkingDirectory $ApiDir
    $text = $status.Output -join "`n"
    if ($text -match 'Migration table not found') { return 'fresh database: every migration would run' }
    if ($status.ExitCode -ne 0) { return "could not read migration status: $(($status.Output | Select-Object -First 2) -join ' ')" }
    $pending = @($status.Output | Where-Object { $_ -match '\bPending\b' }).Count
    $ran = @($status.Output | Where-Object { $_ -match '\bRan\b' }).Count
    if ($pending -eq 0) { return "nothing new to apply ($ran already applied)" }
    return "$pending new migration(s) would be applied ($ran already applied)"
}

function ConvertTo-PsLiteral {
    param([string] $Text)
    return "'" + $Text.Replace("'", "''") + "'"
}

# Opens a titled PowerShell window running $Body. -EncodedCommand avoids
# every quoting problem with the spaces in the project path. -NoExit keeps
# the window open after a crash so the error stays readable.
function Start-ServiceWindow {
    param([string] $Title, [string] $WorkingDirectory, [string] $Body)

    $script = @(
        "`$Host.UI.RawUI.WindowTitle = $(ConvertTo-PsLiteral $Title)",
        "Set-Location -LiteralPath $(ConvertTo-PsLiteral $WorkingDirectory)",
        "Write-Host $(ConvertTo-PsLiteral "$Title - keep this window open. To stop ExamForge run scripts\stop-local.bat") -ForegroundColor Cyan",
        $Body
    ) -join "`n"
    $encoded = [Convert]::ToBase64String([System.Text.Encoding]::Unicode.GetBytes($script))
    return Start-Process -FilePath $WindowsPowerShell `
        -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-NoExit', '-EncodedCommand', $encoded) `
        -WorkingDirectory $WorkingDirectory -PassThru
}

function Wait-ForUrl {
    param(
        [string] $Url,
        [int] $TimeoutSeconds,
        [int] $RequestTimeoutMs,
        [System.Diagnostics.Process] $Window,
        [switch] $RequireOk
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    $started = Get-Date
    $last = $null
    $emptyChecks = 0
    while ((Get-Date) -lt $deadline) {
        $r = Invoke-LocalHttp -Url $Url -TimeoutMs $RequestTimeoutMs
        if ($r) {
            $last = $r.Status
            if ($RequireOk) {
                if ($r.Status -eq 200) { return [pscustomobject]@{ Ready = $true; Status = $r.Status; Reason = '' } }
            } elseif ($r.Status -lt 500) {
                return [pscustomobject]@{ Ready = $true; Status = $r.Status; Reason = '' }
            }
        }
        if ($Window.HasExited) {
            return [pscustomobject]@{ Ready = $false; Status = $last; Reason = 'its window was closed' }
        }
        if (((Get-Date) - $started).TotalSeconds -gt 5 -and -not (Test-WindowHasProgram -WindowProcessId $Window.Id)) {
            $emptyChecks++
            if ($emptyChecks -ge 2) {
                return [pscustomobject]@{ Ready = $false; Status = $last; Reason = 'it stopped right after starting' }
            }
        } else {
            $emptyChecks = 0
        }
        Start-Sleep -Seconds 2
    }
    $reason = "it did not answer within $TimeoutSeconds seconds"
    if ($last) { $reason = "it kept answering HTTP $last for $TimeoutSeconds seconds" }
    return [pscustomobject]@{ Ready = $false; Status = $last; Reason = $reason }
}

function Write-Addresses {
    Write-Host ''
    Write-Host "  On this PC:        http://localhost:$WebPort" -ForegroundColor Green
    $ips = @(Get-LanAddresses)
    if ($ips.Count -gt 0) {
        $first = $true
        foreach ($ip in $ips) {
            $label = '                     '
            if ($first) { $label = '  Phone or tablet:   '; $first = $false }
            Write-Host "${label}http://${ip}:$WebPort   (same Wi-Fi as this PC)" -ForegroundColor Green
        }
        Write-Host '  If the phone cannot open it, see "Opening from a phone" in docs\LOCAL-SETUP.md.'
    } else {
        Write-Host '  Phone or tablet:   no network address found (is this PC on Wi-Fi?)' -ForegroundColor Yellow
    }
}

function Open-Browser {
    if ($NoBrowser) { return }
    try {
        Start-Process "http://localhost:$WebPort" | Out-Null
    } catch {
        Write-Host "Could not open the browser automatically. Open http://localhost:$WebPort yourself." -ForegroundColor Yellow
    }
}

function Invoke-Start {
    $php = ConvertTo-PsLiteral $script:PhpExe
    $npm = ConvertTo-PsLiteral $script:NpmCmd
    if (-not $script:PhpExe) { $php = "'php'" }
    if (-not $script:NpmCmd) { $npm = "'npm.cmd'" }

    $apiBody = @(
        "& $php artisan serve --host=0.0.0.0 --port=$ApiPort",
        "Write-Host 'The API stopped. Read the messages above, then run scripts\stop-local.bat.' -ForegroundColor Red"
    ) -join "`n"
    # queue:work exits on its own now and then (memory limit, queue:restart);
    # the loop brings it back so background jobs never silently stop.
    $workerBody = @(
        'while ($true) {',
        "    & $php artisan queue:work '--queue=$QueueNames' --timeout=900 --tries=3",
        "    Write-Host ('The worker stopped (exit code ' + `$LASTEXITCODE + '). Restarting in 5 seconds...') -ForegroundColor Yellow",
        '    Start-Sleep -Seconds 5',
        '}'
    ) -join "`n"
    # PORT pins Next.js to 3000: given a port, it stops with an error instead
    # of quietly moving to 3001, which the browser and phone would not find.
    $webBody = @(
        "`$env:PORT = '$WebPort'",
        "& $npm run dev",
        "Write-Host 'The web app stopped. Read the messages above, then run scripts\stop-local.bat.' -ForegroundColor Red"
    ) -join "`n"

    # ---- already running: nothing to start
    if ($script:Running) {
        Write-Section 'ExamForge is already running'
        if ($DryRun) {
            Write-DryRun 'would start nothing; a real run would only open the browser'
            if (-not $NoBrowser) { Write-DryRun "would open http://localhost:$WebPort" }
        } else {
            Open-Browser
        }
        Write-Addresses
        Write-Host ''
        Write-Host 'To restart it, run scripts\stop-local.bat first, then start-local again.'
        return 0
    }

    if ($script:StalePidFile) {
        if ($DryRun) {
            Write-DryRun 'would delete the leftover scripts\.local-pids.json (none of its processes are running)'
        } else {
            Remove-Item -LiteralPath $PidFile -Force
        }
    }

    # ---- database
    Write-Section 'Database'
    if ($DryRun) {
        $note = 'database not reachable yet, so the pending count is unknown'
        if ($script:DbReady -and $script:PhpExe) { $note = Get-PendingMigrationNote }
        Write-DryRun "would run in apps\api:  php artisan migrate --force --no-interaction   ($note)"
        if ($script:LanguageCount -eq -1) {
            Write-DryRun 'would run in apps\api:  php artisan db:seed --force --no-interaction   (no languages table yet)'
        } elseif ($script:LanguageCount -eq 0) {
            Write-DryRun 'would run in apps\api:  php artisan db:seed --force --no-interaction   (no languages yet)'
        } elseif ($null -ne $script:LanguageCount) {
            Write-DryRun "would skip seeding: the database already has $($script:LanguageCount) languages"
        } else {
            Write-DryRun 'would seed only if the database has no languages after migrating'
        }
    } else {
        Write-Step 'Applying database updates (php artisan migrate --force)'
        $code = Invoke-NativeStreamed -FilePath $script:PhpExe -Arguments @('artisan', 'migrate', '--force', '--no-interaction') -WorkingDirectory $ApiDir
        if ($code -ne 0) {
            Write-Host ''
            Write-Host "The database update failed (exit code $code). Read the error above." -ForegroundColor Red
            Write-Host 'Nothing was started. Fix the error, then run start-local again.'
            return 1
        }

        $probe = Invoke-DatabaseProbe -Settings (Get-DbSettings)
        $needsSeed = ($probe.Kind -eq 'NO_TABLE') -or ($probe.Kind -eq 'COUNT' -and [int] $probe.Value -eq 0)
        if ($needsSeed) {
            Write-Step 'Adding starting data: languages, exams, prompts (php artisan db:seed --force)'
            $code = Invoke-NativeStreamed -FilePath $script:PhpExe -Arguments @('artisan', 'db:seed', '--force', '--no-interaction') -WorkingDirectory $ApiDir
            if ($code -ne 0) {
                Write-Host ''
                Write-Host "Adding the starting data failed (exit code $code). Read the error above." -ForegroundColor Red
                return 1
            }
        } elseif ($probe.Kind -eq 'COUNT') {
            Write-Step "Starting data already present ($($probe.Value) languages); not seeding"
        } else {
            Write-Host "    Could not count languages ($($probe.Value)); not seeding." -ForegroundColor Yellow
        }
    }

    # ---- windows
    Write-Section 'Starting ExamForge'
    if ($DryRun) {
        Write-DryRun "would open window '$ApiWindowTitle' in apps\api:"
        Write-DryRun "    php artisan serve --host=0.0.0.0 --port=$ApiPort"
        Write-DryRun "would open window '$WorkerWindowTitle' in apps\api:"
        Write-DryRun "    php artisan queue:work --queue=$QueueNames --timeout=900 --tries=3   (restarted if it exits)"
        Write-DryRun "would open window '$WebWindowTitle' in apps\web:"
        Write-DryRun "    npm run dev   (with PORT=$WebPort)"
        Write-DryRun "would save the three window process IDs to $PidFile"
        Write-DryRun "would wait up to $ApiReadyTimeoutSeconds s for http://127.0.0.1:$ApiPort/up and $WebReadyTimeoutSeconds s for http://127.0.0.1:$WebPort"
        if ($NoBrowser) {
            Write-DryRun 'would not open the browser (-NoBrowser)'
        } else {
            Write-DryRun "would open http://localhost:$WebPort in the browser"
        }
        Write-Addresses
        return 0
    }

    $entries = @()
    Write-Step "Opening '$ApiWindowTitle'"
    $api = Start-ServiceWindow -Title $ApiWindowTitle -WorkingDirectory $ApiDir -Body $apiBody
    $entries += New-ProcessEntry -Role 'api' -Title $ApiWindowTitle -ProcessId $api.Id
    Write-Step "Opening '$WorkerWindowTitle'"
    $worker = Start-ServiceWindow -Title $WorkerWindowTitle -WorkingDirectory $ApiDir -Body $workerBody
    $entries += New-ProcessEntry -Role 'worker' -Title $WorkerWindowTitle -ProcessId $worker.Id
    Write-Step "Opening '$WebWindowTitle'"
    $web = Start-ServiceWindow -Title $WebWindowTitle -WorkingDirectory $WebDir -Body $webBody
    $entries += New-ProcessEntry -Role 'web' -Title $WebWindowTitle -ProcessId $web.Id
    # Saved straight away, so stop-local works even if start-up fails below.
    Save-PidFile -Entries $entries
    Write-Host "    Process IDs saved to scripts\.local-pids.json ($($api.Id), $($worker.Id), $($web.Id))"

    Write-Step "Waiting for the API (http://127.0.0.1:$ApiPort/up)"
    $apiWait = Wait-ForUrl -Url "http://127.0.0.1:$ApiPort/up" -TimeoutSeconds $ApiReadyTimeoutSeconds -RequestTimeoutMs 5000 -Window $api -RequireOk
    if (-not $apiWait.Ready) {
        Write-Host ''
        Write-Host "The API is not answering: $($apiWait.Reason)." -ForegroundColor Red
        Write-Host "Look at the window titled '$ApiWindowTitle' for the error."
        Write-Host 'Then run scripts\stop-local.bat to close the windows, fix the error and start again.'
        return 1
    }
    Write-Host '    API is ready.' -ForegroundColor Green

    Write-Step "Waiting for the web app (http://127.0.0.1:$WebPort) - the first start can take a few minutes"
    $webWait = Wait-ForUrl -Url "http://127.0.0.1:$WebPort" -TimeoutSeconds $WebReadyTimeoutSeconds -RequestTimeoutMs 60000 -Window $web
    if (-not $webWait.Ready) {
        Write-Host ''
        Write-Host "The web app is not answering: $($webWait.Reason)." -ForegroundColor Red
        Write-Host "Look at the window titled '$WebWindowTitle' for the error."
        Write-Host 'Then run scripts\stop-local.bat to close the windows, fix the error and start again.'
        return 1
    }
    Write-Host '    Web app is ready.' -ForegroundColor Green

    # The servers that actually hold the ports, recorded too, so stop-local
    # still finds them if their window's process tree is ever broken.
    foreach ($pair in @(@($ApiPort, 'api-server'), @($WebPort, 'web-server'))) {
        $owner = Get-PortOwner -Port ([int] $pair[0])
        if ($owner -and $owner.ProcessId) {
            $entries += New-ProcessEntry -Role $pair[1] -Title "listening on port $($pair[0])" -ProcessId $owner.ProcessId
        }
    }
    Save-PidFile -Entries $entries

    Open-Browser
    Write-Host ''
    Write-Host 'ExamForge is running.' -ForegroundColor Green
    Write-Addresses
    Write-Host ''
    Write-Host 'Keep the three ExamForge windows open while you work (you can minimise them).'
    Write-Host 'To stop: double-click scripts\stop-local.bat'
    return 0
}

# ----------------------------------------------------------------- main

try {
    $mode = 'start'
    if ($CheckOnly) { $mode = 'check only' }
    elseif ($DryRun) { $mode = 'dry run: nothing will be started or changed' }
    Write-Host "ExamForge local start ($mode)" -ForegroundColor Cyan
    Write-Host "Project: $ProjectRoot"

    Invoke-AllChecks
    Write-CheckSummary
    $fails = Get-FailCount

    if ($CheckOnly) {
        if ($fails -gt 0) { exit 1 }
        exit 0
    }

    if ($fails -gt 0) {
        if (-not $DryRun) {
            Write-Host 'Fix the items marked FAIL above, then run start-local again. Nothing was started.' -ForegroundColor Red
            exit 1
        }
        Write-DryRun "a real run would stop here because of the $fails problem(s) above; showing the remaining steps anyway"
    }

    # Only the last value is the exit code; anything else a step happened to
    # emit must not end up as the exit code.
    $result = @(Invoke-Start) | Select-Object -Last 1
    # A dry run reports failure too when a real run would have stopped.
    if ($DryRun -and $fails -gt 0) { exit 1 }
    exit ([int] $result)
} catch {
    Write-Host ''
    Write-Host "Unexpected error: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "At: $($_.InvocationInfo.PositionMessage)" -ForegroundColor DarkGray
    exit 1
}
