<?php

namespace App\Models;

use CodeIgniter\Model;

class KnowledgeCitationModel extends Model
{
    protected $table         = 'knowledge_citations';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['message_id', 'ticket_id', 'article_id', 'revision_id', 'created_at'];

    public function record(int $messageId, string $ticketId, int $articleId): void
    {
        try {
            $this->insert([
                'message_id'  => $messageId,
                'ticket_id'   => (int) $ticketId,
                'article_id'  => $articleId,
                'revision_id' => (new KnowledgeRevisionModel())->latestIdFor($articleId),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Could not record the guide citation for message {id}: {message}', [
                'id'      => $messageId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function forArticle(int $articleId, int $limit = 50): array
    {
        return $this->db->table($this->table . ' c')
            ->select('c.id, c.ticket_id, c.revision_id, c.created_at, t.request_title, t.status')
            ->join('tickets t', 't.id = c.ticket_id', 'left')
            ->where('c.article_id', $articleId)
            ->orderBy('c.id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }

    public function countsByArticle(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        $rows = $this->db->table($this->table)
            ->select('article_id, COUNT(*) AS n')
            ->whereIn('article_id', $articleIds)
            ->groupBy('article_id')
            ->get()
            ->getResultArray();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['article_id']] = (int) $row['n'];
        }

        return $counts;
    }
}
