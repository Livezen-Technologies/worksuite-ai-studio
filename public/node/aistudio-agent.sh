#!/usr/bin/env bash
# WorkSuite AI Studio — training agent for a node.
#
#   curl -fsSL https://aistudio.livezentech.com/node/aistudio-agent.sh | bash -s -- --server https://aistudio.livezentech.com --token aisa_...
#   … | bash -s -- --uninstall
#
# The agent asks AI Studio for work every 15 s (outbound HTTPS only — nothing on this machine is opened up). When the
# server hands it a training job it fetches the job's files, runs them with the generic runner, keeps the server told
# it is alive, stops the run if the job is cancelled, and reports how the process ended. Get the token on the Servers
# page: ⋯ → Install training agent. Run as your normal user (not root); it asks for sudo once to keep running after logout.
set -euo pipefail
VERSION="1.1.1"
DIR="${HOME}/.aistudio-agent"
SERVER="" TOKEN="" UNINSTALL=0
say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }
while [ $# -gt 0 ]; do
  case "$1" in
    --server) SERVER="${2%/}"; shift 2 ;;
    --token) TOKEN="$2"; shift 2 ;;
    --uninstall) UNINSTALL=1; shift ;;
    *) die "unknown option $1" ;;
  esac
done
[ "$(uname -s)" = Linux ] || die "the training agent runs on Linux nodes"
command -v systemctl >/dev/null || die "systemd is required"
export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"

if [ "$UNINSTALL" = 1 ]; then
  systemctl --user disable --now aistudio-agent.service 2>/dev/null || true
  rm -f "${HOME}/.config/systemd/user/aistudio-agent.service"; systemctl --user daemon-reload 2>/dev/null || true
  rm -rf "$DIR"; say "Training agent removed."; exit 0
fi
[ -n "$SERVER" ] && [ -n "$TOKEN" ] || die "usage: --server URL --token aisa_..."
[ "$(id -u)" != 0 ] || die "run this as your normal user, not root (training runs as that user)"
command -v curl >/dev/null || die "curl is required"
# Python environments: python3-venv, or conda / mamba (Anaconda, Miniconda, Miniforge). The service does not load
# your shell's conda setup, so the conda path found now is remembered for the runner.
CONDA="${CONDA_EXE:-$(command -v mamba || command -v conda || true)}"
for c in "$HOME/miniconda3/bin/conda" "$HOME/anaconda3/bin/conda" "$HOME/miniforge3/bin/conda" "$HOME/mambaforge/bin/conda" /opt/conda/bin/conda; do
  [ -n "$CONDA" ] || { [ -x "$c" ] && CONDA="$c"; }
done
if command -v python3 >/dev/null && python3 -c "import venv, ensurepip" 2>/dev/null; then
  PYENV=venv
elif [ -n "$CONDA" ]; then
  PYENV=conda; say "Using conda for training environments: ${CONDA}"
else
  die "need python3-venv (sudo apt install python3-venv) or conda (Miniconda / Anaconda)"
fi

say "Checking the token with ${SERVER}"
CODE="$(curl -s -o /dev/null -w '%{http_code}' -m 20 -X POST -H "Authorization: Bearer ${TOKEN}" -d "version=${VERSION}&probe=1" "${SERVER}/api/agent/heartbeat?format=env")"
[ "$CODE" = 200 ] || die "the server refused the agent token (HTTP ${CODE}). Create a new one on the Servers page."

mkdir -p "$DIR/jobs"; chmod 700 "$DIR"
printf 'SERVER=%s\nTOKEN=%s\nAISTUDIO_PYENV=%s\nAISTUDIO_CONDA=%s\n' "$SERVER" "$TOKEN" "$PYENV" "$CONDA" > "$DIR/agent.env"; chmod 600 "$DIR/agent.env"

