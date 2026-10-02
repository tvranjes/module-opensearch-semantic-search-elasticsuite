<?php

declare(strict_types=1);

namespace InchooDev\OpensearchSemanticSearchElasticsuite\Plugin\Search;

use InchooDev\OpensearchSemanticSearch\Model\Search\HybridQueryApplier;
use InchooDev\OpensearchSemanticSearchElasticsuite\Model\Search\KeywordEngine;
use InchooDev\OpensearchSemanticSearchElasticsuite\Model\Search\RequestInspector;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Mapper;
use Smile\ElasticsuiteCore\Search\RequestInterface;

/**
 * Makes the query Elasticsuite builds a hybrid one.
 *
 * Only the hook lives here. What happens to the query is HybridQueryApplier's business; it leaves
 * the query untouched for any request the gate rejects and on any failure of the semantic side.
 */
class HybridQueryPlugin
{
    /**
     * @param HybridQueryApplier $hybridQueryApplier
     * @param RequestInspector $requestInspector
     * @param KeywordEngine $keywordEngine
     */
    public function __construct(
        private readonly HybridQueryApplier $hybridQueryApplier,
        private readonly RequestInspector $requestInspector,
        private readonly KeywordEngine $keywordEngine
    ) {
    }

    /**
     * Turn the keyword query into a hybrid query.
     *
     * Elasticsuite's mapper returns the request body alone and keeps the index on the request, so
     * the two are paired up for the applier and the body is taken back out of its answer. The
     * rewritten body is executed by Elasticsuite's adapter, which reports a cluster that rejects it.
     *
     * @param Mapper $subject
     * @param array $result
     * @param RequestInterface $request
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterBuildSearchRequest(Mapper $subject, array $result, RequestInterface $request): array
    {
        $searchQuery = $this->hybridQueryApplier->apply(
            $request,
            ['index' => (string) $request->getIndex(), 'body' => $result],
            $this->requestInspector,
            $this->keywordEngine
        );

        return is_array($searchQuery['body'] ?? null) ? $searchQuery['body'] : $result;
    }
}
