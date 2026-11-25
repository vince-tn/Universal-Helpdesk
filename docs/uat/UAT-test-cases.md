# Service Desk UAT Test Cases

Mark each case Pass or Fail. On Fail, write the defect number in the Pass/Fail column. Use test accounts only. Confidential cases must be run by HR testers.

## 1. Accounts and sign-in

| ID | Test | Steps | Expected result | Pass/Fail | Tester | Date |
| --- | --- | --- | --- | --- | --- | --- |
| UAT-01 | Sign-up with email code | 1. Open the sign-up page. 2. Enter name and a company email. 3. Open the email and enter the code. | Account is created and the user lands on the dashboard. The code email arrives within 2 minutes. | | | |
| UAT-02 | Sign-up blocked for outside email | 1. Try to sign up with a personal email that is not on the allowlist. | Sign-up is refused with a clear message. No code is sent. | | | |
| UAT-03 | Wrong or expired code | 1. Start sign-up. 2. Enter a wrong code. 3. Wait for the code to expire and enter it. 4. Use Resend. | Wrong and expired codes are refused. Resend sends a new code. | | | |
| UAT-04 | Login and logout | 1. Log in with email and password. 2. Log out. 3. Press Back in the browser. | Login works. After logout, pages need login again. | | | |
| UAT-05 | MFA enrolment | 1. Open account settings. 2. Turn on MFA. 3. Scan the QR code with an authenticator app. 4. Enter the 6 digit code. 5. Save the recovery codes. | MFA is on. Recovery codes are shown once. | | | |
| UAT-06 | MFA login | 1. Log out. 2. Log in with password. 3. Enter the authenticator code. 4. Try a wrong code. | Correct code logs in. Wrong code is refused. | | | |
| UAT-07 | MFA recovery code | 1. Log in with password. 2. Choose recovery code. 3. Enter one recovery code. 4. Try the same code again on a new login. | First use works. The same code is refused the second time. | | | |
| UAT-08 | Microsoft SSO | 1. Click Sign in with Microsoft. 2. Sign in with a company Microsoft account. 3. Repeat with an account not in the tenant. | Company account logs in to the matching user. Outside account is refused. | | | |

## 2. Raising tickets

| ID | Test | Steps | Expected result | Pass/Fail | Tester | Date |
| --- | --- | --- | --- | --- | --- | --- |
| UAT-09 | New ticket via catalogue | 1. Click New ticket. 2. Pick a service, for example Certificate of employment. 3. Fill the form and submit. | Ticket is saved with the service, department and role of that service. | | | |
| UAT-10 | Dynamic fields | 1. Pick Equipment purchase. 2. Leave a required field blank and submit. 3. Fill all fields and submit. | Required fields are enforced. Field values show on the ticket page. | | | |
| UAT-11 | Impact questions set priority | 1. Raise a ticket, answer that many people are affected and work is stopped. 2. Raise another, one person, work not stopped. | First ticket gets High or Critical priority, second gets Low or Medium, as per the priority matrix. | | | |
| UAT-12 | Attachments PDF, Word, Excel | 1. Attach a PDF, a .docx and a .xlsx to a new ticket. 2. Open each from the ticket page. 3. Try a .exe file. | The three files upload and download intact. The .exe is refused. | | | |

## 3. AI assistant

| ID | Test | Steps | Expected result | Pass/Fail | Tester | Date |
| --- | --- | --- | --- | --- | --- | --- |
| UAT-13 | AI classification | 1. Raise a clear IT request, for example a broken headset. 2. Wait for the assistant. | Title is specific (3 to 12 words), category and department are filled, an opening reply appears. | | | |
| UAT-14 | AI re-routing | 1. Raise a COE request but pick HR / Recruitment. 2. Wait for the assistant. | Ticket moves to HR / Records. The reply says it was moved and why. The activity log shows the move. | | | |
| UAT-15 | Re-routing keeps a correct pick | 1. Raise a payslip question and pick HR / Payroll. | Ticket stays with HR / Payroll. | | | |
| UAT-16 | AI reply quality | 1. Run `php spark helpdesk:ai-eval --department=<dept> --chat`. 2. Reviewer reads 20 replies in the CSV. | No reply promises an approval, an amount or a date. No placeholder text, no article numbers, no requester voice. Reviewer signs off 20 replies. | | | |
| UAT-17 | AI follow-up chat | 1. On a ticket, reply as the requester with more detail. | Assistant answers as the help desk, uses the new detail, does not repeat the opening. | | | |
| UAT-18 | Assistant offline | 1. Stop n8n on staging. 2. Raise a ticket. | Ticket is saved. Reply says a person will review it. No error page. | | | |

