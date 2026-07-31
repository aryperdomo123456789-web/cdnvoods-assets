# Lovable Project Brief - Proxy Mago

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


## Mandatory Environment Rule

This project is being developed **directly on the real production-like VPS**:

- Host VPS: `45.140.192.237`
- OS: `Ubuntu 22.04`
- Active path: `/opt/proxy-mago/proxy-mago-base`

Lovable must **never assume** it is working against an isolated default local
environment of its own. All reasoning, documentation and implementation must
treat this repository as the codebase that is meant to run on the Ubuntu 22.04
server above.

If a document or code path seems ambiguous, the correct interpretation is:

- runtime target = this VPS
- filesystem target = this VPS
- nginx/php/sqlite references = this VPS
- operational decisions = this VPS

## Context

This repository is the working source for a lightweight anti-leak proxy panel for a single XUI origin server.

The goal is to keep the stack small, cheap to run, and easy to maintain on Ubuntu 22.04.

## Current State

Date: 2026-07-31

Deployed environment:

- VPS: `45.140.192.237`
- OS: Ubuntu 22.04
- Web stack installed: `nginx`, `php-fpm`, `php-sqlite3`, `sqlite3`
- PHP runtime available on the VPS: `PHP 8.1`
- Active project path on VPS: `/opt/proxy-mago/proxy-mago-base`
- Git repository initialized on the VPS and connected to GitHub
- Default branch: `main`
- Active public panel: `https://cdnvoods.vr766.com/`
- Public alias under test: `voods.suafontee.com`

## Project Goal

Protect a single streaming origin from direct source leakage by placing a lightweight reverse proxy in front of it and managing the origin through a simple admin panel.

The public main domain is the official front door of the system. It should be the only hostname users share. Alias domains can point to it through Cloudflare, but the origin IP must remain internal and never be published in DNS.

## Important Decisions

- Do not use Laravel.
- Use plain PHP.
- Use SQLite instead of MySQL.
- Use Nginx as the reverse proxy.
- Keep the admin panel minimal and focused.
- Avoid unnecessary services, queues, or background workers.
- Keep the official main hostname behind Cloudflare proxying when the goal is to hide the VPS IP.
- Keep the XUI origin IP out of public DNS records.

## Active Structure

The active implementation lives in:

- `proxy-mago-base/public/` - panel pages and setup flow
- `proxy-mago-base/app/` - config, auth, SQLite, audit, and Nginx config generation
- `proxy-mago-base/config/` - base application config
- `proxy-mago-base/storage/` - SQLite database and logs
- `proxy-mago-base/nginx/` - sample Nginx config

## What Already Exists

- initial setup page
- login/logout
- dashboard
- save settings flow
- Nginx config generator
- SQLite settings storage
- audit log storage
- production plan document
- Lovable project brief

## What Still Needs To Be Built

- remove hard dependency on `allowed_user_agent`
- stop forcing public aliases from `:80` to `:443`
- fix current `500` path on public alias after guard passes
- move very large playlist rewrite to streaming / line-by-line mode
- isolate panel-domain behaviour from public streaming aliases
- produce final production checklist for broad player compatibility

## Practical Example

- Official main: `cdnvoods.vr766.com`
- Origin XUI: `38.190.176.170`
- Origin port: `80`
- Current operating target: DNS only / gray-cloud, without hiding the VPS IP

## Operating Rules For Lovable

- Treat `proxy-mago-base/` as the active source of truth.
- Treat the old root-level files as legacy/reference material unless explicitly asked to migrate them.
- Treat `_isolated_2026-07-31/` as legacy/reference only.
- Keep changes small and incremental.
- Prefer clarity and reliability over clever abstractions.
- Preserve low memory usage.
- Avoid framework migration unless the user explicitly asks for it.
- Never document steps as if they belong to "your local machine" in the abstract.
  If instructions are operational, tie them explicitly to this VPS and its paths.

## Suggested Next Tasks

1. remove UA whitelist as a production requirement
2. stop redirecting public aliases to HTTPS
3. fix public `500` path and validate `get.php`, `player_api.php`, `xmltv.php`
4. implement streaming rewrite for giant playlists
5. validate production checklist against real users and real aliases

## Sync Workflow

Edit in one place, then propagate:

- VPS to GitHub: commit on `/opt/proxy-mago` and push `main`
- GitHub to VPS: `git pull` on `/opt/proxy-mago`

This repository should stay easy to inspect for an AI assistant or a human maintainer.
