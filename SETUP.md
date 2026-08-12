# Chikiting System — setup

One command. Nothing is installed on your machine — no PHP, no Composer, no
MySQL, no mail account, no model.

```bash
docker compose up -d --build
```

First build takes a few minutes (it installs PHP dependencies and pulls a
language model). After that, startup is seconds.

| URL | What |
|---|---|
| <http://localhost:8081> | **The app.** Log in, raise tickets, talk to the assistant |
| <http://localhost:8025> | **Mailpit.** Every email the app sends — this is where signup codes land locally |
| <http://localhost:5678> | n8n. Only if you want to edit the AI workflows |
| <http://localhost:8090> | NocoDB. Optional spreadsheet view of the same database |

> The app is on **8081**, not 8080, because the separate `wp-lab` project holds
> 8080. Change the published port in `docker-compose.yml` if that frees up.

Log in immediately with any of these:

```
admin@universalhelpdesk.local      / ChangeMe123!   superadmin
agent@universalhelpdesk.local      / ChangeMe123!   agent, IT department
requester@universalhelpdesk.local  / ChangeMe123!   requester
```

Then check everything is actually wired up:

```bash
docker compose exec app php spark helpdesk:doctor
```

It checks the database, n8n, both webhooks, the model, mail, the signup
allowlist and ticket routing — and names the fix on any line that fails.

---

## What this thing is

An employee describes a problem in their own words. That is the entire intake
form. Everything after it is automatic:

```
  ┌─ the requester types one paragraph ────────────────────────────┐
  │                                                                 │
  ▼                                                                 │
app (:8081) ──POST──► n8n (:5678) ──► Ollama, local     ┐           │
     │                    │      └──► Gemini, cloud     ┘ classify  │
     │  structured JSON ◄─┘                                         │
     ▼                                                              │
  MySQL          the ticket, tagged: team, category, priority,      │
     │           due date, turnaround                               │
     ▼                                                              │
  ticket page ── the assistant's first message, in the thread ──────┘
                 then a back-and-forth: they reply, it answers
```

n8n **only classifies and drafts**. It never writes to the database. The app
posts a request, n8n answers with structured fields, the app decides what to
store. One writer, one source of truth, every column in a migration you own.

**n8n has no page of its own to visit.** Its webhooks are POST-only API
endpoints; opening one in a browser correctly 404s. The UI is the app.

Inside the containers everything talks by service name — `http://n8n:5678`,
`http://ollama:11434`, `db:3306`, `mailpit:1025`. Only your browser uses
`localhost`, through the published ports.

---

## The ticket is a conversation

A ticket opens as a chat thread, like a Jira issue. Three things happen the
moment one is submitted:

**1. It gets tagged, automatically.** The classifier sets the responsible team,
category, priority, turnaround and due date. That is what puts it in the right
queue for whoever handles it — no triage step, no manual routing. The ticket
page shows all of it in one strip, marked `AI`, so an agent can see at a glance
what was decided for them and override any of it.

**2. The assistant posts the first message.** Always labelled `AI`, never
disguised as a person.

**3. The requester can reply, and it answers.** Only the requester — when an
agent posts, the assistant stays out of the thread. Staff have the ticket in
front of them and do not need a third voice in the conversation.

### When it offers advice, and when it doesn't

This is the part worth understanding, because it is the difference between a
useful assistant and one everybody learns to scroll past.

There are exactly **two doors**, and nothing else opens one:

**Door one — it's trivial.** The model says a normal employee could fix this
themselves in a few obvious steps that can't make anything worse, *and* says it
is `high` confidence. It's asked for `low`/`medium`/`high` on every call and told
to pick the lower one when torn.

**Door two — we've answered it before.** Every new request is matched against
tickets this helpdesk has already resolved (MySQL full-text over what people
actually wrote). Up to three of the closest are handed to the model along with
**how each one was resolved** — the agent's own answer. If one is genuinely the
same problem, the model names it, and the assistant repeats *that recorded fix,
quoted verbatim*. It is not paraphrased: a small model rewriting a person's
answer can only lose a step or invent one.

