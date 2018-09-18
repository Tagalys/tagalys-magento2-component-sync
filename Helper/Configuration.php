<?php
namespace Tagalys\Sync\Helper;

class Configuration extends \Magento\Framework\App\Helper\AbstractHelper
{
    public function __construct(
        \Magento\Framework\Stdlib\DateTime\DateTime $datetime,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezoneInterface,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfigInterface,
        \Magento\Directory\Model\Currency $currency,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attributeFactory,
        \Magento\Catalog\Model\Product $productModel,
        \Magento\Catalog\Model\Config $configModel,
        \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory $collectionFactory,
        \Tagalys\Sync\Model\ConfigFactory $configFactory,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollection
    )
    {
        $this->datetime = $datetime;
        $this->timezoneInterface = $timezoneInterface;
        $this->storeManager = $storeManager;
        $this->scopeConfigInterface = $scopeConfigInterface;
        $this->currency = $currency;
        $this->currencyFactory = $currencyFactory;
        $this->attributeFactory = $attributeFactory;
        $this->productModel = $productModel;
        $this->collectionFactory = $collectionFactory;
        $this->configModel = $configModel;
        $this->productFactory = $productFactory;
        $this->configFactory = $configFactory;
        $this->tagalysApi = $tagalysApi;
        $this->categoryCollection = $categoryCollection;
    }

    public function isTagalysEnabledForStore($storeId, $module = false) {
        $storesForTagalys = $this->getStoresForTagalys();
        if (in_array($storeId, $storesForTagalys)) {
            if ($module === false) {
                return true;
            } else {
                if ($this->getConfig("module:$module:enabled") == '1') {
                    return true;
                }
            }
        }
        return false;
    }

    public function checkStatusCompleted() {
        $setupStatus = $this->getConfig('setup_status');
        if ($setupStatus == 'sync') {
            $storeIds = $this->getStoresForTagalys();
            $allStoresCompleted = true;
            foreach($storeIds as $storeId) {
                $storeSetupStatus = $this->getConfig("store:$storeId:setup_complete");
                if ($storeSetupStatus != '1') {
                    $allStoresCompleted = false;
                    break;
                }
            }
            if ($allStoresCompleted) {
                $this->setConfig("setup_status", 'completed');
                $modulesToActivate = array('search_suggestions', 'search', 'mpages', 'recommendations', 'mystore');
                $this->tagalysApi->log('info', 'All stores synced. Enabling Tagalys features.', array('modulesToActivate' => $modulesToActivate));
                foreach($modulesToActivate as $moduleToActivate) {
                    $this->setConfig("module:$moduleToActivate:enabled", '1');
                }
            }
        }
    }

    public function getConfig($configPath, $jsonDecode = false) {
        $configValue = $this->configFactory->create()->load($configPath)->getValue();
        if ($configValue === NULL) {
            $defaultConfigValues = array(
                'setup_status' => 'api_credentials',
                'search_box_selector' => '#search',
                'cron_heartbeat_sent' => false,
                'suggestions_align_to_parent_selector' => '',
                'periodic_full_sync' => '1',
                'module:mpages:enabled' => '1',
                'categories'=> '[]',
                'category_ids'=> '[]',
                'listing_pages:override_layout'=> '1',
                'listing_pages:override_layout_name'=> '1column'
            );
            if (array_key_exists($configPath, $defaultConfigValues)) {
                $configValue = $defaultConfigValues[$configPath];
            } else {
                $configValue = NULL;
            }
        }
        if ($configValue !== NULL && $jsonDecode) {
            return json_decode($configValue, true);
        }
        return $configValue;
    }

    public function setConfig($configPath, $configValue, $jsonEncode = false) {
        if ($jsonEncode) {
            $configValue = json_encode($configValue);
        }
        try {
            $config = $this->configFactory->create();
            if ($config->checkPath($configPath)) {
                $found = $config->load($configPath);
                $found->setValue($configValue);
                $found->save();
            } else {
                $config->setPath($configPath);
                $config->setValue($configValue);
                $config->save();
            }
        } catch (\Exception $e){
            $this->tagalysApi->log('error', 'Exception in setConfig', array('exception_message' => $e->getMessage()));
        }
    }

    public function truncate() {
        $config = $this->configFactory->create();
        $connection = $config->getResource()->getConnection();
        $tableName = $config->getResource()->getMainTable();
        $connection->truncateTable($tableName);
    }

    public function getStoresForTagalys() {
        $storesForTagalys = $this->getConfig("stores", true);
        
        if ($storesForTagalys != NULL) {
            if (!is_array($storesForTagalys)) {
                $storesForTagalys = array($storesForTagalys);
            }
            return $storesForTagalys;
        }
        return array();
    }

    public function getCategoriesForTagalys() {
        $categoriesForTagalys = $this->getConfig("categories", true);
        
        if ($categoriesForTagalys != NULL) {
            if (!is_array($categoriesForTagalys)) {
                $categoriesForTagalys = array($categoriesForTagalys);
            }
            return $categoriesForTagalys;
        }
        return array();
    }

