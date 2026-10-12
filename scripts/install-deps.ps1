<#
    Installs the two dependencies that are genuinely missing and genuinely
    installable on this machine.

    Deliberately NOT included:

      pgvector      No Windows binaries exist. It needs an MSVC build against
                    the PostgreSQL 18 headers, which is a different job with a
                    real chance of leaving a half-built extension behind.

      Docker        Multi-gigabyte, requires WSL2, and usually a reboot. Not
                    something to start without the machine's owner deciding.

      PHP 8.3       This machine runs XAMPP's 8.2.12 and the whole stack works
                    on it. Nothing in the codebase requires 8.3, so upgrading
                    would risk a working system for no current benefit.

    Run from an elevated PowerShell:
        powershell -ExecutionPolicy Bypass -File scripts\install-deps.ps1
#>

$ErrorActionPreference = 'Stop'

function Test-Admin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    return ([Security.Principal.WindowsPrincipal] $identity).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-Admin)) {
    Write-Host "This script needs Administrator rights." -ForegroundColor Yellow
    Write-Host "Right-click PowerShell, choose 'Run as administrator', then run:"
    Write-Host "  powershell -ExecutionPolicy Bypass -File scripts\install-deps.ps1"
    exit 1
}

function Install-Package {
    param([string] $Id, [string] $Label)

    Write-Host ""
    Write-Host "==> $Label" -ForegroundColor Cyan

    # --exact so a fuzzy match cannot install something else entirely.
    $existing = winget list --id $Id --exact 2>$null | Select-String $Id

    if ($existing) {
        Write-Host "    already installed, skipping"
        return
    }

    winget install --id $Id --exact --silent `
        --accept-package-agreements --accept-source-agreements

    if ($LASTEXITCODE -ne 0) {
        Write-Host "    FAILED (exit $LASTEXITCODE)" -ForegroundColor Red
    } else {
        Write-Host "    installed" -ForegroundColor Green
    }
}

Install-Package -Id 'UB-Mannheim.TesseractOCR' -Label 'Tesseract OCR — reads scanned books that carry no text layer'
Install-Package -Id 'Memurai.MemuraiDeveloper' -Label 'Memurai — Redis-compatible server for the queue'

# ---------------------------------------------------------------- Tamil data
# Tesseract installs English only. Without tam.traineddata a scanned Tamil
# textbook comes back as nonsense rather than as an error, which is the worst
# possible failure mode: it looks like it worked.
Write-Host ""
Write-Host "==> Tamil language data for Tesseract" -ForegroundColor Cyan

$tessRoot = @(
    "$env:ProgramFiles\Tesseract-OCR",
    "${env:ProgramFiles(x86)}\Tesseract-OCR"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $tessRoot) {
    Write-Host "    Tesseract not found on disk; skipping language data" -ForegroundColor Yellow
} else {
    $tessdata = Join-Path $tessRoot 'tessdata'
    New-Item -ItemType Directory -Force -Path $tessdata | Out-Null

    foreach ($lang in @('tam', 'eng')) {
        $target = Join-Path $tessdata "$lang.traineddata"

        if (Test-Path $target) {
            Write-Host "    $lang already present"
            continue
        }

        $url = "https://github.com/tesseract-ocr/tessdata_best/raw/main/$lang.traineddata"

        try {
            Invoke-WebRequest -Uri $url -OutFile $target -UseBasicParsing
            Write-Host "    $lang downloaded" -ForegroundColor Green
        } catch {
            Write-Host "    $lang download failed: $($_.Exception.Message)" -ForegroundColor Red
        }
    }
}

Write-Host ""
Write-Host "Done. Open a NEW terminal so PATH changes take effect." -ForegroundColor Green
Write-Host "Verify with:  tesseract --version   and   memurai-cli ping"
