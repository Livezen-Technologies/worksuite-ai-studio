#!/usr/bin/env bash
# AI Studio — GPU speech service on a node, managed by the training agent (called on every heartbeat).
#
#   aistudio-asr-node.sh ensure <tunnel-port>   set up once (background), keep the service + its tunnel running;
#                                               prints the state: ok | setup | error:<reason> | off
#   aistudio-asr-node.sh stop                   stop the service and its tunnel
#
# Runs Whisper on the GPU (faster-whisper, CTranslate2 with NVIDIA's cuBLAS/cuDNN wheels) — or on all CPU cores of a
# node without one — on 127.0.0.1:18096 and
# forwards it to the Studio on <tunnel-port> over the node's existing tunnel key (~/.aistudio-node). The code and the
# models come from the Studio with the agent token. Python: conda if the agent uses it, else python3-venv.
set -uo pipefail
A="${HOME}/.aistudio-agent"; . "$A/agent.env"
S="$A/asr"; mkdir -p "$S/models" "$S/code"
LOCAL_PORT=18096
NODE="${HOME}/.aistudio-node"
state() { echo "$1"; }

stop_all() {
  for f in service tunnel; do [ -f "$S/$f.pid" ] && kill "$(cat "$S/$f.pid")" 2>/dev/null; rm -f "$S/$f.pid"; done
}
alive() { [ -f "$S/$1.pid" ] && kill -0 "$(cat "$S/$1.pid")" 2>/dev/null; }

setup() {   # runs in the background; writes $S/ready or $S/error
  exec > "$S/setup.log" 2>&1
  rm -f "$S/error"
  PY="$S/env/bin/python"
  if [ ! -x "$PY" ]; then
    if [ "${AISTUDIO_PYENV:-venv}" = conda ] && [ -x "${AISTUDIO_CONDA:-}" ]; then
      "$AISTUDIO_CONDA" create -y -q -p "$S/env" python=3.11 pip || { echo "conda create failed" > "$S/error"; return; }
    else
      python3 -m venv "$S/env" || { echo "python3 -m venv failed" > "$S/error"; return; }
    fi
    "$PY" -m pip install -q --upgrade pip
    GPU_LIBS=""; command -v nvidia-smi >/dev/null && GPU_LIBS="nvidia-cublas-cu12 nvidia-cudnn-cu12==9.*"
    "$PY" -m pip install -q "faster-whisper>=1.1,<2" numpy imageio-ffmpeg $GPU_LIBS \
      || { rm -rf "$S/env"; echo "pip install failed: $(tail -n 2 "$S/setup.log" | tr '\n' ' ')" > "$S/error"; return; }
  fi
  sync_files || return
  touch "$S/ready"
}

sync_files() {   # download the service code and any model file that is new or changed (by size)
  M="$(curl -fsS -m 60 -H "Authorization: Bearer ${TOKEN}" "${SERVER}/api/agent/asr/manifest")" || { echo "manifest download failed" > "$S/error"; return 1; }
  changed=0
  while read -r path size _; do
    [ -n "$path" ] || continue
    dst="$S/$path"; mkdir -p "$(dirname "$dst")"
    if [ ! -f "$dst" ] || [ "$(stat -c %s "$dst")" != "$size" ]; then
      curl -fsS -m 3600 -H "Authorization: Bearer ${TOKEN}" -o "$dst.part" "${SERVER}/api/agent/asr/file?path=${path}" \
        && mv "$dst.part" "$dst" || { echo "download failed: $path" > "$S/error"; return 1; }
      changed=1
    fi
  done <<< "$M"
  date +%s > "$S/synced"
  [ "$changed" = 1 ] && [ -f "$S/service.pid" ] && { kill "$(cat "$S/service.pid")" 2>/dev/null; rm -f "$S/service.pid"; }   # restart on new files
  return 0
}

start_service() {
  PY="$S/env/bin/python"
  LIBS="$("$PY" -c 'import os, nvidia.cublas.lib, nvidia.cudnn.lib; print(os.path.dirname(nvidia.cublas.lib.__file__) + ":" + os.path.dirname(nvidia.cudnn.lib.__file__))' 2>/dev/null)"
  FF="$("$PY" -c 'import imageio_ffmpeg; print(imageio_ffmpeg.get_ffmpeg_exe())' 2>/dev/null)"
  if command -v nvidia-smi >/dev/null; then DEV=cuda; THR=4; else DEV=cpu; THR="$(nproc)"; fi   # CPU nodes use all their cores
  ( cd "$S/code" && LD_LIBRARY_PATH="${LIBS}:${LD_LIBRARY_PATH:-}" ASR_DEVICE=$DEV ASR_PORT=$LOCAL_PORT ASR_THREADS=$THR ASR_FFMPEG="${FF:-ffmpeg}" \
      ASR_MODELS_DIR="$S/models" ASR_MODEL="$S/models/whisper-small-sinhala-ct2-int8" \
      ASR_MULTI_MODEL="$S/models/faster-whisper-small" ASR_LID_MODEL="$S/models/faster-whisper-base" \
      setsid "$PY" asr_service.py >> "$S/service.log" 2>&1 < /dev/null & echo $! > "$S/service.pid" )
}

start_tunnel() {   # reuse the node tunnel's key, host and user; listen on the Studio at <tunnel-port>
  T="$NODE/tunnel.sh"; [ -f "$T" ] || { echo "no node tunnel found (~/.aistudio-node): enrol the node first" > "$S/error"; return 1; }
  DEST="$(grep -o '[A-Za-z0-9._-]*@[A-Za-z0-9._-]*' "$T" | tail -1)"
  SPORT="$(grep -o -- '-p [0-9]*' "$T" | head -1 | cut -d' ' -f2)"
  setsid ssh -N -i "$NODE/id_ed25519" -p "${SPORT:-22}" -o BatchMode=yes -o IdentitiesOnly=yes -o ExitOnForwardFailure=yes \
    -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o UserKnownHostsFile="$NODE/known_hosts" -o StrictHostKeyChecking=yes \
    -R "127.0.0.1:$1:127.0.0.1:$LOCAL_PORT" "$DEST" >> "$S/tunnel.log" 2>&1 < /dev/null & echo $! > "$S/tunnel.pid"
}

case "${1:-}" in
  stop) stop_all; state off ;;
  ensure)
    PORT="${2:-}"; [ -n "$PORT" ] || { stop_all; state off; exit 0; }
    if [ ! -f "$S/ready" ]; then
      [ -f "$S/error" ] && [ $(( $(date +%s) - $(stat -c %Y "$S/error") )) -lt 900 ] && { state "error:$(head -c 200 "$S/error")"; exit 0; }
      if ! alive setup; then ( setup ) & echo $! > "$S/setup.pid"; fi
      state setup; exit 0
    fi
    # new or changed models (e.g. a fine-tune) are picked up every 30 minutes
    [ $(( $(date +%s) - $(cat "$S/synced" 2>/dev/null || echo 0) )) -gt 1800 ] && ! alive setup && ( sync_files ) &
    alive service || start_service
    alive tunnel || start_tunnel "$PORT" || { state "error:$(head -c 200 "$S/error")"; exit 0; }
    if curl -fsS -m 5 "http://127.0.0.1:$LOCAL_PORT/health" >/dev/null 2>&1; then state ok; else state setup; fi ;;
  *) echo "usage: $0 ensure <port> | stop" >&2; exit 2 ;;
esac
