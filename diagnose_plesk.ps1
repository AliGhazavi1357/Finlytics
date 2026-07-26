# Run on the Plesk Windows server inside the site root.
# powershell -ExecutionPolicy Bypass -File .\diagnose_plesk.ps1

$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $Root

Write-Host "==== Finlytics diagnose ===="
Write-Host "Root: $Root"
Write-Host ""

$need = @(
  "app\main.py",
  "web.config",
  "requirements.txt",
  "templates\login.html",
  "static\js\app.js",
  "python_env\Scripts\python.exe",
  ".env"
)

Write-Host "-- Required files --"
foreach ($f in $need) {
  $p = Join-Path $Root $f
  if (Test-Path $p) { Write-Host "[OK] $f" } else { Write-Host "[MISSING] $f" }
}

Write-Host ""
Write-Host "-- Folders write check --"
foreach ($d in @("data", "logs", "data\voice", "data\uploads")) {
  $p = Join-Path $Root $d
  if (-not (Test-Path $p)) {
    try { New-Item -ItemType Directory -Path $p | Out-Null; Write-Host "[CREATED] $d" } catch { Write-Host "[FAIL create] $d" }
  }
  $probe = Join-Path $p "_write_test.tmp"
  try {
    "ok" | Out-File $probe -Encoding utf8
    Remove-Item $probe -Force
    Write-Host "[WRITE OK] $d"
  } catch {
    Write-Host "[WRITE FAIL] $d"
  }
}

$py = Join-Path $Root "python_env\Scripts\python.exe"
if (Test-Path $py) {
  Write-Host ""
  Write-Host "-- Python import test --"
  & $py -c "import sys; print(sys.version)"
  & $py -c "from app.main import app; print('APP_OK', app.title)"
} else {
  Write-Host ""
  Write-Host "python_env not found. Run setup_plesk.ps1 first."
}

Write-Host ""
Write-Host "-- Recent stdout.log (last 40 lines) --"
$log = Join-Path $Root "logs\stdout.log"
if (Test-Path $log) {
  Get-Content $log -Tail 40
} else {
  Write-Host "No logs\stdout.log yet (site may not have started HttpPlatform)."
}

Write-Host ""
Write-Host "Done. If APP_OK printed but site is 500, fix processPath in web.config or ask host for HttpPlatformHandler."
