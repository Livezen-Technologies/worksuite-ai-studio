#!/usr/bin/env bash
#
# aistudio-node — connect this Mac or Linux machine to WorkSuite AI Studio as an Ollama node.
#
#   curl -fsSL https://aistudio.livezentech.com/node/aistudio-node.sh | bash -s -- --server https://aistudio.livezentech.com --token aisn_...
#
# What it does: installs Ollama if missing, creates an SSH key in ~/.aistudio-node, registers the machine with the
# one-time token, and keeps a reverse SSH tunnel (this machine's 127.0.0.1:11434 → the server's 127.0.0.1:<port>)
# running at login. The server account it uses can only forward that one port.
#
#   aistudio-node.sh --status       show the connection
#   aistudio-node.sh --uninstall    stop the tunnel and remove the autostart entry (then remove the node in Studio)
#
# Linux servers (Ubuntu, Debian, RHEL/Rocky/Alma, Fedora, openSUSE, Arch, Alpine) — one command as root sets up
# everything: missing packages, Ollama as a service, optional models, enrolment and the tunnel as a system service:
#
#   curl -fsSL https://aistudio.livezentech.com/node/aistudio-node.sh | sudo bash -s -- \
#     --server https://aistudio.livezentech.com --token aisn_... --model helalm:4b
#
#   --model NAME    pull an Ollama model after installing (repeat for more)
#
set -euo pipefail

DIR="${HOME}/.aistudio-node"
SERVER="" TOKEN="" NAME="" ACTION=install MODELS=()
LABEL=com.livezen.aistudio-node

while [ $# -gt 0 ]; do
  case "$1" in
    --server) SERVER="${2%/}"; shift 2 ;;
    --token) TOKEN="$2"; shift 2 ;;
    --name) NAME="$2"; shift 2 ;;
    --model) MODELS+=("$2"); shift 2 ;;
    --status) ACTION=status; shift ;;
    --uninstall) ACTION=uninstall; shift ;;
    -h|--help) sed -n '2,13p' "$0" 2>/dev/null || true; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }
OS="$(uname -s)"

stop_autostart() {
  if [ "$OS" = Darwin ]; then
    launchctl bootout "gui/$(id -u)/${LABEL}" 2>/dev/null || true
    rm -f "${HOME}/Library/LaunchAgents/${LABEL}.plist"
  elif [ "$(id -u)" = 0 ]; then
    systemctl disable --now aistudio-node.service 2>/dev/null || true
    rm -f /etc/systemd/system/aistudio-node.service; systemctl daemon-reload 2>/dev/null || true
  else
    systemctl --user disable --now aistudio-node.service 2>/dev/null || true
    rm -f "${HOME}/.config/systemd/user/aistudio-node.service"; systemctl --user daemon-reload 2>/dev/null || true
  fi
  pkill -f "${DIR}/tunnel.sh" 2>/dev/null || true
  pkill -f "${DIR}/id_ed25519" 2>/dev/null || true
}

if [ "$ACTION" = uninstall ]; then
  stop_autostart
  say "Tunnel stopped and autostart removed. Remove the node on the Studio Servers page to revoke its key."
  say "Delete ${DIR} to remove the key and settings."
  exit 0
fi

if [ "$ACTION" = status ]; then
  [ -f "${DIR}/node.env" ] || die "This machine is not enrolled."
  cat "${DIR}/node.env"
  if pgrep -f "ssh .*-R 127.0.0.1:" >/dev/null; then say "Tunnel: running"; else say "Tunnel: not running"; fi
  tail -n 5 "${DIR}/tunnel.log" 2>/dev/null || true
  exit 0
fi

[ -n "$SERVER" ] && [ -n "$TOKEN" ] || die "Usage: aistudio-node.sh --server https://aistudio.example.com --token aisn_..."

