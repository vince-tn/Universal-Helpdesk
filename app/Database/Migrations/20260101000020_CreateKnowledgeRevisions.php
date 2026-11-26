<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateKnowledgeRevisions extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'article_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'department_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'action'          => ['type' => 'VARCHAR', 'constraint' => 10],
            'title'           => ['type' => 'VARCHAR', 'constraint' => 150],
            'keywords'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'body'            => ['type' => 'TEXT'],
            'is_active'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'changed_by'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'changed_by_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['article_id', 'id']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('knowledge_article_revisions');

        $articles = $this->db->table('knowledge_articles')
            ->select('id, department_id, title, keywords, body, is_active, created_at, updated_at')
            ->get()
            ->getResultArray();

        foreach ($articles as $article) {
            $this->db->table('knowledge_article_revisions')->insert([
                'article_id'      => (int) $article['id'],
                'department_id'   => $article['department_id'] === null ? null : (int) $article['department_id'],
                'action'          => 'created',
                'title'           => $article['title'],
                'keywords'        => $article['keywords'],
                'body'            => $article['body'],
                'is_active'       => (int) $article['is_active'],
                'changed_by'      => null,
                'changed_by_name' => 'Before history was kept',
                'created_at'      => $article['updated_at'] ?? $article['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down()
    {
        $this->forge->dropTable('knowledge_article_revisions', true);
    }
}
