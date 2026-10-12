# Putting Chikiting System on the office box

Two copies of the app run on one box: **production** follows `main`, and
**staging** follows `staging`. Both update themselves after every push that
passes the tests. Cloudflare Tunnel puts both online without opening any port
on the office router.

```
push to staging ─▶ GitHub runs the tests ─▶ release/staging moves ─▶ staging copy updates
merge into main ─▶ GitHub runs the tests ─▶ release/main moves    ─▶ production updates
                                                                   (within about a minute)
visitors ─▶ Cloudflare ─▶ tunnel ─▶ the box
```

The box is never contacted by GitHub. It checks `release/<branch>` every minute
and pulls the new commit itself ([deploy.sh](deploy.sh)). If the tests fail,
the release branch doesn't move and the box keeps running the last good
version. This works whether the repo is public or private.

Replace `example.com` below with your domain.

---

## 1. Prepare the box

You need a Linux box (Ubuntu 24.04 or Debian 12) with Docker Engine, Docker
Compose v2.24 or newer, and git. Docker's install guide:
<https://docs.docker.com/engine/install/>

```bash
docker compose version
```

Everything below runs as root (`sudo`). The updater needs Docker, and it
keeps the folders owned by one user.

## 2. Two copies of the app

```bash
sudo mkdir -p /opt/chikiting
sudo git clone -b main    https://github.com/vince-tn/Universal-Helpdesk.git /opt/chikiting/production
sudo git clone -b staging https://github.com/vince-tn/Universal-Helpdesk.git /opt/chikiting/staging
```

**If the repo is private,** give the box a read-only deploy key instead:

```bash
sudo ssh-keygen -t ed25519 -N "" -f /root/.ssh/chikiting_deploy
sudo cat /root/.ssh/chikiting_deploy.pub
```

Paste the public key into GitHub under the repo's **Settings → Deploy keys**,
leaving "Allow write access" off. Then add this to `/root/.ssh/config`:

```
Host github.com
  IdentityFile /root/.ssh/chikiting_deploy
```

Clone with `git@github.com:vince-tn/Universal-Helpdesk.git` instead of the
https address.

## 3. Each copy's settings

Each folder gets its own `.env`. It never goes to GitHub. For passwords, use
long random values, different for each copy:

```bash
openssl rand -hex 24
```

`/opt/chikiting/production/.env`:

```ini
DEPLOY_BRANCH=main
COMPOSE_PROJECT_NAME=chikiting
COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml

APP_URL=https://helpdesk.example.com/
ALLOWED_EMAIL_DOMAINS=yourcompany.com,othercompany.com
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USER='your-relay-login'
MAIL_PASS='your-relay-password'
MAIL_FROM='helpdesk@yourcompany.com'
MYSQL_PASSWORD=<random>
MYSQL_ROOT_PASSWORD=<random>
# GEMINI_API_KEY=   optional
```

`/opt/chikiting/staging/.env`:

```ini
DEPLOY_BRANCH=staging
COMPOSE_PROJECT_NAME=chikiting-staging
COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml:docker-compose.staging.yml

APP_URL=https://helpdesk-staging.example.com/
ALLOWED_EMAIL_DOMAINS=yourcompany.com,othercompany.com
# Staging's mail always goes to its own Mailpit; these two only satisfy the prod file.
MAIL_HOST=mailpit
MAIL_FROM=staging@chikiting.local
MYSQL_PASSWORD=<random, not production's>
MYSQL_ROOT_PASSWORD=<random, not production's>
```

Then lock both files down:

```bash
sudo chmod 600 /opt/chikiting/production/.env /opt/chikiting/staging/.env
```

## 4. First start

```bash
cd /opt/chikiting/production && sudo docker compose up -d --build
cd /opt/chikiting/staging && sudo docker compose up -d --build
```

The first start downloads the AI model (about 2 GB) for each copy, so give it
several minutes. Production answers on port 80 to the office network. Staging
answers only on the box itself (`127.0.0.1:8082`), for the tunnel.

Log in as `admin@universalhelpdesk.local` with `ChangeMe123!`, and **change
that password straight away**, on both copies.

Sample guides and rules for testing on staging (optional):

```bash
cd /opt/chikiting/staging && sudo docker compose exec app php spark db:seed SampleKnowledgeSeeder
```

## 5. Turn on automatic updates

```bash
sudo cp /opt/chikiting/production/deploy/systemd/chikiting-deploy@.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now docker chikiting-deploy@production.timer chikiting-deploy@staging.timer
```

To check it's running and watch what it does:

```bash
systemctl list-timers 'chikiting*'
journalctl -u chikiting-deploy@production -f
```

**No sudo?** If the folder belongs to your own account and that account is in
the `docker` group, your crontab works as well, with no admin password:

```bash
(crontab -l 2>/dev/null; echo '* * * * * /bin/bash /opt/chikiting/production/deploy/deploy.sh >> $HOME/chikiting-deploy.log 2>&1') | crontab -
```