    public function getAllCategories() {
        $categories = $this->categoryCollection->create()->addAttributeToSelect('*')->load();
        foreach ($categories as $category) {
            $pathNames = array();
            $pathIds = explode('/', $category->getPath());
            if (count($pathIds) > 2) {
                $path = $this->categoryCollection->create()->addAttributeToSelect('*')->addAttributeToFilter('entity_id', array('in' => $pathIds));
                foreach($path as $i => $path_category) {
                    if ($i != 1) { // skip root category
                        $pathNames[] = $path_category->getName();
                    }
                }
                $output[] = array('value' => implode('/', $pathIds), 'label' => implode(' / ', $pathNames));
            }
        }
        return $output;
    }

    public function getAllWebsiteStores() {
        foreach ($this->storeManager->getWebsites() as $website) {
            foreach ($website->getGroups() as $group) {
                $stores = $group->getStores();
                foreach ($stores as $store) {
                    $website_stores[] = array("value" => $store->getId(), "label" => $website->getName()." / ".$group->getName(). " / ".$store->getName());
                }
            }
        }
        return $website_stores;
    }

    public function syncCategories($categoryPathsForTagalys = false) {
        if ($categoryPathsForTagalys === false) {
            $categoryPathsForTagalys = $this->getCategoriesForTagalys();
        }
        $categoryIdsForTagalys = array();
        foreach ($categoryPathsForTagalys as $categoryPathForTagalys) {
            $split = explode('/', $categoryPathForTagalys);
            $categoryIdsForTagalys[] = end($split);
        }

        $storeIds = $this->getStoresForTagalys();
        $allCategories = $this->getAllCategories();

        $platformPages = array('stores' => array());
        foreach ($storeIds as $index => $storeId) {
            $platformPages['stores'][] = array('id' => $storeId, 'platform_pages' => $this->getStoreCategories($storeId, $categoryIdsForTagalys));
        }

        $tagalysResponse = $this->tagalysApi->clientApiCall('/v1/mpages/_sync_platform_pages', $platformPages);
        if ($tagalysResponse === false) {
            return false;
        }
        if ($tagalysResponse['result'] === true) {
            if (count($tagalysResponse['skipped_pages']) === 0) {
                return true;
            } else {
                $skippedIds = array();
                foreach ($tagalysResponse['skipped_pages'] as $key => $skippedPage) {
                    $split = explode('-', $skippedPage);
                    $skippedIds[] = intval(end($split));
                }
                return $skippedIds;
            }
        }
        return false;
    }

    public function getStoreCategories($storeId, $categoryIdsForTagalys) {
        $rootCategoryId = $this->storeManager->getStore($storeId)->getRootCategoryId();
        $storeCategoriesData = array();
        $allCategories = $this->getAllCategories();
        $listingPagesEnabled = $this->getConfig('module:listingpages:enabled');
        foreach ($allCategories as $allCategory) {
            if (strpos($allCategory['value'], "/$rootCategoryId/") !== false) {
                // $storeId has this category
                $split = explode('/', $allCategory['value']);
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $categoryModel = $objectManager->create('\Magento\Catalog\Model\Category');
                $thisCategoryId = end($split);
                $category = $categoryModel->load($thisCategoryId);
                $storeCategoriesData[] = array(
                    "id" => "__categories-$thisCategoryId",
                    "slug" => $category->getUrl(),
                    "enabled" => ($listingPagesEnabled && in_array($thisCategoryId, $categoryIdsForTagalys)),
                    "name" => implode(' / ', array_slice(explode(' / ', $allCategory['label']), 1)),
                    "filters" => array(array(
                        "field" => "__categories",
                        "value" => $thisCategoryId
                )));
            }
        }
        return $storeCategoriesData;
    }

    public function syncClientConfiguration($storeIds = false) {
        if ($storeIds === false) {
            $storeIds = $this->getStoresForTagalys();
        }
        $clientConfiguration = array('stores' => array());
        foreach ($storeIds as $index => $storeId) {
            $clientConfiguration['stores'][] = $this->getStoreConfiguration($storeId);
        }
        $tagalysResponse = $this->tagalysApi->clientApiCall('/v1/configuration', $clientConfiguration);
        if ($tagalysResponse === false) {
            return false;
        }
        if ($tagalysResponse['result'] == true) {
            if (!empty($tagalysResponse['product_sync_required'])) {
                foreach ($tagalysResponse['product_sync_required'] as $storeId => $required) {
                    $this->setConfig("store:{$storeId}:resync_required", (int)$required);
                }
            }
        }
        return $tagalysResponse;
    }

