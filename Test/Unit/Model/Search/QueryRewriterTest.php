<?php

declare(strict_types=1);

namespace InchooDev\OpensearchSemanticSearchElasticsuite\Test\Unit\Model\Search;

use InchooDev\OpensearchSemanticSearch\Model\Search\FusionScriptBuilder;
use InchooDev\OpensearchSemanticSearch\Model\Search\HybridResult;
use InchooDev\OpensearchSemanticSearch\Model\Search\KeywordCandidates;
use InchooDev\OpensearchSemanticSearch\Model\Search\SearchResult;
use InchooDev\OpensearchSemanticSearchElasticsuite\Model\Search\QueryRewriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryRewriter::class)]
class QueryRewriterTest extends TestCase
{
    private const FULLTEXT = ['multi_match' => ['query' => 'cosy chair', 'fields' => ['search^1']]];
    private const CONTAINER_FILTER = ['terms' => ['visibility' => [3, 4]]];
    private const POST_FILTER = ['term' => ['color' => 5]];

    /**
     * @var QueryRewriter
     */
    private QueryRewriter $rewriter;

    /**
     * Set up the subject under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rewriter = new QueryRewriter(new FusionScriptBuilder());
    }

    /**
     * Only the fulltext clause is widened; every constraint Elasticsuite built stays in force.
     *
     * @return void
     */
    public function testRecallIsWidenedInsideTheFilters(): void
    {
        $result = $this->rewriter->rewrite($this->createSearchQuery(), $this->createHybridResult(), 100);
        $recall = $result['body']['query']['script_score']['query'];

        self::assertSame(self::CONTAINER_FILTER, $recall['bool']['filter']);
        self::assertSame(1, $recall['bool']['boost']);
        self::assertSame(
            ['bool' => ['should' => [self::FULLTEXT, ['ids' => ['values' => ['30']]]], 'minimum_should_match' => 1]],
            $recall['bool']['must']
        );

        // Everything outside the query is exactly what Elasticsuite built.
        self::assertSame(self::POST_FILTER, $result['body']['post_filter']);
        self::assertSame(['brand' => ['terms' => ['field' => 'brand']]], $result['body']['aggregations']);
        self::assertSame(12, $result['body']['size']);
        self::assertSame('magento2_default_catalog_product', $result['index']);
    }

    /**
     * Candidates are ranked by entity ID, best-first.
     *
     * @return void
     */
    public function testFusedCandidatesAreRankedByEntityId(): void
    {
        $result = $this->rewriter->rewrite($this->createSearchQuery(), $this->createHybridResult(), 100);
        $script = $result['body']['query']['script_score']['script'];

        self::assertSame('entity_id', $script['params']['field']);
        self::assertSame(['20' => 3.99, '30' => 2.99, '10' => 1.99], (array) $script['params']['ranks']);
    }

    /**
     * The cap on injected products applies to the semantic-only ones.
     *
     * @return void
     */
    public function testInjectedIdsAreCapped(): void
    {
        $hybridResult = new HybridResult(
            [1 => 0.9, 2 => 0.8, 3 => 0.7],
            [new SearchResult(1), new SearchResult(2), new SearchResult(3)],
            new KeywordCandidates(),
            []
        );

        $result = $this->rewriter->rewrite($this->createSearchQuery(), $hybridResult, 2);

        self::assertSame(
            ['1', '2'],
            $result['body']['query']['script_score']['query']['bool']['must']['bool']['should'][1]['ids']['values']
        );
    }

    /**
     * When every semantic candidate is a keyword match too, the query is re-ranked but not widened.
     *
     * @return void
     */
    public function testNothingToInjectLeavesRecallAlone(): void
    {
        $searchQuery = $this->createSearchQuery();
        $hybridResult = new HybridResult(
            [10 => 0.9],
            [new SearchResult(10)],
            new KeywordCandidates([10], [10 => 'sku-10'], 1.0),
            []
        );

        $result = $this->rewriter->rewrite($searchQuery, $hybridResult, 100);

        self::assertSame($searchQuery['body']['query'], $result['body']['query']['script_score']['query']);
    }

    /**
     * A root query of another shape is re-ranked without adding recall around filters unseen.
     *
     * @return void
     */
    public function testUnexpectedRootQueryIsNotWidened(): void
    {
        $searchQuery = $this->createSearchQuery();
        $searchQuery['body']['query'] = ['function_score' => ['query' => self::FULLTEXT]];

        $result = $this->rewriter->rewrite($searchQuery, $this->createHybridResult(), 100);

        self::assertSame(
            ['function_score' => ['query' => self::FULLTEXT]],
            $result['body']['query']['script_score']['query']
        );
    }

    /**
     * A list of must clauses is kept as the AND it is.
     *
     * @return void
     */
    public function testMustListStaysAConjunction(): void
    {
        $searchQuery = $this->createSearchQuery();
        $searchQuery['body']['query']['bool']['must'] = [self::FULLTEXT, self::POST_FILTER];

        $result = $this->rewriter->rewrite($searchQuery, $this->createHybridResult(), 100);

        self::assertSame(
            ['bool' => ['must' => [self::FULLTEXT, self::POST_FILTER]]],
            $result['body']['query']['script_score']['query']['bool']['must']['bool']['should'][0]
        );
    }

    /**
     * With nothing fused there is nothing to rewrite.
     *
     * @return void
     */
    public function testEmptyResultLeavesTheQueryUntouched(): void
    {
        $searchQuery = $this->createSearchQuery();

        self::assertSame(
            $searchQuery,
            $this->rewriter->rewrite($searchQuery, new HybridResult([], [], new KeywordCandidates(), []), 100)
        );
    }

    /**
     * The query Elasticsuite builds for a search results page.
     *
     * @return array<string,mixed>
     */
    private function createSearchQuery(): array
    {
        return [
            'index' => 'magento2_default_catalog_product',
            'body' => [
                'size' => 12,
                'from' => 0,
                'sort' => [['_score' => ['order' => 'desc']], ['entity_id' => ['order' => 'desc']]],
                'query' => ['bool' => ['filter' => self::CONTAINER_FILTER, 'must' => self::FULLTEXT, 'boost' => 1]],
                'post_filter' => self::POST_FILTER,
                'aggregations' => ['brand' => ['terms' => ['field' => 'brand']]],
            ],
        ];
    }

    /**
     * Keyword found 10 and 20, the vector search found 20 and 30; fused best-first: 20, 30, 10.
     *
     * @return HybridResult
     */
    private function createHybridResult(): HybridResult
    {
        return new HybridResult(
            [20 => 0.9, 30 => 0.5, 10 => 0.4],
            [new SearchResult(20), new SearchResult(30)],
            new KeywordCandidates([10, 20], [10 => 'sku-10', 20 => 'sku-20'], 1.0),
            []
        );
    }
}
