#!/usr/bin/env bash
#
# One-time server setup for node enrolment (aistudio-node). Run as root on the Studio server:
#
#   sudo bash deploy/node-tunnel/setup.sh
#
# Creates the `nodetunnel` user (no shell, no password) and an sshd rule that only lets it open reverse
# forwards. Its keys come from the Studio database through `spark nodes:authorized-keys`, each limited to
# its own 127.0.0.1 port, so removing a node on the Servers page revokes it.
set -euo pipefail

APP=/var/www/ollamahub
PHP=/usr/bin/php8.3

id nodetunnel >/dev/null 2>&1 || useradd --system --create-home --home-dir /var/lib/nodetunnel --shell /usr/sbin/nologin nodetunnel
passwd -l nodetunnel >/dev/null

cat > /usr/local/bin/aistudio-node-keys <<EOF
#!/bin/sh
# sshd AuthorizedKeysCommand: keys of enrolled AI Studio nodes (see app/Services/NodeEnrolmentService.php).
[ "\$1" = nodetunnel ] || exit 0
exec ${PHP} ${APP}/spark --no-header nodes:authorized-keys 2>/dev/null
EOF
chown root:root /usr/local/bin/aistudio-node-keys
chmod 755 /usr/local/bin/aistudio-node-keys

cat > /etc/ssh/sshd_config.d/70-aistudio-nodes.conf <<'EOF'
# AI Studio node tunnels: reverse forwards only, keys from the Studio database.
Match User nodetunnel
    AuthorizedKeysFile none
    AuthorizedKeysCommand /usr/local/bin/aistudio-node-keys %u
    AuthorizedKeysCommandUser www-data
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    AllowTcpForwarding remote
    AllowStreamLocalForwarding no
    AllowAgentForwarding no
    X11Forwarding no
    PermitTunnel no
    PermitTTY no
    GatewayPorts no
    ForceCommand /usr/sbin/nologin
EOF

sshd -t
systemctl reload ssh
echo "nodetunnel ready. Keys served now:"
/usr/local/bin/aistudio-node-keys nodetunnel | sed 's/ssh-ed25519 [^ ]*/ssh-ed25519 …/'
