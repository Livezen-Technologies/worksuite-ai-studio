# WorkSuite AI Studio operations runbook

Production: `https://aistudio.livezentech.com`, app in `/var/www/ollamahub`, database `ollamahub`.

## Deploy

From your machine, in the repository:

```bash
bash deploy/rsync-deploy.sh
```

It builds the frontend, **backs up the database on the server first**
(`/var/backups/aistudio/predeploy-*.sql.gz`, last 10 kept), syncs the code, runs `composer install`,
`php spark migrate --all`, clears the cache, restarts the queue worker and PHP-FPM, then pings
`/api/ping` (3 tries; a failure exits non-zero and prints the pre-deploy backup). It never deletes server-only files: `.env`, `writable/`, `public/node/downloads/`
(desktop installers) and `public/uploads/`.

Override the target with `DEPLOY_HOST`, `DEPLOY_DIR`, `DEPLOY_URL`.

If a step fails, the script prints the backup to restore from (next section).

## Backups

- **Nightly:** `deploy/backup-db.sh` runs from root's crontab at 03:17 and keeps 14 days in
  `/var/backups/aistudio`. Log: `/var/log/aistudio-backup.log`.
- **Off the server:** a backup on the same disk does not survive losing the server. Add
  `BACKUP_RSYNC_TARGET=backup@other-host:/srv/aistudio/` to the cron line (`sudo crontab -e`) and give
  root an SSH key that can write there.
- **In the app:** Storage → Backups also runs backups and can restore (Super Admin only).

- **Restore test:** `deploy/verify-backup.sh` runs from root's crontab on Sundays at 05:23. It restores
  the newest nightly dump into `ollamahub_restore_check`, checks `users`, `roles` and `settings` have
  rows, drops it, and feeds the `BackupRestoreStale` alert (> 8 days). Log: `/var/log/aistudio-backup.log`.
  Run it by hand: `sudo bash /var/www/ollamahub/deploy/verify-backup.sh`.

Check the latest backup: `sudo ls -lt /var/backups/aistudio | head` (the directory is root-only, 0700).

## Restore the database

```bash
cd /var/www/ollamahub
sudo systemctl stop ollamahub-queue
# Safety copy of the current state first
sudo APP_DIR=$PWD bash deploy/backup-db.sh
# Restore (replace the file name)
sudo gunzip -c /var/backups/aistudio/predeploy-YYYYMMDD-HHMMSS.sql.gz | sudo mysql ollamahub
sudo systemctl start ollamahub-queue
```

To roll the code back as well, check out the previous release locally and run the deploy script again
(it migrates forward only; a restored database already has the older schema).

## One-time steps for the existing server (from the security audit)

Do these once on the current production server.

1. **JWT secret.** The app now refuses to start in production without a real `JWT_SECRET`.
   Check `/var/www/ollamahub/.env` has `JWT_SECRET` set to a random value of 32+ characters before
   deploying. If it was missing (the old code silently used a public default), set one with
   `openssl rand -hex 32`. Everyone will need to sign in again, and users with an authenticator app
   must set it up again, because its secrets are encrypted with a key derived from `JWT_SECRET`.
2. **Migrate.** The deploy runs it: adds `users.token_version` (sessions can now be revoked) and
   renames the default site/sender name. After this deploy every existing session is still valid;
   signing out now ends a user's sessions on all devices.
3. **nginx.** Copy the security headers and the sign-in rate limit, then reload:
   ```bash
   sudo cp deploy/nginx-security-headers.conf /etc/nginx/snippets/aistudio-security.conf
   echo 'limit_req_zone $binary_remote_addr zone=aistudio_auth:10m rate=30r/m;' | sudo tee /etc/nginx/conf.d/aistudio-ratelimit.conf
   ```
   Then merge `deploy/nginx-ollamahub.conf` into the live vhost, keeping its `listen 443 ssl` and
   certificate lines (added by certbot), and run `sudo nginx -t && sudo systemctl reload nginx`.
4. **Tighten database privileges:**
   ```sql
   REVOKE ALL PRIVILEGES ON ollamahub.* FROM 'ollamahub'@'localhost';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES,
         CREATE TEMPORARY TABLES ON ollamahub.* TO 'ollamahub'@'localhost';
   FLUSH PRIVILEGES;
   ```
