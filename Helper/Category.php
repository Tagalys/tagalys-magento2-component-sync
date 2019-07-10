<?php
namespace Tagalys\Sync\Helper;

class Category extends \Magento\Framework\App\Helper\AbstractHelper
{

  public function __construct(
    \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
    \Tagalys\Sync\Helper\Api $tagalysApi,
    \Magento\Catalog\Model\ResourceModel\Category\Collection $categoryCollection,
    \Tagalys\Sync\Model\CategoryFactory $tagalysCategoryFactory,
    \Magento\Catalog\Model\CategoryFactory $categoryFactory,
    \Magento\Store\Model\StoreManagerInterface $storeManagerInterface,
    \Magento\Catalog\Model\ProductFactory $productFactory,
    \Magento\Framework\App\ResourceConnection $resourceConnection,
    \Magento\Framework\Model\ResourceModel\Iterator $resourceModelIterator,
    \Magento\Catalog\Api\Data\CategoryProductLinkInterfaceFactory $categoryProductLinkInterfaceFactory,
    \Magento\Catalog\Api\CategoryLinkRepositoryInterface $categoryLinkRepositoryInterface,
    \Magento\Framework\App\CacheInterface $cacheInterface,
    \Magento\Framework\Event\Manager $eventManager,
    \Magento\Framework\App\ProductMetadataInterface $productMetadataInterface,
    \Magento\Framework\Math\Random $random
  ) {
    $this->tagalysConfiguration = $tagalysConfiguration;
    $this->random = $random;
    // $this->logger = new \Zend\Log\Logger();
    $this->tagalysApi = $tagalysApi;
    $this->categoryCollection = $categoryCollection;
    $this->tagalysCategoryFactory = $tagalysCategoryFactory;
    $this->productFactory = $productFactory;
    $this->categoryFactory = $categoryFactory;
    $this->storeManagerInterface = $storeManagerInterface;
    $this->resourceConnection = $resourceConnection;
    $this->resourceModelIterator = $resourceModelIterator;
    $this->categoryProductLinkInterfaceFactory = $categoryProductLinkInterfaceFactory;
    $this->categoryLinkRepositoryInterface = $categoryLinkRepositoryInterface;
    $this->cacheInterface = $cacheInterface;
    $this->eventManager = $eventManager;
    $this->productMetadataInterface = $productMetadataInterface;
    
    $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/tagalys_commands.log');
    $this->tagalysCommandsLogger = new \Zend\Log\Logger();
    $this->tagalysCommandsLogger->addWriter($writer);
  }

  public function createOrUpdateWithData($storeId, $categoryId, $createData, $updateData)
  {
    $firstItem = $this->tagalysCategoryFactory->create()->getCollection()
      ->addFieldToFilter('category_id', $categoryId)
      ->addFieldToFilter('store_id', $storeId)
      ->getFirstItem();

    try {
      if ($id = $firstItem->getId()) {
        $updateData['category_id'] = $categoryId;
        $updateData['store_id'] = $storeId;
        $model = $this->tagalysCategoryFactory->create()->load($id)->addData($updateData);
        $model->setId($id)->save();
      } else {
        $createData['category_id'] = $categoryId;
        $createData['store_id'] = $storeId;
        $model = $this->tagalysCategoryFactory->create()->setData($createData);
        $insertId = $model->save()->getId();
      }
    } catch (Exception $e) {
      
    }
  }
  public function markStoreCategoryIdsForDeletionExcept($storeId, $categoryIds) {
    $collection = $this->tagalysCategoryFactory->create()->getCollection()->addFieldToFilter('store_id', $storeId);
    foreach ($collection as $collectionItem) {
      if (!in_array((int)$collectionItem->getCategoryId(), $categoryIds)) {
        $collectionItem->addData(array('marked_for_deletion' => 1))->save();
      }
    }
  }

