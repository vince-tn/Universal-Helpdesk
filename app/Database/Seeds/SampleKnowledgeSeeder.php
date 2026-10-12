<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class SampleKnowledgeSeeder extends Seeder
{
    private const ARTICLES = [
        'IT' => [
            [
                'title'    => 'Locked out of Windows after too many password attempts',
                'keywords' => 'locked out, account locked, cannot log in, cannot sign in, password expired, login',
                'body'     => "Wait 15 minutes - the lock clears by itself. Then sign in slowly with Caps Lock off.\nIf your password has expired, Windows asks you to choose a new one at the sign-in screen.\nStill locked after 15 minutes? Reply here and IT will unlock the account.",
            ],
            [
                'title'    => 'VPN keeps disconnecting',
                'keywords' => 'vpn, forticlient, remote access, drops, disconnects, work from home',
                'body'     => "Open FortiClient and click Disconnect.\nGo to Settings and click Clear saved credentials.\nReconnect to the office profile and sign in again.\nIf it still drops within ten minutes, reply here with the time it dropped.",
            ],
            [
                'title'    => 'Outlook is not receiving new emails',
                'keywords' => 'email, outlook, inbox, mail not arriving, not syncing, send receive',
                'body'     => "Look at the bar at the bottom of Outlook. If it says Working Offline, click Send / Receive, then Work Offline to turn it off.\nClose Outlook completely and open it again.\nIf mail still is not arriving after 10 minutes, check your mailbox in a web browser. If the mail is there, reply here so IT can repair your Outlook profile.",
            ],
            [
                'title'    => 'Printer not printing, or jobs stuck in the queue',
                'keywords' => 'printer, print, printing, stuck, queue, offline printer',
                'body'     => "Open Settings, then Printers & scanners, choose the printer and open its print queue.\nCancel every document in the queue.\nTurn the printer off for 30 seconds, turn it back on, and print one test page.\nIf the printer shows a paper jam or an error light, do not open the panels yourself - reply here and IT will come over.",
            ],
            [
                'title'    => 'Laptop is running slowly',
                'keywords' => 'slow, lagging, freezing, sluggish, performance, hanging',
                'body'     => "Save your work and restart the laptop (Restart, not Shut down) - that clears most slowdowns.\nClose browser tabs you are not using.\nIf it is still slow after a restart, reply with what you were doing when it slowed down and IT will check it remotely.",
            ],
        ],
        'HR' => [
            [
                'title'    => 'Requesting a certificate of employment',
                'keywords' => 'COE, certificate of employment, employment certificate, proof of employment',
                'body'     => "Reply with what the certificate is for (for example a visa, a bank or a new employer) and who it should be addressed to.\nHR issues it within 3 working days and sends the signed PDF to your work email.",
            ],
            [
                'title'    => 'Filing sick leave',
                'keywords' => 'sick leave, sick, ill, unwell, absent, absence, medical certificate',
                'body'     => "Tell your team leader before your shift starts that you are sick.\nOn your first day back, file the sick leave in the HRIS.\nIf you were out for more than 2 days, attach a medical certificate.",
            ],
            [
                'title'    => 'Filing vacation leave',
                'keywords' => 'vacation leave, vacation, day off, time off, holiday, leave request',
                'body'     => "File vacation leave in the HRIS at least 3 working days before your first day off.\nYour team leader approves it in the HRIS, and you get an email once it is approved.",
            ],
            [
                'title'    => 'Updating your personal details',
                'keywords' => 'address, contact number, emergency contact, civil status, personal information, change details',
                'body'     => "Reply here with the detail that changed and its new value.\nFor a change of name or civil status, attach the supporting document, such as a marriage certificate.\nHR updates your record within 2 working days and confirms here.",
            ],
        ],
        'TQA' => [
            [
                'title'    => 'Requesting a training summary',
                'keywords' => 'training record, trainings attended, training history, summary of training',
                'body'     => "Reply with the date range and whose trainings you need - yours or your team's.\nTQA sends the summary within 2 working days.",
            ],
            [
                'title'    => 'Logging a completed training',
                'keywords' => 'lodge training, record training, training register, completed training, attendance',
                'body'     => "Reply with the training title, date, number of hours, trainer, and the names of everyone who attended.\nTQA adds it to the Training Register within 1 working day.",
            ],
        ],
        'Technical' => [
            [
                'title'    => 'Requesting a change to the website',
                'keywords' => 'website, web page, landing page, update site, typo on website, broken link',
                'body'     => "Reply with the page link, what should change, and the exact new wording or image.\nAnything that makes a claim about products or prices also needs Compliance approval before Technical can publish it.",
            ],
            [
                'title'    => 'Cannot log in to Salesforce',
                'keywords' => 'salesforce, crm, sfdc, login, locked, verification code',
                'body'     => "Use the Forgot Password link on the Salesforce login page. The reset email arrives within a few minutes - check your junk folder too.\nIf Salesforce asks for a verification code that never arrives, reply here and Technical will reset your verification method.",
            ],
        ],
        'Compliance' => [
            [
                'title'    => 'Submitting advertising material for review',
                'keywords' => 'ad, advertisement, flyer, brochure, marketing material, social media post, approval',
                'body'     => "Reply with the final draft attached, where it will be published, and the planned go-live date.\nCompliance reviews it within 2 working days.\nNothing may be published until Compliance has replied here with approval.",
            ],
        ],
        'Operations' => [
            [
                'title'    => 'Reporting missing data in a report',
                'keywords' => 'missing data, incomplete report, report data, figures missing, data gap',
                'body'     => "Reply with the report name, the period it covers, and which figures are missing or look wrong.\nOperations will confirm the data owner and a completion date here.",
            ],
        ],
    ];

    private const HANDLES = [
        'IT' => [
            'handles' => 'IT support: computers, accounts, passwords, email, VPN, printers, network',
            'roles'   => [
                'Helpdesk'        => 'laptops, computers, passwords, locked accounts, email, printers, software installs',
                'Network'         => 'VPN, internet, Wi-Fi, network drives, remote access',
                'Systems'         => 'servers, system access, user accounts, internal systems',
                'IT Staff'        => 'laptops, computers, passwords, locked accounts, email, VPN, printers, software installs',
                'Reports Analyst' => 'internal reports, dashboards, data extracts',
                'Webmaster'       => 'intranet pages, internal web tools, broken internal links',
            ],
        ],
        'Reports/WFM' => [
            'handles' => 'reports, dashboards, data extracts, schedules and workforce planning',
            'roles'   => [
                'Reporting'          => 'reports, dashboards, data extracts, report errors',
                'Workforce Planning' => 'work schedules, rosters, shift swaps, headcount forecasts',
            ],
        ],
        'HR' => [
            'handles' => 'people matters: leave, pay, hiring, employee records and concerns',
            'roles'   => [
                'Employee Relations' => 'leave, sick leave, vacation, attendance, certificate of employment, personal details, workplace concerns',
                'Payroll'            => 'salary, payslips, deductions, overtime pay, pay adjustments',
                'Recruitment'        => 'hiring, job openings, referrals, interviews, onboarding new hires',
            ],
        ],
        'TQA' => [
            'handles' => 'training and quality assurance',
            'roles'   => [
                'Training'          => 'training sessions, training records, lodging completed trainings',
                'Quality Assurance' => 'quality audits, QA scores, call reviews, coaching feedback',
            ],
        ],
        'Technical' => [
            'handles' => 'public website, marketing tools, Salesforce and Tally, databases',
            'roles'   => [
                'Marketing'        => 'campaigns, marketing assets, social media tools',
                'Salesforce/Tally' => 'Salesforce or Tally access, logins, records, reports',
                'SQL'              => 'database queries, data fixes, data exports',
                'Web'              => 'public website changes, landing pages, broken website links',
            ],
        ],
        'Compliance' => [
            'handles' => 'regulatory approvals, compliance reviews and audits',
            'roles'   => [
                'Audit'      => 'internal audits, audit findings, compliance monitoring program',
                'Regulatory' => 'advertising approval, regulatory questions, third-party onboarding',
            ],
        ],
        'Operations' => [
            'handles' => 'operations, service delivery and regulatory report data',
            'roles'   => [
                'Service Delivery' => 'regulatory report data, missing figures, data validation',
                'Support'          => 'day-to-day operational support for the floor',
            ],
        ],
    ];

    private const GUARDRAILS = [
        '' => [
            'instruction' => [
                'Be polite and professional. Never insult, mock or swear at anyone.',
                'Never ask for, or repeat, a password, PIN or one-time code.',
            ],
            'handoff' => ['harass*', 'discriminat*', 'bully*', 'legal action'],
            'block'   => ['fuck*', 'shit*', 'bitch*', 'bastard*', 'asshole*', 'I promise', 'guarantee*'],
        ],
        'IT' => [
            'handoff' => ['phishing', 'hacked', 'virus', 'malware', 'data breach'],
            'block'   => ['format your', 'reinstall windows', 'registry'],
        ],
        'HR' => [
            'instruction' => ['Never state a leave balance, salary or pay amount - only HR staff can confirm those.'],
            'handoff'     => ['payroll', 'salar*', 'payslip*', 'resign*', 'terminat*'],
        ],
        'Compliance' => [
            'instruction' => ['Never give a regulatory or legal opinion - say Compliance will review it.'],
            'handoff'     => ['regulator', 'audit finding'],
        ],
    ];

    public function run()
    {
        $now         = date('Y-m-d H:i:s');
        $departments = array_column($this->db->table('departments')->get()->getResultArray(), 'id', 'name');
        $added       = ['articles' => 0, 'guardrails' => 0];

        if (! isset($departments['IT']) && isset($departments['Admin'])) {
            $departments['IT'] = $departments['Admin'];
        }

        foreach (self::ARTICLES as $department => $articles) {
            if (! isset($departments[$department])) {
                continue;
            }

            foreach ($articles as $article) {
                $exists = $this->db->table('knowledge_articles')
                    ->where('department_id', (int) $departments[$department])
                    ->where('title', $article['title'])
                    ->countAllResults() > 0;

                if ($exists) {
                    continue;
                }

                $this->db->table('knowledge_articles')->insert($article + [
                    'department_id' => (int) $departments[$department],
                    'is_active'     => 1,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
                $added['articles']++;
            }
        }

        foreach (self::GUARDRAILS as $department => $kinds) {
            if ($department !== '' && ! isset($departments[$department])) {
                continue;
            }

            $departmentId = $department === '' ? null : (int) $departments[$department];

            foreach ($kinds as $kind => $values) {
                foreach ($values as $value) {
                    $exists = $this->db->table('guardrails')
                        ->where('department_id', $departmentId)
                        ->where('kind', $kind)
                        ->where('value', $value)
                        ->countAllResults() > 0;

                    if ($exists) {
                        continue;
                    }

                    $this->db->table('guardrails')->insert([
                        'department_id' => $departmentId,
                        'kind'          => $kind,
                        'value'         => $value,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                    $added['guardrails']++;
                }
            }
        }

        $added['handles'] = 0;

        foreach (self::HANDLES as $department => $sample) {
            if (! isset($departments[$department])) {
                continue;
            }

            $departmentId = (int) $departments[$department];

            $added['handles'] += $this->fillBlank('departments', ['id' => $departmentId], $sample['handles']);

            foreach ($sample['roles'] as $role => $handles) {
                $added['handles'] += $this->fillBlank('department_roles', ['department_id' => $departmentId, 'name' => $role], $handles);
            }
        }

        echo "Sample knowledge: added {$added['articles']} article(s), {$added['guardrails']} guardrail(s) and {$added['handles']} team description(s).\n";
    }

    private function fillBlank(string $table, array $where, string $handles): int
    {
        $this->db->table($table)
            ->where($where)
            ->groupStart()->where('handles', null)->orWhere('handles', '')->groupEnd()
            ->update(['handles' => $handles]);

        return $this->db->affectedRows();
    }
}