That second door is the important one. It means advice on something the model
couldn't otherwise be sure about is always a person's answer being replayed,
never the model reasoning from scratch.

Anything else is posted as **Logged & routed** — what was understood, which team
owns it, and no instructions at all. A request the classifier itself flagged for
review never gets advice, whatever else it claimed.

Guards on door two, because a wrong match is worse than no match:

* the model is told explicitly that a different problem with similar words is
  not a match, and neither is the same symptom on a different system
* the case number it returns is checked against the cases actually sent — a
  model that says "case 1" when none were supplied is hallucinating a memory,
  and that is precisely what this mechanism exists to prevent
* the index only ever offers **Resolved** or **Closed** tickets
* a prior ticket with no recorded outcome is skipped entirely

> **This feature is only as good as your resolution notes.** The lookup prefers
> the assistant's resolution note, and otherwise falls back to the longest staff
> message over 25 characters — a rough proxy for "the one that explained
> something". Threads that end in "ok" or "thanks" teach it nothing. If agents
> write a sentence about what actually fixed a ticket, the next person with that
> problem gets answered in seconds.

The point is that being unhelpful is recoverable — the ticket is routed to a
person either way — while being confidently wrong sends somebody off to
reinstall something for an hour. The badge on each message says which one you
are looking at:

| Badge | Means |
|---|---|
| **Suggested fix** | Steps to try. You can ignore them; the ticket stays open |
| **Logged & routed** | Filed and tagged. Nothing expected from you |
| **Needs a person** | The assistant has stood down — the named team owns the next step |

The gate is applied twice, on purpose: once in the n8n workflow
(`Normalize & Enrich`) and again in PHP before the message is stored
(`TicketAssistant::kindFor()`). A model that has been talked into anything still
cannot get past the second one.

### What it may change on a ticket

Nothing, unless the conversation settles it. It can rewrite the description,
write a resolution note, and mark a ticket **Resolved** — and only when:

* the person who raised the ticket sent the message (never an agent, never a
  bystander)
* they actually said it was sorted
* the ticket isn't already Resolved or Closed

`Closed` is never on offer, and it can never reopen anything. Those are a
person's decisions. Both `ai_updated_at` and `ai_resolution_note` are stamped so
an audit can always tell an assistant's edit from an agent's.

If n8n is unreachable, the message is still saved and the assistant says so
plainly rather than pretending to have read it.

---

### Screenshots and formatting

The description box on **New Ticket** is a rich composer, not a plain textarea.
Paste a screenshot straight in, drag one on, or use the Image button. Bold,
lists and links work too. Requests are often "it looks like this" plus two
lines, and flattening that to text loses the only part that was any use.

Every ticket stores both forms:

| Column | What | Who reads it |
|---|---|---|
| `request_body` | Plain text, with `[image]` where a screenshot was | The classifier, the full-text index, exports |
| `request_html` | Sanitised markup including the images | People, on the ticket page |

The plain version is derived *from the sanitised HTML*, so the two can never
disagree, and the classifier is never handed a request full of tags to spend its
attention on.

**On the security of that**, since it means storing markup one person wrote and
showing it to another:

* [app/Libraries/Html.php](app/Libraries/Html.php) is a strict **allowlist** —
  an unlisted tag is unwrapped, an unlisted attribute is dropped. There is no
  blocklist to get behind, and every `on*` handler is gone by construction
* images may **only** point at this app's own upload route. That one rule
  removes `data:` payloads, `javascript:` sources and remote tracking pixels
  together — a screenshot can only ever be something our own endpoint returned
* uploads are validated on their actual decoded image header, not the
  extension, so a PHP file named `.png` is rejected
* they're stored under `writable/`, **not** `public/`, and served through a
  controller behind the auth filter. Nothing is reachable by URL without a
  session, and the filenames are random so nothing is guessable
* sanitising happens on save *and* again on render, so a row that arrived some
  other way — an import, a mysql prompt — still can't put script on a screen

