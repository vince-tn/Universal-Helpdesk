<?php

namespace App\Controllers;

use App\Libraries\SignupPolicy;
use App\Models\SignupAllowlistModel;

class AdminAccessController extends BaseController
{
    protected SignupAllowlistModel $list;

    public function __construct()
    {
        $this->list = new SignupAllowlistModel();
    }

    public function index()
    {
        return view('admin/access/index', [
            'companyDomains' => (new SignupPolicy())->companyDomains(),
            'entries'        => $this->list->listed(),
            'labelMax'       => SignupAllowlistModel::LABEL_MAX,
        ]);
    }

    public function store()
    {
        $entry = SignupPolicy::parse((string) $this->request->getPost('value'));
        $label = trim(preg_replace('/\s+/u', ' ', (string) $this->request->getPost('label')) ?? '');

        if ($entry === null) {
            return redirect()->back()->withInput()
                ->with('error', 'Enter a domain like agency.com.au, or one full email address.');
        }

        [$kind, $value] = $entry;

        if (mb_strlen($label) > SignupAllowlistModel::LABEL_MAX) {
            return redirect()->back()->withInput()
                ->with('error', 'Keep the agency name under ' . SignupAllowlistModel::LABEL_MAX . ' characters.');
        }

        if ($kind === SignupAllowlistModel::KIND_DOMAIN && SignupPolicy::isPublicProvider($value)) {
            return redirect()->back()->withInput()->with(
                'error',
                '@' . $value . ' is a free email service - allowing it would let anyone sign up. Add that person\'s full address instead.'
            );
        }

        $probe = $kind === SignupAllowlistModel::KIND_DOMAIN ? 'someone@' . $value : $value;
        $shown = $kind === SignupAllowlistModel::KIND_DOMAIN ? '@' . $value : $value;

        if (config(\Config\Access::class)->allows($probe)) {
            return redirect()->back()->with('success', $shown . ' can already sign up - it is covered by the company list.');
        }

        if ($this->list->where('value', $value)->countAllResults() > 0) {
            return redirect()->back()->with('success', $shown . ' is already on the list.');
        }

        $this->list->insert([
            'kind'       => $kind,
            'value'      => $value,
            'label'      => $label === '' ? null : $label,
            'created_by' => (int) (current_user()['id'] ?? 0) ?: null,
        ]);

        log_message('info', 'Sign-up access added for {value} by {email}', [
            'value' => $value,
            'email' => current_user()['email'] ?? '?',
        ]);

        return redirect()->to('/admin/access')->with(
            'success',
            $kind === SignupAllowlistModel::KIND_DOMAIN
                ? 'Anyone with an @' . $value . ' address can now sign up.'
                : $value . ' can now sign up.'
        );
    }

    public function delete(int $id)
    {
        $row = $this->list->find($id) ?? throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();

        $this->list->delete($id);

        log_message('info', 'Sign-up access removed for {value} by {email}', [
            'value' => $row['value'],
            'email' => current_user()['email'] ?? '?',
        ]);

        return redirect()->to('/admin/access')->with(
            'success',
            'Removed ' . $row['value'] . '. Accounts already created stay as they are.'
        );
    }
}
