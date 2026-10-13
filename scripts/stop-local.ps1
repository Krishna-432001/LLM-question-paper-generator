<#
.SYNOPSIS
    Stop ExamForge on this PC.

.DESCRIPTION
    Stops exactly what start-local started: the processes recorded in
    scripts\.local-pids.json and everything they started (php, node, cmd
    and console helpers). Other php or node programs on this PC are never
    touched, because a process is only stopped when

      - its ID and its start time both match the recorded entry
        (Windows reuses process IDs), or
      - it descends from such a process and started after its parent.

    The PID file is removed once everything in it has stopped.

    A background job that was cut off is picked up again by the worker the
    next time ExamForge starts (after the queue's retry delay).

    Windows PowerShell 5.1 compatible, ASCII only.

.PARAMETER ProjectRoot
    The ExamForge folder. Defaults to the parent of this script's folder.

.PARAMETER DryRun
    Print what would be stopped without stopping anything.

.EXAMPLE
    scripts\stop-local.bat
#>
[CmdletBinding()]
param(
    [string] $ProjectRoot,
    [switch] $DryRun
)

$ErrorActionPreference = 'Stop'

$Ports = @(8000, 3000)

if (-not $ProjectRoot) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}
if (-not (Test-Path -LiteralPath $ProjectRoot -PathType Container)) {
    Write-Host "Project folder not found: $ProjectRoot" -ForegroundColor Red
    exit 1
}
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).ProviderPath
$PidFile = Join-Path $ProjectRoot 'scripts\.local-pids.json'

function Write-DryRun {
    param([string] $Text)
    Write-Host "[dry-run] $Text" -ForegroundColor Magenta
}

function Test-SameStart {
    param([string] $A, [string] $B)
    if (-not $A -or -not $B) { return $false }
    return ([math]::Abs([int64] $A - [int64] $B) -lt 10000000)
}

function Get-ProcessStartTicks {
    param([int] $ProcessId)
    try {
        $p = Get-Process -Id $ProcessId -ErrorAction Stop
        return [string] $p.StartTime.ToUniversalTime().Ticks
    } catch {
        return $null
    }
}

# One snapshot of every process: by ID, and children by parent ID.
function Get-ProcessTable {
    $byId = @{}
    $children = @{}
    foreach ($p in Get-CimInstance -ClassName Win32_Process -Property ProcessId, ParentProcessId, Name, CreationDate) {
        $id = [int] $p.ProcessId
        $parentId = [int] $p.ParentProcessId
        $byId[$id] = $p
        if (-not $children.ContainsKey($parentId)) {
            $children[$parentId] = New-Object System.Collections.ArrayList
        }
        [void] $children[$parentId].Add($p)
    }
    return @{ ById = $byId; Children = $children }
}

# The root and everything below it, parents before children. A child only
# counts when it started after its parent: a stale parent ID that Windows
# has since handed to an unrelated program must not drag that program in.
function Get-ProcessTree {
    param([hashtable] $Table, [int] $RootId)

    $result = New-Object System.Collections.ArrayList
    $root = $Table.ById[$RootId]
    if (-not $root) { return @() }
    [void] $result.Add($root)
    $queue = New-Object System.Collections.Queue
    $queue.Enqueue($root)
    $seen = @{ $RootId = $true }
    while ($queue.Count -gt 0) {
        $parent = $queue.Dequeue()
        $kids = $Table.Children[[int] $parent.ProcessId]
        if (-not $kids) { continue }
        foreach ($kid in $kids) {
            $kidId = [int] $kid.ProcessId
            if ($seen.ContainsKey($kidId)) { continue }
            if ($kid.CreationDate -lt $parent.CreationDate) { continue }
            $seen[$kidId] = $true
            [void] $result.Add($kid)
            $queue.Enqueue($kid)
        }
    }
    return $result.ToArray()
}

# This script and the windows it runs in are never stopped, in case it was
# started from inside one of the ExamForge windows.
function Get-OwnAncestry {
    param([hashtable] $Table)
    $ids = @{}
    $current = $Table.ById[[int] $PID]
    $guard = 0
    while ($current -and $guard -lt 64) {
        $ids[[int] $current.ProcessId] = $true
        $parent = $Table.ById[[int] $current.ParentProcessId]
        if (-not $parent -or $parent.CreationDate -gt $current.CreationDate) { break }
        $current = $parent
        $guard++
    }
    return $ids
}

function Write-PortReport {
    foreach ($port in $Ports) {
        $conn = $null
        try {
            $conn = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction Stop | Select-Object -First 1
        } catch {
            $conn = $null
        }
        if (-not $conn) { continue }
        $name = 'an unknown program'
        $proc = Get-Process -Id $conn.OwningProcess -ErrorAction SilentlyContinue
        if ($proc) { $name = "$($proc.ProcessName).exe" }
        Write-Host "  Port $port is still in use by $name (PID $($conn.OwningProcess))." -ForegroundColor Yellow
        Write-Host '  start-local did not record it, so it was left alone. Close it yourself or restart the PC.'
    }
}