5. **Nightly backup and firewall** (if the server predates them):
   ```bash
   ( sudo crontab -l 2>/dev/null | grep -v 'deploy/backup-db.sh' ; echo "17 3 * * * APP_DIR=/var/www/ollamahub bash /var/www/ollamahub/deploy/backup-db.sh >> /var/log/aistudio-backup.log 2>&1" ) | sudo crontab -
   sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw --force enable
   ```

6. **Service sandboxing, alerts and log cleanup** (second audit round):
   ```bash
   cd /var/www/ollamahub
   sudo cp deploy/ollamahub-queue.service /etc/systemd/system/
   sudo cp deploy/aistudio-asr.service /etc/systemd/system/
   sudo cp deploy/ml/aistudio-ml.service /etc/systemd/system/
   sudo systemctl daemon-reload && sudo systemctl restart ollamahub-queue aistudio-asr aistudio-ml
   sudo cp deploy/prometheus-alerts.yml /etc/prometheus/aistudio-alerts.yml
   sed "s/__DOMAIN__/aistudio.livezentech.com/" deploy/prometheus-ollamahub.yml | sudo tee /etc/prometheus/prometheus.yml >/dev/null
   sudo systemctl restart prometheus
   ( sudo crontab -l 2>/dev/null | grep -v 'writable/logs' ; echo "41 4 * * * find /var/www/ollamahub/writable/logs -name 'log-*.log' -mtime +30 -delete" ) | sudo crontab -
   ```
   Check each service is still `active` with `systemctl status`. If one fails because it writes
   somewhere the sandbox now protects, add that directory as `ReadWritePaths=` in its unit.
   Alerts appear on Prometheus' `/alerts` page; add an Alertmanager to receive them by email or Slack.

7. **Scheduler as www-data** (1.28.1): the per-minute `spark schedule:run` cron must run as the
   owner of `writable/`, not as `ubuntu`:
   ```bash
   crontab -l 2>/dev/null | grep -v 'spark schedule:run' | crontab -   # as ubuntu: drop the old entry
   ( sudo crontab -u www-data -l 2>/dev/null | grep -v 'spark schedule:run' ; echo "* * * * * cd /var/www/ollamahub && php8.3 spark schedule:run >> /var/www/ollamahub/writable/logs/schedule.log 2>&1" ) | sudo crontab -u www-data -
   ```

8. **nginx hardening, compression, restore tests and outside monitoring** (third audit round):
   ```bash
   cd /var/www/ollamahub
   # nginx: only /index.php reaches PHP-FPM (any other *.php is 404) and gzip for text/JSON/SVG.
   # Merge the `location = /index.php`, `location ~ \.php$ { return 404; }` and gzip lines of
   # deploy/nginx-ollamahub.conf into the live vhost (keep certbot's 443/certificate lines), then:
   sudo nginx -t && sudo systemctl reload nginx
   curl -sI https://aistudio.livezentech.com/x.php | head -1                    # 404
   curl -s -o /dev/null -w '%{http_code}\n' https://aistudio.livezentech.com/api/ping   # 200
   # Weekly restore test (root's crontab)
   ( sudo crontab -l 2>/dev/null | grep -v 'deploy/verify-backup.sh' ; echo "23 5 * * 0 APP_DIR=/var/www/ollamahub bash /var/www/ollamahub/deploy/verify-backup.sh >> /var/log/aistudio-backup.log 2>&1" ) | sudo crontab -
   sudo APP_DIR=/var/www/ollamahub bash deploy/verify-backup.sh                  # first run now
   # Blackbox probe of /api/ping + systemd unit states + new alerts
   sudo apt-get install -y prometheus-blackbox-exporter
   echo 'ARGS="--collector.systemd --collector.systemd.unit-include=(ollamahub-queue|aistudio-asr|aistudio-ml|php8.3-fpm|nginx|mysql)\.service"' | sudo tee /etc/default/prometheus-node-exporter
   sed "s/__DOMAIN__/aistudio.livezentech.com/" deploy/prometheus-ollamahub.yml | sudo tee /etc/prometheus/prometheus.yml >/dev/null
   sudo cp deploy/prometheus-alerts.yml /etc/prometheus/aistudio-alerts.yml
   sudo systemctl enable --now prometheus-blackbox-exporter
   sudo systemctl restart prometheus prometheus-node-exporter
   ```
   Check on Prometheus' `/targets` that `blackbox-site` is up and `/alerts` lists `SiteDown`,
   `ServiceDown` and `BackupRestoreStale`.

   **Remaining: Alertmanager.** Alerts are only visible on `/alerts` until an Alertmanager routes
   them. Install `prometheus-alertmanager`, configure a receiver in `/etc/prometheus/alertmanager.yml`
   with the owner's email (SMTP host and credentials) or Slack webhook, and add to prometheus.yml:
   `alerting: { alertmanagers: [ { static_configs: [ { targets: ['127.0.0.1:9093'] } ] } ] }`.
   Note the blackbox probe runs on the same server, so it catches nginx/PHP/TLS failures but not a
   whole-server outage; an external uptime check covers that.

