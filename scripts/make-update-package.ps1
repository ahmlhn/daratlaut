Param(
    [Parameter(Mandatory = $false)]
    [string]$OutPath = ".\\update-package.zip",

    [Parameter(Mandatory = $false)]
    [object]$IncludeVendor = $true,

    [Parameter(Mandatory = $false)]
    [object]$IncludeBuild = $true
)

$ErrorActionPreference = "Stop"

function ConvertTo-Bool([object]$value, [bool]$defaultValue, [string]$paramName) {
    if ($null -eq $value) { return $defaultValue }
    if ($value -is [bool]) { return [bool]$value }
    if ($value -is [int] -or $value -is [long]) { return ([int64]$value -ne 0) }

    if ($value -is [string]) {
        $s = $value.Trim()
        if ([string]::IsNullOrWhiteSpace($s)) { return $defaultValue }
        switch -Regex ($s.ToLowerInvariant()) {
            '^(1|true|t|yes|y|on)$' { return $true }
            '^(0|false|f|no|n|off)$' { return $false }
        }
        throw "Invalid value for -${paramName}: '$value' (use true/false or 1/0)."
    }

    # Last-resort conversion (keeps behavior for odd call-sites).
    try { return [bool]$value } catch { throw "Invalid value for -${paramName}: '$value' (use true/false or 1/0)." }
}

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $root

$script:RootFull = [System.IO.Path]::GetFullPath($root).TrimEnd("\\")
$script:RootPrefix = $script:RootFull + "\\"

function Normalize-RelPath([string]$base, [string]$full) {
    # PowerShell 5.1 / .NET Framework doesn't have Path::GetRelativePath, so
    # do a fast prefix-strip for paths under root, with a URI fallback.
    $fullPath = [System.IO.Path]::GetFullPath($full)
    if ($fullPath.StartsWith($script:RootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        return $fullPath.Substring($script:RootPrefix.Length).Replace("\\", "/")
    }

    $baseUri = New-Object System.Uri($script:RootPrefix)
    $targetUri = New-Object System.Uri($fullPath)
    return [System.Uri]::UnescapeDataString($baseUri.MakeRelativeUri($targetUri).ToString()).Replace("\\", "/")
}

$IncludeVendor = ConvertTo-Bool $IncludeVendor $true "IncludeVendor"
$IncludeBuild = ConvertTo-Bool $IncludeBuild $true "IncludeBuild"

if (-not (Test-Path ".\\vendor\\autoload.php")) {
    throw "Missing vendor/autoload.php. Run: composer install"
}

if ($IncludeBuild -and -not (Test-Path ".\\public\\build\\manifest.json")) {
    throw "Missing public/build/manifest.json. Run: npm run build"
}

$outAbs = (Resolve-Path (Split-Path -Parent $OutPath)).Path
$outFile = Join-Path $outAbs (Split-Path -Leaf $OutPath)
if (Test-Path $outFile) { Remove-Item -Force $outFile }

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$excludeDirPrefixes = @(
    ".git/",
    "node_modules/",
    "storage/",
    "bootstrap/cache/",
    "public/storage/",
    "public/uploads/"
)

if (-not $IncludeVendor) { $excludeDirPrefixes += "vendor/" }
if (-not $IncludeBuild) { $excludeDirPrefixes += "public/build/" }

$excludeExact = @(
    ".env",
    "scripts/deploy.config.ps1"
)

# Don't include the output zip file if it is created inside the project.
$outRel = Normalize-RelPath $root $outFile
if (-not [string]::IsNullOrWhiteSpace($outRel)) {
    $excludeExact += $outRel
}

Write-Host "[package] Root: $root"
Write-Host "[package] Out:  $outFile"
Write-Host "[package] IncludeVendor=$IncludeVendor IncludeBuild=$IncludeBuild"

$excludeDirPrefixes = $excludeDirPrefixes | ForEach-Object { $_.Replace("\\", "/") }
$excludeExact = $excludeExact | ForEach-Object { $_.Replace("\\", "/") }

function Should-SkipDir([string]$relDir) {
    $relDir = $relDir.Replace("\\", "/")
    if (-not $relDir.EndsWith("/")) { $relDir = $relDir + "/" }
    foreach ($p in $excludeDirPrefixes) {
        if ($relDir.StartsWith($p, [System.StringComparison]::OrdinalIgnoreCase)) { return $true }
    }
    return $false
}

function Should-SkipFile([string]$relFile) {
    $relFile = $relFile.Replace("\\", "/")
    if ($excludeExact -contains $relFile) { return $true }
    if ($relFile.StartsWith(".env.", [System.StringComparison]::OrdinalIgnoreCase)) { return $true }
    foreach ($p in $excludeDirPrefixes) {
        if ($relFile.StartsWith($p, [System.StringComparison]::OrdinalIgnoreCase)) { return $true }
    }
    return $false
}

$zip = [System.IO.Compression.ZipFile]::Open($outFile, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $added = 0
    $skipped = 0
    $skippedDirs = 0

    function Add-Directory([System.IO.DirectoryInfo]$dir) {
        foreach ($fsi in $dir.EnumerateFileSystemInfos("*", [System.IO.SearchOption]::TopDirectoryOnly)) {
            $full = $fsi.FullName
            $rel = Normalize-RelPath $root $full
            if ([string]::IsNullOrWhiteSpace($rel)) { $script:skipped++; continue }
            $rel = $rel.Replace("\\", "/")

            if ($fsi -is [System.IO.DirectoryInfo]) {
                $relDir = $rel
                if (-not $relDir.EndsWith("/")) { $relDir = $relDir + "/" }
                if (Should-SkipDir $relDir) { $script:skippedDirs++; continue }
                Add-Directory ([System.IO.DirectoryInfo]$fsi)
                continue
            }

            if (Should-SkipFile $rel) { $script:skipped++; continue }

            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $full, $rel, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
            $script:added++
        }
    }

    Add-Directory ([System.IO.DirectoryInfo]::new($root))

    Write-Host "[package] Added:   $added files"
    Write-Host "[package] Skipped: $skipped files"
    Write-Host "[package] SkippedDirs: $skippedDirs dirs"
} finally {
    $zip.Dispose()
}

Write-Host "[package] Done."
