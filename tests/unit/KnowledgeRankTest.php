<?php

use App\Libraries\Embeddings;
use App\Libraries\KnowledgeBase;
use CodeIgniter\Test\CIUnitTestCase;

final class KnowledgeRankTest extends CIUnitTestCase
{
    private function article(int $id, string $title, array $vector): array
    {
        return [
            'id' => $id, 'department_id' => 2, 'title' => $title, 'keywords' => '', 'body' => 'Steps.',
            'embedding' => '[]', 'embedding_model' => 'test', 'embedding_hash' => 'x', 'vector' => $vector,
        ];
    }

    public function testCosineMeasuresDirection(): void
    {
        $this->assertEqualsWithDelta(1.0, Embeddings::cosine([1, 2, 3], [2, 4, 6]), 1e-9);
        $this->assertEqualsWithDelta(-1.0, Embeddings::cosine([1, 0], [-1, 0]), 1e-9);
        $this->assertSame(0.0, Embeddings::cosine([], [1, 2]));
        $this->assertSame(0.0, Embeddings::cosine([0, 0], [1, 2]));
    }

    public function testClosestMeaningComesFirstAndUnrelatedGuidesAreLeftOut(): void
    {
        $ranked = KnowledgeBase::rank([
            $this->article(1, 'Printer', [0.0, 1.0, 0.0]),
            $this->article(2, 'Network', [0.9, 0.1, 0.0]),
            $this->article(3, 'VPN', [0.85, 0.3, 0.0]),
        ], [1.0, 0.0, 0.0], []);

        $this->assertSame(['Network', 'VPN'], array_column($ranked, 'title'));
    }

    public function testOnlyGuidesCloseToTheBestMatchAreKept(): void
    {
        $ranked = KnowledgeBase::rank([
            $this->article(1, 'Close', [1.0, 0.05, 0.0]),
            $this->article(2, 'Loose', [0.6, 0.8, 0.0]),
        ], [1.0, 0.0, 0.0], []);

        $this->assertSame(['Close'], array_column($ranked, 'title'));
    }

    public function testMatchingWordsBreakATie(): void
    {
        $ranked = KnowledgeBase::rank([
            $this->article(1, 'First', [1.0, 0.2, 0.0]),
            $this->article(2, 'Second', [1.0, 0.2, 0.0]),
        ], [1.0, 0.0, 0.0], [2 => 8.5, 1 => 7.0]);

        $this->assertSame(['Second', 'First'], array_column($ranked, 'title'));
    }

    public function testNothingRelatedMeansNoGuides(): void
    {
        $this->assertSame([], KnowledgeBase::rank([$this->article(1, 'Printer', [0.0, 1.0, 0.0])], [1.0, 0.0, 0.0], []));
    }

    public function testResultsAreLimitedAndCarryNoInternalFields(): void
    {
        $articles = [];
        for ($i = 1; $i <= 5; $i++) {
            $articles[] = $this->article($i, 'Guide ' . $i, [1.0, 0.01 * $i, 0.0]);
        }

        $ranked = KnowledgeBase::rank($articles, [1.0, 0.0, 0.0], [], 3);

        $this->assertCount(3, $ranked);
        foreach ($ranked as $row) {
            $this->assertArrayNotHasKey('vector', $row);
            $this->assertArrayNotHasKey('embedding', $row);
            $this->assertArrayNotHasKey('embedding_hash', $row);
            $this->assertArrayHasKey('score', $row);
        }
    }

    public function testQueriesAndGuidesAreWrittenTheWayTheModelExpects(): void
    {
        $qwen  = new Embeddings('http://ollama:11434', 'qwen3-embedding:0.6b');
        $other = new Embeddings('http://ollama:11434', 'nomic-embed-text');

        $this->assertStringStartsWith('Instruct: ', $qwen->query('my vpn keeps dropping'));
        $this->assertStringEndsWith("Query: my vpn keeps dropping", $qwen->query('  my vpn keeps dropping  '));
        $this->assertSame('my vpn keeps dropping', $other->query('my vpn keeps dropping'));
        $this->assertSame("VPN keeps disconnecting\nvpn, forticlient\nReconnect.", Embeddings::document([
            'title' => 'VPN keeps disconnecting', 'keywords' => 'vpn, forticlient', 'body' => 'Reconnect.',
        ]));
        $this->assertSame('Title only', Embeddings::document(['title' => 'Title only', 'keywords' => null, 'body' => '']));
    }
}
