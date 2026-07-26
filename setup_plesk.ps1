# Finlytics — setup script for Windows Plesk httpdocs
# Run inside the site root after extracting the ZIP:
#   powershell -ExecutionPolicy Bypass -File .\setup_plesk.ps1

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $Root

Write-Host "==> Finlytics Plesk setup in: $Root"

# Prefer py launcher, fallback to python
$pythonCmd = $null
foreach ($c in @("py -3.12", "py -3.11", "py -3", "python")) {
  try {
    $null = Invoke-Expression "$c -c `"import sys; print(sys.version)`""
    $pythonCmd = $c
    break
  } catch {
    continue
  }
}

if (-not $pythonCmd) {
  throw "Python 3.11+ not found. Install Python or enable it in Plesk."
}

Write-Host "==> Using: $pythonCmd"

$venvPath = Join-Path $Root "python_env"
if (-not (Test-Path $venvPath)) {
  Write-Host "==> Creating venv python_env ..."
  Invoke-Expression "$pythonCmd -m venv `"$venvPath`""
} else {
  Write-Host "==> venv already exists"
}

$venvPython = Join-Path $venvPath "Scripts\python.exe"
if (-not (Test-Path $venvPython)) {
  throw "venv python not found at $venvPython"
}

Write-Host "==> Upgrading pip ..."
& $venvPython -m pip install --upgrade pip

Write-Host "==> Installing requirements ..."
& $venvPython -m pip install -r (Join-Path $Root "requirements.txt")

foreach ($dir in @("data", "data\voice", "data\uploads", "logs")) {
  $p = Join-Path $Root $dir
  if (-not (Test-Path $p)) {
    New-Item -ItemType Directory -Path $p | Out-Null
  }
}

$envExample = Join-Path $Root ".env.example"
$envFile = Join-Path $Root ".env"
if (-not (Test-Path $envFile)) {
  Copy-Item $envExample $envFile
  Write-Host "==> Created .env from .env.example — edit SECRET_KEY now"
} else {
  Write-Host "==> .env already exists"
}

Write-Host "==> Import smoke test ..."
& $venvPython -c "from app.main import app; print('OK', app.title)"

Write-Host ""
Write-Host "Setup finished."
Write-Host "1) Edit .env (SECRET_KEY, DEBUG=false)"
Write-Host "2) Give Write permission on data\ and logs\"
Write-Host "3) Recycle App Pool in Plesk"
Write-Host "4) Open your domain and login"
