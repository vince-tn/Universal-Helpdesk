# Contributing

Thanks for helping with the Chikiting System.

## Getting it running

Docker is the only prerequisite.

```bash
cp .env.example .env
docker compose up -d --build
```

Then open <http://localhost:8081> and log in as `admin@universalhelpdesk.local`
with `ChangeMe123!`. Emails, including signup codes, land in Mailpit at
<http://localhost:8025> and never leave your machine.

Check everything is wired up — this names the fix on any failure:

```bash
docker compose exec app php spark helpdesk:doctor
```

[SETUP.md](SETUP.md) is the real documentation: how the assistant decides what
to say, how registration works, and how it is deployed.

## The branch flow

```
your branch ──PR──▶ staging ──merge──▶ main
                      │                  │
                 staging copy       production
                 updates in ~1 min  updates in ~1 min
```

Branch off `staging`, open a pull request into `staging`. The tests run on the
pull request.

**Merging is deploying.** When a merge into `staging` or `main` passes the
tests, the box pulls it and runs the migrations within about a minute. There is
no separate release step. Nobody has to merge your pull request in a hurry.

## What CI checks

- every file under `app/` and `tests/` parses (`php -l`)
- the unit tests in `tests/unit`

Run them yourself first:

```bash
docker compose exec app vendor/bin/phpunit --no-coverage tests/unit
```

## House rules

**Add a migration, never edit one that has already run.** The schema lives in
`app/Database/Migrations/` and nowhere else. The box applies migrations
automatically on deploy and does not roll them back.

**Edit n8n workflows in `n8n/provision/workflows/`, not in the n8n editor.**
Anything you change in the editor is replaced the next time the workflows are
re-imported. To try your change locally:

```bash
HELPDESK_FORCE_IMPORT=true docker compose up -d --force-recreate n8n
```

**Never edit code on the office box.** The updater refuses to deploy over
hand-edited files and says so in its log, which quietly stops every later
release until someone notices.

**Keep secrets out of commits.** `.env` is ignored on purpose. Nothing with a
real password, API key or customer address belongs in the repository.

**Match the style of the file you are in.** This project has a consistent voice
in its code and its user-facing copy; follow the surrounding code rather than
introducing a new convention.

## Pull requests

Say what changed and why, and how you checked it. If it touches the assistant's
behaviour — what it is allowed to say, when it gives advice, what it may change
on a ticket — say which of the checks in
[SETUP.md](SETUP.md) you tested against. That part is deliberately conservative
and reviewed closely.