## 4. Working tickets

| ID | Test | Steps | Expected result | Pass/Fail | Tester | Date |
| --- | --- | --- | --- | --- | --- | --- |
| UAT-19 | Internal note hidden | 1. As an agent, add an internal note. 2. Log in as the requester and open the ticket. 3. Check the requester's email. | Requester cannot see the note and gets no email for it. | | | |
| UAT-20 | Canned reply | 1. As an agent, insert a canned reply. 2. Edit it and send. | Text is inserted, editable, and sent to the requester. | | | |
| UAT-21 | Assign | 1. As a Team Leader, assign the ticket to an agent. 2. Check the agent's queue and email. | Ticket shows the assignee, appears in their queue, and they get an email. | | | |
| UAT-22 | SLA due dates | 1. Raise tickets with different priorities. 2. Check first response and resolution due dates. | Due dates match the SLA targets for each priority and count working hours only, if set. | | | |
| UAT-23 | SLA pause | 1. Set a ticket to Waiting on requester. 2. Wait 1 hour. 3. Set it back to In progress. | SLA clock pauses while waiting. Due date moves out by the paused time. | | | |
| UAT-24 | Resolve needs assignee and note | 1. Try to resolve an unassigned ticket. 2. Assign it, try to resolve without a note. 3. Add a note and resolve. | Steps 1 and 2 are refused with a message. Step 3 resolves and emails the requester. | | | |
| UAT-25 | Reopen by reply | 1. Resolve a ticket. 2. As the requester, reply on the ticket or to the email. | Ticket reopens and the assignee is told. | | | |

## 5. Approvals

| ID | Test | Steps | Expected result | Pass/Fail | Tester | Date |
| --- | --- | --- | --- | --- | --- | --- |
| UAT-26 | Two-step approval | 1. Raise an Equipment purchase. 2. Team Leader approves. 3. Supervisor approves. | Ticket waits for each step in order. Each approver gets an email. After step 2 the ticket goes to the team. | | | |
| UAT-27 | Approval rejected | 1. Raise an Equipment purchase. 2. Team Leader rejects with a reason. | Ticket is closed as rejected. Requester gets the reason by email. Supervisor is not asked. | | | |
| UAT-28 | Approver rights | 1. Log in as an agent who is not an approver. 2. Try to approve. | Approve and reject are not available. | | | |

## 6. Confidential HR

| ID | Test | Steps | Expected result | Pass/Fail | Tester | Date |
| --- | --- | --- | --- | --- | --- | --- |
| UAT-29 | Confidential visibility | 1. Raise a Payroll dispute. 2. Log in as an IT agent, an HR Recruitment agent and an HR Payroll agent. 3. Search and open the ticket URL directly. | Only HR Payroll staff and admins can see it. Others get not found, in lists, search, reports and export. | | | |
| UAT-30 | Confidential uses local AI | 1. Raise an Employee Relations concern. 2. Check the ticket AI source. 3. Run `php spark helpdesk:ai-eval --department=HR` and check ai_source for Payroll and Employee Relations rows. | AI source is Local for every confidential case. Nothing goes to OpenRouter. | | | |
| UAT-31 | Moved into a confidential team | 1. Raise a payslip issue under IT / Helpdesk. 2. Let the assistant move it to HR / Payroll. | Ticket becomes confidential after the move and drops out of IT views. | | | |

## 7. Notifications and closure

| ID | Test | Steps | Expected result | Pass/Fail | Tester | Date |
| --- | --- | --- | --- | --- | --- | --- |
| UAT-32 | Ticket received email | 1. Raise a ticket. | Requester gets a receipt with the ticket number and link. | | | |
| UAT-33 | Assigned email | 1. Assign a ticket. | Assignee gets an email with the link. | | | |
| UAT-34 | Staff reply email | 1. Agent replies publicly. | Requester gets the reply text and link. | | | |
| UAT-35 | Requester reply email | 1. Requester replies. | Assignee gets an email. | | | |
| UAT-36 | Approval request and result emails | 1. Run UAT-26 and UAT-27. | Approvers get request emails. Requester gets approved or rejected emails. | | | |
| UAT-37 | Resolved email | 1. Resolve a ticket. | Requester gets the resolution note and how to reopen. | | | |
| UAT-38 | SLA warning email | 1. Let a ticket near its due time. | Assignee and Team Leader get a warning before the breach. | | | |
| UAT-39 | Mention email | 1. Mention a staff member in an internal note. | Mentioned person gets an email. Requester does not. | | | |
| UAT-40 | Auto close | 1. Resolve a ticket. 2. Run `php spark tickets:housekeeping` after the auto close period. | Ticket moves to Closed. Requester is told. | | | |
| UAT-41 | CSAT survey | 1. Close a ticket. 2. Open the survey link as the requester. 3. Rate and comment. 4. Try the link again. | Rating is saved to the ticket and reports. Second use is refused or shows the saved rating. | | | |

