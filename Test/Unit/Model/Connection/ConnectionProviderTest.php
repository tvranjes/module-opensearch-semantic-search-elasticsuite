<?php

declare(strict_types=1);

namespace InchooDev\OpensearchSemanticSearchElasticsuite\Test\Unit\Model\Connection;

use InchooDev\OpensearchSemanticSearchElasticsuite\Model\Connection\ConnectionProvider;
use OpenSearch\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Smile\ElasticsuiteCore\Api\Client\ClientConfigurationInterface;
use Smile\ElasticsuiteCore\Client\ClientBuilder;
use Smile\ElasticsuiteCore\Helper\IndexSettings;

#[CoversClass(ConnectionProvider::class)]
class ConnectionProviderTest extends TestCase
{
    /**
     * The client is built by Elasticsuite's builder from Elasticsuite's own client settings.
     *
     * @return void
     */
    public function testClientIsBuiltFromElasticsuiteSettings(): void
    {
        $options = ['servers' => ['search.example:9200'], 'enable_http_auth' => true];
        $client = $this->createMock(Client::class);

        $clientConfiguration = $this->createMock(ClientConfigurationInterface::class);
        $clientConfiguration->method('getOptions')->willReturn($options);

        $clientBuilder = $this->createMock(ClientBuilder::class);
        $clientBuilder->expects(self::once())->method('build')->with($options)->willReturn($client);

        $provider = new ConnectionProvider(
            $clientBuilder,
            $clientConfiguration,
            $this->createMock(IndexSettings::class)
        );

        self::assertSame($client, $provider->createClient());
    }

    /**
     * The vector index takes the alias prefix Elasticsuite gives its own indices.
     *
     * @return void
     */
    public function testIndexPrefixIsElasticsuitesAlias(): void
    {
        $indexSettings = $this->createMock(IndexSettings::class);
        $indexSettings->method('getIndexAlias')->willReturn('shop');

        $provider = new ConnectionProvider(
            $this->createMock(ClientBuilder::class),
            $this->createMock(ClientConfigurationInterface::class),
            $indexSettings
        );

        self::assertSame('shop', $provider->getIndexPrefix());
    }
}
