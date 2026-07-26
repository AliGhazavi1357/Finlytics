# ساخت بسته ZIP برای آپلود روی Plesk (هاست اشتراکی — PHP مثل Attendance)
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
if (-not $root) { $root = "d:\Python Projects\Projects One\Finlytics" }
$outDir = Join-Path $root "dist"
$stage = Join-Path $outDir "finlytics-plesk"
$zipPath = Join-Path $outDir "finlytics-nesfejahan-plesk-php-v1.0.0.zip"

New-Item -ItemType Directory -Force -Path $outDir | Out-Null
if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
New-Item -ItemType Directory -Force -Path $stage | Out-Null

# Core PHP API + static frontend
Copy-Item (Join-Path $root "api") (Join-Path $stage "api") -Recurse
Copy-Item (Join-Path $root "static") (Join-Path $stage "static") -Recurse
Copy-Item (Join-Path $root "login.html") $stage -Force
Copy-Item (Join-Path $root "app.html") $stage -Force
Copy-Item (Join-Path $root "status.html") $stage -ErrorAction SilentlyContinue

# Plesk helpers
Copy-Item (Join-Path $root "publish-plesk\*") $stage -Force

# صفحه اصلی = ورود
Copy-Item (Join-Path $root "login.html") (Join-Path $stage "index.html") -Force

# Empty data dirs
New-Item -ItemType Directory -Force -Path (Join-Path $stage "data\uploads") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $stage "data\voice") | Out-Null
Set-Content (Join-Path $stage "data\.gitkeep") ""
Set-Content (Join-Path $stage "data\uploads\.gitkeep") ""

# حذف فایل‌های غیرضروری پایتون از استیج (اگر کپی شده باشند)
Get-ChildItem $stage -Recurse -Directory -Filter "__pycache__" | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
Compress-Archive -Path (Join-Path $stage "*") -DestinationPath $zipPath -CompressionLevel Optimal

Write-Host "OK:" $zipPath
Write-Host ("Size MB: {0:N1}" -f ((Get-Item $zipPath).Length / 1MB))
