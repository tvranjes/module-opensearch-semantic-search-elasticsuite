<?php

declare(strict_types=1);

namespace InchooDev\OpensearchSemanticSearchElasticsuite\Test\Unit\Model\Search;

use InchooDev\OpensearchSemanticSearch\Model\Search\CandidateQueryRunner;
use InchooDev\OpensearchSemanticSearch\Model\Search\FusionScriptBuilder;
use InchooDev\OpensearchSemanticSearch\Model\Search\KeywordCandidates;
use InchooDev\OpensearchSemanticSearch\Model\Search\ProcessingFlag;
use InchooDev\OpensearchSemanticSearchElasticsuite\Model\Search\KeywordEngine;
use InchooDev\OpensearchSemanticSearchElasticsuite\Model\Search\QueryRewriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Mapper;
use Smile\ElasticsuiteCore\Search\Request;
use Smile\ElasticsuiteCore\Search\Request\Builder as RequestBuilder;

#[CoversClass(KeywordEngine::class)]
class KeywordEngineTest extends TestCase
{
    private const STORE_ID = 1;
    private const INDEX = 'magento2_default_catalog_product';

    /**
     * @var RequestBuilder&MockObject
     */
    private MockObject $requestBuilder;

    /**
     * @var Mapper&MockObject
     */
    private MockObject $mapper;

    /**
     * @var CandidateQueryRunner&MockObject
     */
    private MockObject $candidateQueryRunner;

    /**
     * @var ProcessingFlag
     */
    private ProcessingFlag $processingFlag;

    /**
     * @var KeywordEngine
     */
    private KeywordEngine $engine;

    /**
     * Set up the subject under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->requestBuilder = $this->createMock(RequestBuilder::class);
        $this->mapper = $this->createMock(Mapper::class);
        $this->candidateQueryRunner = $this->createMock(CandidateQueryRunner::class);
        $this->processingFlag = new ProcessingFlag();

        $this->engine = new KeywordEngine(
            $this->requestBuilder,
            $this->mapper,
            $this->processingFlag,
            $this->candidateQueryRunner,
            new QueryRewriter(new FusionScriptBuilder())
        );
    }

    /**
     * The built query runs as a score ordered candidate window, keeping what narrows the result.
     *
     * @return void
     */
    public function testBuiltQueryBecomesACandidateWindow(): void
    {
        $query = ['bool' => ['must' => ['multi_match' => ['query' => 'cosy chair']]]];
        $postFilter = ['term' => ['color' => 5]];
        $candidates = new KeywordCandidates([7], [7 => 'sku-7'], 1.0);

        $this->candidateQueryRunner->expects(self::once())
            ->method('run')
            ->with(
                self::INDEX,
                [
                    'size' => 50,
                    'sort' => ['_score' => 'desc'],
                    'from' => 0,
                    'query' => $query,
                    'post_filter' => $postFilter,
                    'track_total_hits' => false,
                    '_source' => ['sku'],
                ],
                self::STORE_ID
            )
            ->willReturn($candidates);

        $result = $this->engine->getCandidates(
            [
                'index' => self::INDEX,
                'body' => [
                    'size' => 12,
                    'sort' => [['price.price' => ['order' => 'asc']]],
                    'from' => 24,
                    'query' => $query,
                    'post_filter' => $postFilter,
                    'aggregations' => ['brand' => []],
                    'track_total_hits' => true,
                    'highlight' => ['fields' => []],
                    'collapse' => ['field' => 'parent_id'],
                ],
            ],
            50,
            self::STORE_ID
        );

        self::assertSame($candidates, $result);
    }

    /**
     * A plain query string is searched for with Elasticsuite's own search results request.
     *
     * @return void
     */
    public function testQueryTextIsSearchedThroughElasticsuitesRequest(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getIndex')->willReturn(self::INDEX);

        $this->requestBuilder->expects(self::once())
            ->method('create')
            ->with(self::STORE_ID, 'quick_search_container', 0, 50, 'cosy chair')
            ->willReturn($request);

        // The mapper is the one the hybrid plugin listens on, so the pass has to be flagged.
        $this->mapper->expects(self::once())
            ->method('buildSearchRequest')
            ->with($request)
            ->willReturnCallback(function (): array {
                self::assertTrue($this->processingFlag->isProcessing());

                return ['size' => 50, 'query' => ['match_all' => []]];
            });

        $this->candidateQueryRunner->expects(self::once())
            ->method('run')
            ->with(self::INDEX, self::callback(static fn (array $body): bool => $body['size'] === 50), self::STORE_ID)
            ->willReturn(new KeywordCandidates());

        $this->engine->getCandidatesForText('cosy chair', self::STORE_ID, 50);

        self::assertFalse($this->processingFlag->isProcessing());
    }
}
