<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

// department layout, starter knowledge, guardrails and service catalogue for a fresh branded copy
class ServiceDeskSeeder extends Seeder
{
    private const DEPARTMENTS = [
        'IT' => [
            'handles' => 'computers, laptops, headsets, peripherals, accounts, passwords, email, VPN, printers, network, software',
            'roles'   => [
                'Helpdesk' => ['headsets, mouse, keyboard, monitors, passwords, account lockouts, Outlook, Teams, printers', 0],
                'Systems'  => ['laptop builds, software installs, licences, shared drives, access rights, new hire setup', 0],
                'Network'  => ['VPN, wifi, internet, slow connection, firewall, phone lines, softphone', 0],
            ],
        ],
        'Web' => [
            'handles' => 'company websites, landing pages, website content changes, broken links, forms, publishing',
            'roles'   => [
                'Webmaster' => ['website edits, new pages, publishing, broken pages, forms, SEO fixes', 0],
            ],
        ],
        'Marketing' => [
            'handles' => 'marketing assets, social posts, banners, brochures, email campaigns, brand use',
            'roles'   => [
                'Design'  => ['banners, posters, social graphics, presentations, brand templates', 0],
                'Content' => ['social posts, campaign copy, newsletters, announcements', 0],
            ],
        ],
        'Systems & Data' => [
            'handles' => 'Salesforce, Tally, SQL databases, data pulls, CRM access, integrations',
            'roles'   => [
                'Salesforce/Tally' => ['Salesforce access, layouts, reports, Tally entries and sync', 0],
                'SQL'              => ['database queries, data extracts, fixes to stored data', 0],
            ],
        ],
        'HR' => [
            'handles' => 'payroll, leave, certificates, recruitment, onboarding, employee concerns',
            'roles'   => [
                'Recruitment'        => ['manpower requests, referrals, job posts, interviews, onboarding', 0],
                'Payroll'            => ['pay disputes, pay adjustments, deductions, payslips, government contributions', 1],
                'Employee Relations' => ['workplace concerns, grievances, conduct, disciplinary matters', 1],
                'Records'            => ['certificate of employment, personal details updates, 201 file', 0],
            ],
        ],
        'TQA' => [
            'handles' => 'training batches, refreshers, coaching, call quality audits, scoring disputes',
            'roles'   => [
                'Training'          => ['new hire training, refreshers, training materials, certification', 0],
                'Quality Assurance' => ['call audits, QA scores, score disputes, calibration', 0],
            ],
        ],
        'Reports/WFM' => [
            'handles' => 'reports, dashboards, schedules, shift swaps, headcount forecasts, attendance data',
            'roles'   => [
                'Reporting'          => ['daily reports, dashboards, report errors, new report requests', 0],
                'Workforce Planning' => ['schedules, shift swaps, overtime plans, forecasts', 0],
            ],
        ],
        'Compliance' => [
            'handles' => 'regulatory questions, audits, policy checks, data privacy, incident reports',
            'roles'   => [
                'Regulatory' => ['regulatory questions, scripts approval, complaints handling rules', 0],
                'Audit'      => ['internal audits, evidence requests, ISO checks, data privacy incidents', 1],
            ],
        ],
        'Operations' => [
            'handles' => 'floor operations, client escalations, service delivery, site facilities, room issues',
            'roles'   => [
                'Support'          => ['floor support, seat moves, facilities, room equipment, supplies', 0],
                'Service Delivery' => ['client escalations, process changes, service levels', 0],
            ],
        ],
    ];

