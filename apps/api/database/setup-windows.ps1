# ExamForge — one-shot local PostgreSQL bootstrap for Windows
#
# RUN THIS ONCE, AS ADMINISTRATOR:
#   Right-click PowerShell -> "Run as administrator", then:
#   & "C:\Users\Krishna\Downloads\llm for quepaper generate\apps\api\database\setup-windows.ps1"
#
# What it does:
#   1. Backs up pg_hba.conf
#   2. Adds ONE temporary line trusting postgres@127.0.0.1 (so no password is needed)
#   3. Restarts PostgreSQL so that line takes effect
#   4. Creates the examforge role + examforge/examforge_test databases + extensions
#   5. RESTORES pg_hba.conf from the backup and restarts again
#
# Step 5 runs in a finally block, so your authentication config is put back even
# if something fails in the middle. Nothing is left loosened.

$ErrorActionPreference = 'Stop'

$pgRoot  = 'C:\Program Files\PostgreSQL\18'
$dataDir = Join-Path $pgRoot 'data'
$hba     = Join-Path $dataDir 'pg_hba.conf'
$backup  = Join-Path $dataDir 'pg_hba.conf.examforge-backup'
$psql    = Join-Path $pgRoot 'bin\psql.exe'
$service = 'postgresql-x64-18'

# Must match DB_PASSWORD in apps/api/.env
$appPassword = 'Ef_9tKm3Rz7pQx2Vn5Ls8Wd4Yb6Hc1Ja'

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
      ).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'ERROR: This script must be run from an Administrator PowerShell.' -ForegroundColor Red
    Write-Host 'Right-click PowerShell -> Run as administrator, then run it again.'
    exit 1
}

Write-Host '[1/5] Backing up pg_hba.conf ...' -ForegroundColor Cyan
Copy-Item $hba $backup -Force

try {
    Write-Host '[2/5] Adding temporary trust line ...' -ForegroundColor Cyan
    $trust = 'host    all             postgres        127.0.0.1/32            trust'
    $lines = @('# TEMPORARY - ExamForge setup', $trust) + (Get-Content $hba)
    # ASCII, no BOM - a BOM at the top of pg_hba.conf stops PostgreSQL parsing it.
    [System.IO.File]::WriteAllLines($hba, $lines, [System.Text.Encoding]::ASCII)

    Write-Host '[3/5] Restarting PostgreSQL ...' -ForegroundColor Cyan
    Restart-Service $service -Force
    Start-Sleep -Seconds 4

    Write-Host '[4/5] Creating role, databases and extensions ...' -ForegroundColor Cyan
    $env:PGPASSWORD = ''

    # Role (idempotent: create or reset the password).
    $roleSql = @"
DO `$`$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'examforge') THEN
    ALTER ROLE examforge WITH LOGIN CREATEDB PASSWORD '$appPassword';
  ELSE
    CREATE ROLE examforge WITH LOGIN CREATEDB PASSWORD '$appPassword';
  END IF;
END
`$`$;
"@
    & $psql -U postgres -h 127.0.0.1 -d postgres -w -v ON_ERROR_STOP=1 -c $roleSql

    # CREATE DATABASE cannot run inside a transaction block, so each is its own
    # call and an "already exists" error is expected on a re-run.
    foreach ($db in @('examforge', 'examforge_test')) {
        & $psql -U postgres -h 127.0.0.1 -d postgres -w `
            -c "CREATE DATABASE $db OWNER examforge ENCODING 'UTF8' TEMPLATE template0;" 2>&1 |
            ForEach-Object { if ($_ -notmatch 'already exists') { Write-Host "    $_" } }
    }

    # Extensions need superuser, which is why they are created here and not in
    # a Laravel migration.
    foreach ($db in @('examforge', 'examforge_test')) {
        $ext = @'
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS ltree;
CREATE EXTENSION IF NOT EXISTS citext;
GRANT ALL ON SCHEMA public TO examforge;
ALTER SCHEMA public OWNER TO examforge;
'@
        & $psql -U postgres -h 127.0.0.1 -d $db -w -v ON_ERROR_STOP=1 -c $ext
        Write-Host "    extensions ready in $db"
    }
}
finally {
    Write-Host '[5/5] Restoring pg_hba.conf and restarting ...' -ForegroundColor Cyan
    Copy-Item $backup $hba -Force
    Remove-Item $backup -Force -ErrorAction SilentlyContinue
    Restart-Service $service -Force
    Start-Sleep -Seconds 4
    $env:PGPASSWORD = ''
}

# Prove password auth is back on and the app credential works.
$env:PGPASSWORD = $appPassword
$check = & $psql -U examforge -h 127.0.0.1 -d examforge -w -t -c 'select current_user;' 2>&1
$env:PGPASSWORD = ''

if ($check -match 'examforge') {
    Write-Host ''
    Write-Host 'SUCCESS - databases ready, authentication restored.' -ForegroundColor Green
    Write-Host 'Now tell Claude Code: "done, run the migrations"'
} else {
    Write-Host ''
    Write-Host "VERIFICATION FAILED: $check" -ForegroundColor Red
    Write-Host 'pg_hba.conf has been restored. Paste this output back to Claude Code.'
}
