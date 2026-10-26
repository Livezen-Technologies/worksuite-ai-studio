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
VENV="${HOME}/.aistudio-agent/venvs/${REQ_HASH}"
if [ ! -x "${VENV}/bin/python" ]; then
  post '{"phase":"installing Python packages (first run on this node)","log":["runner: building the Python environment; the first run takes a few minutes"]}'
  python3 -m venv "$VENV" || fail "python3 -m venv failed (install python3-venv)"
  "${VENV}/bin/pip" install -q --upgrade pip >/dev/null 2>&1
  "${VENV}/bin/pip" install -q -r requirements.txt > pip.log 2>&1 || { rm -rf "$VENV"; fail "pip install failed: $(tail -n 3 pip.log | tr '\n' ' ')"; }
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
