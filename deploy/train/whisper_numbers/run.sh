#!/usr/bin/env bash
# AI Studio training job runner: Whisper Sinhala numbers fine-tune on an NVIDIA node.
#   AISTUDIO_JOB_URL=… AISTUDIO_JOB_TOKEN=… bash run.sh
# Fetches its files from the job (same token), builds a venv once, trains, and uploads the result to the server.
set -euo pipefail
: "${AISTUDIO_JOB_URL:?set AISTUDIO_JOB_URL}" "${AISTUDIO_JOB_TOKEN:?set AISTUDIO_JOB_TOKEN}"
FILES="${AISTUDIO_JOB_URL%/progress}/files"
DIR="${HOME}/.aistudio-train/whisper-numbers"; mkdir -p "$DIR"; cd "$DIR"
auth=(-H "Authorization: Bearer ${AISTUDIO_JOB_TOKEN}")
fail() { curl -s -m 20 "${auth[@]}" -H 'Content-Type: application/json' -d "{\"status\":\"failed\",\"phase\":\"setup failed\",\"log\":[\"$1\"]}" "$AISTUDIO_JOB_URL" >/dev/null || true; echo "error: $1" >&2; exit 1; }
trap 'fail "run.sh stopped at line $LINENO"' ERR

command -v nvidia-smi >/dev/null || fail "nvidia-smi not found: this job needs an NVIDIA GPU node"
command -v python3 >/dev/null || fail "python3 not found"
echo "==> Fetching job files"
for f in train.py numfix.py train.parquet dev.parquet; do
  curl -fsS "${auth[@]}" -o "$f" "${FILES}/${f}"
done
echo "==> Python environment (first run installs PyTorch with CUDA, a few minutes)"
if [ ! -x .venv/bin/python ]; then
  python3 -m venv .venv || fail "python3 -m venv failed: install python3-venv"
  .venv/bin/pip install -q --upgrade pip
  .venv/bin/pip install -q torch transformers==4.46.3 accelerate pyarrow soundfile librosa
fi
.venv/bin/python -c "import torch; assert torch.cuda.is_available(), 'CUDA not available to PyTorch'" || fail "PyTorch cannot see the GPU"
echo "==> Training (progress on the AI Studio Training page)"
trap - ERR
if ! .venv/bin/python train.py 2>&1 | tee train.log; then
  grep -q '"status": "failed"\|nothing to upload' train.log || fail "train.py stopped with an error: $(tail -n 1 train.log | tr -d '\"')"
  exit 1
fi
echo "==> Done. The model was uploaded to AI Studio."
