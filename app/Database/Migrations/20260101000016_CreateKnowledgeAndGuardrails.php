<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateKnowledgeAndGuardrails extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'department_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'title'         => ['type' => 'VARCHAR', 'constraint' => 150],
            'keywords'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'body'          => ['type' => 'TEXT'],
            'is_active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('department_id');
        $this->forge->createTable('knowledge_articles');

        if ($this->isMySql()) {
            $this->db->query('ALTER TABLE knowledge_articles ADD FULLTEXT INDEX ft_knowledge (title, keywords, body)');
        }

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'department_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'kind'          => ['type' => 'VARCHAR', 'constraint' => 20],
            'value'         => ['type' => 'VARCHAR', 'constraint' => 300],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['department_id', 'kind']);
        $this->forge->createTable('guardrails');

        $now = date('Y-m-d H:i:s');

        foreach ($this->db->table('departments')->get()->getResultArray() as $department) {
            $context = trim((string) ($department['ai_context'] ?? ''));

            if ($context !== '') {
                $this->db->table('knowledge_articles')->insert([
                    'department_id' => (int) $department['id'],
                    'title'         => mb_substr($department['name'] . ' notes', 0, 150),
                    'body'          => $context,
                    'is_active'     => 1,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }

            foreach (preg_split('/\R/', (string) ($department['ai_guardrails'] ?? '')) as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $this->db->table('guardrails')->insert([
                    'department_id' => (int) $department['id'],
                    'kind'          => 'instruction',
                    'value'         => mb_substr($line, 0, 300),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }

        $this->forge->dropColumn('departments', ['ai_context', 'ai_guardrails']);
    }

    public function down()
    {
        $this->forge->addColumn('departments', [
            'ai_context'    => ['type' => 'TEXT', 'null' => true, 'after' => 'name'],
            'ai_guardrails' => ['type' => 'TEXT', 'null' => true, 'after' => 'ai_context'],
        ]);

        foreach ($this->db->table('departments')->get()->getResultArray() as $department) {
            $id = (int) $department['id'];

            $articles = $this->db->table('knowledge_articles')->where('department_id', $id)->get()->getResultArray();
            $rules    = $this->db->table('guardrails')->where('department_id', $id)->where('kind', 'instruction')->get()->getResultArray();

            $context = count($articles) === 1
                ? $articles[0]['body']
                : implode("\n\n", array_map(static fn ($a) => $a['title'] . "\n" . $a['body'], $articles));

            $this->db->table('departments')->where('id', $id)->update([
                'ai_context'    => $context ?: null,
                'ai_guardrails' => implode("\n", array_column($rules, 'value')) ?: null,
            ]);
        }

        $this->forge->dropTable('guardrails', true);
        $this->forge->dropTable('knowledge_articles', true);
    }

    private function isMySql(): bool
    {
        return str_contains(strtolower($this->db->DBDriver), 'mysql');
    }
}
