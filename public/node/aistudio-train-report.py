#!/usr/bin/env python3
"""aistudio-train-report — send a training run's live progress to WorkSuite AI Studio.

Follows the run's log file and reports every few seconds: step / total and ETA (from the tqdm progress bar),
loss and learning rate (Hugging Face Trainer log lines), evaluation lines like `[after] {"wer": ...}`, the last
log lines, and this machine's GPU and CPU load. Needs only Python 3.8+; nvidia-smi and psutil are used if present.

  Get the token on the Training page (job → Live progress → Create report token), then:

  python aistudio-train-report.py --url https://aistudio.example.com/api/training/12/progress --token aist_... \
      --log out/train.log [--pid 1234]

It marks the job completed when the log prints a final `REPORT` line (or --pid exits cleanly), failed on a Python
traceback. AISTUDIO_JOB_URL / AISTUDIO_JOB_TOKEN can replace --url / --token.
"""
import argparse, ast, json, os, re, shutil, socket, subprocess, sys, time, urllib.request

ap = argparse.ArgumentParser()
ap.add_argument("--url", default=os.environ.get("AISTUDIO_JOB_URL"))
ap.add_argument("--token", default=os.environ.get("AISTUDIO_JOB_TOKEN"))
ap.add_argument("--log", required=True)
ap.add_argument("--pid", type=int)
ap.add_argument("--every", type=float, default=10)
ap.add_argument("--server-id", type=int)
args = ap.parse_args()
if not args.url or not args.token:
    sys.exit("--url and --token (or AISTUDIO_JOB_URL / AISTUDIO_JOB_TOKEN) are required")

TQDM = re.compile(r"(\d+)/(\d+) \[([\d:]+)<([\d:?]+),\s*([\d.]+)(s/it|it/s)\]")
EVAL = re.compile(r"^\[(\w+)\] (\{.*\})\s*$")


def secs(t):
    if "?" in t:
        return None
    n = 0
    for part in t.split(":"):
        n = n * 60 + int(part)
    return n


def machine():
    out = {"host": socket.gethostname()}
    try:
        import psutil
        out["cpu_pct"] = psutil.cpu_percent(interval=None)
        out["ram_pct"] = psutil.virtual_memory().percent
    except ImportError:
        pass
    if shutil.which("nvidia-smi"):
        try:
            q = subprocess.run(["nvidia-smi", "--query-gpu=name,utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw",
                                "--format=csv,noheader,nounits"], capture_output=True, text=True, timeout=5).stdout.splitlines()[0]
            name, util, used, total, temp, power = [x.strip() for x in q.split(",")]
            f = lambda s: float(s) if s.replace(".", "", 1).isdigit() else None
            out.update(gpu_name=name, gpu_pct=f(util), vram_used_mb=f(used), vram_total_mb=f(total), gpu_temp_c=f(temp), gpu_power_w=f(power))
            if f(used) is not None and f(total):
                out["vram_pct"] = round(f(used) / f(total) * 100, 1)
        except Exception:
            pass
    return out


def post(payload):
    req = urllib.request.Request(args.url, data=json.dumps(payload, ensure_ascii=False).encode(), method="POST",
                                 headers={"Content-Type": "application/json", "Authorization": "Bearer " + args.token})
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return r.status
    except Exception as e:
        print(f"report failed: {e}", file=sys.stderr)


def alive(pid):
    if pid is None:
        return True
    if os.name == "nt":
        out = subprocess.run(["tasklist", "/FI", f"PID eq {pid}", "/NH"], capture_output=True, text=True).stdout
        return str(pid) in out
    try:
        os.kill(pid, 0)
        return True
    except OSError:
        return False


pos, carry, status = 0, "", None
state = {"phase": "starting"}
metrics = {}
print(f"Reporting {args.log} to {args.url}")
while True:
    new_lines = []
    if os.path.exists(args.log):
        with open(args.log, "rb") as f:
            f.seek(pos)
            chunk = f.read()
            pos = f.tell()
        text = carry + chunk.decode("utf-8", "replace")
        # tqdm rewrites its line with \r; treat both \r and \n as line ends, keep the unfinished tail.
        parts = re.split(r"[\r\n]", text)
        carry = parts.pop()
        for line in parts:
            if not line.strip():
                continue
            m = TQDM.search(line)
            if m:
                step, total, el, eta, rate, unit = m.groups()
                rate = float(rate)
                state.update(step=int(step), total_steps=int(total), eta_seconds=secs(eta),
                             sec_per_step=rate if unit == "s/it" else round(1 / rate, 3) if rate else None, phase="training")
                continue  # progress bars are not log lines
            if line.lstrip().startswith("{'loss'") or line.lstrip().startswith("{'train_runtime'"):
                try:
                    d = ast.literal_eval(line.strip())
                    for k in ("loss", "learning_rate", "epoch", "grad_norm"):
                        if k in d and isinstance(d[k], (int, float)):
                            state["lr" if k == "learning_rate" else k] = d[k]
                except Exception:
                    pass
            e = EVAL.match(line.strip())
            if e:
                try:
                    metrics[e.group(1)] = json.loads(e.group(2))
                    state["phase"] = f"evaluating ({e.group(1)})" if e.group(1) == "before" else "finishing"
                except ValueError:
                    pass
            if line.startswith("REPORT "):
                status = "completed"
            if line.startswith("Traceback") or re.match(r"^\w*Error: ", line):
                status = "failed"
            new_lines.append(line)

    if status is None and args.pid and not alive(args.pid):
        status = "completed" if "after" in metrics else "failed"

    payload = {**state, **machine(), "log": new_lines[-200:]}
    if args.server_id:
        payload["server_id"] = args.server_id
    if metrics:
        payload["metrics"] = metrics
    payload["status"] = status or "running"
    post(payload)
    if status:
        print(f"Job {status}.")
        break
    time.sleep(args.every)
