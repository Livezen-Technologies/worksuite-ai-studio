#!/usr/bin/env bash
#
# WorkSuite AI Studio node control helper. Installed once by an administrator, root-owned:
#
#   curl -fsSL https://STUDIO/node/aistudio-node-control.sh | sudo install -m 755 /dev/stdin /usr/local/sbin/aistudio-node-control
#   echo "$USER ALL=(root) NOPASSWD: /usr/local/sbin/aistudio-node-control" | sudo tee /etc/sudoers.d/aistudio-node-control
#
# The training agent then runs `sudo -n /usr/local/sbin/aistudio-node-control ACTION` when the Studio asks.
# It does exactly one of three things and nothing else, so the sudoers rule grants nothing more:
#   restart   restart the Ollama service
#   update    update Ollama with the official installer, then restart it
#   shutdown  power the machine off in one minute (cancel with: sudo shutdown -c)
set -euo pipefail
case "${1:-}" in
  restart)
    systemctl restart ollama
    echo "Ollama restarted" ;;
  update)
    tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT
    curl -fsSL --proto '=https' https://ollama.com/install.sh -o "$tmp"
    sh "$tmp" >/dev/null
    systemctl restart ollama
    echo "Ollama updated to $(ollama --version 2>/dev/null | awk '{print $NF}')" ;;
  shutdown)
    shutdown -h +1 "Shut down from WorkSuite AI Studio" >/dev/null 2>&1
    echo "Shutting down in one minute" ;;
  *)
    echo "usage: aistudio-node-control restart|update|shutdown" >&2; exit 2 ;;
esac
