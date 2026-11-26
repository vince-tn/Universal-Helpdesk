# Service desk data privacy note

**DRAFT for review. This is not legal advice.** It describes what the service desk
software does today, as read from the code, so the Data Protection Officer and the
ISO 27001 reviewer can check it against the Data Privacy Act of 2012 (Republic Act
10173), its implementing rules, and NPC circulars. Items marked "to confirm" need an
owner decision or a check on the live server.

## 1. What the service desk stores

All of this sits in one MariaDB database and one uploads folder on the server.

| Data | What it holds | Personal data in it |
| --- | --- | --- |
| Tickets | title, request text, department, role, category, status, priority, due date, resolution note, routing reason, screenshot notes written by AI | requester name and email, anything the person typed |
| Messages | replies from requesters, staff and the AI assistant, internal notes | names, free text |
| Attachments | screenshots and files added to tickets, stored in `writable/uploads/tickets` | whatever is on screen: names, IDs, payslips, chats |
| Accounts | name, email, company, role, department, password hash, two-step sign-in secret (encrypted) and recovery codes (hashed), Microsoft sign-in link, expiry date, email preference | yes |
| Sign-up and reset codes | email, hashed code, expiry, attempt count, IP address | email, IP address |
| Notifications and mail queue | in-app notices and queued emails with ticket excerpts | names, emails, ticket text |
| Ticket activity | who changed status, priority, assignee, team | names |
| Audit log | admin actions, exports, sign-in security events, with user, time and IP | names, IP addresses |
| Application logs | errors and mail events in `writable/logs` | some lines include email addresses |

Sensitive personal information under the Act can appear in tickets for HR Payroll
(pay, deductions, government contributions), Employee Relations (grievances,
conduct) and Compliance Audit (privacy incidents). Section 4 covers how these are
kept on the server.

## 2. Where it is hosted

- **Server:** one office server running the app, database, n8n and the local AI
  model (Ollama) in Docker containers.
- **Network:** staff reach it through Tailscale private addresses.
- **To confirm:** the repository also ships two ways to publish the app to the
  internet: a Cloudflare Tunnel (`docker-compose.tunnel.yml`, described in
  `deploy/README.md`) and a Tailscale Funnel setting
  (`deploy/tailscale/funnel.json` has `AllowFunnel` on). If either is switched on
  in production, the login page is reachable from the public internet, not only
  over Tailscale. The reviewer should check which compose files the live server
  uses.
- **Backups:** `deploy/backup.sh` runs nightly at 02:30 Manila time. It saves a
  database dump and the uploads folder to `~/chikiting-backups/<date>` and keeps
  14 days.
- **Gap:** the backups sit on the same disk as the live data. A disk failure, theft
  or ransomware event loses both. An encrypted off-site copy is needed.

## 3. What may leave the server

Only the AI features send ticket content off the server, and only for departments
where cloud AI is allowed (section 4). Outgoing email (SMTP), Microsoft sign-in and
email intake (Microsoft Graph) also talk to outside services, but they are not
covered by this AI note.

**Ticket classification and replies.** The app sends the ticket to n8n on the same
server. For a cloud-allowed ticket, n8n may pass it to OpenRouter, which forwards it
to an AI provider. What is sent:

- requester name and email, department, role, the request text, and screenshot
  notes
- for replies: the conversation history (latest messages), the sender name and role
- up to three similar resolved tickets as examples (request text and resolution).
  Confidential tickets are never picked as examples.
- department guide excerpts and guardrail text

**Screenshots.** For a cloud-allowed ticket, up to 3 images (8 MB in total) go
straight from the app to OpenRouter to be described in text. The description is
saved on the ticket so the images are not sent again.

**Provider rules.**

- Requests the app sends itself (screenshot reading and other direct OpenRouter
  calls) include `data_collection: deny`, so OpenRouter refuses providers that keep
  or train on the data for that request.
- Free models (names ending `:free`) are removed from the screenshot model list in
  code, so they never read screenshots.
- The team routing step inside the n8n `submit-ticket` workflow also sends
  `data_collection: deny`.