# --- Packages (Linux) ---------------------------------------------------------
# curl, the OpenSSH client and the tools the Ollama installer needs. Installed only when missing, only as root.
need=()
for c in curl ssh ssh-keygen awk pgrep; do command -v "$c" >/dev/null || need+=("$c"); done
if [ "$OS" = Linux ] && ! command -v zstd >/dev/null && ! command -v ollama >/dev/null; then need+=(zstd); fi
if [ ${#need[@]} -gt 0 ]; then
  [ "$OS" = Linux ] && [ "$(id -u)" = 0 ] || die "Missing: ${need[*]}. Install them (or run this with sudo on Linux) and try again."
  say "Installing packages: ${need[*]}"
  if command -v apt-get >/dev/null; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq && apt-get install -y -qq curl openssh-client gawk procps zstd ca-certificates >/dev/null
  elif command -v dnf >/dev/null; then dnf install -y -q curl openssh-clients gawk procps-ng zstd
  elif command -v yum >/dev/null; then yum install -y -q curl openssh-clients gawk procps-ng zstd
  elif command -v zypper >/dev/null; then zypper -nq install curl openssh-clients gawk procps zstd
  elif command -v pacman >/dev/null; then pacman -Sy --noconfirm --needed curl openssh gawk procps-ng zstd
  elif command -v apk >/dev/null; then apk add -q curl openssh-client gawk procps zstd bash
  else die "Unknown package manager. Install: ${need[*]}"
  fi
fi
command -v curl >/dev/null || die "curl is required."
command -v ssh >/dev/null || die "OpenSSH client (ssh) is required."
[ -f "${DIR}/node.env" ] && die "Already enrolled (${DIR}/node.env). Run --uninstall and remove the node in Studio first."

# --- Ollama -----------------------------------------------------------------
ollama_up() { curl -fsS -m 3 http://127.0.0.1:11434/api/version >/dev/null 2>&1; }
if ! command -v ollama >/dev/null && ! ollama_up; then
  say "Installing Ollama"
  if [ "$OS" = Darwin ]; then
    command -v brew >/dev/null || die "Install Ollama from https://ollama.com/download (or Homebrew), then run this again."
    brew install ollama
  else
    curl -fsSL https://ollama.com/install.sh | sh
  fi
fi
if ! ollama_up; then
  say "Starting Ollama"
  if [ "$OS" = Darwin ] && command -v brew >/dev/null && brew list ollama >/dev/null 2>&1; then
    brew services start ollama >/dev/null
  elif [ "$OS" = Darwin ] && [ -d /Applications/Ollama.app ]; then
    open -a Ollama
  elif command -v systemctl >/dev/null && systemctl list-unit-files ollama.service >/dev/null 2>&1; then
    if [ "$(id -u)" = 0 ]; then systemctl enable --now ollama; else sudo systemctl enable --now ollama; fi
  else
    nohup ollama serve >/dev/null 2>&1 &
  fi
  for _ in $(seq 1 20); do ollama_up && break; sleep 1; done
  ollama_up || die "Ollama is not answering on 127.0.0.1:11434."
fi
OLLAMA_VERSION="$(curl -fsS http://127.0.0.1:11434/api/version | sed -n 's/.*"version":"\([^"]*\)".*/\1/p')"
for m in "${MODELS[@]+"${MODELS[@]}"}"; do
  say "Pulling model ${m}"
  ollama pull "$m" || say "Could not pull ${m}; continuing."
done

# --- Machine facts ------------------------------------------------------------
HOST="$(hostname)"
if [ "$OS" = Darwin ]; then
  OSNAME="macOS $(sw_vers -productVersion)"
  CORES="$(sysctl -n hw.ncpu)"
  RAM="$(( $(sysctl -n hw.memsize) / 1073741824 ))"
  GPU="$(sysctl -n machdep.cpu.brand_string 2>/dev/null) (Metal)"; CUDA=""
else
  OSNAME="$(. /etc/os-release 2>/dev/null && echo "${PRETTY_NAME:-Linux}")"
  CORES="$(nproc)"
  RAM="$(awk '/MemTotal/ {printf "%.0f", $2/1048576}' /proc/meminfo)"
  GPU="" CUDA=""
  if command -v nvidia-smi >/dev/null; then
    GPU="$(nvidia-smi --query-gpu=name,memory.total --format=csv,noheader 2>/dev/null | head -1)"
    CUDA="$(nvidia-smi 2>/dev/null | sed -n 's/.*CUDA Version: *\([0-9.]*\).*/\1/p' | head -1)"
  fi
fi
js() { printf '%s' "$1" | tr -d '"\\\n\r'; }  # values are plain text; drop characters that would break the JSON

# --- Key + enrolment ------------------------------------------------------------
mkdir -p "$DIR"; chmod 700 "$DIR"
[ -f "${DIR}/id_ed25519" ] || ssh-keygen -q -t ed25519 -N "" -C "aistudio-node@${HOST}" -f "${DIR}/id_ed25519"
PUB="$(cut -d' ' -f1,2 "${DIR}/id_ed25519.pub")"

say "Registering ${NAME:-$HOST} with ${SERVER}"
BODY="{\"token\":\"$(js "$TOKEN")\",\"public_key\":\"${PUB}\",\"name\":\"$(js "$NAME")\",\"hostname\":\"$(js "$HOST")\",\"os\":\"$(js "$OSNAME")\",\"gpu_model\":\"$(js "$GPU")\",\"cuda_version\":\"$(js "$CUDA")\",\"cpu_cores\":\"$(js "$CORES")\",\"ram_gb\":\"$(js "$RAM")\",\"ollama_version\":\"$(js "$OLLAMA_VERSION")\"}"
RESP="$(curl -sS -m 30 -w '\n%{http_code}' -H 'Content-Type: application/json' -d "$BODY" "${SERVER}/api/nodes/enrol?format=env")"
CODE="${RESP##*$'\n'}"; RESP="${RESP%$'\n'*}"
if [ "$CODE" != 201 ]; then
  MSG="$(printf '%s' "$RESP" | sed -n 's/.*"message":"\([^"]*\)".*/\1/p')"
  die "Enrolment failed (${CODE}): ${MSG:-$RESP}"
fi
val() { printf '%s\n' "$RESP" | sed -n "s/^$1=//p" | head -1; }
PORT="$(val TUNNEL_PORT)"; SSH_USER="$(val SSH_USER)"; SSH_HOST="$(val SSH_HOST)"; SSH_PORT="$(val SSH_PORT)"
case "$PORT" in ''|*[!0-9]*) die "Unexpected answer from the server." ;; esac
printf '%s\n' "$(val KNOWN_HOST)" > "${DIR}/known_hosts"
cat > "${DIR}/node.env" <<EOF
SERVER=${SERVER}
SERVER_ID=$(val SERVER_ID)
NAME=$(val NAME)
TUNNEL_PORT=${PORT}
SSH_TARGET=${SSH_USER}@${SSH_HOST}
SSH_PORT=${SSH_PORT:-22}
EOF

# --- Tunnel ---------------------------------------------------------------------
cat > "${DIR}/tunnel.sh" <<EOF
#!/usr/bin/env bash
# Keeps the AI Studio tunnel up; reconnects after drops, sleep and network changes.
while true; do
  echo "\$(date '+%F %T') connecting" >> "${DIR}/tunnel.log"
  ssh -N -i "${DIR}/id_ed25519" -p ${SSH_PORT:-22} \\
    -o BatchMode=yes -o IdentitiesOnly=yes -o ExitOnForwardFailure=yes \\
    -o ServerAliveInterval=30 -o ServerAliveCountMax=3 \\
    -o UserKnownHostsFile="${DIR}/known_hosts" -o StrictHostKeyChecking=yes \\
    -R 127.0.0.1:${PORT}:127.0.0.1:11434 ${SSH_USER}@${SSH_HOST} 2>> "${DIR}/tunnel.log"
  echo "\$(date '+%F %T') disconnected (\$?), retrying in 15s" >> "${DIR}/tunnel.log"
  sleep 15
done
EOF
chmod 700 "${DIR}/tunnel.sh"

say "Setting the tunnel to start automatically"
if [ "$OS" = Darwin ]; then
  mkdir -p "${HOME}/Library/LaunchAgents"
  cat > "${HOME}/Library/LaunchAgents/${LABEL}.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>${LABEL}</string>
  <key>ProgramArguments</key><array><string>/bin/bash</string><string>${DIR}/tunnel.sh</string></array>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
</dict></plist>
EOF
  launchctl bootout "gui/$(id -u)/${LABEL}" 2>/dev/null || true
  launchctl bootstrap "gui/$(id -u)" "${HOME}/Library/LaunchAgents/${LABEL}.plist"
elif command -v systemctl >/dev/null; then
  UNIT="[Unit]
Description=WorkSuite AI Studio node tunnel
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/bin/bash ${DIR}/tunnel.sh
Restart=always
RestartSec=10
"
  if [ "$(id -u)" = 0 ]; then
    printf '%s\n[Install]\nWantedBy=multi-user.target\n' "$UNIT" > /etc/systemd/system/aistudio-node.service
    systemctl daemon-reload && systemctl enable --now aistudio-node.service
  else
    mkdir -p "${HOME}/.config/systemd/user"
    printf '%s\n[Install]\nWantedBy=default.target\n' "$UNIT" > "${HOME}/.config/systemd/user/aistudio-node.service"
    systemctl --user daemon-reload && systemctl --user enable --now aistudio-node.service
    # Keep the user service running without an open login session (needs polkit or sudo).
    loginctl enable-linger "$(id -un)" 2>/dev/null || sudo -n loginctl enable-linger "$(id -un)" 2>/dev/null \
      || say "Tip: run 'sudo loginctl enable-linger $(id -un)' so the tunnel also runs while you are logged out."
  fi
else
  nohup bash "${DIR}/tunnel.sh" >/dev/null 2>&1 &
  say "No systemd found: the tunnel runs now but will not start at boot."
fi

sleep 5
if pgrep -f "ssh .*-R 127.0.0.1:${PORT}:" >/dev/null; then
  say "Connected as \"$(val NAME)\" (server port ${PORT}). It shows as online on ${SERVER}/servers within 5 minutes."
else
  say "Registered, but the tunnel is not up yet. Check ${DIR}/tunnel.log"
fi
