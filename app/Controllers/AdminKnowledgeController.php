<?php

namespace App\Controllers;

use App\Libraries\Guardrails;
use App\Libraries\KnowledgeBase;
use App\Libraries\Org;
use App\Libraries\RoutingDirectory;
use App\Models\DepartmentModel;
use App\Models\GuardrailModel;
use App\Models\KnowledgeArticleModel;
use App\Models\KnowledgeCitationModel;
use App\Models\KnowledgeRevisionModel;

class AdminKnowledgeController extends BaseController
{
    protected KnowledgeArticleModel $articles;
    protected GuardrailModel $guardrails;
    protected KnowledgeRevisionModel $revisions;
    protected KnowledgeCitationModel $citations;

    public function __construct()
    {
        $this->articles   = new KnowledgeArticleModel();
        $this->guardrails = new GuardrailModel();
        $this->revisions  = new KnowledgeRevisionModel();
        $this->citations  = new KnowledgeCitationModel();
    }

    public function index(int $departmentId)
    {
        $department = $this->department($departmentId);
        $scopeName  = $department['id'] === null ? '' : $department['name'];
        $try        = trim((string) $this->request->getGet('try'));
        $preview    = null;

        if ($try !== '') {
            $knowledge = new KnowledgeBase();
            $rules     = new Guardrails();

            $preview = [
                'articles' => $knowledge->relevant($scopeName, $try),
                'handoff'  => $rules->handoffTopic($scopeName, $try),
                'blocked'  => $rules->blockedPhrase($scopeName, $try),
                'routing'  => $department['id'] === null ? [] : (new RoutingDirectory())->shortlist($try, $scopeName, ''),
            ];
        }

        $articles   = $this->articles->forDepartment($department['id']);
        $articleIds = array_map(static fn ($a) => (int) $a['id'], $articles);

        return view('admin/knowledge/index', [
            'department' => $department,
            'pageId'     => $departmentId,
            'articles'   => $articles,
            'revisions'  => $this->revisions->countsByArticle($articleIds),
            'citedCount' => $this->citations->countsByArticle($articleIds),
            'rules'      => $this->guardrails->forDepartment($department['id']),
            'try'        => $try,
            'preview'    => $preview,
            'limits'     => [
                'title'    => KnowledgeArticleModel::TITLE_MAX,
                'keywords' => KnowledgeArticleModel::KEYWORDS_MAX,
                'body'     => KnowledgeArticleModel::BODY_MAX,
            ],
        ]);
    }

    public function storeArticle(int $departmentId)
    {
        $department = $this->department($departmentId);
        $data       = $this->articleInput();

        if (is_string($data)) {
            return redirect()->back()->withInput()->with('error', $data);
        }

        $row = $data + ['department_id' => $department['id'], 'is_active' => 1];
        $id  = $this->articles->insert($row, true);

        if ($id !== false) {
            $this->revisions->record((int) $id, $row, KnowledgeRevisionModel::ACTION_CREATED, current_user());
        }

        return $this->backTo($departmentId, 'Article "' . $data['title'] . '" added.');
    }

    public function updateArticle(int $id)
    {
        $article = $this->articles->find($id) ?? throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        $this->guardScope($article['department_id']);

        $data = $this->articleInput();

        if (is_string($data)) {
            return redirect()->back()->with('error', $data);
        }

        $row = $data + ['is_active' => $this->request->getPost('is_active') !== null ? 1 : 0];

        $this->articles->update($id, $row);
        $this->revisions->record(
            $id,
            $row + ['department_id' => $article['department_id']],
            KnowledgeRevisionModel::ACTION_UPDATED,
            current_user()
        );

        return $this->backTo((int) $article['department_id'], 'Article "' . $data['title'] . '" saved.');
    }

    public function deleteArticle(int $id)
    {
        $article = $this->articles->find($id) ?? throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        $this->guardScope($article['department_id']);

        $this->revisions->record($id, $article, KnowledgeRevisionModel::ACTION_DELETED, current_user());
        $this->articles->delete($id);

        return $this->backTo((int) $article['department_id'], 'Article "' . $article['title'] . '" deleted.');
    }