cat > "$DIR/agent-loop.sh" <<'LOOP'
#!/usr/bin/env bash
# AI Studio training agent loop (installed by aistudio-agent.sh).
VERSION="1.1.1"
DIR="${HOME}/.aistudio-agent"
. "$DIR/agent.env"
JOB="" PID="" EXITED="" CODE="" TAIL=""
log() { echo "$(date '+%F %T') $*" >> "$DIR/agent.log"; }
log "agent ${VERSION} started"
trap '[ -n "$PID" ] && kill -TERM -- "-$PID" 2>/dev/null; exit 0' TERM INT
while true; do
  # Did the current run end?
  if [ -n "$PID" ] && ! kill -0 "$PID" 2>/dev/null; then
    wait "$PID" 2>/dev/null; CODE=$?
    EXITED="$JOB"; TAIL="$(tail -c 400 "$DIR/jobs/$JOB/runner.log" 2>/dev/null | tr -d '\r')"
    log "job $JOB ended with code $CODE"
    JOB="" PID=""
  fi
  R="$(curl -s -m 30 -X POST -H "Authorization: Bearer ${TOKEN}" \
        --data-urlencode "version=${VERSION}" --data-urlencode "job_id=${JOB}" --data-urlencode "gpus=$(nvidia-smi -L 2>/dev/null | grep -c '^GPU')" \
        --data-urlencode "exited_job_id=${EXITED}" --data-urlencode "exit_code=${CODE}" --data-urlencode "tail=${TAIL}" \
        "${SERVER}/api/agent/heartbeat?format=env" 2>>"$DIR/agent.log")" || { sleep 30; continue; }
  EXITED="" CODE="" TAIL=""
  get() { printf '%s\n' "$R" | sed -n "s/^$1=//p" | head -1; }
  case "$(get ACTION)" in
    run)
      JOB="$(get JOB_ID)"; JD="$DIR/jobs/$JOB"; mkdir -p "$JD"
      log "job $JOB: starting"
      if curl -fsS -m 60 -o "$JD/runner.sh" "$(get RUNNER_URL)"; then
        AISTUDIO_JOB_URL="$(get JOB_URL)" AISTUDIO_JOB_TOKEN="$(get TOKEN)" AISTUDIO_JOB_DIR="$JD" \
          AISTUDIO_PYENV="${AISTUDIO_PYENV:-venv}" AISTUDIO_CONDA="${AISTUDIO_CONDA:-}" \
          setsid bash "$JD/runner.sh" > "$JD/runner.log" 2>&1 < /dev/null &
        PID=$!
      else
        log "job $JOB: could not download the runner"; EXITED="$JOB"; CODE=97; TAIL="could not download the runner"; JOB=""
      fi ;;
    stop)
      if [ -n "$PID" ]; then log "job $JOB: cancelled on the server, stopping"; kill -TERM -- "-$PID" 2>/dev/null; sleep 10; kill -KILL -- "-$PID" 2>/dev/null; fi
      wait "$PID" 2>/dev/null; JOB="" PID="" ;;
  esac
  sleep 15
done
LOOP
chmod 700 "$DIR/agent-loop.sh"

say "Installing the agent service"
mkdir -p "${HOME}/.config/systemd/user"
cat > "${HOME}/.config/systemd/user/aistudio-agent.service" <<EOF
[Unit]
Description=WorkSuite AI Studio training agent
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/bin/bash ${DIR}/agent-loop.sh
Restart=always
RestartSec=15
KillMode=control-group

[Install]
WantedBy=default.target
EOF
# Keep the user service running without an open login (and make the user bus exist for systemctl --user).
if ! loginctl show-user "$(id -un)" -p Linger 2>/dev/null | grep -q yes; then
  loginctl enable-linger "$(id -un)" 2>/dev/null || sudo loginctl enable-linger "$(id -un)" \
    || die "could not enable lingering: run 'sudo loginctl enable-linger $(id -un)' and run this again"
  for _ in 1 2 3 4 5; do [ -S "${XDG_RUNTIME_DIR}/bus" ] && break; sleep 1; done
fi
systemctl --user daemon-reload
systemctl --user enable aistudio-agent.service
systemctl --user restart aistudio-agent.service   # a reinstall must load the new token into a running agent
sleep 3
if systemctl --user is-active --quiet aistudio-agent.service; then
  say "Training agent running. It picks up queued jobs this node can run (log: ${DIR}/agent.log)."
  say "Training must also be allowed for this node on the Servers page."
else
  die "the agent service did not start: systemctl --user status aistudio-agent.service"
fi