## 8. Queues and reports

| ID | Test | Steps | Expected result | Pass/Fail | Tester | Date |
| --- | --- | --- | --- | --- | --- | --- |
| UAT-42 | Queues | 1. Open My tickets, Team queue, Unassigned, Overdue. | Each shows only matching tickets for the user's team. | | | |
| UAT-43 | Filters | 1. Filter by status, priority, department, assignee and date. 2. Combine two filters. | Results match the filters. Counts are right. | | | |
| UAT-44 | Reports | 1. Open Reports as a Supervisor. 2. Check volume, SLA met, CSAT and AI routing figures for a known week. | Figures match a manual count of test tickets. | | | |
| UAT-45 | Export permissions | 1. Export as a Supervisor. 2. Try export as an agent. 3. Check the file for confidential tickets. | Supervisor can export, agent cannot. Confidential tickets appear only for allowed users. | | | |

## 9. Room booking

| ID | Test | Steps | Expected result | Pass/Fail | Tester | Date |
| --- | --- | --- | --- | --- | --- | --- |
| UAT-46 | Booking clash | 1. Book Room 1, 10:00 to 11:00. 2. As another user, book Room 1, 10:30 to 11:30. | Second booking is refused with the clash shown. | | | |
| UAT-47 | Booking approval | 1. Book a room that needs approval. 2. Room manager approves. 3. Book again, manager rejects. | Booking stays pending until approved. Requester is told either way. | | | |
| UAT-48 | Recurring booking | 1. Book weekly for 4 weeks. 2. Cancel one date. | Four bookings are made, clashes are reported, one date cancels alone. | | | |
| UAT-49 | Check-in | 1. Open a booking within the check-in window and check in. | Booking shows checked in. | | | |
| UAT-50 | Auto-release | 1. Book a room and do not check in. 2. Run `php spark rooms:release` after the grace period. | Booking is released and the room is free to book. Owner is told. | | | |

## 10. Inbound email and knowledge

| ID | Test | Steps | Expected result | Pass/Fail | Tester | Date |
| --- | --- | --- | --- | --- | --- | --- |
| UAT-51 | Email-to-ticket | 1. Send an email with an attachment to the service desk mailbox. 2. Reply to the receipt email. | First email makes a ticket with the attachment. The reply is added to the same ticket, not a new one. | | | |
| UAT-52 | Email from unknown sender | 1. Send from an address that is not a user. | Handled as per policy (ticket for allowed domains, ignored or bounced otherwise). | | | |
| UAT-53 | Knowledge review queue | 1. Resolve a ticket with a good fix. 2. Open the knowledge review queue as a Supervisor. 3. Edit and approve the draft. 4. Reject another. | Approved draft becomes an active article. Rejected draft does not appear to the assistant. | | | |
| UAT-54 | Assistant uses new article | 1. After UAT-53, raise a ticket on the same topic. | Assistant reply reflects the approved article, without quoting an article number. | | | |

## 11. Governance

| ID | Test | Steps | Expected result | Pass/Fail | Tester | Date |
| --- | --- | --- | --- | --- | --- | --- |
| UAT-55 | SLA sign-off page | 1. Open the SLA sign-off page as a department head. 2. Review targets and sign off. 3. Change a target as admin. | Sign-off is recorded with name and date. A later change asks for sign-off again. | | | |
| UAT-56 | Backup | 1. Run the backup script. 2. Check the backup file and its date. | Backup file is created, not empty, and kept per the retention rule. | | | |
| UAT-57 | Restore | 1. Restore the latest backup into a spare staging copy. 2. Open five known tickets with attachments. | Tickets, messages and attachments match the source. | | | |
| UAT-58 | Audit log | 1. Change a ticket status, assign, re-route, approve, and change a user's role. 2. Open the audit log. | Each action shows who, what, when and the old and new value. Log cannot be edited from the UI. | | | |