    public function getStoreConfiguration($storeId) {
        $store = $this->storeManager->getStore($storeId);
        $tagSetsAndCustomFields = $this->getTagSetsAndCustomFields($store->getId());
        $productsCount = $this->productFactory->create()->getCollection()->setStoreId($storeId)->addStoreFilter($storeId)->addAttributeToFilter('status', 1)->addAttributeToFilter('visibility', array("neq" => 1))->count();
        $configuration = array(
            'id' => $storeId,
            'label' => $store->getName(),
            'locale' => $this->scopeConfigInterface->getValue('general/locale/code', 'store', $storeId),
            'multi_currency_mode' => 'exchange_rate',
            'timezone' => $this->timezoneInterface->getConfigTimezone('store', $store),
            'currencies' => $this->getCurrencies($store),
            'fields' => $tagSetsAndCustomFields['custom_fields'],
            'tag_sets' => $tagSetsAndCustomFields['tag_sets'],
            'sort_options' =>  $this->getSortOptions($storeId),
            'products_count' => $productsCount
        );
        return $configuration;
    }

    public function getSortOptions($storeId) {
        $sort_options = array();
        foreach ($this->configModel->getAttributesUsedForSortBy() as $key => $attribute) {
            $sort_options[] = array(
                'field' => $attribute->getAttributeCode(),
                'label' => $attribute->getStoreLabel($storeId)
            );
        }
        return $sort_options;
    }

    public function getCurrencies($store, $onlyDefault = false) {
        $currencies = array();
        $codes = $store->getAvailableCurrencyCodes();
        $rates = $this->currency->getCurrencyRates(
            $store->getBaseCurrencyCode(),
            $codes
        );
        $baseCurrencyCode = $store->getBaseCurrencyCode();
        $defaultCurrencyCode = $store->getDefaultCurrencyCode();
        if (empty($rates[$baseCurrencyCode])) {
            $rates[$baseCurrencyCode] = '1.0000';
        }
        foreach ($codes as $code) {
            if (isset($rates[$code])) {
                $defaultCurrency = ($defaultCurrencyCode == $code ? true : false);
                $thisCurrency = $this->currencyFactory->create()->load($code);
                $label = $thisCurrency->getCurrencySymbol();
                if (empty($label)) {
                    $label = $code;
                }
                $currencies[] = array(
                    'id' => $code,
                    'label' => $label,
                    'exchange_rate' => $rates[$code],
                    'rounding_mode' => 'round',
                    'fractional_digits' => 2,
                    'default' => $defaultCurrency
                );
                if ($onlyDefault && $defaultCurrency) {
                    return end($currencies);
                }
            }
        }
        return $currencies;
    }

    public function getCurrenciesByCode($store) {
        $currencies = $this->getCurrencies($store);
        $currenciesByCode = array();
        foreach ($currencies as $currency) {
            $currenciesByCode[$currency['id']] = $currency;
        }
        return $currenciesByCode;
    }


    public function getTagSetsAndCustomFields($storeId) {
        $tagalys_core_fields = array("__id", "name", "sku", "link", "sale_price", "image_url", "introduced_at", "in_stock");
        $tag_sets = array();
        $tag_sets[] = array("id" =>"__categories", "label" => "Categories", "filters" => true, "search" => true);
        $custom_fields = array();
        $magento_tagalys_type_mapping = array(
            'text' => 'string',
            'textarea' => 'string',
            'date' => 'datetime',
            'boolean' => 'boolean',
            'multiselect' => 'string',
            'select' => 'string',
            'price' => 'float'
        );
        $collection = $this->collectionFactory->create()->addVisibleFilter();
        foreach($collection as $attribute) {
            if (!in_array($attribute->getAttributeCode(), array('status', 'tax_class_id'))) {
                $isForDisplay = ((bool)$attribute->getUsedInProductListing() && (bool)$attribute->getIsUserDefined());
                if ($attribute->getIsFilterable() || $attribute->getIsSearchable() || $isForDisplay) {
                    if ($attribute->getFrontendInput() != 'multiselect') {
                        if (!in_array($attribute->getAttributecode(), $tagalys_core_fields)) {
                            $isPriceField = ($attribute->getFrontendInput() == "price" );
                            if (array_key_exists($attribute->getFrontendInput(), $magento_tagalys_type_mapping)) {
                                $type = $magento_tagalys_type_mapping[$attribute->getFrontendInput()];
                            } else {
                                $type = 'string';
                            }
                            $custom_fields[] = array(
                                'name' => $attribute->getAttributecode(),
                                'label' => $attribute->getStoreLabel($storeId),
                                'type' => $type,
                                'currency' => $isPriceField,
                                'display' => ($isForDisplay || $isPriceField),
                                'filters' => (bool)$attribute->getIsFilterable(),
                                'search' => (bool)$attribute->getIsSearchable()
                            );
                        }
                    }

                    if ($attribute->usesSource() && !in_array($attribute->getFrontendInput(), array('boolean'))) {
                        $tag_sets[] = array(
                            'id' => $attribute->getAttributecode(),
                            'label' => $attribute->getStoreLabel($storeId),
                            'filters' => (bool)$attribute->getIsFilterable(),
                            'search' => (bool)$attribute->getIsSearchable(),
                            'display' => $isForDisplay
                        );
                    }
                }
            }
        }
        return compact('tag_sets', 'custom_fields');
    }
}