    private const ARTICLES = [
        'Web' => [
            ['Requesting a website content change', 'website change, edit page, update text, website', "Send the page link, the exact text to change, and the new text.\nAdd a screenshot if it helps.\nSmall text edits go live within 2 working days once approved."],
            ['Reporting a broken page or link', 'broken link, 404, page not loading, error page', "Send the page link and the link that does not work.\nSay which browser you used.\nThe web team checks broken pages the same working day."],
            ['Requesting a new landing page', 'landing page, new page, campaign page', "Send the purpose, target audience, go-live date and the content you have.\nNew pages need marketing approval before work starts."],
            ['Website form not sending', 'form, contact form, not sending, enquiry form', "Send the page link and the time you submitted the form.\nDo not resubmit many times, the team can see the attempts."],
            ['Publishing schedule', 'publish, go live, schedule, deadline', "Changes are published on working days between 9 AM and 4 PM.\nUrgent fixes outside these hours need a supervisor to approve."],
        ],
        'Marketing' => [
            ['Requesting a design asset', 'banner, poster, graphic, design, artwork', "Send the size or platform, the message, the deadline and any photos or logos to use.\nAllow 3 working days for a first draft."],
            ['Using the company logo', 'logo, brand, brand guide, colours', "Use only the logo files from the shared brand folder.\nDo not stretch, recolour or add effects to the logo."],
            ['Social media post request', 'social post, facebook, linkedin, instagram', "Send the message, the photo and the date it should go out.\nPosts need approval from your supervisor before marketing schedules them."],
            ['Email campaign request', 'email campaign, newsletter, mailout', "Send the audience, the message, the send date and the call to action.\nAllow 5 working days for build and test sends."],
            ['Presentation template', 'presentation, slides, deck, template', "Use the company slide template from the shared brand folder.\nMarketing can tidy a finished deck within 2 working days."],
        ],
        'Systems & Data' => [
            ['Requesting Salesforce access', 'salesforce access, crm login, profile', "Send your full name, team, and the access your supervisor approved.\nAccess is set up within 1 working day after approval."],
            ['A Salesforce report looks wrong', 'salesforce report, wrong numbers, dashboard', "Send the report name, the filter you used and what you expected to see.\nAdd a screenshot of the numbers."],
            ['Requesting a data extract', 'data extract, data pull, export, sql', "Send the fields you need, the date range and what the data is for.\nPersonal data extracts need a supervisor to approve."],
            ['Tally entry not syncing', 'tally, sync, not updating', "Send the record number and the time you saved it.\nDo not re-enter the record, it can create duplicates."],
            ['Fixing a wrong record', 'wrong record, correct data, fix entry', "Send the record number, the wrong value and the correct value.\nThe team confirms here once fixed."],
        ],
        'Reports/WFM' => [
            ['Requesting a shift swap', 'shift swap, change shift, schedule change', "Agree the swap with your colleague first.\nSend both names, both dates and both shifts. Your team leader must approve it."],
            ['A daily report is missing', 'missing report, report not sent, daily report', "Send the report name and the date.\nReports go out by 10 AM. If it is later than that, reply here."],
            ['Report numbers look wrong', 'wrong numbers, report error, figures', "Send the report name, the figure that looks wrong and what you expected.\nAdd a screenshot."],
            ['Requesting a new report', 'new report, dashboard request', "Send what the report is for, the fields, how often you need it and who should receive it."],
            ['Viewing your schedule', 'schedule, roster, my shift', "Your schedule is in the WFM tool under My Schedule.\nIf it is blank, reply here with your employee number."],
        ],
    ];

    private const EXTRA_ARTICLES = [
        'HR' => [
            ['Payroll cut-off and pay dates', 'pay date, cut-off, payroll schedule, salary date', "Pay goes out on the 15th and the last working day of the month.\nChanges filed after the cut-off, 5 working days before pay day, go into the next pay run."],
        ],
        'TQA' => [
            ['Disputing a QA score', 'qa score, dispute, audit result, calibration', "Send the call or audit ID and the part of the score you disagree with.\nDisputes must be raised within 3 working days of the score being shared."],
            ['Booking a refresher training', 'refresher, retraining, coaching session', "Send the topic, the people who need it and two dates that work.\nTQA confirms the slot within 2 working days."],
            ['Finding training materials', 'training materials, modules, slides, lms', "Training decks and recordings are in the shared TQA folder.\nIf a module is missing, reply with its name."],
        ],
        'Compliance' => [
            ['Reporting a possible data privacy incident', 'data privacy, breach, leak, wrong recipient, personal data', "Stop and do not forward the data further.\nRaise a ticket straight away with what was shared, with whom and when. Do not include the personal data itself."],
            ['Script or wording approval', 'script approval, wording, regulatory check', "Send the script or wording and where it will be used.\nAllow 3 working days for review."],
            ['Requesting audit evidence', 'audit evidence, iso, records request', "Send the audit name, the control and the evidence needed.\nCompliance replies with what to upload and the deadline."],
            ['Complaint handling rules', 'complaint, escalation rules, regulator', "Log every complaint in the client system the same day.\nIf a customer mentions the regulator or the ombudsman, escalate to your team leader at once."],
        ],
        'Operations' => [
            ['Reporting faulty room equipment', 'room, tv, projector, conference phone, broken', "Send the room name and what is not working.\nIT or Operations checks it the same working day."],
            ['Requesting a seat move', 'seat move, desk change, workstation', "Send your name, current seat and the seat you need, plus your team leader's okay."],
            ['Office supplies', 'supplies, headset stock, stationery', "Send the item and quantity.\nSupplies are handed out on Tuesdays and Thursdays."],
            ['Raising a client escalation', 'client escalation, urgent client issue', "Send the client, what happened, and the impact.\nMark work stopped as yes if service to the client is down."],
        ],
    ];

    private const GUARDRAILS = [
        'never promise an approval, a pay amount, a refund or a date on behalf of a team',
        'never ask for or repeat passwords, one time codes or full bank details',
        'speak as the help desk, never as the requester',
        'do not mention internal article numbers or field names',
    ];

