param(
    [string]$ConfigPath = "",
    [switch]$SkipPush,
    [switch]$DryRun
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-Info {
    param([string]$Message)
    Write-Host "[deploy] $Message"
}

function Fail {
    param([string]$Message)
    Write-Error $Message
    exit 1
}

function Require-Command {
    param([string]$Name)
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        Fail "Command '$Name' not found in PATH."
    }
}

function Run-Checked {
    param(
        [string]$Command,
        [string[]]$Arguments
    )

    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        Fail "Command failed: $Command $($Arguments -join ' ')"
    }
}

function Resolve-RemotePath {
    param([string]$Path)

    if ($Path -eq "~") {
        return '$HOME'
    }

    if ($Path.StartsWith("~/")) {
        return '$HOME/' + $Path.Substring(2)
    }

    return $Path
}

function Escape-DoubleQuotedShell {
    param([string]$Value)
    return $Value.Replace('\', '\\').Replace('"', '\"')
}

function Escape-SingleQuotedShell {
    param([string]$Value)
    return $Value.Replace("'", "'""'""'")
}

function Build-PostDeployScript {
    param([hashtable]$Config)

    $lines = @()
    $runEnvPreflight = [bool]$Config.RemoteEnvPreflight
    $repoUrl = [string]$Config.RepoUrl
    $repoUrlEscaped = Escape-SingleQuotedShell $repoUrl

    if ([string]$Config.DeployMode -eq "git") {
        if (-not [string]::IsNullOrWhiteSpace($repoUrl)) {
            $lines += "if git remote get-url origin >/dev/null 2>&1; then git remote set-url origin '$repoUrlEscaped'; else git remote add origin '$repoUrlEscaped'; fi"
        }
        $lines += "git fetch origin '$($Config.Branch)'"
        $lines += "git checkout '$($Config.Branch)'"
        $lines += "git pull --ff-only origin '$($Config.Branch)'"
    }

    if ($runEnvPreflight) {
        $lines += 'if [ -f scripts/hosting-setup.php ]; then php scripts/hosting-setup.php --env-only; fi'
    }

    if ($Config.RemoteComposerInstall) {
        $lines += "composer install --no-dev --optimize-autoloader --no-interaction"
    }

    if ($Config.RemoteNpmBuild) {
        $lines += "npm ci"
        $lines += "npm run build"
    }

    if ($Config.RemoteHostingSetup) {
        $lines += "composer hosting:setup"
    }

    return $lines
}

function Build-GitBootstrapScript {
    param([hashtable]$Config)

    $remotePathEscaped = Escape-DoubleQuotedShell (Resolve-RemotePath ([string]$Config.RemotePath))
    $branchEscaped = Escape-SingleQuotedShell ([string]$Config.Branch)
    $repoUrl = [string]$Config.RepoUrl
    $repoUrlEscaped = Escape-SingleQuotedShell $repoUrl
    $canCloneIfMissing = (-not [string]::IsNullOrWhiteSpace($repoUrl)) -and [bool]$Config.GitCloneIfMissing
    $canAdoptExistingNonRepo = (-not [string]::IsNullOrWhiteSpace($repoUrl)) -and [bool]$Config.GitAdoptExistingNonRepo

    $lines = @(
        "remote_path=""$remotePathEscaped""",
        'mkdir -p "$(dirname "$remote_path")"',
        'if [ ! -d "$remote_path/.git" ]; then'
    )

    if ($canCloneIfMissing) {
        $lines += '  if [ -n "$(ls -A "$remote_path" 2>/dev/null)" ]; then'
        if ($canAdoptExistingNonRepo) {
            $lines += '    echo "Remote path non-empty and not a git repo. Adopting existing folder into git..."'
            $lines += '    cd "$remote_path"'
            $lines += '    git init'
            $lines += "    if git remote get-url origin >/dev/null 2>&1; then git remote set-url origin '$repoUrlEscaped'; else git remote add origin '$repoUrlEscaped'; fi"
            $lines += "    git fetch origin '$branchEscaped'"
            $lines += "    if ! git checkout -B '$branchEscaped' ""origin/$branchEscaped""; then"
            $lines += '      echo "Checkout failed due to existing untracked files. Running one-time cleanup (preserve .env/.env.* and uploads)..."'
            $lines += '      git clean -fd -e .env -e .env.* -e public/uploads -e uploads'
            $lines += "      git checkout -B '$branchEscaped' ""origin/$branchEscaped"""
            $lines += '    fi'
            $lines += '    cd - >/dev/null'
        } else {
            $lines += '    echo "Remote path exists and is not a git repo. Refusing auto-clone into non-empty directory." >&2'
            $lines += '    echo "Set GitAdoptExistingNonRepo=true, or clean folder first, or use DeployMode=''upload'' once." >&2'
            $lines += '    exit 11'
        }
        $lines += '  fi'
        $lines += '  if [ -z "$(ls -A "$remote_path" 2>/dev/null)" ]; then'
        $lines += "    git clone --branch '$branchEscaped' --single-branch '$repoUrlEscaped' ""`$remote_path"""
        $lines += '  fi'
    } elseif (-not [string]::IsNullOrWhiteSpace($repoUrl)) {
        $lines += '  echo "Remote path is not a git repo and GitCloneIfMissing=false." >&2'
        $lines += '  exit 10'
    } else {
        $lines += '  echo "Remote path is not a git repo and RepoUrl is empty in deploy config." >&2'
        $lines += '  exit 10'
    }

    $lines += 'fi'
    return $lines
}

function Build-RemoteScript {
    param(
        [hashtable]$Config,
        [string[]]$BeforeCd,
        [string[]]$AfterCd
    )

    $remotePath = Escape-DoubleQuotedShell (Resolve-RemotePath ([string]$Config.RemotePath))
    $lines = @(
        "set -e"
    )
    $lines += $BeforeCd
    $lines += "cd ""$remotePath"""
    $lines += $AfterCd
    return ($lines -join "`n") + "`n"
}

function Get-SshArgs {
    param([hashtable]$Config)

    $args = @()
    if (-not [string]::IsNullOrWhiteSpace([string]$Config.SshKeyPath)) {
        $args += "-i"
        $args += [string]$Config.SshKeyPath
    }
    if (-not [bool]$Config.StrictHostKeyChecking) {
        $args += "-o"
        $args += "StrictHostKeyChecking=no"
    }
    $args += "-p"
    $args += [string]$Config.Port
    $args += "$($Config.User)@$($Config.Host)"
    return $args
}

function Get-ScpArgs {
    param([hashtable]$Config)

    $args = @()
    if (-not [string]::IsNullOrWhiteSpace([string]$Config.SshKeyPath)) {
        $args += "-i"
        $args += [string]$Config.SshKeyPath
    }
    if (-not [bool]$Config.StrictHostKeyChecking) {
        $args += "-o"
        $args += "StrictHostKeyChecking=no"
    }
    $args += "-P"
    $args += [string]$Config.Port
    return $args
}

function Get-RemoteExecCommand {
    param([hashtable]$Config)

    $shell = [string]$Config.RemoteShell
    if ([string]::IsNullOrWhiteSpace($shell)) {
        return "sh -s"
    }

    return $shell
}

function Invoke-RemoteScript {
    param(
        [string]$RemoteScript,
        [string[]]$SshArgs
    )

    $prevEap = $ErrorActionPreference
    $hasNativeEap = $null -ne (Get-Variable -Name PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue)
    if ($hasNativeEap) {
        $prevNativeEap = $PSNativeCommandUseErrorActionPreference
        $PSNativeCommandUseErrorActionPreference = $false
    }
    $ErrorActionPreference = "Continue"

    try {
        $rawOutput = $RemoteScript | & ssh @SshArgs 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $prevEap
        if ($hasNativeEap) {
            $PSNativeCommandUseErrorActionPreference = $prevNativeEap
        }
    }

    $output = @(
        $rawOutput | ForEach-Object {
            if ($_ -is [System.Management.Automation.ErrorRecord]) {
                $_.ToString()
            } else {
                "$_"
            }
        }
    )

    foreach ($line in @($output)) {
        Write-Host $line
    }

    return @{
        ExitCode = $exitCode
        Output = @($output | ForEach-Object { "$_" })
    }
}

function Run-GitDeploy {
    param(
        [hashtable]$Config,
        [string]$ProjectRoot,
        [switch]$SkipPush,
        [switch]$DryRun
    )

    Require-Command "git"
    Require-Command "ssh"

    if (-not $SkipPush) {
        cmd /c "git rev-parse --is-inside-work-tree >nul 2>nul"
        if ($LASTEXITCODE -ne 0) {
            Fail "Local folder '$ProjectRoot' is not a git repository. Use DeployMode='upload' for local-to-hosting deploy, or clone from GitHub."
        }

        $status = git status --porcelain
        if ($LASTEXITCODE -ne 0) {
            Fail "Unable to read git status."
        }

        if (-not [string]::IsNullOrWhiteSpace(($status -join "`n"))) {
            Fail "Local working tree is not clean. Commit/stash changes first, or rerun with -SkipPush."
        }

        Write-Info "Pushing local branch '$($Config.Branch)' to origin..."
        Run-Checked "git" @("push", "origin", [string]$Config.Branch)
    } else {
        Write-Info "Skip push enabled."
    }

    $remoteScript = Build-RemoteScript -Config $Config -BeforeCd (Build-GitBootstrapScript -Config $Config) -AfterCd (Build-PostDeployScript -Config $Config)
    $sshArgs = Get-SshArgs -Config $Config
    $sshArgs += (Get-RemoteExecCommand -Config $Config)

    if ($DryRun) {
        Write-Info "Dry run mode. Remote script:"
        Write-Host "----------------------------------------"
        Write-Host $remoteScript
        Write-Host "----------------------------------------"
        Write-Info "SSH command: ssh $($sshArgs -join ' ')"
        return
    }

    Write-Info "Running remote deploy steps (git mode)..."
    $result = Invoke-RemoteScript -RemoteScript $remoteScript -SshArgs $sshArgs
    if ($result.ExitCode -ne 0) {
        $logPath = Join-Path ([System.IO.Path]::GetTempPath()) ("deploy-remote-" + [Guid]::NewGuid().ToString("N") + ".log")
        Set-Content -LiteralPath $logPath -Value ($result.Output -join [Environment]::NewLine) -Encoding UTF8
        Fail "Remote deploy failed (exit $($result.ExitCode)). Log: $logPath"
    }
}

function Run-UploadDeploy {
    param(
        [hashtable]$Config,
        [string]$ProjectRoot,
        [switch]$DryRun
    )

    Require-Command "tar"
    Require-Command "scp"
    Require-Command "ssh"

    if ($Config.LocalNpmBuild) {
        Write-Info "Running local frontend build..."
        Run-Checked "npm" @("ci")
        Run-Checked "npm" @("run", "build")
    }

    $archiveName = ".deploy-" + [Guid]::NewGuid().ToString("N") + ".tar.gz"
    $localArchive = Join-Path ([System.IO.Path]::GetTempPath()) $archiveName
    $remoteArchive = '$HOME/' + $archiveName
    $scpRemoteTargetPath = $archiveName

    $excludeArgs = @()
    foreach ($pattern in @($Config.UploadExcludes)) {
        $excludeArgs += "--exclude=$pattern"
    }

    $tarArgs = @("-czf", $localArchive) + $excludeArgs + @("-C", $ProjectRoot, ".")
    $remotePathEscaped = Escape-DoubleQuotedShell (Resolve-RemotePath ([string]$Config.RemotePath))
    $remoteArchiveEscaped = Escape-DoubleQuotedShell $remoteArchive

    $remoteScript = Build-RemoteScript -Config $Config -BeforeCd @(
        "archive_path=""$remoteArchiveEscaped""",
        'mkdir -p "$(dirname "$archive_path")"',
        "mkdir -p ""$remotePathEscaped""",
        "tar -xzf ""`$archive_path"" -C ""$remotePathEscaped"""
    ) -AfterCd ((Build-PostDeployScript -Config $Config) + @('rm -f "$archive_path"'))

    $sshArgs = Get-SshArgs -Config $Config
    $scpArgs = Get-ScpArgs -Config $Config
    $scpTarget = "$($Config.User)@$($Config.Host):$scpRemoteTargetPath"

    if ($DryRun) {
        Write-Info "Dry run mode (upload)."
        Write-Info "Tar command: tar $($tarArgs -join ' ')"
        Write-Info "SCP command: scp $($scpArgs -join ' ') $localArchive $scpTarget"
        Write-Info "SSH command: ssh $($sshArgs -join ' ') $((Get-RemoteExecCommand -Config $Config))"
        Write-Host "----------------------------------------"
        Write-Host $remoteScript
        Write-Host "----------------------------------------"
        return
    }

    try {
        Write-Info "Creating deploy archive..."
        Run-Checked "tar" $tarArgs

        Write-Info "Uploading archive to server..."
        Run-Checked "scp" ($scpArgs + @($localArchive, $scpTarget))

        Write-Info "Running remote deploy steps (upload mode)..."
        $result = Invoke-RemoteScript -RemoteScript $remoteScript -SshArgs ($sshArgs + @((Get-RemoteExecCommand -Config $Config)))
        if ($result.ExitCode -ne 0) {
            $logPath = Join-Path ([System.IO.Path]::GetTempPath()) ("deploy-remote-" + [Guid]::NewGuid().ToString("N") + ".log")
            Set-Content -LiteralPath $logPath -Value ($result.Output -join [Environment]::NewLine) -Encoding UTF8
            Fail "Remote deploy failed (exit $($result.ExitCode)). Log: $logPath"
        }
    } finally {
        if (Test-Path -LiteralPath $localArchive) {
            Remove-Item -LiteralPath $localArchive -Force -ErrorAction SilentlyContinue
        }
    }
}

if ([string]::IsNullOrWhiteSpace($ConfigPath)) {
    $ConfigPath = Join-Path $PSScriptRoot "deploy.config.ps1"
}

if (-not (Test-Path -LiteralPath $ConfigPath)) {
    $examplePath = Join-Path $PSScriptRoot "deploy.config.example.ps1"
    Fail "Config not found: $ConfigPath. Copy '$examplePath' to '$ConfigPath' and fill your values."
}

$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $projectRoot

$config = & $ConfigPath
if ($null -eq $config -or $config.GetType().Name -ne "Hashtable") {
    Fail "Deploy config must return a hashtable."
}

$defaults = @{
    DeployMode = "upload"   # upload | git
    Branch = "main"
    RepoUrl = ""
    GitCloneIfMissing = $true
    GitAdoptExistingNonRepo = $false
    Port = 22
    RemoteComposerInstall = $true
    RemoteHostingSetup = $true
    RemoteNpmBuild = $false
    LocalNpmBuild = $false
    RemoteEnvPreflight = $true
    RemoteShell = "bash -se"
    SshKeyPath = ""
    StrictHostKeyChecking = $true
    UploadExcludes = @(
        ".git",
        ".env",
        ".env.*",
        "vendor",
        "node_modules",
        "storage/logs/*",
        "storage/framework/cache/data/*",
        "storage/framework/sessions/*",
        "storage/framework/views/*",
        "public/storage",
        "scripts/deploy.config.ps1"
    )
}

foreach ($entry in $defaults.GetEnumerator()) {
    if (-not $config.ContainsKey($entry.Key)) {
        $config[$entry.Key] = $entry.Value
    }
}

$required = @("Host", "User", "RemotePath")
foreach ($key in $required) {
    if (-not $config.ContainsKey($key) -or [string]::IsNullOrWhiteSpace([string]$config[$key])) {
        Fail "Missing required config key: $key"
    }
}

$mode = ([string]$config.DeployMode).ToLowerInvariant()
if ($mode -ne "upload" -and $mode -ne "git") {
    Fail "Invalid DeployMode '$mode'. Use 'upload' or 'git'."
}

Write-Info "Using project: $projectRoot"
Write-Info "Deploy mode: $mode"
Write-Info "Target: $($config.User)@$($config.Host):$($config.RemotePath)"

if ($mode -eq "git") {
    if (-not $config.ContainsKey("Branch") -or [string]::IsNullOrWhiteSpace([string]$config.Branch)) {
        Fail "Missing required config key for git mode: Branch"
    }
    if (-not $config.ContainsKey("RepoUrl") -or [string]::IsNullOrWhiteSpace([string]$config.RepoUrl)) {
        Fail "Missing required config key for git mode: RepoUrl"
    }
    if ([string]$config.RepoUrl -match "x-access-token:@") {
        Fail "RepoUrl contains empty token. Set GITHUB_TOKEN in your terminal first."
    }
    Run-GitDeploy -Config $config -ProjectRoot $projectRoot -SkipPush:$SkipPush -DryRun:$DryRun
} else {
    if ($SkipPush) {
        Write-Info "-SkipPush ignored in upload mode."
    }
    Run-UploadDeploy -Config $config -ProjectRoot $projectRoot -DryRun:$DryRun
}

Write-Info "Deploy completed."
