<?php

declare(strict_types=1);

namespace InchooDev\OpensearchSemanticSearchElasticsuite\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Settings that only exist when Elasticsuite is the search engine.
 */
class Config
{
    public const XML_PATH_AUTOCOMPLETE_ENABLED =
        'inchoodev_opensearch_semantic_search/elasticsuite/autocomplete_enabled';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * Should the search box product suggestions get the hybrid ranking too.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAutocompleteEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_AUTOCOMPLETE_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