- The main classify and reply steps in n8n use the n8n OpenRouter chat node, which
  does not send per-request provider rules. These calls rely on the OpenRouter
  account privacy setting and on any OpenRouter preset named in `OPENROUTER_MODEL`.
  **To confirm:** the account setting that blocks providers which train on or keep
  prompts is switched on.

**Switches that stop cloud use entirely:** `AI_ESCALATION=off`, an empty
`OPENROUTER_API_KEY`, or `AI_CLOUD_PROVIDER=none`. `AI_IMAGES=off` stops only the
screenshot reading.

## 4. What never leaves the server

- **Confidential tickets.** HR Payroll, HR Employee Relations and Compliance Audit
  are marked confidential. With `CONFIDENTIAL_LOCAL_ONLY` on (the default), these
  tickets are classified and answered by the local model only, and their
  screenshots are never sent out.
- **Per-department switch.** Each department has a "cloud AI" setting. When it is
  off, that department's tickets stay on the local model.
- **Knowledge search.** Guide embeddings are made by the local model.
- **Known limit:** a ticket's confidential flag is set from the department and role
  the requester picked. If the AI later reroutes a ticket into a confidential team,
  the flag is updated after the first classification, which may already have used
  the cloud model. The example tickets in section 3 are also not filtered by the
  department cloud switch.

## 5. Access control

- **Roles:** requester, agent, team leader, supervisor, super admin. Requesters see
  only their own tickets. Staff see their department's tickets. Team leaders and
  supervisors also see the departments they lead.
- **Confidential circle:** a confidential ticket is visible only to its requester,
  the team leader and supervisor of that department, the assignee, staff in the
  matching sub-team role, and super admins.
- **Two-step sign-in:** required for super admins, supervisors and team leaders
  (Microsoft sign-in counts). Secrets are encrypted at rest.
- **Passwords:** at least 12 characters, not the email, not a common password.
  Admin-set passwords must be changed at first sign-in. Failed logins lock for 15
  minutes. Self-service reset uses an emailed code that expires in 15 minutes.
- **Account expiry:** temporary accounts can have an end date; they cannot sign in
  or reset a password after it.
- **Audit log:** records admin changes, password and two-step events, and exports,
  with user, time and IP.
- **Exports:** only super admins and supervisors can export ticket or report data.
  Each export is written to the audit log with the row count and filters.

## 6. Retention

The software does not delete closed tickets, messages, attachments or audit rows
today. Password reset codes are pruned after a day and sign-up codes after 7 days. Backups are kept 14 days.

A retention period must be agreed. As a starting point for the owner to confirm:

- closed tickets and messages: **2 years** after closing
- attachments: **1 year** after the ticket closes
- audit log: to be set by the ISO 27001 owner

Once agreed, a scheduled clean-up job needs to be built.

## 7. Data subject requests

Requests (access, correction, deletion, objection) go to the Data Protection
Officer. IT carries them out on the server.

- **Access or copy:** a super admin can filter the ticket list by requester and
  export it. The export holds ticket summaries, not message text or attachments, so
  a full copy needs a database query and the files from the uploads folder.
- **Correction:** a super admin edits the account. Ticket text is corrected by
  adding a note.
- **Deletion:** deleting an account with ticket history disables it instead, to
  keep the audit trail. Removing that person's ticket content needs a manual
  database job.
- **To confirm:** who signs off each request, and the response time.

There is no built-in tool for a full personal data export or erasure yet.

## 8. Open items for the reviewer

1. Confirm whether the Cloudflare Tunnel or Tailscale Funnel is active in production.
2. Set up an encrypted off-site backup copy and test a restore.
3. Confirm the OpenRouter account privacy setting and the preset used by n8n.
4. Agree retention periods and build the clean-up job.
5. Decide how to close the reroute gap in section 4 (for example, classify on the
   local model first when the ticket could be confidential).
6. Decide whether example tickets should follow the department cloud switch.
7. Add a privacy notice on the sign-up and new ticket pages telling users that
   non-confidential ticket text may be processed by an outside AI provider.
8. Check the data processing terms of OpenRouter and the providers it routes to,
   including where they process data (cross-border transfer).
9. Set log rotation for `writable/logs`, which holds email addresses.
10. Build a personal data export and erasure tool, or write the manual runbook.