JPG, PNG, GIF and WebP, 5MB each. With JavaScript disabled the composer
degrades to an ordinary textarea and the ticket still submits.

## Registration: who gets an account

There is no employee list here and no HRIS integration, deliberately — three
companies plus occasional external staff makes any list wrong within a week.
Two checks stand in for it, and they are different in kind:

**1. Domain allowlist.** The address must end in a domain you recognise.
Instant, and catches typos and outsiders in the same motion. Subdomains count.

**2. A one-time code, emailed.** Proves the person actually reads that mailbox.
This is the check that matters, and it is the one a mailbox-validation API
(Clearout and friends) genuinely cannot do for you — against Microsoft 365 or
any catch-all domain, SMTP verification accepts everything and tells you
nothing.

Neither is worth much alone. Together they amount to *"you have a working
mailbox at one of our companies"*, which is the real membership test.

### Configuring it

```bash
cp .env.example .env
```

```dotenv
ALLOWED_EMAIL_DOMAINS=covau.com.au,company-two.com,company-three.com.au
ALLOWED_EMAILS=the.one.contractor@gmail.com
```

Then `docker compose up -d app`.

`ALLOWED_EMAILS` waives the *domain* check for named individuals — the external
contractor on nobody's payroll. It never waives the code.

**An empty `ALLOWED_EMAIL_DOMAINS` turns the domain check off entirely** and
anyone with any address can register. That's the default so the demo works out
of the box; `docker compose exec app php spark helpdesk:doctor` reports it as a failure every time so it cannot be
forgotten on the way to a deployment.

### Reading the code locally

Signup emails go to **Mailpit**, not to a real inbox. Sign up, then open
<http://localhost:8025> and the code is sitting there. Nothing leaves your
machine, there is no account to create, and a test can never reach a real
person.

If mail is down entirely, the code is written to `docker compose logs app` as a
fallback so a local demo is never blocked.

### The rules around the code

| | |
|---|---|
| Length / lifetime | 6 digits, 15 minutes, single use |
| Storage | Hashed with bcrypt, exactly like a password |
| Wrong guesses | 5, then the code is burned |
| Resend | One per 60s, 5 per address per hour |
| Live codes per address | Exactly one — resending replaces, never widens |

Nothing is written to `users` until the code comes back correct. An abandoned
signup leaves behind a row that expires on its own and is pruned after a week.
The password is hashed at the *first* step, so the plaintext never reaches
storage of any kind — not even a pending row.

### Why not build this in n8n forms

You already moved past it. n8n's form trigger is one-shot: it renders, it
submits, it runs. A code loop needs state across two requests (issue → store →
mail → second screen → compare → handle expiry, wrong codes, resends, rate
limits), and expressing that in n8n means a `Wait` node holding an execution
open per signup, with the pending account living inside an execution's data.
It is possible and it is unpleasant — and every one of those pieces is one class
in an app that already exists.

The app **is** the portal. It has sessions, a users table, migrations, CSRF, a
mailer and a login page. The registration flow is 4 files and a migration
(`Config/Access.php`, `Libraries/EmailVerifier.php`, `Libraries/Mailer.php`,
`Models/EmailVerificationModel.php`, plus two views). Intake never needed
moving — it was never in n8n. n8n classifies; it was never the front door.

---

## Editing things

The project is bind-mounted into the container, so **edits on your machine are
live**. Just refresh.

| Changed | Do |
|---|---|
| PHP / views / CSS | nothing, just refresh |
| `composer.json` | `docker compose restart app` |
| `docker-compose.yml` env | `docker compose up -d app` |
| `docker/app/Dockerfile` | `docker compose up -d --build app` |
| A new migration | `docker compose restart app` (migrations run at startup) |
| An n8n workflow JSON | `HELPDESK_FORCE_IMPORT=true docker compose up -d --force-recreate n8n` |

n8n workflows are imported **only when missing**, so anything you tune in the
n8n editor survives a restart. `HELPDESK_FORCE_IMPORT=true` re-imports from the
repo, discarding editor changes.

