# InchooDev_OpensearchSemanticSearchElasticsuite

Elasticsuite integration for
[InchooDev_OpensearchSemanticSearch](../OpensearchSemanticSearch/README.md): the same hybrid
keyword + semantic ranking, applied to the queries Elasticsuite builds.

Install it only on stores that run Elasticsuite. The base module does not depend on it and keeps
working with Magento's own OpenSearch engine without it.

## Requirements

| | |
|---|---|
| Base module | `inchoo-dev/module-opensearch-semantic-search`, set up as its README describes |
| Elasticsuite | `smile/elasticsuite` 2.12 (developed and verified on 2.12.2), selected as the search engine |
| Cluster | OpenSearch with the plugins and the deployed model the base module needs. Elasticsuite on Elasticsearch is not supported: the vector index needs ml-commons and neural search |

## What it does

- **Search results page** (`quick_search_container`): Elasticsuite's query is rewritten into its
  hybrid form by a plugin on `Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Mapper`.
  Anything else that searches through that container goes through the same plugin, which includes
  Elasticsuite's GraphQL product search; that path has not been exercised yet.
- **Search box product suggestions** (`catalog_product_autocomplete`): the same, switchable under
  *Stores > Configuration > Catalog > OpenSearch Semantic Search > Elasticsuite > Semantic Ranking
  In Autocomplete*. Each suggestion request costs one model inference and one candidate query.
- **Cluster connection**: the vector index lives on the cluster Elasticsuite is configured for,
  under Elasticsuite's alias prefix (`<alias>_neural_<storeId>`). Nothing is configured twice.
- **Toolbar fallback**: tells the base module that "best match first" is the `asc` direction in
  Elasticsuite.

Category pages, category search, widgets and the tracker are not touched.

## How the query is rewritten

Elasticsuite's root query is a `bool` whose `must` is the fulltext query and whose `filter` holds
the container filters.

- Only `must` is widened to "fulltext match OR semantic candidate". The container filters, the
  post filter (layered navigation), aggregations, sort and page window stay as Elasticsuite built
  them and apply to the semantic candidates too.
- The whole query is wrapped in a `script_score` that ranks the fused candidates by their fusion
  rank, looked up by the integer `entity_id` doc value. Fulltext matches outside the candidate
  window keep their order in a score band below them.
- A root query of any other shape is re-ranked but not widened.

Any failure on the semantic side logs an error and leaves Elasticsuite's query untouched.

## Behaviour to know about

- **The result set is the same under every sort.** Elasticsuite computes totals and facets with a
  separate request that carries no sort, so the module cannot leave only the sorted list alone, the
  way it does with Magento's own engine. Sorting by price or name therefore lists keyword matches
  and semantic candidates together. How wide that set is depends on *Semantic Candidates* and
  *Minimum Semantic Score* in the base module's configuration.
- **Optimizers** shape the ranking through the keyword candidate list they ordered. A product only
  the vector search found receives no optimizer boost.
- **Products pinned to a search term** still come first: Elasticsuite's position sort stays in
  front of the score. Blacklisted products stay excluded, since that is a container filter.
- **One inference per page.** Elasticsuite sends several requests for one page; they share a single
  vector search.
- **No category push-down.** The vector search is store-wide; Elasticsuite's own category filter is
  applied by the rewritten query.
- **Index prefix.** If Elasticsuite's alias differs from `catalog/search/opensearch_index_prefix`,
  the vector index gets a new name when the engine is switched. Run
  `bin/magento indexer:reindex inchoodev_opensearch_semantic_embedding` after switching.

## Installation

```bash
bin/magento module:enable InchooDev_OpensearchSemanticSearchElasticsuite
bin/magento setup:upgrade
bin/magento opensearch-semantic-search:status   # shows the vector index the module resolved
bin/magento opensearch-semantic-search:test "something soft to sit on"
```

## Tests

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist \
    app/code/InchooDev/OpensearchSemanticSearchElasticsuite/Test/Unit
```