    private const SERVICES = [
        ['it-headset', 'Headset or peripheral replacement', 'Broken or missing headset, mouse, keyboard or monitor', 'IT', 'Helpdesk', 'Common Request', [['item', 'Which item', 'text', true], ['asset_tag', 'Asset tag (if you can see it)', 'text', false]], 0, null],
        ['it-password', 'Password reset or account lockout', 'Locked out of Windows, email or a system', 'IT', 'Helpdesk', 'Common Request', [['system', 'Which system', 'text', true]], 0, null],
        ['it-software', 'Software install', 'New software or a licence', 'IT', 'Systems', 'Special Request', [['software', 'Software name', 'text', true], ['reason', 'What it is for', 'text', true]], 0, null],
        ['it-equipment', 'Equipment purchase', 'A new laptop, monitor or other equipment to buy', 'IT', 'Systems', 'Mid-Scale Request', [['item', 'Item', 'text', true], ['quantity', 'Quantity', 'number', true], ['reason', 'Business reason', 'text', true]], 1, 'team_leader,supervisor'],
        ['it-network', 'VPN, wifi or internet problem', 'Cannot connect or the connection drops', 'IT', 'Network', 'Common Request', [['location', 'Office or home', 'text', false]], 0, null],
        ['it-general', 'Other IT issue', 'Anything else for IT', 'IT', null, 'Common Request', [], 0, null],
        ['web-change', 'Website change', 'Edit or publish content on a company website', 'Web', 'Webmaster', 'Small-Scale Request', [['page_url', 'Page URL', 'url', true], ['deadline', 'Needed by', 'date', false]], 1, 'supervisor'],
        ['web-broken', 'Broken page or link', 'A page or link on our website is not working', 'Web', 'Webmaster', 'Common Request', [['page_url', 'Page URL', 'url', true]], 0, null],
        ['mkt-asset', 'Marketing asset', 'A banner, poster, social graphic or campaign', 'Marketing', 'Design', 'Small-Scale Request', [['asset_type', 'Type of asset', 'text', true], ['deadline', 'Needed by', 'date', true]], 1, 'supervisor'],
        ['sys-access', 'Salesforce or Tally access', 'New access or a change in access', 'Systems & Data', 'Salesforce/Tally', 'Common Request', [['system', 'System', 'text', true]], 1, 'team_leader'],
        ['sys-extract', 'Data extract', 'A data pull or export', 'Systems & Data', 'SQL', 'Special Request', [['fields', 'Fields needed', 'text', true], ['date_range', 'Date range', 'text', true]], 0, null],
        ['hr-payroll-dispute', 'Payroll dispute', 'Something on your pay looks wrong', 'HR', 'Payroll', 'Special Request', [['pay_period', 'Pay period', 'text', true], ['amount', 'Amount in question', 'number', false]], 0, null],
        ['hr-payroll-adjust', 'Payroll adjustment', 'A pay change that needs sign-off', 'HR', 'Payroll', 'Special Request', [['pay_period', 'Pay period', 'text', true], ['amount', 'Amount', 'number', true]], 1, 'supervisor'],
        ['hr-coe', 'Certificate of employment', 'A COE for a visa, bank or new employer', 'HR', 'Records', 'Common Request', [['purpose', 'Purpose', 'text', true], ['copies', 'Copies', 'number', true]], 0, null],
        ['hr-manpower', 'Manpower request', 'Request new headcount', 'HR', 'Recruitment', 'Mid-Scale Request', [['role', 'Role', 'text', true], ['headcount', 'Headcount', 'number', true], ['start_date', 'Start date', 'date', true]], 1, 'supervisor'],
        ['hr-referral', 'Employee referral', 'Refer someone for a role', 'HR', 'Recruitment', 'Common Request', [['candidate', 'Candidate name', 'text', true], ['role', 'Role', 'text', true]], 0, null],
        ['hr-concern', 'Workplace concern', 'A private concern for Employee Relations', 'HR', 'Employee Relations', 'Special Request', [], 0, null],
        ['tqa-training', 'Training request', 'A refresher or new training', 'TQA', 'Training', 'Small-Scale Request', [['topic', 'Topic', 'text', true], ['attendees', 'How many attendees', 'number', false]], 0, null],
        ['tqa-dispute', 'QA score dispute', 'Dispute a call audit score', 'TQA', 'Quality Assurance', 'Common Request', [['call_id', 'Call or audit ID', 'text', true]], 0, null],
        ['wfm-swap', 'Shift swap', 'Swap a shift with a colleague', 'Reports/WFM', 'Workforce Planning', 'Common Request', [['colleague', 'Colleague', 'text', true], ['dates', 'Dates', 'text', true]], 1, 'team_leader'],
        ['wfm-report', 'Report request or fix', 'A new report or a report that looks wrong', 'Reports/WFM', 'Reporting', 'Common Request', [['report', 'Report name', 'text', true]], 0, null],
        ['cmp-incident', 'Data privacy incident', 'Report a possible data privacy incident', 'Compliance', 'Audit', 'Special Request', [], 0, null],
        ['ops-room', 'Room or facilities issue', 'Faulty room equipment, aircon, lights or supplies', 'Operations', 'Support', 'Common Request', [['room', 'Room or area', 'text', true]], 0, null],
        ['ops-escalation', 'Client escalation', 'A client issue that needs operations', 'Operations', 'Service Delivery', 'Special Request', [['client', 'Client', 'text', true]], 0, null],
    ];