> **`--force-recreate` is not optional here.** Provisioning only runs when the
> container starts, and `docker compose up -d n8n` does nothing at all when the
> config hasn't changed — so the second time you set `HELPDESK_FORCE_IMPORT=true`
> it silently no-ops and you spend an hour wondering why your workflow edit
> isn't live. The n8n CLI cannot hot-reload either: it writes to n8n's database
> and prints *"changes will not take effect if n8n is running"*.
>
> To check what's actually live rather than what you think is:
>
> ```bash
> docker compose exec n8n n8n export:workflow --id=HelpdeskSubmit01 --output=/dev/stdout
> ```

```bash
docker compose exec app bash            # a bash prompt inside the app container
docker compose exec app php spark migrate          # php spark migrate
docker compose exec app php spark routes           # php spark routes
docker compose exec db mysql -uhelpdesk -phelpdesk helpdesk               # a mysql prompt on the helpdesk database
docker compose logs -f app             # tail the app
docker compose ps               # what's running
docker compose stop             # stop everything, keep data
docker compose down -v && docker compose up -d --build            # nuke the volumes and rebuild from scratch
```

### Rescuing tickets raised while the AI was down

A ticket submitted while n8n was unreachable, or while the model was still
downloading, is saved with empty fields and flagged for review — never lost.
`request_body` holds exactly what the requester typed, which is what this is
for:

```bash
docker compose exec app php spark helpdesk:reclassify --dry-run
```

Drop `--dry-run` to actually run it. It re-classifies every flagged ticket,
re-derives priority and due date, and posts the real answer into each thread to
replace the "I could not reach the assistant" message. `--id 5` does one ticket;
`--all` re-runs even the ones that classified fine.

A ticket whose classifier still won't answer is left exactly as it was, rather
than being overwritten with something worse.

Any of these also work as plain `docker compose exec app php spark ...` if you
prefer typing it out.

---

## Data model

Everything lives in [app/Database/Migrations/](app/Database/Migrations/), MySQL
in the `db` container.

| Table | Holds |
|---|---|
| `users` | Accounts. Roles: `superadmin`, `agent`, `requester` |
| `tickets` | The request plus every classified field, and `request_body` — the requester's original wording, kept so a bad classification can be redone rather than retyped |
| `ticket_meta` | Priority, due date, assignee. Seeded by the classifier, overridable by an agent |
| `ticket_messages` | The conversation. `kind` and `ai_confidence` record what an assistant message was presented as, and how sure it claimed to be |
| `email_verifications` | Pending signup codes, hashed. Self-expiring |

To change the schema, add a migration — don't edit one that has already run.

### Departments

The classifier routes to granular team names (`Compliance HQ`, `AU Operation
Team`, `Technical - Web`). Agent accounts are scoped to the seven buckets in
`departments()`. `resolve_base_department()` in `app/Common.php` maps one onto
the other, and it is what decides whether an agent can see a ticket at all.

Anything it fails to map is a ticket in a queue nobody is watching. The
`helpdesk:doctor` command reports any it finds, and the fix is one line in that
alias table.

---

## Deploying to the office machine

Same containers, one extra file:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Read the header comment in
[docker-compose.prod.yml](docker-compose.prod.yml) — it explains every
difference and why.

Because the target is a computer in the office rather than a hosting tier,
**nothing has to be taken out to make it fit**. Ollama comes too. The stack runs
exactly as it does on your laptop, and keeps running with no internet at all.

### On the office machine, once

```bash
git clone <this repo> && cd Universal-Helpdesk
cp .env.example .env      # then fill it in — see below
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php spark helpdesk:doctor
```

Docker Desktop (or Docker Engine) is the only prerequisite. Set it to start on
login; everything in the stack is `restart: always`, so a power cut or a
Tuesday reboot brings the helpdesk back without anyone logging in.

### What has to be in `.env` first