9. **Queue unit, backup directory, MCP gateway** (fourth audit round; all commands are safe to re-run):
   ```bash
   cd /var/www/ollamahub
   # Queue worker: absolute php path, and failed runs are logged (journalctl -u ollamahub-queue)
   # instead of disappearing silently; the 5 s polling loop stays.
   sudo cp deploy/ollamahub-queue.service /etc/systemd/system/ollamahub-queue.service
   sudo systemctl daemon-reload && sudo systemctl restart ollamahub-queue
   systemctl status ollamahub-queue --no-pager | head -5
   # Backups: root-owned and root-only, for both nightly and pre-deploy dumps (deploys no longer chown it).
   sudo install -d -m 700 /var/backups/aistudio
   sudo chown -R root:root /var/backups/aistudio && sudo chmod 700 /var/backups/aistudio
   sudo ls -ld /var/backups/aistudio                                           # drwx------ root root
   ```
   If the MCP gateway (`mcp-server/`, HTTP mode) is deployed: rebuild it (`npm ci && npm run build`)
   and restart it. It now listens on `HOST` (default `127.0.0.1`; nginx proxies to
   `127.0.0.1:8787`, so leave it unset), allows 120 requests/minute per client IP on `/mcp` and 20
   failed sign-ins per 15 minutes per IP. Behind nginx, set `TRUST_PROXY=loopback` in its `.env` so
   limits apply per real client instead of to nginx as a whole. Check: `ss -ltnp | grep 8787`
   shows `127.0.0.1:8787`.

### Later

- **ASR service location.** `aistudio-asr.service` runs as `ubuntu` from `/home/ubuntu/sinhala-build`.
  When convenient, move it to `/opt/aistudio-asr` owned by a dedicated system user
  (`sudo useradd --system --home /opt/aistudio-asr aistudio-asr`), recreate the venv there, point
  `User=`, `ExecStart=` and `ReadWritePaths=` at it and set `ProtectHome=true`. Not done yet so the
  live paths keep working.

## Security behaviour to know

- **Sign-in rate limits:** 5 attempts per account and 30 per IP per minute (HTTP 429), plus nginx
  damping on `/api/auth/login`.
- **Sessions:** signing out, a password change, a role or status change, or "reset sign-in methods"
  revokes the user's tokens at once. Suspended users and role changes take effect on the next request.
- **Uploads:** dataset imports accept jsonl/json/csv/tsv/txt (UTF-8, up to 200 MB; 100 MB in My
  Workspace), speech datasets a zip up to 4 GB, knowledge documents text formats up to 20 MB.
- **Audit log:** besides every change, it records downloads and exports and every sign-in attempt
  (the email tried, never the password). A failed audit write is logged to `writable/logs`.
- **Administration ceiling:** outside super admin, nobody can assign, edit or delete a user or role
  with more access than their own, change their own role, or grant permissions they lack. The last
  active super admin cannot be demoted or suspended. Database backups are super-admin only.
- **Server URLs:** node and provider URLs may be loopback or private (normal for Ollama nodes) but not
  link-local or cloud metadata addresses (e.g. `169.254.169.254`).

## Health checks

- `GET /api/ping`: public liveness.
- `GET /api/health` (signed in): database, nodes, queue and storage.
- Queue worker: `systemctl status ollamahub-queue`, logs in `writable/logs/`.
