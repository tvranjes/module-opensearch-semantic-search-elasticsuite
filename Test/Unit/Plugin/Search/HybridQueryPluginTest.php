<?php

declare(strict_types=1);

namespace InchooDev\OpensearchSemanticSearchElasticsuite\Test\Unit\Plugin\Search;

use InchooDev\OpensearchSemanticSearch\Model\Search\HybridQueryApplier;
use InchooDev\OpensearchSemanticSearchElasticsuite\Model\Search\KeywordEngine;
use InchooDev\OpensearchSemanticSearchElasticsuite\Model\Search\RequestInspector;
use InchooDev\OpensearchSemanticSearchElasticsuite\Plugin\Search\HybridQueryPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Mapper;
use Smile\ElasticsuiteCore\Search\Request;

#[CoversClass(HybridQueryPlugin::class)]
class HybridQueryPluginTest extends TestCase
{
    private const INDEX = 'magento2_default_catalog_product';
    private const BODY = ['size' => 12, 'query' => ['match_all' => []]];

    /**
     * @var HybridQueryApplier&MockObject
     */
    private MockObject $applier;

    /**
     * @var RequestInspector&MockObject
     */
    private MockObject $inspector;

    /**
     * @var KeywordEngine&MockObject
     */
    private MockObject $keywordEngine;

    /**
     * @var Request&MockObject
     */
    private MockObject $request;

    /**
     * @var HybridQueryPlugin
     */
    private HybridQueryPlugin $plugin;

    /**
     * Set up the subject under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->applier = $this->createMock(HybridQueryApplier::class);
        $this->inspector = $this->createMock(RequestInspector::class);
        $this->keywordEngine = $this->createMock(KeywordEngine::class);
        $this->request = $this->createMock(Request::class);
        $this->request->method('getIndex')->willReturn(self::INDEX);

        $this->plugin = new HybridQueryPlugin($this->applier, $this->inspector, $this->keywordEngine);
    }

    /**
     * The body is paired with the request's index for the applier and its body is handed back.
     *
     * @return void
     */
    public function testBodyGoesThroughTheApplierPairedWithItsIndex(): void
    {
        $rewrittenBody = ['size' => 12, 'query' => ['script_score' => []]];

        $this->applier->expects(self::once())
            ->method('apply')
            ->with(
                $this->request,
                ['index' => self::INDEX, 'body' => self::BODY],
                $this->inspector,
                $this->keywordEngine
            )
            ->willReturn(['index' => self::INDEX, 'body' => $rewrittenBody]);

        self::assertSame(
            $rewrittenBody,
            $this->plugin->afterBuildSearchRequest($this->createMock(Mapper::class), self::BODY, $this->request)
        );
    }

    /**
     * An answer without a body can only be a defect; Elasticsuite's own body is kept.
     *
     * @return void
     */
    public function testMalformedAnswerKeepsTheOriginalBody(): void
    {
        $this->applier->method('apply')->willReturn([]);

        self::assertSame(
            self::BODY,
            $this->plugin->afterBuildSearchRequest($this->createMock(Mapper::class), self::BODY, $this->request)
        );
    }
}
