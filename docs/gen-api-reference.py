#!/usr/bin/env python3
"""Regenerate docs/api-reference.md from app/Config/Routes.php. Run from the repository root."""
import re

src = open('app/Config/Routes.php').read()
out = [
    "# WorkSuite AI Studio API reference", "",
    "Generated from `app/Config/Routes.php` (regenerate with `python3 docs/gen-api-reference.py`). Every JSON",
    "endpoint answers `{ status, message, data }`; errors carry `status: \"error\"` and a readable `message`",
    "(422 adds field errors in `data`).", "",
    "| Group | Auth |", "|---|---|",
    "| `/api/auth/*` | public sign-in steps; `profile` and `logout` need a token |",
    "| `/api/*` (admin) | `Authorization: Bearer <access token>` from `/api/auth/login`; per-route permissions are checked in the controller |",
    "| `/api/v1/*` (gateway) | API key and secret (API Keys page) |",
    "| `/api/agent/*` | node agent token |",
    "| `/api/ping` | public liveness |", "",
]
rows = []


def flush():
    if rows:
        out.extend(["| Method | Path | Handler |", "|---|---|---|"] + rows + [""])
        rows.clear()


prefix = ''
for line in src.splitlines():
    m = re.search(r"\$routes->group\('([^']+)'", line)
    if m:
        flush(); prefix = m.group(1); out += [f"## `/{prefix}`", ""]; continue
    m = re.match(r"\s*//\s*---\s*(.+?)\s*---", line)
    if m:
        flush(); out += [f"### {m.group(1)}", ""]; continue
    if line.strip().startswith('});'):
        flush(); prefix = ''; continue
    m = re.search(r"\$routes->(get|post|put|delete|patch)\('([^']*)',\s*'([^']+)'", line)
    if m:
        meth, path, handler = m.groups()
        full = '/' + (prefix + '/' + path if prefix else path).strip('/')
        full = re.sub(r"\(:num\)", "{id}", full)
        full = re.sub(r"\(:segment\)", "{name}", full)
        full = full.replace("([a-z]+)", "{group}").replace("(restart|shutdown|update)", "{restart|shutdown|update}")
        rows.append(f"| {meth.upper()} | `{full}` | {handler.replace('Api' + chr(92), '')} |")
flush()
open('docs/api-reference.md', 'w').write("\n".join(out) + "\n")
print(f"{sum(1 for l in out if l.startswith('| GET') or l.startswith('| POST') or l.startswith('| PUT') or l.startswith('| DELETE'))} routes")
