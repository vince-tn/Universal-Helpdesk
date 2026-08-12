# Chikiting System

An internal helpdesk where the intake form is one box: *describe your problem*.
Everything after that is automatic — the request is classified, tagged, routed to
the owning team, and answered in a chat thread on its own ticket.

```bash
docker compose up -d --build
```

That's the whole setup. Nothing is installed on your machine — no PHP, no
Composer, no MySQL, no mail account, no model. Then open
**<http://localhost:8081>** and log in as
`admin@universalhelpdesk.local` / `ChangeMe123!`.

**→ [SETUP.md](SETUP.md) is the real documentation.** How it fits together, how
the assistant decides what to say, how registration works, how to deploy it.

---

## What's in the box

| | |
|---|---|
| **One-box intake** | An employee types a paragraph. No dropdowns to guess at, no form to fill in wrong |
| **Automatic tagging** | Team, category, priority, turnaround and due date are set at intake. No triage step |
| **A ticket that talks back** | Each ticket is a chat thread. The assistant answers the requester, and can rewrite the description or mark it resolved when the conversation settles it |
| **Advice only when it's earned** | Steps are presented as steps only when the model is confident *and* the request is self-serviceable. Everything else is filed with an honest "routed to X" |
| **Always labelled** | Every machine-written message carries an `AI` badge. Nothing pretends to be a person |
| **Verified registration** | Work-email domain allowlist plus a one-time code to the inbox. No employee list, no HRIS |
| **Runs on your own hardware** | Ollama is local and stays local — no API key, no per-request cost, no ticket text leaving the building. Add a Gemini key and the big requests escalate to the cloud model as well |

## The stack

```
Browser ──► app          CodeIgniter 4, PHP 8.3    :8081
              │
              ├──POST──► n8n                       :5678   classify & draft
              │            ├──► Ollama             :11434  local model
              │            └──► Gemini                     cloud model, optional
              │
              ├────────► MySQL                     :3307   the only store
              └────────► Mailpit                   :8025   every email, captured
```

n8n only classifies and drafts — it never writes to the database. The app posts
a request, n8n answers with structured fields, the app decides what to store.
One writer, one source of truth, every column in a migration you own.

## Commands

Build and start everything, then tail the app log:

```bash
docker compose up -d --build
```

Check it's all actually wired up — this names the fix on any failure:

```bash
docker compose exec app php spark helpdesk:doctor
```

Stop, keeping your data:

```bash
docker compose stop
```

Delete the volumes and rebuild from scratch — **this wipes the tickets**:

```bash
docker compose down -v && docker compose up -d --build
```

Same stack, hardened, for the office machine:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

## Layout

```
app/
  Commands/          spark helpdesk:doctor, helpdesk:import-sqlite
  Config/            Access.php (who may register), N8n.php, Email.php
  Controllers/       TicketController, AuthController, AdminUserController
  Database/          migrations — the schema lives here and nowhere else
  Libraries/         TicketAssistant (what the AI may say and do), EmailVerifier
  Models/            TicketModel, N8nService, UserModel, ...
  Views/             layout, tickets, auth, admin
docker/              the app image and its startup
n8n/provision/       workflows + credentials, seeded automatically at boot
public/css/style.css the whole design system, one file
```

Built on [CodeIgniter 4](https://codeigniter.com). MIT licensed — see
[LICENSE](LICENSE).
