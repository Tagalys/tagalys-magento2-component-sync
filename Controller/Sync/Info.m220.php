<?php
 
namespace Tagalys\Sync\Controller\Sync;
 
use Magento\Framework\App\Action\Context;
 
class Info extends \Magento\Framework\App\Action\Action
{
    protected $jsonResultFactory;

    public function __construct(
        Context $context,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Tagalys\Sync\Helper\Sync $tagalysSync,
        \Tagalys\Sync\Helper\Category $tagalysCategoryHelper,
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Magento\Framework\View\Page\Config $pageConfig,
        \Tagalys\Sync\Model\ConfigFactory $configFactory,
        \Tagalys\Sync\Model\CategoryFactory $tagalysCategoryFactory,
        \Tagalys\Mpages\Model\MpagescacheFactory $mpagescacheFactory,
        \Magento\Framework\Filesystem $filesystem,
        \Tagalys\Sync\Helper\Product $tagalysProduct,
        \Tagalys\Mpages\Helper\Mpages $tagalysMpages
    )
    {
        $this->jsonResultFactory = $jsonResultFactory;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
        $this->tagalysSync = $tagalysSync;
        $this->tagalysCategoryHelper = $tagalysCategoryHelper;
        $this->queueHelper = $queueHelper;
        $this->pageConfig = $pageConfig;
        $this->configFactory = $configFactory;
        $this->tagalysCategoryFactory = $tagalysCategoryFactory;
        $this->mpagescacheFactory = $mpagescacheFactory;
        $this->filesystem = $filesystem;
        $this->tagalysProduct = $tagalysProduct;
        $this->tagalysMpages = $tagalysMpages;
        
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/tagalys_api.log');
        $this->logger = new \Zend\Log\Logger();
        $this->logger->addWriter($writer);

        parent::__construct($context);
    }

    public function _checkPrivateIdentification($identification) {
        $apiCredentials = $this->tagalysConfiguration->getConfig('api_credentials', true);
        return ($identification['client_code'] == $apiCredentials['client_code'] && $identification['api_key'] == $apiCredentials['private_api_key']);
    }
 
