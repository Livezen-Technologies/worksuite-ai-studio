<#
  aistudio-node — connect this Windows machine to WorkSuite AI Studio as an Ollama node.

    & ([scriptblock]::Create((irm https://aistudio.livezentech.com/node/aistudio-node.ps1))) -Server https://aistudio.livezentech.com -Token aisn_...

  Installs Ollama if missing (winget), creates an SSH key in %USERPROFILE%\.aistudio-node, registers the machine with
  the one-time token, and keeps a reverse SSH tunnel (127.0.0.1:11434 here -> the server's 127.0.0.1:<port>) running
  at login through the scheduled task "AIStudioNode". The server account it uses can only forward that one port.

    -Status      show the connection
    -Uninstall   stop the tunnel and remove the scheduled task (then remove the node in Studio)
#>
param(
    [string]$Server,
    [string]$Token,
    [string]$Name,
    [switch]$Status,
    [switch]$Uninstall
)
$ErrorActionPreference = 'Stop'
$Dir  = Join-Path $env:USERPROFILE '.aistudio-node'
$Task = 'AIStudioNode'
function Say($m) { Write-Host "==> $m" -ForegroundColor Cyan }
function Die($m) { throw "aistudio-node: $m" }

function Stop-Tunnel {
    Unregister-ScheduledTask -TaskName $Task -Confirm:$false -ErrorAction SilentlyContinue
    Get-CimInstance Win32_Process -Filter "Name='ssh.exe' OR Name='powershell.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like "*$Dir*" -and $_.ProcessId -ne $PID } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

if ($Uninstall) {
    Stop-Tunnel
    Say 'Tunnel stopped and scheduled task removed. Remove the node on the Studio Servers page to revoke its key.'
    Say "Delete $Dir to remove the key and settings."
    return
}
if ($Status) {
    if (-not (Test-Path "$Dir\node.env")) { Die 'This machine is not enrolled.' }
    Get-Content "$Dir\node.env"
    $t = Get-ScheduledTask -TaskName $Task -ErrorAction SilentlyContinue
    Say ("Scheduled task: " + $(if ($t) { $t.State } else { 'missing' }))
    if (Test-Path "$Dir\tunnel.log") { Get-Content "$Dir\tunnel.log" -Tail 5 }
    return
}

if (-not $Server -or -not $Token) { Die 'Usage: aistudio-node.ps1 -Server https://aistudio.example.com -Token aisn_...' }
$Server = $Server.TrimEnd('/')
if (Test-Path "$Dir\node.env") { Die "Already enrolled ($Dir\node.env). Run -Uninstall and remove the node in Studio first." }
$ssh = (Get-Command ssh.exe -ErrorAction SilentlyContinue).Source
$keygen = (Get-Command ssh-keygen.exe -ErrorAction SilentlyContinue).Source
if (-not $ssh -or -not $keygen) { Die 'OpenSSH Client is required: Settings > System > Optional features > OpenSSH Client.' }

# --- Ollama -----------------------------------------------------------------
function Test-Ollama { try { Invoke-RestMethod http://127.0.0.1:11434/api/version -TimeoutSec 3 } catch { $null } }
$ollamaExe = Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama app.exe'
if (-not (Test-Ollama)) {
    if (-not (Test-Path $ollamaExe) -and -not (Get-Command ollama -ErrorAction SilentlyContinue)) {
        if (-not (Get-Command winget -ErrorAction SilentlyContinue)) { Die 'Install Ollama from https://ollama.com/download, then run this again.' }
        Say 'Installing Ollama'
        winget install --id Ollama.Ollama -e --silent --accept-package-agreements --accept-source-agreements --disable-interactivity | Out-Null
    }
    Say 'Starting Ollama'
    if (Test-Path $ollamaExe) { Start-Process $ollamaExe } else { Start-Process ollama -ArgumentList serve -WindowStyle Hidden }
    for ($i = 0; $i -lt 20 -and -not (Test-Ollama); $i++) { Start-Sleep 1 }
}
$ver = Test-Ollama
if (-not $ver) { Die 'Ollama is not answering on 127.0.0.1:11434.' }

# --- Machine facts ------------------------------------------------------------
$cs  = Get-CimInstance Win32_ComputerSystem
$os  = Get-CimInstance Win32_OperatingSystem
$gpu = ''; $cuda = ''
$smi = Get-Command nvidia-smi -ErrorAction SilentlyContinue
if ($smi) {
    $gpu  = (& $smi.Source --query-gpu=name,memory.total --format=csv,noheader | Select-Object -First 1)
    $cuda = ((& $smi.Source) -join "`n") -replace '(?s).*CUDA Version:\s*([0-9.]+).*', '$1'
} else {
    $gpu = (Get-CimInstance Win32_VideoController | Select-Object -First 1).Name
}

# --- Key + enrolment ------------------------------------------------------------
New-Item -ItemType Directory -Force $Dir | Out-Null
$key = Join-Path $Dir 'id_ed25519'
if (-not (Test-Path $key)) { Start-Process -FilePath $keygen -ArgumentList "-q -t ed25519 -N `"`" -C aistudio-node@$env:COMPUTERNAME -f `"$key`"" -Wait -NoNewWindow }
$pub = ((Get-Content "$key.pub" -Raw).Trim() -split '\s+')[0..1] -join ' '

Say "Registering $(if ($Name) { $Name } else { $env:COMPUTERNAME }) with $Server"
$body = @{
    token = $Token; public_key = $pub; name = $Name; hostname = $env:COMPUTERNAME
    os = $os.Caption; gpu_model = $gpu; cuda_version = $cuda
    cpu_cores = $cs.NumberOfLogicalProcessors; ram_gb = [math]::Round($cs.TotalPhysicalMemory / 1GB)
    ollama_version = $ver.version
} | ConvertTo-Json
try {
    $r = (Invoke-RestMethod -Method Post -Uri "$Server/api/nodes/enrol" -ContentType 'application/json' -Body $body).data
} catch {
    $msg = try { ($_.ErrorDetails.Message | ConvertFrom-Json).message } catch { $_.Exception.Message }
    Die "Enrolment failed: $msg"
}
Set-Content "$Dir\known_hosts" $r.known_host -Encoding ascii
@"
SERVER=$Server
SERVER_ID=$($r.server_id)
NAME=$($r.name)
TUNNEL_PORT=$($r.tunnel_port)
SSH_TARGET=$($r.ssh_user)@$($r.ssh_host)
SSH_PORT=$($r.ssh_port)
"@ | Set-Content "$Dir\node.env" -Encoding ascii

# --- Tunnel ---------------------------------------------------------------------
@"
# Keeps the AI Studio tunnel up; reconnects after drops, sleep and network changes.
while (`$true) {
    Add-Content '$Dir\tunnel.log' "`$(Get-Date -Format s) connecting"
    & '$ssh' -N -i '$key' -p $($r.ssh_port) -E '$Dir\ssh.log' ``
        -o BatchMode=yes -o IdentitiesOnly=yes -o ExitOnForwardFailure=yes ``
        -o ServerAliveInterval=30 -o ServerAliveCountMax=3 ``
        -o UserKnownHostsFile='$Dir\known_hosts' -o StrictHostKeyChecking=yes ``
        -R 127.0.0.1:$($r.tunnel_port):127.0.0.1:11434 $($r.ssh_user)@$($r.ssh_host)
    Add-Content '$Dir\tunnel.log' "`$(Get-Date -Format s) disconnected (`$LASTEXITCODE), retrying in 15s"
    Start-Sleep 15
}
"@ | Set-Content "$Dir\tunnel.ps1" -Encoding utf8

Say 'Setting the tunnel to start automatically'
$action   = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$Dir\tunnel.ps1`""
$trigger  = New-ScheduledTaskTrigger -AtLogOn -User "$env:USERDOMAIN\$env:USERNAME"
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName $Task -Action $action -Trigger $trigger -Settings $settings -Description 'WorkSuite AI Studio node tunnel' -Force | Out-Null
Start-ScheduledTask -TaskName $Task

# Ollama starts with Windows too.
$startup = Join-Path ([Environment]::GetFolderPath('Startup')) 'Ollama.lnk'
if ((Test-Path $ollamaExe) -and -not (Test-Path $startup)) {
    $sc = (New-Object -ComObject WScript.Shell).CreateShortcut($startup); $sc.TargetPath = $ollamaExe; $sc.Save()
}

Start-Sleep 6
$up = Get-CimInstance Win32_Process -Filter "Name='ssh.exe'" | Where-Object { $_.CommandLine -like "*127.0.0.1:$($r.tunnel_port):*" }
if ($up) { Say "Connected as `"$($r.name)`" (server port $($r.tunnel_port)). It shows as online on $Server/servers within 5 minutes." }
else { Say "Registered, but the tunnel is not up yet. Check $Dir\ssh.log" }
