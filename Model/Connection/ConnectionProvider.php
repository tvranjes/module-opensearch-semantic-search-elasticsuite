<?php

declare(strict_types=1);

namespace InchooDev\OpensearchSemanticSearchElasticsuite\Model\Connection;

use InchooDev\OpensearchSemanticSearch\Api\ConnectionProviderInterface;
use OpenSearch\Client;
use Smile\ElasticsuiteCore\Api\Client\ClientConfigurationInterface;
use Smile\ElasticsuiteCore\Client\ClientBuilder;
use Smile\ElasticsuiteCore\Helper\IndexSettings;

/**
 * Cluster of the Elasticsuite engine, read from Elasticsuite's own client and index settings.
 *
 * A store running Elasticsuite configures its cluster under Stores > Configuration > Elasticsuite
 * and nowhere else; core's catalog/search/opensearch_* fields are hidden there and usually still
 * point at localhost. The vector index therefore goes where Elasticsuite's product index is, under
 * the same alias prefix.
 */
class ConnectionProvider implements ConnectionProviderInterface
{
    /**
     * @param ClientBuilder $clientBuilder
     * @param ClientConfigurationInterface $clientConfiguration
     * @param IndexSettings $indexSettings
     */
    public function __construct(
        private readonly ClientBuilder $clientBuilder,
        private readonly ClientConfigurationInterface $clientConfiguration,
        private readonly IndexSettings $indexSettings
    ) {
    }

    /**
     * Build a new client from Elasticsuite's client settings.
     *
     * @return Client
     */
    public function createClient(): Client
    {
        // Elasticsuite's builder, not its client: the builder returns a fresh low level client with
        // hosts, credentials and TLS settings applied, while Elasticsuite's Client keeps the one it
        // searches with private.
        return $this->clientBuilder->build($this->clientConfiguration->getOptions());
    }

    /**
     * Alias prefix Elasticsuite gives its own indices.
     *
     * @return string
     */
    public function getIndexPrefix(): string
    {
        return (string) $this->indexSettings->getIndexAlias();
    }
}
