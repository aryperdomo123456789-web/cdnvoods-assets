# Lovable Project Brief - Proxy Mago

## Context

This repository is the working source for a lightweight anti-leak proxy panel for a single XUI origin server.

The goal is to keep the stack small, cheap to run, and easy to maintain on Ubuntu 22.04.

## Current State

Date: 2026-07-23

Deployed environment:

- VPS: `45.140.192.237`
- OS: Ubuntu 22.04
- Web stack installed: `nginx`, `php-fpm`, `php-sqlite3`, `sqlite3`
- PHP runtime available on the VPS: `PHP 8.1`
- Active project path on VPS: `/opt/proxy-mago/proxy-mago-base`
- Git repository initialized on the VPS and connected to GitHub
- Default branch: `main`

## Project Goal

Protect a single streaming origin from direct source leakage by placing a lightweight reverse proxy in front of it and managing the origin through a simple admin panel.

## Important Decisions

- Do not use Laravel.
- Use plain PHP.
- Use SQLite instead of MySQL.
- Use Nginx as the reverse proxy.
- Keep the admin panel minimal and focused.
- Avoid unnecessary services, queues, or background workers.

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

## What Still Needs To Be Built

- actual token/signature validation for playback requests
- origin proxy routing rules for the stream paths
- URL rewrite layer for playlists and stream endpoints
- better validation for origin host and domain mapping
- optional per-IP limit and abuse tracking
- safer export/install workflow for the Nginx config

## Operating Rules For Lovable

- Treat `proxy-mago-base/` as the active source of truth.
- Treat the old root-level files as legacy/reference material unless explicitly asked to migrate them.
- Keep changes small and incremental.
- Prefer clarity and reliability over clever abstractions.
- Preserve low memory usage.
- Avoid framework migration unless the user explicitly asks for it.

## Suggested Next Tasks

1. cleanly separate legacy files from the active panel
2. add the proxy routing layer for one origin
3. add tokenized playback protection
4. add a one-click Nginx export/apply flow
5. add simple health checks and logs in the panel

## Sync Workflow

Edit in one place, then propagate:

- VPS to GitHub: commit on `/opt/proxy-mago` and push `main`
- GitHub to VPS: `git pull` on `/opt/proxy-mago`

This repository should stay easy to inspect for an AI assistant or a human maintainer.