Watch it with `tail -f ~/chikiting-deploy.log`. The log stays quiet when
there's nothing new.

## Backups

Every night at 02:30 the database and the uploaded screenshots are saved to
`~/chikiting-backups/<date>`, and backups older than 14 days are deleted:

```bash
(crontab -l 2>/dev/null; echo '30 2 * * * /bin/bash /opt/chikiting/production/deploy/backup.sh >> $HOME/chikiting-backup.log 2>&1') | crontab -
```

To take one now, run `bash deploy/backup.sh`. These copies sit on the same disk
as the app, so copy them somewhere else now and then.

To restore one (this replaces what's in the database now):

```bash
cd /opt/chikiting/production
gunzip -c ~/chikiting-backups/<date>/database.sql.gz | docker compose exec -T db sh -c 'export MYSQL_PWD="$MYSQL_PASSWORD"; exec mysql -uhelpdesk helpdesk'
tar -xzf ~/chikiting-backups/<date>/uploads.tar.gz -C writable
```

## Sign-up limits

Each address gets at most 5 sign-up codes an hour, and each visitor (by IP
address) at most 30. On a rollout day, when many people sign up from the same
office connection, raise the second limit in `.env`, for example
`SIGNUP_MAX_SENDS_PER_IP_PER_HOUR=300`. The app picks it up straight away.

## 6. Put it online: Cloudflare Tunnel (free)

### No domain yet: a quick tunnel

This needs no domain and no Cloudflare account. Add the tunnel file and the
host setting to the production `.env`:

```ini
COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml:docker-compose.tunnel.yml
PUBLIC_HOST_SUFFIXES=.trycloudflare.com
```

```bash
cd /opt/chikiting/production && sudo docker compose up -d && bash deploy/public-url.sh
```

That prints the public address, something like
`https://calm-river-1234.trycloudflare.com`. Two catches:

- **The address changes** every time the tunnel restarts (a reboot, say). Run
  `bash deploy/public-url.sh` again to get the new one.
- **It's a free testing service** with no uptime guarantee. For a permanent
  address, use a domain and a named tunnel (below), and drop the tunnel file.

### With a domain: a named tunnel

The domain has to be managed in Cloudflare.

1. Go to Cloudflare **Zero Trust → Networks → Tunnels → Create a tunnel →
   Cloudflared**, and name it `chikiting`. Cloudflare moves these menus now
   and then; search for "Tunnels" if this path has changed.
2. Choose the box's system (Debian/Ubuntu) and run the install command it
   shows on the box. That command carries the tunnel's secret token, so don't
   paste it anywhere else.
3. Add two public hostnames:

   | Hostname | Service |
   |---|---|
   | `helpdesk.example.com` | `HTTP` → `localhost:80` |
   | `helpdesk-staging.example.com` | `HTTP` → `localhost:8082` |

   Use `helpdesk-staging`, not `staging.helpdesk`. Cloudflare's free
   certificate only covers one level of subdomain, so a two-level name shows a
   security error.

## 7. Keep staging private: Cloudflare Access (free up to 50 users)

**Zero Trust → Access → Applications → Add an application → Self-hosted**:

- Domain: `helpdesk-staging.example.com`
- Policy: Allow, emails ending in `@yourcompany.com`, or a list of specific
  addresses
- Login method: One-time PIN

Visitors now get a Cloudflare login screen before staging loads.

---

## Day to day

**Making a change:**
1. Push to `staging`. The tests run on GitHub (Actions tab, 1–2 min).
2. The staging copy updates about a minute later. Check it at
   `helpdesk-staging.example.com`.
3. Happy with it? Merge `staging` into `main` on GitHub. Production follows
   the same way.

**Tests failed:** nothing changes on the box. Fix the problem and push again.

**Undoing a release:** revert the commit on GitHub. The box follows the revert
like any other change. Database migrations are not undone automatically.

**Settings (`.env`):** edit the file on the box, then apply it from inside
that folder:

```bash
sudo docker compose up -d
```

**Don't:**
- Don't edit code on the box. The updater refuses to deploy over hand-edited
  files, and says so in its log.
- Don't edit workflows in the box's n8n editor. The next workflow change from
  GitHub replaces them. Change `n8n/provision/workflows/` in the repo instead.

**Opening n8n or staging's mail** from your own computer, over SSH:

```bash
ssh -L 5678:localhost:5678 -L 5679:localhost:5679 -L 8026:localhost:8026 you@the-box
```

Then go to `localhost:5678` (production n8n), `localhost:5679` (staging n8n)
or `localhost:8026` (staging mail).

## Before opening it to everyone

The app is still served by PHP's built-in web server, which is fine on the
office network but not hardened for the open internet. Swap it for a proper
web server, and make the app aware that it sits behind HTTPS (secure cookies,
real visitor addresses), before sharing the public link widely.