    public function execute()
    {
        $this->pageConfig->setRobots('NOINDEX,NOFOLLOW');

        $resultJson = $this->jsonResultFactory->create();
        
        $params = $this->getRequest()->getParams();

        if (array_key_exists('identification', $params)) {
            if ($this->_checkPrivateIdentification($params['identification'])) {
                switch($params['info_type']) {
                    case 'status':
                        try {
                            $info = array('config' => array(), 'files_in_media_folder' => array(), 'sync_status' => $this->tagalysSync->status());
                            $configCollection = $this->configFactory->create()->getCollection()->setOrder('id', 'ASC');
                            foreach($configCollection as $i) {
                                $info['config'][$i->getData('path')] = $i->getData('value');
                            }
                            $mediaDirectory = $this->filesystem->getDirectoryRead('media')->getAbsolutePath('tagalys');
                            $filesInMediaDirectory = scandir($mediaDirectory);
                            foreach ($filesInMediaDirectory as $key => $value) {
                                if (!is_dir($mediaDirectory . DIRECTORY_SEPARATOR . $value)) {
                                    if (!preg_match("/^\./", $value)) {
                                        $info['files_in_media_folder'][] = $value;
                                    }
                                }
                            }
                            $response = $info;  
                        } catch (Exception $e) {
                            $response = array('result' => false, 'exception' => true);
                            $this->tagalysApi->log('warn', 'Error in indexAction: ' . $e->getMessage(), array('params' => $params));
                        }
                        break;
                    case 'mpages_cache':
                        $cache = array();
                        $mpagescacheCollection = $this->mpagescacheFactory->create()->getCollection()->setOrder('id', 'ASC');
                        foreach($mpagescacheCollection as $i) {
                            array_push($cache, array('id' => $i->getId(), 'store_id' => $i->getStoreId(), 'url' => $i->getUrl(), 'cachedata' => $i->getCachedata()));
                        }
                        $response = $cache;
                        break;
                    case 'product_details':
                        $productDetails = array();
                        foreach ($this->tagalysConfiguration->getStoresForTagalys() as $storeId) {
                            $productDetailsForStore = (array) $this->tagalysProduct->productDetails($params['product_id'], $storeId);
                            $productDetails['store-'.$storeId] = $productDetailsForStore;
                        }
                        $response = $productDetails;
                        break;
                    case 'reset_sync_statuses':
                        $this->queueHelper->truncate();
                        foreach ($this->tagalysConfiguration->getStoresForTagalys() as $storeId) {
                            $sync_types = array('updates', 'feed');
                            foreach($sync_types as $sync_type) {
                              $syncTypeStatus = $this->tagalysConfiguration->getConfig("store:$storeId:" . $sync_type . "_status", true);
                              $syncTypeStatus['status'] = 'finished';
                              $feed_status = $this->tagalysConfiguration->setConfig("store:$storeId:" . $sync_type . "_status", json_encode($syncTypeStatus));
                            }
                        }
                        $response = array('reset' => true);
                        break;
                    case 'trigger_full_product_sync':
                        $this->tagalysApi->log('warn', 'Triggering full products resync via API', array('force_regenerate_thumbnails' => ($params['force_regenerate_thumbnails'] == 'true')));
                        foreach ($this->tagalysConfiguration->getStoresForTagalys() as $storeId) {
                            if (isset($params['products_count'])) {
                                $this->tagalysSync->triggerFeedForStore($storeId, ($params['force_regenerate_thumbnails'] == 'true'), $params['products_count'], true);
                            } else {
                                $this->tagalysSync->triggerFeedForStore($storeId, ($params['force_regenerate_thumbnails'] == 'true'), false, true);
                            }
                        }
                        $this->queueHelper->truncate();
                        $response = array('triggered' => true);
                        break;
                    case 'insert_into_sync_queue':
                        $this->tagalysApi->log('warn', 'Inserting into sync queue via API', array('product_ids' => $params['product_ids']));
                        $this->queueHelper->insertUnique($params['product_ids']);
                        $response = array('inserted' => true);
                        break;
                    case 'truncate_sync_queue':
                        $this->tagalysApi->log('warn', 'Truncating sync queue via API');
                        $this->queueHelper->truncate();
                        $response = array('truncated' => true);
                        break;
                    case 'update_mpages_cache':
                        $this->tagalysApi->log('warn', 'Updating Merchandised Pages cache via API');
                        $this->tagalysMpages->updateMpagesCache();
                        $response = array('updated' => true);
                        break;
                    case 'update_specific_mpage_cache':
                        $this->tagalysApi->log('warn', 'Updating specific Merchandised Page cache via API', array('mpage' => $params['mpage']));
                        $renderingMethod = $this->tagalysConfiguration->getConfig('listing_pages:rendering_method');
                        if (($params['platform'] === true || $params['platform'] === 'true') && $renderingMethod == 'platform') {
                            // magento category page and rendering method is platform
                            $this->tagalysCategoryHelper->markPositionsSyncRequired($params['identification']['store_id'], explode('-', $params['id'])[1]);
                        } else {
                            // tagalys page or platform page where rendering is tagalys_js
                            foreach ($this->tagalysConfiguration->getStoresForTagalys() as $storeId) {
                                $this->tagalysMpages->updateSpecificMpageCache($storeId, (($params['platform'] === true || $params['platform'] === 'true') ? 1 : 0), $params['mpage']);
                            }
                        }
                        $response = array('updated' => true);
                        break;
                    case 'mark_positions_sync_required_for_categories':
                        $this->tagalysCategoryHelper->markPositionsSyncRequiredForCategories($params['identification']['store_id'], $params['category_ids']);
                        $response = array('updated' => true);
                        break;
                    case 'get_categories_powered_by_tagalys':
                        $categories = array();
                        $tagalysCategoryCollection = $this->tagalysCategoryFactory->create()->getCollection()->setOrder('id', 'ASC');
                        foreach($tagalysCategoryCollection as $i) {
                            $fields = array('id', 'category_id', 'store_id', 'positions_synced_at', 'positions_sync_required', 'marked_for_deletion', 'status');
                            $categoryData = array();
                            foreach($fields as $field) {
                                $categoryData[$field] = $i->getData($field);
                            }
                            array_push($categories, $categoryData);
                        }
                        $response = array('categories' => $categories);
                        break;
                    case 'update_tagalys_health_status':
                        if (isset($params['value']) && in_array($params['value'], array('1', '0'))) {
                            $this->tagalysConfiguration->setConfig("tagalys:health", $params['value']);
                        } else {
                            $this->tagalysSync->updateTagalysHealth();
                        }
                        $response = array('health_status' => $this->tagalysConfiguration->getConfig("tagalys:health"));
                        break;
                    case 'get_tagalys_health_status':
                        $response = array('health_status' => $this->tagalysConfiguration->getConfig("tagalys:health"));
                        break;
                    case 'update_tagalys_plan_features':
                        $this->tagalysConfiguration->setConfig('tagalys_plan_features', $params['plan_features'], true);
                        $response = array('updated' => true);
                        break;
                    case 'create_category':
                        try{
                            $this->logger->info("create_category: params: ".json_encode($params));
                            $categoryId = $this->tagalysCategoryHelper->createCategory($params['store_id'], $params['category_details']);
                            $response = ['status'=> 'OK', 'category_id'=> $categoryId];
                        } catch(\Exception $e){
                            $response = ['status'=> 'error', 'message' => $e->getMessage()];
                        }
                        break;
                    case 'update_category':
                        try{
                            $this->logger->info("update_category: params: ".json_encode($params));
                            $res = $this->tagalysCategoryHelper->updateCategoryDetails($params['category_id'], $params['category_details']);
                            if($res) {
                                $response = ['status'=>'OK', 'message'=>$res];
                            } else {
                                $response = ['status'=>'error', 'message'=>'Unknown error occurred'];
                            }
                        } catch(\Exception $e){
                            $response = ['status'=> 'error', 'message' => $e->getMessage()];
                        }
                        break;
                    case 'delete_tagalys_category':
                        try{
                            $this->logger->info("delete_tagalys_category: params: ".json_encode($params));
                            $res = $this->tagalysCategoryHelper->deleteTagalysCategory($params['category_id']);
                            if($res) {
                                $response = ['status'=>'OK', 'message'=>$res];
                            } else {
                                $response = ['status'=>'error', 'message'=>'Unknown error occurred'];
                            }
                        } catch(\Exception $e){
                            $response = ['status'=> 'error', 'message' => $e->getMessage()];
                        }
                        break;
                    case 'assign_products_to_category_and_remove':
                        try{
                            $this->logger->info("assign_products_to_category_and_remove: params: ".json_encode($params));
                            if($params['product_positions'] == -1){
                                $params['product_positions'] = [];
                            }
                            $res = $this->tagalysCategoryHelper->bulkAssignProductsToCategoryAndRemove($params['category_id'], $params['product_positions']);
                            if($res) {
                                $response = ['status'=>'OK', 'message'=>$res];
                            } else {
                                $response = ['status'=>'error', 'message'=>'Unknown error occurred'];
                            }
                        } catch(\Exception $e){
                            $response = ['status'=> 'error', 'message' => $e->getMessage()];
                        }
                        break;
                    case 'update_product_positions':
                        try{
                            $this->logger->info("update_product_positions: params: ".json_encode($params));
                            if($params['product_positions'] == -1){
                                $params['product_positions'] = [];
                            }
                            $res = $this->tagalysCategoryHelper->performCategoryPositionUpdate($params['identification']['store_id'], $params['category_id'], $params['product_positions']);
                            if($res) {
                                $response = ['status'=>'OK', 'message'=>$res];
                            } else {
                                $response = ['status'=>'error', 'message'=>'Unknown error occurred'];
                            }
                        } catch(\Exception $e){
                            $response = ['status'=> 'error', 'message' => $e->getMessage()];
                        }
                        break;
                    case 'get_plugin_version':
                        $response = ['status' => 'OK', 'plugin_version' => $this->tagalysApi->getPluginVersion()];
                        break;
                    case 'ping':
                        $response = ['status' => 'OK', 'message' => 'pong'];
                        break;
                    case 'get_tagalys_logs':
                        try{
                            if (empty($params['lines'])) {
                                $params['lines'] = 10;
                            }
                            ob_start();
                            passthru('tail -n'. escapeshellarg($params['lines']).' var/log/tagalys_'.escapeshellarg($params['file']).'.log');
                            $response = ['status' => 'OK', 'message' => explode("\n",trim(ob_get_clean()))];
                        } catch(\Exception $e) {
                            $response = ['status' => 'error', 'message' => $e->getMessage()];
                        }
                        break;
                    case 'set_default_sort_by':
                        // called after category save using Magento API.
                        try {
                            $this->logger->info("set_default_sort_by: params: " . json_encode($params));
                            $this->tagalysCategoryHelper->reindexFlatCategories();
                            $res = $this->tagalysCategoryHelper->setDefaultSortBy($params['category_id']);
                            if ($res) {
                                $response = ['status' => 'OK', 'message' => $res];
                            } else {
                                $response = ['status' => 'error', 'message' => 'Unknown error occurred'];
                            }
                        } catch (\Exception $e) {
                            $response = ['status' => 'error', 'message' => $e->getMessage()];
                        }
                        break;
                    case 'update_category_pages_store_mapping':
                        $this->tagalysConfiguration->setConfig('category_pages_store_mapping', $params['store_mapping'], true);
                        $response = array('updated' => true, $params['store_mapping']);
                        break;
                }
            } else {
                $this->tagalysApi->log('warn', 'Invalid identification in InfoAction', array('params' => $params));
                $response = array('result' => false);
            }
        } else {
            throw new \Magento\Framework\Exception\NotFoundException(__('Missing params'));
            $response = array('result' => false);
        }

        $resultJson->setData($response);

        return $resultJson;
    }
}