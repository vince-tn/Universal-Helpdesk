<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddArticleEmbeddings extends Migration
{
    public function up()
    {
        $this->forge->addColumn('knowledge_articles', [
            'embedding'       => ['type' => 'MEDIUMTEXT', 'null' => true, 'after' => 'body'],
            'embedding_model' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'embedding'],
            'embedding_hash'  => ['type' => 'CHAR', 'constraint' => 40, 'null' => true, 'after' => 'embedding_model'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('knowledge_articles', ['embedding', 'embedding_model', 'embedding_hash']);
    }
}
