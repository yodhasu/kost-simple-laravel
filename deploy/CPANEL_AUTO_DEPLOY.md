# cPanel Auto Deploy via GitHub Action + Webhook Flag + Cron

This setup is for cheap cPanel hosting without an interactive terminal.

## Flow

1. Push to GitHub `main`.
2. GitHub Action calls `https://YOUR_DOMAIN/deploy-hook.php` with a secret token.
3. `public/deploy-hook.php` validates the token and writes `/home/tbkd2629/kost-app/storage/app/deploy.flag`.
4. cPanel cron runs `deploy/deploy_if_flagged.sh` every minute.
5. The cron worker sees the flag, pulls/clones the repo, refreshes public files, clears/optimizes Laravel cache, then deletes the flag.

## One-time cPanel setup

### 1) Upload/deploy these files once

Make sure these files exist on production after deploy:

- `/home/tbkd2629/public_html/deploy-hook.php`
- `/home/tbkd2629/kost-app/deploy/deploy_if_flagged.sh`
- `/home/tbkd2629/kost-app/deploy/deploy.env`

### 2) Create deploy config

In cPanel File Manager, create:

`/home/tbkd2629/kost-app/deploy/deploy.env`

Use this template:

```bash
APP_PATH=/home/tbkd2629/kost-app
PUBLIC_PATH=/home/tbkd2629/public_html
BRANCH=main
REPO_URL=https://github.com/YOUR_USER/YOUR_REPO.git
RUN_MIGRATIONS=0
PHP_BIN=php
COMPOSER_BIN=composer
GIT_BIN=git
```

For a private repo, use a restricted GitHub token or deploy credential in `REPO_URL`, for example:

```bash
REPO_URL=https://x-access-token:YOUR_GITHUB_TOKEN@github.com/YOUR_USER/YOUR_REPO.git
```

Keep this file private. Do not commit the real `deploy.env`.

### 3) Create webhook token file

Create a long random token locally, then put it in:

`/home/tbkd2629/kost-app/storage/app/deploy-webhook-token`

The file should contain only the token, no quotes.

Example token command locally:

```bash
openssl rand -hex 32
```

### 4) Add GitHub repository secrets

In GitHub repo → Settings → Secrets and variables → Actions → New repository secret:

- `CPANEL_DEPLOY_WEBHOOK_URL`
  - value: `https://YOUR_DOMAIN/deploy-hook.php`
- `CPANEL_DEPLOY_WEBHOOK_TOKEN`
  - value: same token from `deploy-webhook-token`

### 5) Add cPanel cron job

In cPanel → Cron Jobs:

Common Settings:

- Choose **Once Per Minute**.

Command:

```bash
/bin/bash /home/tbkd2629/kost-app/deploy/deploy_if_flagged.sh >> /home/tbkd2629/kost-app/storage/logs/deploy-cron.log 2>&1
```

If `/bin/bash` fails on your host, try:

```bash
bash /home/tbkd2629/kost-app/deploy/deploy_if_flagged.sh >> /home/tbkd2629/kost-app/storage/logs/deploy-cron.log 2>&1
```

## Testing

After setup, trigger GitHub Action manually from the Actions tab or push to `main`.

Check logs in cPanel File Manager:

- `/home/tbkd2629/kost-app/storage/logs/deploy-hook.log`
- `/home/tbkd2629/kost-app/storage/logs/deploy-cron.log`

A successful deploy ends with:

```text
Deploy complete: <commit>
```

## Safety notes

- The web hook only writes a flag; it does not run shell commands directly.
- Cron uses a lock directory to prevent overlapping deploys.
- Migrations are disabled by default (`RUN_MIGRATIONS=0`).
- Only `main` branch hooks are accepted.