  public function isMultiStoreWarningRequired()
  {
    $categoryIdObj = new \Magento\Framework\DataObject(array('category_id' => 24));
    $this->eventManager->dispatch( 'tagalys_category_positions_updated', ['tgls_data' => $categoryIdObj]);
    $allStores = $this->tagalysConfiguration->getAllWebsiteStores();
    $showMultiStoreConfig = false;
    $checkAllCategories = false;
    if (count($allStores) > 1) {
      $rootCategories = array();
      foreach ($allStores as $store) {
        $rootCategoryId = $this->storeManagerInterface->getStore($store['value'])->getRootCategoryId();
        if (in_array($rootCategoryId, $rootCategories)) {
          $checkAllCategories = true;
          break;
        } else {
          array_push($rootCategories, $rootCategoryId);
        }
      }
    }

    if ($checkAllCategories) {
      $allCategories = array();
      foreach ($allStores as $store) {
        $rootCategoryId = $this->storeManagerInterface->getStore($store['value'])->getRootCategoryId();
        $categories = $this->categoryCollection
          ->setStoreId($store['value'])
          ->addFieldToFilter('is_active', 1)
          ->addAttributeToFilter('path', array('like' => "1/{$rootCategoryId}/%"))
          ->addAttributeToSelect('id');
        foreach ($categories as $cat) {
          if (in_array($cat->getId(), $allCategories)) {
            $showMultiStoreConfig = true;
            break;
          } else {
            array_push($allCategories, $cat->getId());
          }
        }
      }
    }
    return $showMultiStoreConfig;
  }

  public function transitionFromCategoriesConfig()
  {
    $categoryIds = $this->tagalysConfiguration->getConfig("category_ids", true);
    $storesForTagalys = $this->tagalysConfiguration->getStoresForTagalys();
    foreach ($storesForTagalys as $storeId) {
      $originalStoreId = $this->storeManagerInterface->getStore();
      $this->storeManagerInterface->setCurrentStore($storeId);
      foreach ($categoryIds as $i => $categoryId) {
        $category = $this->categoryFactory->create()->load($categoryId);
        $categoryActive = $category->getIsActive();
        if ($categoryActive && ($category->getDisplayMode() != 'PAGE')) {
          // TODO: createOrUpdateWithData() & Mage_Catalog_Model_Category::DM_PAGE -> PAGE
          $this->createOrUpdateWithData($storeId, $categoryId, array('positions_sync_required' => 0, 'marked_for_deletion' => 0, 'status' => 'pending_sync'), array('marked_for_deletion' => 0));
        }
      }
      $this->storeManagerInterface->setCurrentStore($originalStoreId);
    }
  }

  public function isProductPushDownAllowed($categoryId)
  {
    $allStores = $this->storeManagerInterface->getStores();
    $activeInStores = 0;
    if (count($allStores) == 1) {
      // Single Store
      return true;
    }
    // Multiple Stores
    foreach ($allStores as $store) {
      $categories = $this->categoryCollection
        ->setStoreId($store['value'])
        ->addFieldToFilter('is_active', 1)
        ->addFieldToFilter('entity_id', $categoryId)
        ->addAttributeToSelect('id');
      if ($categories->count() > 0) {
        $activeInStores++;
        if ($activeInStores > 1) {
          return ($this->tagalysConfiguration->getConfig("listing_pages:same_or_similar_products_across_all_stores") == '1');
        }
      }
    }
    return true;
  }

  public function maintenanceSync()
  {
    // once a day
    $listingPagesEnabled = ($this->tagalysConfiguration->getConfig("module:listingpages:enabled") == '1');
    if ($listingPagesEnabled) {
      // 1. try and sync all failed categories - mark positions_sync_required as 1 for all failed categories - this will then try and sync the categories again
      $failedCategories = $this->tagalysCategoryFactory->create()->getCollection()
        ->addFieldToFilter('status', 'failed')
        ->addFieldToFilter('marked_for_deletion', 0);
      foreach ($failedCategories as $i => $failedCategory) {
        $failedCategory->addData(array('status' => 'pending_sync'))->save();
      }

      // 2. if preference is to power all categories, loop through all categories and add missing items to the tagalys_core_categories table
      // TODO
      // 3. send all category ids to be powered by tagalys - tagalys will delete other ids
      $storesForTagalys = $this->tagalysConfiguration->getStoresForTagalys();
      $categoriesForTagalys = array();
      foreach ($storesForTagalys as $key => $storeId) {
        $categoriesForTagalys[$storeId] = array();
        $storeCategories = $this->tagalysCategoryFactory->create()->getCollection()
          ->addFieldToFilter('store_id', $storeId);
        foreach ($storeCategories as $i => $storeCategory) {
          array_push($categoriesForTagalys[$storeId], '__categories--' . $storeCategory->getCategoryId());
        }
      }
      $this->tagalysApi->clientApiCall('/v1/mpages/_platform/verify_enabled_pages', array('enabled_pages' => $categoriesForTagalys));
    }
    return true;
  }

