# Common Goals — Build Script
# Creates a clean distribution ZIP from the common-goals/ directory.
# Usage:  powershell -ExecutionPolicy Bypass -File build.ps1

$ErrorActionPreference = "Stop"

$pluginDir = "common-goals"
$buildDir  = "dist"
$version   = $null

# Extract version from the main plugin file
$mainFile = Join-Path $pluginDir "common-goals.php"
if (Test-Path $mainFile) {
    $content = Get-Content $mainFile -Raw
    if ($content -match "Version:\s*(.+)") {
        $version = $matches[1].Trim()
    }
}

if (-not $version) {
    Write-Host "ERROR: Could not detect plugin version." -ForegroundColor Red
    exit 1
}

$zipName = "common-goals-$version.zip"
$zipPath = Join-Path $buildDir $zipName

Write-Host ""
Write-Host "=== Common Goals Build ===" -ForegroundColor Cyan
Write-Host "Version: $version"
Write-Host "Output:  $zipPath"
Write-Host ""

# Clean previous build
if (Test-Path $buildDir) {
    Remove-Item $buildDir -Recurse -Force
}
New-Item -ItemType Directory -Path $buildDir | Out-Null

# Create a staging copy with only distribution files
$staging = Join-Path $buildDir "staging"
$stagingPlugin = Join-Path $staging "common-goals"

# Files and directories to include in the distribution
$includeItems = @(
    "common-goals.php",
    "uninstall.php",
    "readme.txt",
    "LICENSE",
    "includes",
    "templates",
    "assets",
    "languages",
    "docs"
)

New-Item -ItemType Directory -Path $stagingPlugin -Force | Out-Null

foreach ($item in $includeItems) {
    $source = Join-Path $pluginDir $item
    $dest   = Join-Path $stagingPlugin $item

    if (Test-Path $source) {
        if ((Get-Item $source) -is [System.IO.DirectoryInfo]) {
            Copy-Item $source -Destination $dest -Recurse
        } else {
            Copy-Item $source -Destination $dest
        }
        Write-Host "  + $item"
    } else {
        Write-Host "  - $item (skipped, not found)" -ForegroundColor Yellow
    }
}

# Verify required files exist
$required = @("common-goals.php", "readme.txt", "uninstall.php")
foreach ($req in $required) {
    $reqPath = Join-Path $stagingPlugin $req
    if (-not (Test-Path $reqPath)) {
        Write-Host "ERROR: Required file missing: $req" -ForegroundColor Red
        exit 1
    }
}

# Remove dev-only files from staging (in case they were copied with directories)
Get-ChildItem -Path $stagingPlugin -Recurse -Include "*.distignore", ".gitignore" -File | Remove-Item -Force

# Create the ZIP
Write-Host ""
Write-Host "Compressing..." -ForegroundColor Cyan
Compress-Archive -Path (Join-Path $staging "*") -DestinationPath $zipPath -Force

# Clean staging
Remove-Item $staging -Recurse -Force

# Report
$size = (Get-Item $zipPath).Length
$sizeKB = [math]::Round($size / 1024, 1)

Write-Host ""
Write-Host "=== Build Complete ===" -ForegroundColor Green
Write-Host "File: $zipPath"
Write-Host "Size: $sizeKB KB"
Write-Host ""
Write-Host "Install by uploading the ZIP via WordPress admin:"
Write-Host "  Plugins > Add New > Upload Plugin"
Write-Host ""
