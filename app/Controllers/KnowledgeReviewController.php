<?php

namespace App\Controllers;

use App\Libraries\KnowledgeReview;

// queue of ticked answers waiting for a lead
class KnowledgeReviewController extends BaseController
{
    public function index()
    {
        return view('admin/knowledge_review/index', [
            'pending' => (new KnowledgeReview())->pending(current_user()),
        ]);
    }

    public function decide(int $messageId)
    {
        [$ok, $message] = (new KnowledgeReview())->decide($messageId, current_user(), (string) $this->request->getPost('action'));

        return redirect()->to('/admin/knowledge-review')->with($ok ? 'success' : 'error', $message);
    }
}
