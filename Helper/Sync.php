<?php

namespace Tagalys\Sync\Helper;

class Sync extends \Magento\Framework\App\Helper\AbstractHelper
{
    public function __construct(
        \Magento\Framework\App\ProductMetadataInterface $productMetadataInterface,
        \Magento\Framework\Module\ModuleListInterface $moduleListInterface,
        \Tagalys\Sync\Model\ConfigFactory $configFactory,
        \Magento\Framework\Filesystem $filesystem,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Tagalys\Sync\Helper\Product $tagalysProduct,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\Math\Random $random,
        \Magento\Framework\UrlInterface $urlInterface,
        \Magento\Framework\Url $frontUrlHelper,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Tagalys\Sync\Model\QueueFactory $queueFactory,
        \Tagalys\Sync\Helper\Queue $queueHelper
    )
    {
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/tagalys.log');
        $this->tagalysLogger = new \Zend\Log\Logger();
        $this->tagalysLogger->addWriter($writer);

        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
        $this->tagalysProduct = $tagalysProduct;
        $this->productFactory = $productFactory;
        $this->random = $random;
        $this->urlInterface = $urlInterface;
        $this->storeManager = $storeManager;
        $this->frontUrlHelper = $frontUrlHelper;
        $this->queueFactory = $queueFactory;
        $this->queueHelper = $queueHelper;

        $this->filesystem = $filesystem;
        $this->directory = $filesystem->getDirectoryWrite(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA);

        $this->perPage = 50;
        $this->maxProducts = 500;
    }

    public function triggerFeedForStore($storeId, $forceRegenerateThumbnails = false, $productsCount = false, $abandonIfExisting = false) {
        $feedStatus = $this->tagalysConfiguration->getConfig("store:$storeId:feed_status", true);
        if ($feedStatus == NULL || in_array($feedStatus['status'], array('finished')) || $abandonIfExisting) {
            $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
            $timeNow = $utcNow->format(\DateTime::ATOM);
            if ($productsCount == false) {
                $productsCount = $this->getProductsCount($storeId);
            }
            $feedStatus = $this->tagalysConfiguration->setConfig("store:$storeId:feed_status", json_encode(array(
                'status' => 'pending',
                'filename' => $this->_getNewSyncFileName($storeId, 'feed'),
                'products_count' => $productsCount,
                'completed_count' => 0,
                'updated_at' => $timeNow,
                'triggered_at' => $timeNow,
                'force_regenerate_thumbnails' => $forceRegenerateThumbnails
            )));
            $this->tagalysConfiguration->setConfig("store:$storeId:resync_required", '0');
            return true;
        } else {
            return false;
        }
    }

    public function _getNewSyncFileName($storeId, $type) {
        $domain =  $this->_getDomain($storeId);
        $datetime = date("YmdHis");
        return "syncfile-$domain-$storeId-$type-$datetime.jsonl";
    }
    public function _getDomain($storeId) {
        $store = $this->storeManager->getStore($storeId);
        $baseUrl = $store->getBaseUrl();
        $baseUrl = rtrim($baseUrl, '/');
        $exploded_1 = explode("://", $baseUrl);
        $replaced_1 = str_replace("-", "__", $exploded_1[1]);
        return str_replace("/", "___", $replaced_1);
    }

    public function getProductsCount($storeId) {
        return $this->_getCollection($storeId, 'feed')->count();
    }

    public function _getCollection($storeId, $type, $productIdsFromUpdatesQueueForCronInstance = array()) {
        $collection = $this->productFactory->create()->getCollection()
            ->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter('status', 1)
            ->addAttributeToFilter('visibility', array("neq" => 1))
            ->addAttributeToSelect('entity_id');
        if ($type == 'updates') {
            $collection = $collection->addAttributeToFilter('entity_id', array('in' => $productIdsFromUpdatesQueueForCronInstance));
        }
        return $collection;
    }

