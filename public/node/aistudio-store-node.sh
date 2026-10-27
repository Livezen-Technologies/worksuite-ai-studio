#!/usr/bin/env bash
# AI Studio — storage helper on a node, managed by the training agent (called on every heartbeat).
#
#   STORE_KEY=… aistudio-store-node.sh ensure <tunnel-port> <folder>   keep the helper + its tunnel running; prints ok | error:<reason>
#   aistudio-store-node.sh stop                                        stop both
#
# Serves <folder> on 127.0.0.1:18098 (Python standard library only: nothing to install) and forwards it to the Studio
# on <tunnel-port> over the node's tunnel key (~/.aistudio-node). Every request must carry the node's storage key;
# objects never leave <folder>. Endpoints: GET /stat, PUT|GET|DELETE /o/<key> (PUT checks ?sha256= before keeping it).
set -uo pipefail
A="${HOME}/.aistudio-agent"
S="$A/store"; mkdir -p "$S"
LOCAL_PORT=18098
NODE="${HOME}/.aistudio-node"

stop_all() { for f in service tunnel; do [ -f "$S/$f.pid" ] && kill "$(cat "$S/$f.pid")" 2>/dev/null; rm -f "$S/$f.pid"; done; }
alive() { [ -f "$S/$1.pid" ] && kill -0 "$(cat "$S/$1.pid")" 2>/dev/null; }

write_service() {
  cat > "$S/store_service.py" <<'PY'
import hashlib, hmac, json, os, shutil, sys, urllib.parse
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
ROOT = os.path.realpath(os.environ["STORE_DIR"]); KEY = os.environ["STORE_KEY"].encode(); os.makedirs(ROOT, exist_ok=True)
CHUNK = 1 << 20
class H(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def log_message(self, *a): pass
    def reply(self, code, body=b"", ctype="text/plain"):
        if isinstance(body, str): body = body.encode()
        self.send_response(code); self.send_header("Content-Type", ctype); self.send_header("Content-Length", str(len(body))); self.end_headers(); self.wfile.write(body)
    def allowed(self):
        if hmac.compare_digest(self.headers.get("X-Store-Key", "").encode(), KEY): return True
        self.reply(403, "bad storage key"); return False
    def target(self):
        u = urllib.parse.urlparse(self.path); key = urllib.parse.unquote(u.path[3:])
        p = os.path.realpath(os.path.join(ROOT, key))
        if not key or not p.startswith(ROOT + os.sep): self.reply(400, "invalid key"); return None, None
        return p, urllib.parse.parse_qs(u.query)
    def do_GET(self):
        if not self.allowed(): return
        if self.path == "/stat":
            d = shutil.disk_usage(ROOT); return self.reply(200, json.dumps({"total": d.total, "used": d.used, "free": d.free}), "application/json")
        if not self.path.startswith("/o/"): return self.reply(404, "not found")
        p, _ = self.target()
        if p is None: return
        if not os.path.isfile(p): return self.reply(404, "no such object")
        self.send_response(200); self.send_header("Content-Type", "application/octet-stream"); self.send_header("Content-Length", str(os.path.getsize(p))); self.end_headers()
        with open(p, "rb") as f: shutil.copyfileobj(f, self.wfile, CHUNK)
    def do_PUT(self):
        if not self.allowed(): return
        p, q = self.target() if self.path.startswith("/o/") else (None, None)
        if p is None: return self.reply(404, "not found") if not self.path.startswith("/o/") else None
        n = int(self.headers.get("Content-Length", "0")); os.makedirs(os.path.dirname(p), exist_ok=True)
        h = hashlib.sha256(); tmp = p + ".part"
        with open(tmp, "wb") as f:
            while n > 0:
                b = self.rfile.read(min(CHUNK, n))
                if not b: break
                f.write(b); h.update(b); n -= len(b)
            f.flush(); os.fsync(f.fileno())
        want = (q.get("sha256") or [""])[0]
        if n > 0 or (want and want != h.hexdigest()):
            os.unlink(tmp); return self.reply(422, "upload incomplete or checksum mismatch")
        os.replace(tmp, p); self.reply(201, "stored")
    def do_DELETE(self):
        if not self.allowed(): return
        p, _ = self.target() if self.path.startswith("/o/") else (None, None)
        if p is None: return
        try: os.unlink(p)
        except FileNotFoundError: pass
        self.reply(200, "deleted")
ThreadingHTTPServer(("127.0.0.1", int(os.environ.get("STORE_PORT", "18098"))), H).serve_forever()
PY
}

start_service() {   # detached with every stream redirected, so the agent never waits on this script's output
  write_service
  STORE_DIR="$1" STORE_KEY="$STORE_KEY" STORE_PORT=$LOCAL_PORT setsid python3 "$S/store_service.py" >> "$S/service.log" 2>&1 < /dev/null &
  echo $! > "$S/service.pid"; echo "$1" > "$S/dir"; printf '%s' "$STORE_KEY" | sha256sum | cut -c1-16 > "$S/keyid"
}

start_tunnel() {   # reuse the node tunnel's key, host and user; listen on the Studio at <tunnel-port>
  T="$NODE/tunnel.sh"; [ -f "$T" ] || { echo "no node tunnel found (~/.aistudio-node): enrol the node first"; return 1; }
  DEST="$(grep -o '[A-Za-z0-9._-]*@[A-Za-z0-9._-]*' "$T" | tail -1)"
  SPORT="$(grep -o -- '-p [0-9]*' "$T" | head -1 | cut -d' ' -f2)"
  setsid ssh -N -i "$NODE/id_ed25519" -p "${SPORT:-22}" -o BatchMode=yes -o IdentitiesOnly=yes -o ExitOnForwardFailure=yes \
    -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o UserKnownHostsFile="$NODE/known_hosts" -o StrictHostKeyChecking=yes \
    -R "127.0.0.1:$1:127.0.0.1:$LOCAL_PORT" "$DEST" >> "$S/tunnel.log" 2>&1 < /dev/null & echo $! > "$S/tunnel.pid"
}

case "${1:-}" in
  stop) stop_all; echo off ;;
  ensure)
    PORT="${2:-}"; DIRP="${3:-}"; case "$DIRP" in /*) ;; ?*) DIRP="$HOME/$DIRP" ;; esac   # relative = under the agent user's home
    [ -n "$PORT" ] && [ -n "$DIRP" ] && [ -n "${STORE_KEY:-}" ] || { stop_all; echo off; exit 0; }
    command -v python3 >/dev/null || { echo "error:python3 is not installed on the node"; exit 0; }
    mkdir -p "$DIRP" 2>/dev/null && [ -w "$DIRP" ] || { echo "error:cannot write to $DIRP on the node"; exit 0; }
    # folder or key changed on the Studio: restart with the new ones
    if alive service && { [ "$(cat "$S/dir" 2>/dev/null)" != "$DIRP" ] || [ "$(printf '%s' "$STORE_KEY" | sha256sum | cut -c1-16)" != "$(cat "$S/keyid" 2>/dev/null)" ]; }; then stop_all; fi
    alive service || start_service "$DIRP"
    alive tunnel || { E="$(start_tunnel "$PORT")" || { echo "error:$E"; exit 0; }; }
    sleep 1; alive service && echo ok || echo "error:$(tail -n 1 "$S/service.log" | head -c 200)" ;;
  *) echo "usage: $0 ensure <port> <folder> | stop" >&2; exit 2 ;;
esac