  public function _checkSyncLock($syncStatus)
  {
    if ($syncStatus['locked_by'] == null) {
      return true;
    } else {
      // some other process has claimed the thread. if a crash occours, check last updated at < 15 minutes ago and try again.
      $lockedAt = new \DateTime($syncStatus['updated_at']);
      $now = new \DateTime();
      $intervalSeconds = $now->getTimestamp() - $lockedAt->getTimestamp();
      $minSecondsForOverride = 5 * 60;
      if ($intervalSeconds > $minSecondsForOverride) {
        $this->tagalysApi->log('warn', 'Overriding stale locked process for categories sync', array('pid' => $syncStatus['locked_by'], 'locked_seconds_ago' => $intervalSeconds));
        return true;
      } else {
        $this->tagalysApi->log('warn', 'Categories sync locked by another process', array('pid' => $syncStatus['locked_by'], 'locked_seconds_ago' => $intervalSeconds));
        return false;
      }
    }
  }

  public function markPositionsSyncRequired($storeId, $categoryId)
  {
    $firstItem = $this->tagalysCategoryFactory->create()->getCollection()
      ->addFieldToFilter('store_id', $storeId)
      ->addFieldToFilter('category_id', $categoryId)
      ->getFirstItem();
    if ($id = $firstItem->getId()) {
      $firstItem->addData(array('positions_sync_required' => 1))->save();
    }
    return true;
  }
  public function markPositionsSyncRequiredForCategories($storeId, $categoryIds) {
      $conn = $this->resourceConnection->getConnection();
      $tableName = $this->resourceConnection->getTableName('tagalys_category');
      if ($categoryIds == 'all') {
          $whereData = array(
              'category_id > ?' => 1,
              'store_id = ?' => $storeId
          );
      } else {
          $whereData = array(
              'category_id IN (?)' => $categoryIds
          );
      }
      $updateData = array(
          'positions_sync_required' => 1
      );
      $conn->update($tableName, $updateData, $whereData);
      return true;
  }
  public function getEnabledCount($storeId)
  {
    return $this->tagalysCategoryFactory->create()->getCollection()
      ->addFieldToFilter('store_id', $storeId)
      ->addFieldToFilter('marked_for_deletion', 0)
      ->count();
  }
  public function getPendingSyncCount($storeId)
  {
    return $this->tagalysCategoryFactory->create()->getCollection()
      ->addFieldToFilter('store_id', $storeId)
      ->addFieldToFilter('status', 'pending_sync')
      ->addFieldToFilter('marked_for_deletion', 0)
      ->count();
  }
  public function getRequiringPositionsSyncCount($storeId)
  {
    return $this->tagalysCategoryFactory->create()->getCollection()
      ->addFieldToFilter('store_id', $storeId)
      ->addFieldToFilter('status', 'powered_by_tagalys')
      ->addFieldToFilter('positions_sync_required', 1)
      ->addFieldToFilter('marked_for_deletion', 0)
      ->count();
  }
  public function getRequiresPositionsSyncCollection()
  {
    $categoriesToSync = $this->tagalysCategoryFactory->create()->getCollection()
      ->addFieldToFilter('positions_sync_required', 1)
      ->addFieldToFilter('marked_for_deletion', 0);
    return $categoriesToSync;
  }