    public function sync($maxProducts = 500) {
        $this->maxProducts = $maxProducts;
        if ($this->perPage > $maxProducts) {
            $this->perPage = $maxProducts;
        }
        $stores = $this->tagalysConfiguration->getStoresForTagalys();
        if ($stores != NULL) {
            $this->_checkAndSyncConfig();

            // get product ids from update queue to be processed in this cron instance
            $productIdsFromUpdatesQueueForCronInstance = $this->_productIdsFromUpdatesQueueForCronInstance();
            // products from obervers are added to queue without any checks. so add related configurable products if necessary
            foreach($productIdsFromUpdatesQueueForCronInstance as $productId) {
                $this->queueHelper->queuePrimaryProductIdFor($productId);

            }
            $updatesPerformed = array();
            foreach($stores as $i => $storeId) {
                $updatesPerformed[$storeId] = $this->_syncForStore($storeId, $productIdsFromUpdatesQueueForCronInstance);
            }
            $updatesPerformedForAllStores = true;
            foreach ($stores as $i => $storeId) {
                if ($updatesPerformed[$storeId] == false) {
                    $updatesPerformedForAllStores = false;
                    break;
                }
            }
            if ($updatesPerformedForAllStores) {
                $this->_deleteProductIdsFromUpdatesQueueForCronInstance($productIdsFromUpdatesQueueForCronInstance);
                $this->queueHelper->truncateIfEmpty();
            }
        }
        return true;
    }