try {
    $mode = 'stop'
    if ($DryRun) { $mode = 'dry run: nothing will be stopped' }
    Write-Host "ExamForge local stop ($mode)" -ForegroundColor Cyan
    Write-Host "Project: $ProjectRoot"
    Write-Host ''

    if (-not (Test-Path -LiteralPath $PidFile -PathType Leaf)) {
        Write-Host 'ExamForge is not running from start-local (there is no scripts\.local-pids.json).'
        Write-PortReport
        exit 0
    }

    $state = $null
    try {
        $state = Get-Content -LiteralPath $PidFile -Raw | ConvertFrom-Json
    } catch {
        $state = $null
    }
    if (-not $state) {
        Write-Host 'scripts\.local-pids.json could not be read; nothing was stopped.' -ForegroundColor Yellow
        if ($DryRun) {
            Write-DryRun 'would delete the unreadable file'
        } else {
            Remove-Item -LiteralPath $PidFile -Force
            Write-Host 'Deleted it. Close any ExamForge windows that are still open yourself.'
        }
        Write-PortReport
        exit 0
    }

    Write-Host "Started at: $($state.startedAt)"
    $table = Get-ProcessTable
    $own = Get-OwnAncestry -Table $table
    $plan = New-Object System.Collections.ArrayList
    $planned = @{}
    $skippedOwn = $false

    foreach ($entry in @($state.processes)) {
        if (-not $entry -or -not $entry.pid) { continue }
        $id = [int] $entry.pid
        $label = '{0,-10}' -f $entry.role

        $ticks = Get-ProcessStartTicks -ProcessId $id
        if (-not $ticks) {
            Write-Host "  $label PID $id has already stopped."
            continue
        }
        if (-not (Test-SameStart -A $ticks -B ([string] $entry.startTime))) {
            Write-Host "  $label PID $id now belongs to a different program; left alone."
            continue
        }

        $tree = @(Get-ProcessTree -Table $table -RootId $id)
        $names = @()
        foreach ($p in $tree) {
            $pidValue = [int] $p.ProcessId
            if ($planned.ContainsKey($pidValue)) { continue }
            if ($own.ContainsKey($pidValue)) { $skippedOwn = $true; continue }
            $planned[$pidValue] = $true
            [void] $plan.Add($p)
            if ($p.Name -ne 'conhost.exe') { $names += "$($p.Name) ($pidValue)" }
        }
        if ($names.Count -gt 0) {
            $verb = 'stopping'
            if ($DryRun) { $verb = 'would stop' }
            Write-Host "  $label $verb $($names -join ', ')"
        }
    }

    if ($DryRun) {
        Write-Host ''
        Write-DryRun "would stop $($plan.Count) process(es) (console helpers included) and delete scripts\.local-pids.json"
        exit 0
    }

    # Parents first, so a window cannot restart the program inside it (the
    # worker window restarts queue:work when it exits).
    $failed = @()
    foreach ($p in $plan) {
        try {
            Stop-Process -Id ([int] $p.ProcessId) -Force -ErrorAction Stop
        } catch {
            if (Get-Process -Id ([int] $p.ProcessId) -ErrorAction SilentlyContinue) {
                $failed += "$($p.Name) ($($p.ProcessId)): $($_.Exception.Message)"
            }
        }
    }

    # Anything those processes started in the meantime.
    for ($sweep = 0; $sweep -lt 3; $sweep++) {
        $late = @()
        $fresh = Get-ProcessTable
        foreach ($p in $plan) {
            $kids = $fresh.Children[[int] $p.ProcessId]
            if (-not $kids) { continue }
            foreach ($kid in $kids) {
                $kidId = [int] $kid.ProcessId
                if ($planned.ContainsKey($kidId) -or $own.ContainsKey($kidId)) { continue }
                if ($kid.CreationDate -lt $p.CreationDate) { continue }
                $planned[$kidId] = $true
                $late += $kid
            }
        }
        if ($late.Count -eq 0) { break }
        foreach ($kid in $late) {
            [void] $plan.Add($kid)
            try {
                Stop-Process -Id ([int] $kid.ProcessId) -Force -ErrorAction Stop
            } catch {
                if (Get-Process -Id ([int] $kid.ProcessId) -ErrorAction SilentlyContinue) {
                    $failed += "$($kid.Name) ($($kid.ProcessId)): $($_.Exception.Message)"
                }
            }
        }
    }

    # Give Windows a moment to release the ports.
    $deadline = (Get-Date).AddSeconds(5)
    while ((Get-Date) -lt $deadline) {
        $alive = @($plan | Where-Object { Get-Process -Id ([int] $_.ProcessId) -ErrorAction SilentlyContinue })
        if ($alive.Count -eq 0) { break }
        Start-Sleep -Milliseconds 250
    }

    Write-Host ''
    if ($failed.Count -gt 0) {
        Write-Host 'Some processes could not be stopped:' -ForegroundColor Red
        foreach ($f in $failed) { Write-Host "  $f" }
        Write-Host 'Close their windows yourself (or restart the PC), then run stop-local again.'
        Write-Host 'scripts\.local-pids.json was kept so the next run can try again.'
        exit 1
    }

    Remove-Item -LiteralPath $PidFile -Force
    if ($skippedOwn) {
        Write-Host 'This window is one of the ExamForge windows, so it was left open. Close it yourself.' -ForegroundColor Yellow
    }
    Write-Host "ExamForge stopped ($($plan.Count) process(es)). Removed scripts\.local-pids.json." -ForegroundColor Green
    Write-PortReport
    exit 0
} catch {
    Write-Host ''
    Write-Host "Unexpected error: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "At: $($_.InvocationInfo.PositionMessage)" -ForegroundColor DarkGray
    exit 1
}
