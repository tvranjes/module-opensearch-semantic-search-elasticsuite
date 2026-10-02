<?php

declare(strict_types=1);

namespace InchooDev\OpensearchSemanticSearchElasticsuite\Model\Search;

use InchooDev\OpensearchSemanticSearch\Api\KeywordEngineInterface;
use InchooDev\OpensearchSemanticSearch\Model\Search\CandidateQueryRunner;
use InchooDev\OpensearchSemanticSearch\Model\Search\HybridResult;
use InchooDev\OpensearchSemanticSearch\Model\Search\KeywordCandidates;
use InchooDev\OpensearchSemanticSearch\Model\Search\ProcessingFlag;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Mapper;
use Smile\ElasticsuiteCore\Search\Request\Builder as RequestBuilder;

/**
 * Keyword side of the Elasticsuite engine.
 *
 * The keyword ranking that takes part in fusion is Elasticsuite's own query - spellchecking,
 * field weights, optimizers and all - executed with a candidate sized window instead of the
 * storefront page size.
 */
class KeywordEngine implements KeywordEngineInterface
{
    private const SEARCH_REQUEST_NAME = 'quick_search_container';

    /**
     * @param RequestBuilder $requestBuilder
     * @param Mapper $mapper
     * @param ProcessingFlag $processingFlag
     * @param CandidateQueryRunner $candidateQueryRunner
     * @param QueryRewriter $queryRewriter
     */
    public function __construct(
        private readonly RequestBuilder $requestBuilder,
        private readonly Mapper $mapper,
        private readonly ProcessingFlag $processingFlag,
        private readonly CandidateQueryRunner $candidateQueryRunner,
        private readonly QueryRewriter $queryRewriter
    ) {
    }

    /**
     * Execute an already built Elasticsuite query with a candidate sized window.
     *
     * The post filter stays: it carries the shopper's layered navigation choices, and candidates
     * outside them would take fusion slots from products the result page can actually show.
     *
     * @param array<string,mixed> $searchQuery
     * @param int $size
     * @param int $storeId
     * @return KeywordCandidates
     */
    public function getCandidates(array $searchQuery, int $size, int $storeId): KeywordCandidates
    {
        $body = $searchQuery['body'] ?? [];
        unset($body['aggregations'], $body['highlight'], $body['collapse']);

        $body['from'] = 0;
        $body['size'] = max(1, $size);
        $body['sort'] = ['_score' => 'desc'];
        $body['_source'] = ['sku'];
        $body['track_total_hits'] = false;

        return $this->candidateQueryRunner->run((string) ($searchQuery['index'] ?? ''), $body, $storeId);
    }

    /**
     * Build and execute Elasticsuite's search results query for a query string.
     *
     * The container already filters on visibility, so nothing has to be added to the query.
     *
     * @param string $queryText
     * @param int $storeId
     * @param int $size
     * @return KeywordCandidates
     */
    public function getCandidatesForText(string $queryText, int $storeId, int $size): KeywordCandidates
    {
        $wasProcessing = $this->processingFlag->isProcessing();
        // The mapper below is the one the hybrid plugin listens on; this query must stay keyword only.
        $this->processingFlag->setProcessing(true);

        try {
            $request = $this->requestBuilder->create(
                $storeId,
                self::SEARCH_REQUEST_NAME,
                0,
                max(1, $size),
                $queryText
            );

            $searchQuery = [
                'index' => (string) $request->getIndex(),
                'body' => $this->mapper->buildSearchRequest($request),
            ];
        } finally {
            $this->processingFlag->setProcessing($wasProcessing);
        }

        return $this->getCandidates($searchQuery, $size, $storeId);
    }

    /**
     * Produce the hybrid version of an already built Elasticsuite query.
     *
     * @param array<string,mixed> $searchQuery
     * @param HybridResult $hybridResult
     * @param int $maxInjectedIds
     * @return array<string,mixed>
     */
    public function rewrite(array $searchQuery, HybridResult $hybridResult, int $maxInjectedIds): array
    {
        return $this->queryRewriter->rewrite($searchQuery, $hybridResult, $maxInjectedIds);
    }
}
