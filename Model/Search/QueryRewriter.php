<?php

declare(strict_types=1);

namespace InchooDev\OpensearchSemanticSearchElasticsuite\Model\Search;

use InchooDev\OpensearchSemanticSearch\Model\Search\FusionScriptBuilder;
use InchooDev\OpensearchSemanticSearch\Model\Search\HybridResult;

/**
 * Rewrites Elasticsuite's search query into its hybrid form.
 *
 * Elasticsuite's root query is a bool whose "must" is the fulltext query (wrapped in a
 * function_score when optimizers apply) and whose "filter" holds the container filters. Two things
 * change and nothing else:
 *  - recall becomes "fulltext match OR semantic candidate": only "must" is widened, so the
 *    container filters, the post filter, the aggregations, the sort and the page window stay as
 *    Elasticsuite built them and apply to the semantic candidates too;
 *  - relevance ordering of the fused candidates is replaced by their fusion rank, and fulltext
 *    matches outside the candidate window keep their order in a score band below them.
 *
 * Optimizer boosts therefore still shape the ranking, through the keyword candidate list they
 * ordered, but a product only the vector search found receives none.
 *
 * Candidates are looked up by entity ID, which Elasticsuite indexes as an integer doc value.
 */
class QueryRewriter
{
    private const ENTITY_ID_DOC_FIELD = 'entity_id';

    /**
     * @param FusionScriptBuilder $fusionScriptBuilder
     */
    public function __construct(private readonly FusionScriptBuilder $fusionScriptBuilder)
    {
    }

    /**
     * Produce the hybrid version of an already built search query.
     *
     * @param array<string,mixed> $searchQuery
     * @param HybridResult $hybridResult
     * @param int $maxInjectedIds
     * @return array<string,mixed>
     */
    public function rewrite(array $searchQuery, HybridResult $hybridResult, int $maxInjectedIds): array
    {
        $originalQuery = $searchQuery['body']['query'] ?? null;
        if (!is_array($originalQuery) || $hybridResult->isEmpty()) {
            return $searchQuery;
        }

        $joinKeys = [];
        foreach ($hybridResult->getFusedIds() as $entityId) {
            $joinKeys[$entityId] = (string) $entityId;
        }

        $searchQuery['body']['query'] = [
            'script_score' => [
                'query' => $this->buildRecallQuery(
                    $originalQuery,
                    $hybridResult->getSemanticOnlyIds($maxInjectedIds)
                ),
                'script' => $this->fusionScriptBuilder->build($hybridResult, self::ENTITY_ID_DOC_FIELD, $joinKeys),
            ],
        ];

        return $searchQuery;
    }

    /**
     * Union of the fulltext query and the semantic candidates, with every filter preserved.
     *
     * A root query that is not the bool Elasticsuite emits is left exactly as it is. Its filters
     * could be anywhere inside it, and adding recall next to a filter that cannot be seen would let
     * semantic candidates around it; such a query is still re-ranked, just not widened.
     *
     * @param array<string,mixed> $originalQuery
     * @param string[] $semanticOnlyIds
     * @return array<string,mixed>
     */
    private function buildRecallQuery(array $originalQuery, array $semanticOnlyIds): array
    {
        $bool = $originalQuery['bool'] ?? null;

        if ($semanticOnlyIds === [] || !is_array($bool) || empty($bool['must']) || !is_array($bool['must'])) {
            return $originalQuery;
        }

        // Elasticsuite emits a single clause here; a list of clauses is an AND of them.
        $fulltextQuery = array_is_list($bool['must']) ? ['bool' => ['must' => $bool['must']]] : $bool['must'];

        $bool['must'] = [
            'bool' => [
                'should' => [$fulltextQuery, ['ids' => ['values' => $semanticOnlyIds]]],
                'minimum_should_match' => 1,
            ],
        ];

        return ['bool' => $bool];
    }
}
