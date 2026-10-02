<?php

declare(strict_types=1);

namespace InchooDev\OpensearchSemanticSearchElasticsuite\Model\Search;

use InchooDev\OpensearchSemanticSearch\Api\RequestInspectorInterface;
use InchooDev\OpensearchSemanticSearchElasticsuite\Model\Config;
use Magento\Framework\Search\Request\Dimension;
use Magento\Framework\Search\RequestInterface;
use Smile\ElasticsuiteCore\Search\RequestInterface as ElasticsuiteRequestInterface;

/**
 * Reads the requests Elasticsuite builds from elasticsuite_search_request.xml.
 */
class RequestInspector implements RequestInspectorInterface
{
    public const AUTOCOMPLETE_REQUEST_NAME = 'catalog_product_autocomplete';

    private const SCOPE_DIMENSION = 'scope';

    private const MAX_DEPTH = 10;

    /**
     * Accessors that lead from a query to the queries that decide what matches and how it scores.
     *
     * "getFilter" is deliberately absent: filters narrow the result, and a text match among them
     * is a layered navigation choice, not what the shopper typed into the search box.
     */
    private const SCORING_CHILD_ACCESSORS = ['getQuery', 'getMust', 'getShould'];

    /**
     * @param Config $config
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * The shopper's search term, or an empty string when the request is not to be expanded.
     *
     * Elasticsuite keeps no copy of the raw term on the request; it only survives inside the
     * fulltext queries built from it, whichever spelling strategy shaped them.
     *
     * @param RequestInterface $request
     * @return string
     */
    public function getQueryText(RequestInterface $request): string
    {
        if ($request->getName() === self::AUTOCOMPLETE_REQUEST_NAME
            && !$this->config->isAutocompleteEnabled($this->resolveStoreId($request))
        ) {
            return '';
        }

        return $this->findQueryText($request->getQuery(), 0);
    }

    /**
     * Every request Elasticsuite built is expanded, whatever its sort.
     *
     * Elasticsuite counts results and builds facets with a separate request that carries no sort
     * at all. Skipping only the sorted list request, the way core's engine can, would put the
     * widened total and facets above a keyword-only list. So the result set is the same under
     * every sort, and the fused ranking simply goes unused when the shopper sorts by something
     * else.
     *
     * @param RequestInterface $request
     * @return bool
     */
    public function isExpandable(RequestInterface $request): bool
    {
        return $request instanceof ElasticsuiteRequestInterface;
    }

    /**
     * Field names the request is sorted by, for diagnostics.
     *
     * @param RequestInterface $request
     * @return string[]
     */
    public function describeSort(RequestInterface $request): array
    {
        if (!$request instanceof ElasticsuiteRequestInterface) {
            return [];
        }

        return array_values(array_map(
            static fn ($sortOrder): string => (string) $sortOrder->getField(),
            (array) $request->getSortOrders()
        ));
    }

    /**
     * No category is pushed down to the vector search.
     *
     * Elasticsuite can define a category by a rule instead of a product list (virtual categories),
     * and the vector index only knows plain category IDs, so a pushed down category could wrongly
     * exclude products. The rewritten query still applies Elasticsuite's own category filter, so
     * results stay correct; the vector search just spends its k slots store-wide.
     *
     * @param RequestInterface $request
     * @return int|null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getCategoryId(
        // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- required by the contract
        RequestInterface $request
    ): ?int {
        return null;
    }

    /**
     * Depth limited search for the first fulltext query in the scoring part of the tree.
     *
     * Query types are recognised by their accessors rather than by class, so a custom query type
     * that wraps or carries the term the same way is understood too.
     *
     * @param mixed $query
     * @param int $depth
     * @return string
     */
    private function findQueryText(mixed $query, int $depth): string
    {
        if ($depth > self::MAX_DEPTH || !is_object($query)) {
            return '';
        }

        if (method_exists($query, 'getQueryText')) {
            $queryText = $query->getQueryText();

            if (is_string($queryText) && trim($queryText) !== '') {
                return trim($queryText);
            }
        }

        foreach (self::SCORING_CHILD_ACCESSORS as $accessor) {
            if (!method_exists($query, $accessor)) {
                continue;
            }

            $children = $query->$accessor();

            foreach (is_array($children) ? $children : [$children] as $child) {
                $queryText = $this->findQueryText($child, $depth + 1);

                if ($queryText !== '') {
                    return $queryText;
                }
            }
        }

        return '';
    }

    /**
     * Store the request was built for, or null to fall back to the current store.
     *
     * @param RequestInterface $request
     * @return int|null
     */
    private function resolveStoreId(RequestInterface $request): ?int
    {
        $scope = $request->getDimensions()[self::SCOPE_DIMENSION] ?? null;

        return $scope instanceof Dimension && (int) $scope->getValue() > 0 ? (int) $scope->getValue() : null;
    }
}
