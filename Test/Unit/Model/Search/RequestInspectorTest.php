<?php

declare(strict_types=1);

namespace InchooDev\OpensearchSemanticSearchElasticsuite\Test\Unit\Model\Search;

use InchooDev\OpensearchSemanticSearchElasticsuite\Model\Config;
use InchooDev\OpensearchSemanticSearchElasticsuite\Model\Search\RequestInspector;
use Magento\Framework\Search\Request as CoreRequest;
use Magento\Framework\Search\Request\Dimension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smile\ElasticsuiteCore\Search\Request;
use Smile\ElasticsuiteCore\Search\Request\Query\Boolean;
use Smile\ElasticsuiteCore\Search\Request\Query\Filtered;
use Smile\ElasticsuiteCore\Search\Request\Query\MultiMatch;
use Smile\ElasticsuiteCore\Search\Request\Query\Terms;
use Smile\ElasticsuiteCore\Search\Request\SortOrder\Standard as SortOrder;

#[CoversClass(RequestInspector::class)]
class RequestInspectorTest extends TestCase
{
    private const STORE_ID = 1;

    /**
     * @var Config&MockObject
     */
    private MockObject $config;

    /**
     * @var RequestInspector
     */
    private RequestInspector $inspector;

    /**
     * Set up the subject under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->inspector = new RequestInspector($this->config);
    }

    /**
     * The term is recovered from the fulltext query of the tree Elasticsuite builds for a search.
     *
     * @return void
     */
    public function testQueryTextIsReadFromTheFulltextQuery(): void
    {
        // The exact-match shape: container filters around a filtered pair of multi match queries.
        $query = new Filtered(
            new Filtered(
                new MultiMatch(' cosy chair ', ['search' => 1]),
                new MultiMatch('cosy chair', ['search' => 1])
            ),
            new Terms([3, 4], 'visibility')
        );

        $request = $this->createRequest('quick_search_container', $query);

        self::assertSame('cosy chair', $this->inspector->getQueryText($request));
    }

    /**
     * A spelling strategy that nests the fulltext queries in a bool is understood as well.
     *
     * @return void
     */
    public function testQueryTextIsFoundInsideBooleanClauses(): void
    {
        $query = new Filtered(new Boolean([], [new MultiMatch('cozy chiar', ['search' => 1])]));

        $request = $this->createRequest('quick_search_container', $query);

        self::assertSame('cozy chiar', $this->inspector->getQueryText($request));
    }

    /**
     * A text match that only filters is not what the shopper searched for.
     *
     * @return void
     */
    public function testTextThatOnlyFiltersIsNotASearchTerm(): void
    {
        $query = new Filtered(null, new MultiMatch('blue', ['color' => 1]));

        self::assertSame('', $this->inspector->getQueryText($this->createRequest('catalog_view_container', $query)));
    }

    /**
     * Autocomplete is expanded only while its switch is on, for the store the request is built for.
     *
     * @return void
     */
    public function testAutocompleteFollowsItsSwitch(): void
    {
        $query = new Filtered(new MultiMatch('cosy', ['search' => 1]));
        $request = $this->createRequest(RequestInspector::AUTOCOMPLETE_REQUEST_NAME, $query);

        $this->config->expects(self::exactly(2))
            ->method('isAutocompleteEnabled')
            ->with(self::STORE_ID)
            ->willReturnOnConsecutiveCalls(false, true);

        self::assertSame('', $this->inspector->getQueryText($request));
        self::assertSame('cosy', $this->inspector->getQueryText($request));
    }

    /**
     * The list request is expanded under any sort, because the count request cannot tell them apart.
     *
     * @return void
     */
    public function testRequestIsExpandableWhateverItsSort(): void
    {
        $byRelevance = $this->createRequest('quick_search_container', null, [
            new SortOrder('search_query.position', SortOrder::SORT_ASC),
            new SortOrder('_score', SortOrder::SORT_DESC),
        ]);
        $byPrice = $this->createRequest('quick_search_container', null, [
            new SortOrder('price.price', SortOrder::SORT_ASC),
            new SortOrder('entity_id', SortOrder::SORT_DESC),
        ]);
        $unsortedCount = $this->createRequest('quick_search_container');

        self::assertTrue($this->inspector->isExpandable($byRelevance));
        self::assertTrue($this->inspector->isExpandable($byPrice));
        self::assertTrue($this->inspector->isExpandable($unsortedCount));
        self::assertSame(['price.price', 'entity_id'], $this->inspector->describeSort($byPrice));
    }

    /**
     * A request Elasticsuite did not build cannot be vouched for.
     *
     * @return void
     */
    public function testForeignRequestIsNotExpandable(): void
    {
        $request = $this->createMock(CoreRequest::class);

        self::assertFalse($this->inspector->isExpandable($request));
        self::assertSame([], $this->inspector->describeSort($request));
    }

    /**
     * No category is pushed down to the vector search.
     *
     * @return void
     */
    public function testNoCategoryIsPushedDown(): void
    {
        self::assertNull($this->inspector->getCategoryId($this->createRequest('quick_search_container')));
    }

    /**
     * Build an Elasticsuite request for the test store.
     *
     * @param string $name
     * @param object|null $query
     * @param SortOrder[] $sortOrders
     * @return Request&MockObject
     */
    private function createRequest(string $name, ?object $query = null, array $sortOrders = []): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getName')->willReturn($name);
        $request->method('getQuery')->willReturn($query);
        $request->method('getSortOrders')->willReturn($sortOrders);
        $request->method('getDimensions')->willReturn(['scope' => new Dimension('scope', self::STORE_ID)]);

        return $request;
    }
}