    private const CANNED = [
        [null, 'Need more detail', "Thanks for the ticket. To move this forward, could you send a screenshot and the exact time it happened?"],
        [null, 'Working on it', "Thanks for your patience. We are working on this now and will update you here."],
        [null, 'Resolved, please confirm', "This should be sorted now. Please check and reply here if anything is still not right."],
    ];

    public function run()
    {
        $db  = $this->db;
        $now = date('Y-m-d H:i:s');

        $technical = $db->table('departments')->where('name', 'Technical')->get()->getRowArray();
        if ($technical !== null && $db->table('departments')->where('name', 'Systems & Data')->countAllResults() === 0) {
            $db->table('departments')->where('id', $technical['id'])->update(['name' => 'Systems & Data', 'updated_at' => $now]);
            $db->table('department_roles')->where('department_id', $technical['id'])->whereIn('name', ['Web', 'Marketing'])->delete();
        }

        $order = 0;
        foreach (self::DEPARTMENTS as $name => $spec) {
            $row = $db->table('departments')->where('name', $name)->get()->getRowArray();

            if ($row === null) {
                $db->table('departments')->insert(['name' => $name, 'is_active' => 1, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
                $id = (int) $db->insertID();
            } else {
                $id = (int) $row['id'];
            }

            $db->table('departments')->where('id', $id)->update([
                'handles' => mb_substr($spec['handles'], 0, 150), 'is_active' => 1, 'sort_order' => $order++, 'updated_at' => $now,
            ]);

            foreach ($spec['roles'] as $role => [$handles, $confidential]) {
                $exists = $db->table('department_roles')->where(['department_id' => $id, 'name' => $role])->countAllResults() > 0;

                $data = ['handles' => mb_substr($handles, 0, 150), 'is_confidential' => $confidential, 'updated_at' => $now];

                if ($exists) {
                    $db->table('department_roles')->where(['department_id' => $id, 'name' => $role])->update($data);
                } else {
                    $db->table('department_roles')->insert($data + ['department_id' => $id, 'name' => $role, 'created_at' => $now]);
                }
            }
        }

        $ids = array_column($db->table('departments')->get()->getResultArray(), 'id', 'name');

        foreach (array_merge_recursive(self::ARTICLES, self::EXTRA_ARTICLES) as $department => $articles) {
            if (! isset($ids[$department])) {
                continue;
            }
            foreach ($articles as [$title, $keywords, $body]) {
                if ($db->table('knowledge_articles')->where(['department_id' => $ids[$department], 'title' => $title])->countAllResults() > 0) {
                    continue;
                }
                $db->table('knowledge_articles')->insert([
                    'department_id' => $ids[$department], 'title' => $title, 'keywords' => $keywords, 'body' => $body,
                    'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        foreach (self::GUARDRAILS as $rule) {
            if ($db->table('guardrails')->where(['department_id' => null, 'value' => $rule])->countAllResults() === 0) {
                $db->table('guardrails')->insert(['department_id' => null, 'kind' => 'instruction', 'value' => $rule, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $sort = 0;
        foreach (self::SERVICES as [$key, $name, $description, $department, $role, $category, $fields, $approval, $chain]) {
            if ($db->table('services')->where('service_key', $key)->countAllResults() > 0) {
                continue;
            }
            $db->table('services')->insert([
                'service_key'       => $key,
                'name'              => $name,
                'description'       => $description,
                'department'        => $department,
                'role'              => $role,
                'category'          => $category,
                'fields_json'       => json_encode(array_map(static fn ($f) => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2], 'required' => $f[3]], $fields)),
                'requires_approval' => $approval,
                'approval_chain'    => $chain,
                'is_active'         => 1,
                'sort_order'        => $sort++,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }

        if ($db->table('canned_responses')->countAllResults() === 0) {
            foreach (self::CANNED as [$department, $title, $body]) {
                $db->table('canned_responses')->insert(['department_id' => $department, 'title' => $title, 'body' => $body, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        echo "Service desk structure ready.\n";
    }
}