  public function updatePositionsIfRequired($maxProductsPerCronRun = 50, $perPage = 5, $force = false)
  {
    $listingPagesEnabled = ($this->tagalysConfiguration->getConfig("module:listingpages:enabled") == '1');
    if ($listingPagesEnabled || $force) {
      $pid = $this->random->getRandomString(24);
      $this->tagalysApi->log('local', '1. Started updatePositionsIfRequired', array('pid' => $pid));
      $categoriesSyncStatus = $this->tagalysConfiguration->getConfig("categories_sync_status", true);
      if ($this->_checkSyncLock($categoriesSyncStatus)) {
        $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
        $timeNow = $utcNow->format(\DateTime::ATOM);
        $syncStatus = array(
          'updated_at' => $timeNow,
          'locked_by' => $pid
        );
        $this->tagalysConfiguration->setConfig('categories_sync_status', $syncStatus, true);
        $collection = $this->getRequiresPositionsSyncCollection();
        $remainingCount = $collection->count();
        // $this->logger("updatePositionsIfRequired: remainingCount: {$remainingCount}", null, 'tagalys_processes.log', true);
        $countToSyncInCronRun = min($remainingCount, $maxProductsPerCronRun);
        $numberCompleted = 0;
        $circuitBreaker = 0;
        while ($numberCompleted < $countToSyncInCronRun && $circuitBreaker < 26) {
          $circuitBreaker += 1;
          $categoriesToSync = $this->getRequiresPositionsSyncCollection()->setPageSize($perPage);
          $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
          $timeNow = $utcNow->format(\DateTime::ATOM);
          $syncStatus['updated_at'] = $timeNow;
          $this->tagalysConfiguration->setConfig('categories_sync_status', $syncStatus, true);
          foreach ($categoriesToSync as $categoryToSync) {
            $storeId = $categoryToSync->getStoreId();
            $categoryId = $categoryToSync->getCategoryId();
            $newPositions = $this->tagalysApi->storeApiCall($storeId . '', '/v1/mpages/_platform/__categories-' . $categoryId . '/positions', array());
            if ($newPositions != false) {
              if($this->tagalysConfiguration->getConfig('listing_pages:position_sort_direction') == 'asc'){
                $this->_updatePositions($categoryId, $newPositions['positions']);
              } else {
                $this->_updatePositionsReverse($categoryId, $newPositions['positions']);
              }
              $categoryToSync->addData(array('positions_sync_required' => 0, 'positions_synced_at' => date("Y-m-d H:i:s")))->save();
            } else {
              // api call failed
            }
          }
          $numberCompleted += $categoriesToSync->count();
          // $this->logger("updatePositionsIfRequired: completed {$numberCompleted}", null, 'tagalys_processes.log', true);
        }
        $syncStatus['locked_by'] = null;
        $this->tagalysConfiguration->setConfig('categories_sync_status', $syncStatus, true);
      }
    }
  }

