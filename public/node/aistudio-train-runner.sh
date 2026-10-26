#!/usr/bin/env bash
# AI Studio generic training runner — started by the node agent for one job.
#   AISTUDIO_JOB_URL=…/api/training/{id}/progress AISTUDIO_JOB_TOKEN=… AISTUDIO_JOB_DIR=… bash aistudio-train-runner.sh
# Fetches the job's files listed in manifest.txt, builds (once per requirements set) a Python venv, loads job.env and
# runs train.py, which reports its own progress and uploads its result. Setup errors are reported as a failed job.
set -uo pipefail
: "${AISTUDIO_JOB_URL:?}" "${AISTUDIO_JOB_TOKEN:?}"
JD="${AISTUDIO_JOB_DIR:-$PWD}"; cd "$JD"
FILES="${AISTUDIO_JOB_URL%/progress}/files"
auth=(-H "Authorization: Bearer ${AISTUDIO_JOB_TOKEN}")
post() { curl -s -m 20 "${auth[@]}" -H 'Content-Type: application/json' -d "$1" "$AISTUDIO_JOB_URL" >/dev/null || true; }
fail() { echo "error: $1" >&2; post "{\"status\":\"failed\",\"phase\":\"setup failed\",\"log\":[\"$(printf '%s' "$1" | tr -d '"\\')\"]}"; exit 1; }

post '{"phase":"fetching job files","log":["runner: fetching job files"]}'
curl -fsS "${auth[@]}" -o manifest.txt "${FILES}/manifest.txt" || fail "could not fetch the job manifest"
while read -r f; do
  [ -n "$f" ] || continue
  curl -fsS "${auth[@]}" -o "$f" "${FILES}/${f}" || fail "could not fetch ${f}"
done < manifest.txt

REQ_HASH="$(sha256sum requirements.txt | cut -c1-16)"
# One environment per requirements set: python3-venv, or a conda env when the node uses conda (AISTUDIO_PYENV=conda).
PYENV="${AISTUDIO_PYENV:-venv}"
if [ "$PYENV" = conda ]; then
  CONDA="${AISTUDIO_CONDA:-$(command -v conda || true)}"; [ -x "$CONDA" ] || fail "conda not found at '${CONDA}'; reinstall the agent"
  VENV="${HOME}/.aistudio-agent/conda/${REQ_HASH}"
else
  VENV="${HOME}/.aistudio-agent/venvs/${REQ_HASH}"
fi
if [ ! -x "${VENV}/bin/python" ]; then
  post '{"phase":"installing Python packages (first run on this node)","log":["runner: building the Python environment ('"$PYENV"'); the first run takes a few minutes"]}'
  if [ "$PYENV" = conda ]; then
    "$CONDA" create -y -q -p "$VENV" python=3.11 pip > env.log 2>&1 || { rm -rf "$VENV"; fail "conda create failed: $(tail -n 3 env.log | tr '\n' ' ')"; }
  else
    python3 -m venv "$VENV" || fail "python3 -m venv failed (install python3-venv, or install the agent with conda)"
  fi
  "${VENV}/bin/python" -m pip install -q --upgrade pip >/dev/null 2>&1
  "${VENV}/bin/python" -m pip install -q -r requirements.txt > pip.log 2>&1 || { rm -rf "$VENV"; fail "pip install failed: $(tail -n 3 pip.log | tr '\n' ' ')"; }
fi
if command -v nvidia-smi >/dev/null; then
  "${VENV}/bin/python" -c "import torch, sys; sys.exit(0 if torch.cuda.is_available() else 1)" 2>/dev/null \
    || fail "PyTorch cannot see the GPU on this node (check the NVIDIA driver)"
fi
set -a; . ./job.env; set +a
post '{"phase":"training","log":["runner: starting train.py"]}'
"${VENV}/bin/python" train.py 2>&1 | tee train.log
RC=${PIPESTATUS[0]}
if [ "$RC" != 0 ] && ! grep -q 'nothing to upload' train.log; then
  fail "train.py exited with code ${RC}: $(tail -n 2 train.log | tr '\n' ' ' | cut -c1-300)"
fi
exit "$RC"
