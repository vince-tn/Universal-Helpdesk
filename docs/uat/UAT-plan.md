# Service Desk UAT Plan

## Purpose

Confirm the service desk is ready for real staff before go-live. The operations head asked for three things before any department goes live:

- 10 to 20 sample tickets routed correctly for each department.
- 20 AI replies reviewed for each department.
- UAT signed off, and the go-live checklist complete for each department.

## Scope

In scope:

- Sign-up with an email code, login, MFA with recovery codes, Microsoft SSO.
- New tickets through the service catalogue, dynamic fields, impact questions and priority, attachments.
- AI classification, re-routing to the right team, AI reply quality, confidential HR tickets on the local model only.
- Staff work: internal notes, canned replies, assignment, SLA due dates and pause, resolve rules, reopen by reply.
- Two-step approvals and rejection.
- Every email notification, auto close and the CSAT survey.
- Queues, filters, reports and export permissions.
- Room booking: clash check, approval, recurring bookings, check-in and auto-release.
- Email-to-ticket, the knowledge review queue, the SLA sign-off page.
- Backups and restore, the audit log.

Out of scope:

- Load testing beyond normal office volume.
- Changes to the AI models or n8n workflows during UAT. Any change restarts the AI test for the affected departments.
- Departments not in the pilot or the first roll-out wave.

## Roles

| Role | Who | Responsibility |
| --- | --- | --- |
| UAT lead | Service desk owner | Runs the plan, tracks defects, calls go or no-go |
| Operations head | Sponsor | Final go or no-go sign-off |
| Department head | One per department | Agrees roles and handles, signs the department checklist |
| Supervisor and Team Leader | Per department | Run staff test cases, approve test approvals |
| Agents (at least two) | Per department | Work test tickets end to end |
| Requesters | Staff from other teams and AU client contacts | Raise test tickets, reply, rate CSAT |
| Reviewer | Department head or delegate | Reviews 20 AI replies for the department |
| Technical support | IT / system admin | Environment, backups, restore test, defect fixes |

## Test environment

- Staging copy of the service desk with production settings, seeded departments, roles, services and knowledge.
- Real mailbox for notification tests (Mailpit on staging is fine for content, one live mailbox for end to end).
- n8n with OpenRouter first and the local Ollama model as fallback, confidential teams set to local only.
- Test accounts for each role in each pilot department.

## Entry criteria

- Build deployed to staging, `php spark helpdesk:doctor` shows no FAIL lines.
- Departments, roles, handles, services, SLA targets and at least 5 knowledge articles per pilot department loaded.
- Test accounts created and shared with testers.
- UAT test cases (UAT-test-cases.md) handed out with owners.
- AI evaluation run once: `php spark helpdesk:ai-eval --chat` with the report saved.

## Exit criteria

- Every test case in UAT-test-cases.md run and marked Pass, or Fail with an accepted workaround.
- No open Severity 1 or Severity 2 defects.
- AI evaluation for each go-live department: at least 18 of 20 sample tickets routed correctly, and all re-route cases reviewed.
- 20 AI replies reviewed per department by the reviewer, with no reply that promises an approval, an amount or a date.
- Confidential HR cases answered by the local model only (ai_source is Local in the report).
- Go-live checklist (go-live-checklist.md) complete and signed for each department going live.
- Backup and restore test passed.

## Defect severity

| Severity | Meaning | Rule |
| --- | --- | --- |
| 1 | Data leak, confidential ticket visible to the wrong person, system down | Blocks go-live |
| 2 | Core flow broken (cannot raise, assign, resolve, approve, or emails not sent) | Blocks go-live |
| 3 | Works with a workaround | Fix before roll-out wave 2 |
| 4 | Cosmetic or wording | Fix when convenient |

## Schedule

| Date | Milestone |
| --- | --- |
| 1 Dec 2026 | Staging ready, entry criteria checked |
| 2 to 11 Dec 2026 | UAT round 1, all test cases, AI evaluation for every department |
| 14 to 18 Dec 2026 | Defect fixes, AI prompt and knowledge tuning |
| 5 to 13 Jan 2027 | UAT round 2, retest of fixes, AI evaluation rerun for IT and HR |
| 15 Jan 2027 | UAT complete, go or no-go for the IT and HR pilot |
| 18 Jan 2027 | Pilot live for IT and HR |
| 18 Jan to 3 Feb 2027 | Pilot support, daily check of routing and AI replies |
| 5 Feb 2027 | Roll out Web, Marketing, TQA and Operations |

Systems & Data, Reports/WFM and Compliance follow once their checklists are signed.

## AI evaluation

Run in the app container:

```
docker compose exec app php spark helpdesk:ai-eval --chat
```

One department only, 10 cases:

```
docker compose exec app php spark helpdesk:ai-eval --department=HR --limit=10 --chat
```

The report goes to `writable/reports/ai-eval-YYYYmmdd-HHMM.csv` and `.md`. Attach the `.md` to the go or no-go pack. The reviewer reads the reply column in the CSV for 20 cases in their department and marks each one acceptable or not.

## Sign-off

| Role | Name | Decision | Date | Signature |
| --- | --- | --- | --- | --- |
| UAT lead | | | | |
| IT department head | | | | |
| HR department head | | | | |
| Operations head | | | | |