    public function cachePopularSearches() {
        try {
            $setupStatus = $this->tagalysConfiguration->getConfig('setup_status');
            $setupComplete = ($setupStatus == 'completed');
            if ($setupComplete) {
                $storesForTagalys = $this->tagalysConfiguration->getStoresForTagalys();
                if ($storesForTagalys != null) {
                    foreach ($storesForTagalys as $storeId) {
                        $popularSearches = $this->tagalysApi->storeApiCall($storeId.'', '/v1/popular_searches', array());
                        if ($popularSearches != false && array_key_exists('popular_searches', $popularSearches)) {
                            $this->tagalysConfiguration->setConfig("store:{$storeId}:popular_searches", $popularSearches['popular_searches'], true);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->tagalysApi->log('local', "Error in cachePopularSearches: ". $e->getMessage());
        }
    }

    public function _checkAndSyncConfig() {
        $configSyncRequired = $this->tagalysConfiguration->getConfig('config_sync_required');
        if ($configSyncRequired == '1') {
            $this->tagalysConfiguration->syncClientConfiguration();
            $this->tagalysConfiguration->setConfig('config_sync_required', '0');
        }
    }

    public function _syncForStore($storeId, $productIdsFromUpdatesQueueForCronInstance) {
        $updatesPerformed = false;
        $feedResponse = $this->_generateFilePart($storeId, 'feed');
        $syncFileStatus = $feedResponse['syncFileStatus'];
        if (!$this->_isFeedGenerationInProgress($storeId, $syncFileStatus)) {
            if (count($productIdsFromUpdatesQueueForCronInstance) > -1) {
                $updatesResponse = $this->_generateFilePart($storeId, 'updates', $productIdsFromUpdatesQueueForCronInstance);
                if (isset($updatesResponse['updatesPerformed']) and $updatesResponse['updatesPerformed']) {
                    $updatesPerformed = true;
                }
            }
        }
        return $updatesPerformed;
    }

    public function _isFeedGenerationInProgress($storeId, $storeFeedStatus) {
        if ($storeFeedStatus == null) {
            return false;
        }
        if (in_array($storeFeedStatus['status'], array('finished'))) {
            return false;
        }
        return true;
    }

    public function _checkLock($syncFileStatus) {
        if (!array_key_exists('locked_by', $syncFileStatus) || $syncFileStatus['locked_by'] == null) {
            return true;
        } else {
            // some other process has claimed the thread. if a crash occours, check last updated at < 15 minutes ago and try again.
            $lockedAt = new \DateTime($syncFileStatus['updated_at']);
            $now = new \DateTime();
            $intervalSeconds = $now->getTimestamp() - $lockedAt->getTimestamp();
            $minSecondsForOverride = 10 * 60;
            if ($intervalSeconds > $minSecondsForOverride) {
                $this->tagalysApi->log('warn', 'Overriding stale locked process', array('pid' => $syncFileStatus['locked_by'], 'locked_seconds_ago' => $intervalSeconds));
                return true;
            } else {
                $this->tagalysApi->log('warn', 'Sync file generation locked by another process', array('pid' => $syncFileStatus['locked_by'], 'locked_seconds_ago' => $intervalSeconds));
                return false;
            }
        }
    }

    public function _reinitializeUpdatesConfig($storeId, $productIdsFromUpdatesQueueForCronInstance) {
        $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
        $timeNow = $utcNow->format(\DateTime::ATOM);
        $updatesCount = count($productIdsFromUpdatesQueueForCronInstance);
        $syncFileStatus = array(
            'status' => 'pending',
            'filename' => $this->_getNewSyncFileName($storeId, 'updates'),
            'products_count' => $updatesCount,
            'completed_count' => 0,
            'updated_at' => $timeNow,
            'triggered_at' => $timeNow
        );
        $this->tagalysConfiguration->setConfig("store:$storeId:updates_status", $syncFileStatus, true);
        return $syncFileStatus;
    }

    public function _updateProductsCount($storeId, $type, $collection) {
        $productsCount = $collection->count();
        $syncFileStatus = $this->tagalysConfiguration->getConfig("store:$storeId:{$type}_status", true);
        if ($syncFileStatus != NULL) {
            $syncFileStatus['products_count'] = $productsCount;
            $this->tagalysConfiguration->setConfig("store:$storeId:{$type}_status", $syncFileStatus, true);
        }
        return $productsCount;
    }
    public function _productIdsFromUpdatesQueueForCronInstance() {
        $queueCollection = $this->queueFactory->create()->getCollection()->setOrder('id', 'ASC')->setPageSize($this->maxProducts);
        $productIdsFromUpdatesQueueForCronInstance = array();
        foreach ($queueCollection as $i => $queueItem) {
            $productId = $queueItem->getProductId();
            array_push($productIdsFromUpdatesQueueForCronInstance, $productId);
        }
        return $productIdsFromUpdatesQueueForCronInstance;
    }
    public function _deleteProductIdsFromUpdatesQueueForCronInstance($productIdsFromUpdatesQueueForCronInstance) {
        $collection = $this->queueFactory->create()->getCollection()->addFieldToFilter('product_id', array('in' => $productIdsFromUpdatesQueueForCronInstance));
        foreach($collection as $queueItem) {
            $queueItem->delete();
        }
    }

    public function _generateFilePart($storeId, $type, $productIdsFromUpdatesQueueForCronInstance = array()) {
        $pid = $this->random->getRandomString(24);

        $this->tagalysApi->log('local', '1. Started _generateFilePart', array('pid' => $pid, 'storeId' => $storeId, 'type' => $type));

        $updatesPerformed = false;
        $syncFileStatus = $this->tagalysConfiguration->getConfig("store:$storeId:{$type}_status", true);
        if ($syncFileStatus == NULL) {
            if ($type == 'feed') {
                // if feed_status config is missing, generate it.
                $this->triggerFeedForStore($storeId);
            }
            if ($type == 'updates') {
                $this->_reinitializeUpdatesConfig($storeId, $productIdsFromUpdatesQueueForCronInstance);
            }
        }
        $syncFileStatus = $this->tagalysConfiguration->getConfig("store:$storeId:{$type}_status", true);

        $this->tagalysApi->log('local', '2. Read / Initialized syncFileStatus', array('pid' => $pid, 'storeId' => $storeId, 'type' => $type, 'syncFileStatus' => $syncFileStatus));

        if ($syncFileStatus != NULL) {
            if ($type == 'updates' && in_array($syncFileStatus['status'], array('finished'))) {
                // if updates are finished, reset config
                $this->_reinitializeUpdatesConfig($storeId, $productIdsFromUpdatesQueueForCronInstance);
                $syncFileStatus = $this->tagalysConfiguration->getConfig("store:$storeId:{$type}_status", true);
            }

            if (in_array($syncFileStatus['status'], array('pending', 'processing'))) {
                if ($this->_checkLock($syncFileStatus) == false) {
                    return compact('syncFileStatus');
                }

                $this->tagalysApi->log('local', '3. Unlocked', array('pid' => $pid, 'storeId' => $storeId, 'type' => $type));

                $deletedIds = array();
                if ($type == 'updates') {
                    $collection = $this->_getCollection($storeId, $type, $productIdsFromUpdatesQueueForCronInstance);
                    $productIdsInCollection = array();
                    $select = $collection->getSelect();
                    $products = $select->query();
                    foreach($products as $product) {
                        array_push($productIdsInCollection, $product['entity_id']);
                    }
                    $deletedIds = array_diff($productIdsFromUpdatesQueueForCronInstance, $productIdsInCollection);
                } else {
                    $collection = $this->_getCollection($storeId, $type);
                }
                $select = $collection->getSelect();

                // set updated_at as this is used to check for stale processes
                $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
                $timeNow = $utcNow->format(\DateTime::ATOM);
                $syncFileStatus['updated_at'] = $timeNow;
                // update products count
                $productsCount = $this->_updateProductsCount($storeId, $type, $collection);
                if ($productsCount == 0 && count($deletedIds) == 0) {
                    if ($type == 'feed') {
                        $this->tagalysApi->log('warn', 'No products for feed generation', array('storeId' => $storeId, 'syncFileStatus' => $syncFileStatus));
                    }
                    $syncFileStatus['status'] = 'finished';
                    $this->tagalysConfiguration->setConfig("store:$storeId:{$type}_status", $syncFileStatus, true);
                    $updatesPerformed = true;
                    return compact('syncFileStatus', 'updatesPerformed');
                } else {
                    $syncFileStatus['locked_by'] = $pid;
                    // set status to processing
                    $syncFileStatus['status'] = 'processing';
                    $this->tagalysConfiguration->setConfig("store:$storeId:{$type}_status", $syncFileStatus, true);
                }

                $this->tagalysApi->log('local', '4. Locked with pid', array('pid' => $pid, 'storeId' => $storeId, 'type' => $type, 'syncFileStatus' => $syncFileStatus));

                // setup file
                $stream = $this->directory->openFile('tagalys/'.$syncFileStatus['filename'], 'a');
                $stream->lock();

                foreach($deletedIds as $i => $deletedId) {
                    $stream->write(json_encode(array("perform" => "delete", "payload" => array('__id' => $deletedId))) ."\r\n");
                }


                $cronInstanceCompletedProducts = 0;

                $timeStart = time();
                if ($productsCount == 0) {
                    $fileGenerationCompleted = true;
                } else {
                    $fileGenerationCompleted = false;

                    if ($type == 'feed') {
                        $totalRemainingProducts = $syncFileStatus['products_count'] - $syncFileStatus['completed_count'];
                        $cronInstanceTotalProducts = min($totalRemainingProducts, $this->maxProducts);
                    }
                    if ($type == 'updates') {
                        $cronInstanceTotalProducts = $productsCount; // already limited to product_ids_from_updates_queue_for_cron_instance
                    }
                    // avoid infinite loops due to undetected bugs / unexpected issues
                    // use a circut breaker with limit of 26 (1000 products per cron instance and 50 products per page = 25. so 26 is not expected.)
                    $circuitBreaker = 0;
                    try {
                        while($cronInstanceCompletedProducts < $cronInstanceTotalProducts && $circuitBreaker < 26) {
                            $circuitBreaker += 1;
                            $products = $select->limit($this->perPage, $syncFileStatus['completed_count'])->query();
                            $triggerDatetime = strtotime($syncFileStatus['triggered_at']);
                            foreach($products as $product) {
                                $forceRegenerateThumbnail = false;
                                if ($type == 'updates') {
                                    $forceRegenerateThumbnail = true;
                                } else {
                                    if (array_key_exists('force_regenerate_thumbnails', $syncFileStatus)) {
                                        $forceRegenerateThumbnail = $syncFileStatus['force_regenerate_thumbnails'];
                                    }
                                }
                                $productDetails = (array) $this->tagalysProduct->productDetails($product['entity_id'], $storeId, $forceRegenerateThumbnail);

                                if (array_key_exists('scheduled_updates', $productDetails) && count($productDetails['scheduled_updates']) > 0) {
                                    for($i = 0; $i < count($productDetails['scheduled_updates']); $i++) {
                                        $atDatetime = strtotime($productDetails['scheduled_updates'][$i]['at']);
                                        unset($productDetails['scheduled_updates'][$i]['at']);
                                        $productDetails['scheduled_updates'][$i]['in'] = $atDatetime - $triggerDatetime;
                                    }
                                }
                                
                                $stream->write(json_encode(array("perform" => "index", "payload" => $productDetails)) ."\r\n");
                                $syncFileStatus['completed_count'] += 1;
                                $cronInstanceCompletedProducts += 1;
                                $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
                                $timeNow = $utcNow->format(\DateTime::ATOM);
                                $syncFileStatus['updated_at'] = $timeNow;
                                $this->tagalysConfiguration->setConfig("store:$storeId:{$type}_status", $syncFileStatus, true);
                            }
                        }
                        $timeEnd = time();
                    } catch (\Exception $e) {
                        $this->tagalysApi->log('error', 'Exception in generateFilePart', array('storeId' => $storeId, 'syncFileStatus' => $syncFileStatus, 'message' => $e->getMessage()));
                    }
                    if ($type == 'feed') {
                        $totalRemainingProducts = $syncFileStatus['products_count'] - $syncFileStatus['completed_count'];
                        // $circuitBreaker of 26 is not expected unless we're counting wrong. in that case, complete
                        if ($totalRemainingProducts <= 0 || $circuitBreaker >= 26) {
                            $fileGenerationCompleted = true;
                            if ($circuitBreaker >= 26) {
                                $this->tagalysApi->log('error', 'Circuit breaker triggered. Sync file marked as completed.', array('pid' => $pid, 'storeId' => $storeId, 'syncFileStatus' => $syncFileStatus));
                            }
                        }
                    }
                    if ($type == 'updates') {
                        $fileGenerationCompleted = true; // updates are sent at every cron instance even if queue is larger
                    }
                }
                $updatesPerformed = true;
                // close file outside of try/catch
                $stream->unlock();
                $stream->close();
                // remove lock
                $syncFileStatus['locked_by'] = null;
                $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
                $timeNow = $utcNow->format(\DateTime::ATOM);
                $syncFileStatus['updated_at'] = $timeNow;
                $timeEnd = time();
                $timeElapsed = $timeEnd - $timeStart;
                if ($fileGenerationCompleted) {
                    $syncFileStatus['status'] = 'generated_file';
                    $syncFileStatus['completed_count'] += count($deletedIds);
                    $this->tagalysApi->log('info', 'Completed writing ' . $syncFileStatus['completed_count'] . ' products to '. $type .' file. Last batch of ' . $cronInstanceCompletedProducts . ' took ' . $timeElapsed . ' seconds.', array('storeId' => $storeId, 'syncFileStatus' => $syncFileStatus));
                } else {
                    $this->tagalysApi->log('info', 'Written ' . $syncFileStatus['completed_count'] . ' out of ' . $syncFileStatus['products_count'] . ' products to '. $type .' file. Last batch of ' . $cronInstanceCompletedProducts . ' took ' . $timeElapsed . ' seconds', array('storeId' => $storeId, 'syncFileStatus' => $syncFileStatus));
                    $syncFileStatus['status'] = 'pending';
                }
                $this->tagalysConfiguration->setConfig("store:$storeId:{$type}_status", $syncFileStatus, true);
                $this->tagalysApi->log('local', '5. Removed lock', array('pid' => $pid, 'storeId' => $storeId, 'type' => $type, 'syncFileStatus' => $syncFileStatus));
                if ($fileGenerationCompleted) {
                    $this->_sendFileToTagalys($storeId, $type, $syncFileStatus);
                }
            } elseif (in_array($syncFileStatus['status'], array('generated_file'))) {
                $this->_sendFileToTagalys($storeId, $type, $syncFileStatus);
            }
        } else {
            $this->tagalysApi->log('error', 'Unexpected error in generateFilePart. syncFileStatus is NULL', array('storeId' => $storeId));
        }
        return compact('syncFileStatus', 'updatesPerformed');
    }

    public function _sendFileToTagalys($storeId, $type, $syncFileStatus = null) {
        if ($syncFileStatus == null) {
            $syncFileStatus = $this->tagalysConfiguration->getConfig("store:$storeId:{$type}_status", true);
        }

        if (in_array($syncFileStatus['status'], array('generated_file'))) {
            $baseUrl = '';
            $webUrl = $this->urlInterface->getBaseUrl(array('_type' => 'web'));
            $mediaUrl = $this->urlInterface->getBaseUrl(array('_type' => 'media'));
            if (strpos($mediaUrl, $webUrl) === false) {
                // media url different from website url - probably a CDN. use website url to link to the file we create
                $baseUrl = $webUrl . 'media/';
            } else {
                $baseUrl = $mediaUrl;
            }
            $linkToFile = $baseUrl . "tagalys/" . $syncFileStatus['filename'];

            $triggerDatetime = strtotime($syncFileStatus['triggered_at']);
            $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
            $data = array(
                'link' => $linkToFile,
                'updates_count' => $syncFileStatus['products_count'],
                'store' => $storeId,
                'seconds_since_reference' => ($utcNow->getTimestamp() - $triggerDatetime),
                'callback_url' => $this->frontUrlHelper->getUrl('tagalys/sync/callback/')
            );
            $response = $this->tagalysApi->storeApiCall($storeId.'', "/v1/products/sync_$type", $data);
            if ($response != false && $response['result']) {
                $syncFileStatus['status'] = 'sent_to_tagalys';
                $this->tagalysConfiguration->setConfig("store:$storeId:{$type}_status", $syncFileStatus, true);
            } else {
                $this->tagalysApi->log('error', 'Unexpected response in _sendFileToTagalys', array('storeId' => $storeId, 'syncFileStatus' => $syncFileStatus, 'response' => $response));
            }
        } else {
            $this->tagalysApi->log('error', 'Error: Called _sendFileToTagalys with syncFileStatus ' . $syncFileStatus['status'], array('storeId' => $storeId, 'syncFileStatus' => $syncFileStatus));
        }
    }

    public function receivedCallback($storeId, $filename) {
        $type = null;
        if (strpos($filename, '-feed-') !== false) {
            $type = 'feed';
        } elseif (strpos($filename, '-updates-') !== false) {
            $type = 'updates';
        }
        $syncFileStatus = $this->tagalysConfiguration->getConfig("store:$storeId:{$type}_status", true);
        if ($syncFileStatus != null) {
            if ($syncFileStatus['status'] == 'sent_to_tagalys') {
                if ($syncFileStatus['filename'] == $filename) {
                    $filePath = $this->filesystem->getDirectoryRead('media')->getAbsolutePath() . 'tagalys/' . $filename;
                    if (!file_exists($filePath) || !unlink($filePath)) {
                        $this->tagalysApi->log('warn', 'Unable to delete file in receivedCallback', array('syncFileStatus' => $syncFileStatus, 'filename' => $filename));
                    }
                    $syncFileStatus['status'] = 'finished';
                    $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
                    $timeNow = $utcNow->format(\DateTime::ATOM);
                    $syncFileStatus['updated_at'] = $timeNow;
                    $this->tagalysConfiguration->setConfig("store:$storeId:{$type}_status", $syncFileStatus, true);
                    if ($type == 'feed') {
                        $this->tagalysConfiguration->setConfig("store:$storeId:setup_complete", '1');
                        $this->tagalysApi->log('info', 'Feed sync completed.', array('store_id' => $storeId));
                        $this->tagalysConfiguration->checkStatusCompleted();
                    } else {
                        $this->tagalysApi->log('info', 'Updates sync completed.', array('store_id' => $storeId));
                    }
                } else {
                    $this->tagalysApi->log('warn', 'Unexpected filename in receivedCallback', array('syncFileStatus' => $syncFileStatus, 'filename' => $filename));
                }
            } else {
                $this->tagalysApi->log('warn', 'Unexpected receivedCallback trigger', array('syncFileStatus' => $syncFileStatus, 'filename' => $filename));
            }
        } else {
            // TODO handle error
        }
    }

    public function status() {
        $storesSyncRequired = false;
        $waitingForTagalys = false;
        $resyncScheduled = false;
        $syncStatus = array();
        $setupStatus = $this->tagalysConfiguration->getConfig('setup_status');
        $setupComplete = ($setupStatus == 'completed');
        $syncStatus['setup_complete'] = $setupComplete;
        $syncStatus['stores'] = array();
        foreach ($this->tagalysConfiguration->getStoresForTagalys() as $key => $storeId) {
            $thisStore = array();
            
            $thisStore['name'] = $this->storeManager->getStore($storeId)->getName();
            
            $storeSetupComplete = $this->tagalysConfiguration->getConfig("store:$storeId:setup_complete");
            $thisStore['setup_complete'] = ($storeSetupComplete == '1');

            $storeFeedStatus = $this->tagalysConfiguration->getConfig("store:$storeId:feed_status", true);
            if ($storeFeedStatus != null) {
                $statusForClient = '';
                switch($storeFeedStatus['status']) {
                    case 'pending':
                        $statusForClient = 'Waiting to write to file';
                        $storesSyncRequired = true;
                        break;
                    case 'processing':
                        $statusForClient = 'Writing to file';
                        $storesSyncRequired = true;
                        break;
                    case 'generated_file':
                        $statusForClient = 'Generated file. Sending to Tagalys.';
                        $storesSyncRequired = true;
                        break;
                    case 'sent_to_tagalys':
                        $statusForClient = 'Waiting for Tagalys';
                        $waitingForTagalys = true;
                        break;
                    case 'finished':
                        $statusForClient = 'Finished';
                        break;
                }
                $storeResyncRequired = $this->tagalysConfiguration->getConfig("store:$storeId:resync_required");
                if ($storeResyncRequired == '1') {
                    $resyncScheduled = true;
                    if ($statusForClient == 'Finished') {
                        $statusForClient = 'Scheduled at 1 AM';
                    }
                }
                if ($statusForClient == 'Writing to file' || $statusForClient == 'Waiting to write to file') {
                    $completed_percentage = round(((int)$storeFeedStatus['completed_count'] / (int)$storeFeedStatus['products_count']) * 100, 2);
                    $statusForClient = $statusForClient . ' (completed '.$completed_percentage.'%)';
                }
                $thisStore['feed_status'] = $statusForClient;
            } else {
                $storesSyncRequired = true;
            }

            $storeUpdatesStatus = $this->tagalysConfiguration->getConfig("store:$storeId:updates_status", true);
            $remainingUpdates = $this->queueFactory->create()->getCollection()->count();
            if ($thisStore['setup_complete']) {
                if ($remainingUpdates > 0) {
                    $storesSyncRequired = true;
                    $thisStore['updates_status'] = $remainingUpdates . ' remaining';
                } else {
                    if ($storeUpdatesStatus == null) {
                        $thisStore['updates_status'] = 'Nothing to update';
                    } else {
                        switch($storeUpdatesStatus['status']) {
                            case 'generated_file':
                                $thisStore['updates_status'] = 'Generated file. Sending to Tagalys.';
                                $storesSyncRequired = true;
                                break;
                            case 'sent_to_tagalys':
                                $thisStore['updates_status'] = 'Waiting for Tagalys';
                                $waitingForTagalys = true;
                                break;
                            case 'finished':
                                $thisStore['updates_status'] = 'Finished';
                                break;
                        }
                    }
                }
            } else {
                if ($remainingUpdates > 0) {
                    $thisStore['updates_status'] = 'Waiting for feed sync';
                } else {
                    $thisStore['updates_status'] = 'Nothing to update';
                }
            }

            $syncStatus['stores'][$storeId] = $thisStore;
        }
        $syncStatus['client_side_work_completed'] = false;
        $configSyncRequired = $this->tagalysConfiguration->getConfig('config_sync_required');
        if ($storesSyncRequired == true || $configSyncRequired == '1') {
            if ($storesSyncRequired == true) {
                $syncStatus['status'] = 'Stores Sync Pending';
            } else {
                if ($configSyncRequired == '1') {
                    $syncStatus['status'] = 'Configuration Sync Pending';
                } else {
                    // should never come here
                    $syncStatus['status'] = 'Pending';
                }
            }
        } else {
            $syncStatus['client_side_work_completed'] = true;
            if ($waitingForTagalys) {
                $syncStatus['waiting_for_tagalys'] = true;
                $syncStatus['status'] = 'Waiting for Tagalys';
            } else {
                $syncStatus['status'] = 'Fully synced';
            }
        }

        if ($resyncScheduled) {
            $syncStatus['status'] = $syncStatus['status'] . '. Resync scheduled at 1 AM. You can resync manually by using the <strong>Trigger full products resync now</strong> option in the <strong>Support & Troubleshooting</strong> tab and then clicking on the <strong>Sync Manually</strong> button that will show below.';
        }

        return $syncStatus;
    }
}