The prod overlay **refuses to start** without these. That is deliberate — each
one is dangerous left at its default:

| | |
|---|---|
| `APP_URL` | The address people will type, with a trailing slash — `http://helpdesk.local/`. Every link and the verification email read this |
| `ALLOWED_EMAIL_DOMAINS` | Empty means anyone who can reach the box can register |
| `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` | The defaults are `helpdesk`, and are meant to be |
| `MAIL_HOST`, `MAIL_FROM` | Codes have to reach real inboxes now |

Then change the seeded superadmin password from `ChangeMe123!`.

### What the office sees

Only the app, on port 80. MySQL, n8n, Mailpit and Ollama all drop to loopback —
reachable from the machine itself, from nowhere else. **n8n's editor has no
login in this setup**, so that one is not optional. Reach it from your desk with
a tunnel:

```bash
ssh -L 5678:localhost:5678 you@the-office-machine
```

### The model, in production

Ollama stays the default: no API key, no per-request cost, and no ticket text
leaving the building — which is the real argument for it once this holds actual
staff requests. It wants ~4–6GB of RAM free for `qwen2.5:3b`.

Setting `GEMINI_API_KEY` as well is still worth it. It doesn't replace Ollama;
it means the big, unmatched requests escalate to a model that classifies the
awkward ones noticeably better, and it's free. Leave it empty and the stack is
entirely self-contained.

### The one thing this doesn't do

The app is served by PHP's built-in server (8 workers). That's fine for an
internal tool this size on a trusted office LAN. It is **not** fine facing the
public internet — if you ever port-forward this or point a public domain at it,
put Caddy in front for TLS first.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| `Bind for 0.0.0.0:8081 failed: port is already allocated` | Something else has the port. `docker ps` to find it, or change the published port |
| Links point at the wrong port, or carry `/index.php/` | `app_baseURL` / `app_indexPage` in compose. They must use **underscores** — PHP rewrites dots to underscores importing into `$_ENV`, so `app.baseURL` silently does nothing |
| Every ticket says "flagged for manual review" | n8n unreachable, workflow unpublished, or no model pulled. `docker compose exec app php spark helpdesk:doctor` names which |
| The first ticket takes two minutes | Normal. The local model is loading its weights. Later ones are quick |
| The assistant never replies in the thread | It only answers the requester, and never on a Closed ticket. Check `AI_ASSISTANT` isn't `off` |
| Signup says "we could not send the code" | Mailpit isn't up. `docker compose up -d mailpit`, then `docker compose exec app php spark helpdesk:doctor` |
| Signup code never arrives | Locally it never leaves the machine — read it at <http://localhost:8025> |
| "Use your work email" on a valid address | `ALLOWED_EMAIL_DOMAINS` doesn't include that domain. Add it, then `docker compose up -d app` |
| n8n: `ECONNREFUSED 127.0.0.1:11434` | An Ollama credential using `localhost`. It must be `http://ollama:11434` |
| `This webhook is not registered for GET requests` | You opened a webhook in a browser. It only accepts POST — the UI is the app on 8081 |
| Tickets land in the wrong department | `qwen2.5:3b` is small for a 12-way taxonomy. Set `GEMINI_API_KEY`, or `OLLAMA_MODEL` to something larger |
| Timestamps look hours off | `TZ` and `app_appTimezone` on the app service. Both must be set — one is the container clock, the other is what `date()` formats with |
| "The action you requested is not allowed" on a form | A CSRF token from a page that has been open since before a restart. Reload the page and submit again |
| `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build` fails on `!override` | Compose older than v2.24. Upgrade Docker Compose — that line is what keeps MySQL and n8n off the office network |
| `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build` says a variable is not set | Working as intended. See the table above — the overlay refuses to start with those at their defaults |

## Full reset

`docker compose down -v && docker compose up -d --build` (`docker compose down -v`) deletes the volumes: the database,
the n8n workflows and the pulled model all go, and the next start rebuilds them.
**This deletes your tickets.** There is no separate file to keep any more —
MySQL is the only store.
