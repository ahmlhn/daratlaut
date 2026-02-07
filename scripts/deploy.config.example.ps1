@{
    # Deploy mode:
    # - upload: local folder -> hosting via tar+scp (no GitHub needed)
    # - git:    push/pull from GitHub branch
    DeployMode = "upload"

    # SSH target
    Host = "153.92.9.198"
    Port = 65002
    User = "u429122506"

    # Remote Laravel directory (where artisan/composer.json lives)
    RemotePath = "~/panel"

    # Git branch (used only when DeployMode = "git")
    Branch = "main"

    # Optional SSH key path (leave empty to use default ssh-agent/key)
    SshKeyPath = ""

    # Set false only if host fingerprint rotates often (less secure)
    StrictHostKeyChecking = $true

    # Remote actions
    # Run env preflight first (fix invalid .env before composer scripts)
    RemoteEnvPreflight = $true
    RemoteComposerInstall = $true
    RemoteHostingSetup = $true
    RemoteNpmBuild = $false

    # Local actions (upload mode)
    LocalNpmBuild = $false

    # Remote shell command used by ssh (change to "sh -s" if bash unavailable)
    RemoteShell = "bash -se"

    # Optional custom excludes for upload mode
    # UploadExcludes = @(".git", ".env", "vendor", "node_modules")
}