    public function history(int $id)
    {
        $article  = $this->articles->find($id);
        $snapshot = $article ?? $this->revisions->where('article_id', $id)->orderBy('id', 'DESC')->first();

        if ($snapshot === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $departmentId = ($snapshot['department_id'] ?? null) === null ? null : (int) $snapshot['department_id'];

        $this->guardScope($departmentId);

        return view('admin/knowledge/history', [
            'article'    => $article,
            'articleId'  => $id,
            'title'      => (string) $snapshot['title'],
            'pageId'     => $departmentId ?? 0,
            'department' => $departmentId === null
                ? 'All departments'
                : (string) ((new DepartmentModel())->find($departmentId)['name'] ?? 'Unknown department'),
            'revisions'  => $this->revisions->forArticle($id),
            'citations'  => $this->citations->forArticle($id),
        ]);
    }

    public function storeGuardrail(int $departmentId)
    {
        $department = $this->department($departmentId);
        $kind       = (string) $this->request->getPost('kind');
        $value      = trim(preg_replace('/\s+/u', ' ', (string) $this->request->getPost('value')) ?? '');

        if (! in_array($kind, GuardrailModel::KINDS, true)) {
            return redirect()->back()->with('error', 'Choose what kind of guardrail this is.');
        }

        $max = GuardrailModel::VALUE_MAX[$kind];

        if ($value === '' || mb_strlen($value) > $max) {
            return redirect()->back()->withInput()->with('error', "Enter the rule - 1 to {$max} characters.");
        }

        $duplicate = $this->guardrails
            ->where('department_id', $department['id'])
            ->where('kind', $kind)
            ->where('value', $value)
            ->countAllResults() > 0;

        if (! $duplicate) {
            $this->guardrails->insert(['department_id' => $department['id'], 'kind' => $kind, 'value' => $value]);
        }

        return $this->backTo($departmentId, $duplicate ? 'That guardrail is already there.' : 'Guardrail added.');
    }

    public function deleteGuardrail(int $id)
    {
        $rule = $this->guardrails->find($id) ?? throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        $this->guardScope($rule['department_id']);

        $this->guardrails->delete($id);

        return $this->backTo((int) $rule['department_id'], 'Guardrail removed.');
    }

    private function articleInput(): array|string
    {
        $title    = trim((string) $this->request->getPost('title'));
        $keywords = trim(preg_replace('/\s+/u', ' ', (string) $this->request->getPost('keywords')) ?? '');
        $body     = trim(str_replace("\r\n", "\n", (string) $this->request->getPost('body')));

        return match (true) {
            $title === '' || mb_strlen($title) > KnowledgeArticleModel::TITLE_MAX
                => 'Give the article a title of 1 to ' . KnowledgeArticleModel::TITLE_MAX . ' characters.',
            $body === '' || mb_strlen($body) > KnowledgeArticleModel::BODY_MAX
                => 'Write the article - 1 to ' . KnowledgeArticleModel::BODY_MAX . ' characters. Split anything longer into separate articles.',
            mb_strlen($keywords) > KnowledgeArticleModel::KEYWORDS_MAX
                => 'Keep the keywords under ' . KnowledgeArticleModel::KEYWORDS_MAX . ' characters.',
            default => ['title' => $title, 'keywords' => $keywords === '' ? null : $keywords, 'body' => $body],
        };
    }

    private function department(int $departmentId): array
    {
        if ($departmentId === 0) {
            $this->guardScope(null);

            return ['id' => null, 'name' => 'All departments'];
        }

        $this->guardScope($departmentId);

        $row = (new DepartmentModel())->find($departmentId) ?? throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();

        return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    private function guardScope(int|string|null $departmentId): void
    {
        $user = current_user();

        $allowed = Org::canEditKnowledge($user, $departmentId === null ? null : (int) $departmentId);

        if (! $allowed) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
    }

    private function backTo(int $departmentId, string $message)
    {
        return redirect()->to('/admin/departments/' . $departmentId . '/knowledge')->with('success', $message);
    }
}