  public function _updatePositions($categoryId, $positions)
  {
    $conn = $this->resourceConnection->getConnection();
    $tableName = $this->resourceConnection->getTableName('catalog_category_product');
    $beforeM225 = (version_compare($this->productMetadataInterface->getVersion(), '2.2.5') < 0);
    if ($beforeM225) {
      $indexTableName = $this->resourceConnection->getTableName('catalog_category_product_index');
    }
    $updateWithSql = false;
    $skippedId = NULL;
    $skippedPosition = NULL;

    if ($this->isProductPushDownAllowed($categoryId)) {
      $whereData = array(
        'category_id = ?' => (int)$categoryId,
        'position <= ?' => count($positions)
      );
      $updateData = array(
        'position' => (count($positions) + 1)
      );
      $conn->update($tableName, $updateData, $whereData);
      if ($beforeM225) {
        $conn->update($indexTableName, $updateData, $whereData);
      } else {
        $allStores = $this->tagalysConfiguration->getAllWebsiteStores();
        foreach ($allStores as $store) {
          $indexTableName = $this->resourceConnection->getTableName('catalog_category_product_index_store'.$store['value']);
          $conn->update($indexTableName, $updateData, $whereData);
          $indexTableName = $this->resourceConnection->getTableName('catalog_category_product_index_store'.$store['value'].'_replica');
          $conn->update($indexTableName, $updateData, $whereData);
        }
      }
    }

    foreach ($positions as $productId => $productPosition) {
      if (!$updateWithSql) {
        $updateWithSql = true;
        $skippedId = $productId;
        $skippedPosition = $productPosition;
      }
      $whereData = array(
        'category_id = ?' => (int)$categoryId,
        'product_id = ?' => (int)$productId
      );
      $updateData = array(
        'position' => (int)$productPosition
      );
      $conn->update($tableName, $updateData, $whereData);
      if ($beforeM225) {
        $conn->update($indexTableName, $updateData, $whereData);
      } else {
        $allStores = $this->tagalysConfiguration->getAllWebsiteStores();
        foreach ($allStores as $store) {
          $indexTableName = $this->resourceConnection->getTableName('catalog_category_product_index_store'.$store['value']);
          $conn->update($indexTableName, $updateData, $whereData);
          $indexTableName = $this->resourceConnection->getTableName('catalog_category_product_index_store'.$store['value'].'_replica');
          $conn->update($indexTableName, $updateData, $whereData);
        }
      }
    }
    $category = $this->categoryFactory->create()->load($categoryId);
    $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $category]);

    $categoryIdObj = new \Magento\Framework\DataObject(array('category_id' => $categoryId));
    $this->eventManager->dispatch('tagalys_category_positions_updated', ['tgls_data' => $categoryIdObj]);
    /**
     * API update for 1 product: To trigger magento to clear cache
     * Should be done after SQL update(Just to be safe so that reindexing is triggered after all positions are updated)
     */
    try {
      // $this->updateProductPositionWithApi($categoryId, $skippedId, $skippedPosition);
    } catch (Exception $e) {
      //TODO: Log error
    } finally {
      // $event_data_array = array('category_id' => (int)$categoryId);
      // $varien_object = new Varien_Object($event_data_array);
      // $objectManager->get('Magento\Framework\Event\Manager')->dispatchEvent('tagalys_category_positions_updated', array('varien_obj' => $varien_object));
    }
    return true;
  }

  public function _updatePositionsReverse($categoryId, $positions)
  {
    $conn = $this->resourceConnection->getConnection();
    $tableName = $this->resourceConnection->getTableName('catalog_category_product');
    $totalPositions = count($positions);
    $beforeM225 = (version_compare($this->productMetadataInterface->getVersion(), '2.2.5') < 0);
    if ($beforeM225) {
      $indexTableName = $this->resourceConnection->getTableName('catalog_category_product_index');
    }

    if ($this->isProductPushDownAllowed($categoryId)) {
      $whereData = array(
        'category_id = ?' => (int)$categoryId,
        'position >= ?' => 100
      );
      $updateData = array(
        'position' => 99
      );
      $conn->update($tableName, $updateData, $whereData);

      if ($beforeM225) {
        $conn->update($indexTableName, $updateData, $whereData);
      } else {
        $allStores = $this->tagalysConfiguration->getAllWebsiteStores();
        foreach ($allStores as $store) {
          $indexTableName = $this->resourceConnection->getTableName('catalog_category_product_index_store'.$store['value']);
          $conn->update($indexTableName, $updateData, $whereData);
          $indexTableName = $this->resourceConnection->getTableName('catalog_category_product_index_store'.$store['value'].'_replica');
          $conn->update($indexTableName, $updateData, $whereData);
        }
      }
    }

    foreach ($positions as $productId => $productPosition) {
      $whereData = array(
        'category_id = ?' => (int)$categoryId,
        'product_id = ?' => (int)$productId
      );
      $updateData = array(
        'position' => 101 + $totalPositions - (int)$productPosition
        // Padding
      );
      $conn->update($tableName, $updateData, $whereData);
      if ($beforeM225) {
        $conn->update($indexTableName, $updateData, $whereData);
      } else {
        $allStores = $this->tagalysConfiguration->getAllWebsiteStores();
        foreach ($allStores as $store) {
          $indexTableName = $this->resourceConnection->getTableName('catalog_category_product_index_store'.$store['value']);
          $conn->update($indexTableName, $updateData, $whereData);
          $indexTableName = $this->resourceConnection->getTableName('catalog_category_product_index_store'.$store['value'].'_replica');
          $conn->update($indexTableName, $updateData, $whereData);
        }
      }
    }
    $category = $this->categoryFactory->create()->load($categoryId);
    $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $category]);

    $categoryIdObj = new \Magento\Framework\DataObject(array('category_id' => $categoryId));
    $this->eventManager->dispatch('tagalys_category_positions_updated', ['tgls_data' => $categoryIdObj]);
    // $event_data_array = array('category_id' => (int)$categoryId);
    // $varien_object = new Varien_Object($event_data_array);
    // $objectManager->get('Magento\Framework\Event\Manager')->dispatchEvent('tagalys_category_positions_updated', array('varien_obj' => $varien_object));
    return true;
  }

  public function getRemainingForSync()
  {
    return $this->tagalysCategoryFactory->create()->getCollection()
      ->addFieldToFilter('status', 'pending_sync')
      ->addFieldToFilter('marked_for_deletion', 0)->count();
  }
  public function getRemainingForDelete()
  {
    return $this->tagalysCategoryFactory->create()->getCollection()
      ->addFieldToFilter('marked_for_deletion', 1)->count();
  }
  public function syncAll($force = false)
  {
    $remainingForSync = $this->getRemainingForSync();
    $remainingForDelete = $this->getRemainingForDelete();
    // echo('syncAll: ' . json_encode(compact('remainingForSync', 'remainingForDelete')));
    while ($remainingForSync > 0 || $remainingForDelete > 0) {
      $this->sync(50, $force);
      $remainingForSync = $this->getRemainingForSync();
      $remainingForDelete = $this->getRemainingForDelete();
      // echo('syncAll: ' . json_encode(compact('remainingForSync', 'remainingForDelete')));
    }
  }
  public function getStoreCategoryDetails($storeId, $categoryId) {
    $originalStoreId = $this->storeManagerInterface->getStore()->getId();
    $this->storeManagerInterface->setCurrentStore($storeId);
    $category = null;
    $category = $this->categoryFactory->create()->load($categoryId);
    $categoryActive = $category->getIsActive();
    if ($categoryActive) {
        return array(
            "id" => "__categories-$categoryId",
            "slug" => $category->getUrl(),
            "enabled" => true,
            "name" => implode(' / ', array_slice(explode(' |>| ', $this->tagalysConfiguration->getCategoryName($category)), 1)),
            "filters" => array(
              array(
                  "field" => "__categories",
                  "value" => $categoryId
              ),
              array(
                  "field" => "visibility",
                  "tag_jsons" => array("{\"id\":\"2\",\"name\":\"Catalog\"}", "{\"id\":\"4\",\"name\":\"Catalog, Search\"}")
              )
        ));
    }
    $this->storeManagerInterface->setCurrentStore($originalStoreId);
  }
  public function sync($max, $force = false)
  {
    $listingPagesEnabled = ($this->tagalysConfiguration->getConfig("module:listingpages:enabled") == '1');
    if ($listingPagesEnabled || $force) {
      $detailsToSync = array();

      // save
      $categoriesToSync = $this->tagalysCategoryFactory->create()->getCollection()
        ->addFieldToFilter('status', 'pending_sync')
        ->addFieldToFilter('marked_for_deletion', 0)
        ->setPageSize($max);
      foreach ($categoriesToSync as $i => $categoryToSync) {
        $storeId = $categoryToSync->getStoreId();
        array_push($detailsToSync, array('perform' => 'save', 'store_id' => $storeId, 'payload' => $this->getStoreCategoryDetails($storeId, $categoryToSync->getCategoryId())));
      }
      // delete
      $categoriesToDelete = $this->tagalysCategoryFactory->create()->getCollection()
        ->addFieldToFilter('marked_for_deletion', 1)
        ->setPageSize($max);
      foreach ($categoriesToDelete as $i => $categoryToDelete) {
        $storeId = $categoryToDelete->getStoreId();
        $categoryId = $categoryToDelete->getCategoryId();
        array_push($detailsToSync, array('perform' => 'delete', 'store_id' => $storeId, 'payload' => array('id' => "__categories-{$categoryId}")));
      }

      if (count($detailsToSync) > 0) {
        // sync
        $tagalysResponse = $this->tagalysApi->clientApiCall('/v1/mpages/_sync_platform_pages', array('actions' => $detailsToSync));

        if ($tagalysResponse != false) {
          foreach ($tagalysResponse['save_actions'] as $i => $saveActionResponse) {
            $firstItem = $this->tagalysCategoryFactory->create()->getCollection()
              ->addFieldToFilter('store_id', $saveActionResponse['store_id'])
              ->addFieldToFilter('category_id', explode('-', $saveActionResponse['id'])[1])
              ->getFirstItem();
            if ($id = $firstItem->getId()) {
              if ($saveActionResponse['saved']) {
                $firstItem->addData(array('status' => 'powered_by_tagalys', 'positions_sync_required' => 1))->save();
              } else {
                $firstItem->addData(array('status' => 'failed'))->save();
              }
            }
          }
          foreach ($categoriesToDelete as $i => $categoryToDelete) {
            $categoryToDelete->delete();
          }
        }
      }
    }
  }

  public function assignParentCategoriesToAllProducts($viaDb = false){
      $productCollection = $this->productFactory->create()->getCollection()
                      ->addAttributeToFilter('status', 1)
                      ->addAttributeToFilter('visibility', array("neq" => 1))
                      ->addAttributeToSelect('entity_id, product_id')
                      ->load();
      $this->resourceModelIterator->walk($productCollection->getSelect(), array(array($this, 'assignParentCategoriesToProductHandler')), array('viaDb' => $viaDb));
  }

  public function assignParentCategoriesToProductHandler($args){
      $this->assignParentCategoriesToProductId($args['row']['entity_id'], $args['viaDb']);
  }

  public function assignParentCategoriesToProductId($productId, $viaDb = false) {
      $this->tagalysCommandsLogger->info( "assignParentCategoriesToProductId: $productId");
      $product = $this->productFactory->create()->load($productId);
      $categoryIds = $product->getCategoryIds();
      $assignedParents = array();
      foreach($categoryIds as $categoryId){
          $category = $this->categoryFactory->create()->load($categoryId);
          foreach($category->getParentCategories() as $parent){
              if ((int)$parent->getLevel() > 1) {
                  if(!in_array($parent->getId(), $categoryIds) and !in_array($parent->getId(), $assignedParents)){
                      array_push($assignedParents, $parent->getId());
                      if ($viaDb) {
                          $this->assignProductToCategoryViaDb($parent->getId(), $product);
                      } else {
                          $this->assignProductToCategory($parent->getId(), $product);
                      }
                  }
              }
          }
      }
  }

  public function assignProductToCategory($categoryId, $product, $viaDb = false){
      $this->tagalysCommandsLogger->info("assignProductToCategory: {$categoryId}");
      $productSku = $product->getSku();
      $categoryProductLink = $this->categoryProductLinkInterfaceFactory->create();
      $categoryProductLink->setSku($productSku);
      $categoryProductLink->setCategoryId($categoryId);
      $categoryProductLink->setPosition(999);
      $this->categoryLinkRepositoryInterface->save($categoryProductLink);
  }
  public function assignProductToCategoryViaDb($categoryId, $product){
      $this->tagalysCommandsLogger->info("assignProductToCategoryViaDb: {$categoryId}");
      $conn = $this->resourceConnection->getConnection();
      $table = $this->resourceConnection->getTableName('catalog_category_product');
      $assignData = array('category_id'=>(int)$categoryId, 'product_id'=>(int)($product->getId()), 'position'=>9999);
      $conn->insert($table, $assignData);
  }

  public function uiPoweredByTagalys($storeId, $categoryId) {
      try {
          $firstItem = $this->tagalysCategoryFactory->create()->getCollection()
              ->addFieldToFilter('store_id', $storeId)
              ->addFieldToFilter('category_id', $categoryId)
              ->addFieldToFilter('status', 'powered_by_tagalys')
              ->addFieldToFilter('marked_for_deletion', 0)
              ->getFirstItem();
          if ($id = $firstItem->getId()) {
              return true;
          } else {
              return false;
          }
      } catch (Exception $e) {
          return false;
      }
  }
}
