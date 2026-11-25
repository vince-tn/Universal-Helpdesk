# Email to ticket

Office 365 has basic-auth IMAP turned off, so the help desk reads the intake mailboxes through Microsoft Graph with an app-only token.

## What it does

`php spark tickets:mail-intake` runs on its own or as part of `tickets:housekeeping` (only when all four settings below are set). For each mailbox in `MAIL_INTAKE` it reads up to 25 unread messages from the inbox, oldest first.

- Subject has `[#123]`, ticket 123 exists, and the sender is its requester or staff who can see it: the mail becomes a reply on that ticket. Quoted history below the reply is cut, HTML is sanitised, the text is capped at 5000 characters. A requester reply reopens a resolved or closed ticket and moves "Awaiting Requester" back to "In Progress", same as the web form.
- Anything else: a new ticket from the sender, routed to the mailbox's department and role. Only senders whose domain is in `ALLOWED_EMAIL_DOMAINS`, or whose address is in `ALLOWED_EMAILS`, get a ticket. Others are skipped and logged. An empty allow list means no new tickets from email.
- Skipped without a ticket: no-reply and mailer-daemon senders, messages with an `Auto-Submitted` header other than `no` (out of office, bounces), mail sent from the intake mailboxes or `MAIL_FROM`, and disabled accounts.
- Every message is marked read and its Graph id is stored in `mail_intake_log`, so nothing is handled twice. The `outcome` column says what happened (`created`, `replied`, `skipped`, `error`) and `detail` says why.

## Settings

```
GRAPH_TENANT_ID=<directory (tenant) id>
GRAPH_CLIENT_ID=<application (client) id>
GRAPH_CLIENT_SECRET=<client secret value>
MAIL_INTAKE=itsupport@kinectinc.com=IT/Helpdesk,hr@kinectinc.com=HR
```

`MAIL_INTAKE` is a comma list of `mailbox=Department` or `mailbox=Department/Role`. The department must match a department name in the app exactly. Names with a slash, like `Reports/WFM`, work.

Restart the app container after changing them: `docker compose up -d app`.

## Entra setup

1. Entra admin center, App registrations, New registration. Name it "Help desk mail intake". Single tenant. No redirect URI.
2. API permissions, Add a permission, Microsoft Graph, Application permissions, `Mail.ReadWrite`. Then "Grant admin consent". ReadWrite is needed to mark messages read. Do not add `Mail.Send`; outgoing mail still goes through SMTP.
3. Certificates and secrets, New client secret. Copy the value into `GRAPH_CLIENT_SECRET`. Note the expiry date and put a reminder in the calendar; intake stops when it expires.
4. Copy the Application (client) ID and Directory (tenant) ID from the Overview page.

## Limit the app to the intake mailboxes

Without this step an application `Mail.ReadWrite` grant can read every mailbox in the tenant. Scope it with an Application Access Policy in Exchange Online PowerShell:

```powershell
Connect-ExchangeOnline

New-DistributionGroup -Name "Help desk intake mailboxes" -Alias helpdesk-intake -Type Security `
  -Members itsupport@kinectinc.com, hr@kinectinc.com

New-ApplicationAccessPolicy -AppId <client id> -PolicyScopeGroupId helpdesk-intake@kinectinc.com `
  -AccessRight RestrictAccess -Description "Help desk mail intake"

Test-ApplicationAccessPolicy -Identity itsupport@kinectinc.com -AppId <client id>
Test-ApplicationAccessPolicy -Identity someone.else@kinectinc.com -AppId <client id>
```

The first test should say `Granted`, the second `Denied`. Policy changes can take up to an hour to apply. When a new intake mailbox is added to `MAIL_INTAKE`, add it to the group too.

Microsoft now also offers RBAC for Applications in Exchange Online as the successor to Application Access Policies. Either works; use one, not both.

Each company with its own tenant needs its own app registration and its own settings.

## Check it

```
docker compose exec app php spark tickets:mail-intake --verbose
```

Send a test mail from an allowed address to an intake mailbox, run the command, and check the new ticket. Then reply to the confirmation mail and run it again: the reply should land on the same ticket.

Errors from Graph (wrong secret, missing consent, mailbox outside the policy) go to the app log in `writable/logs`.
