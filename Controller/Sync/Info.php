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
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Magento\Framework\View\Page\Config $pageConfig,
        \Tagalys\Sync\Model\ConfigFactory $configFactory,
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
        $this->queueHelper = $queueHelper;
        $this->pageConfig = $pageConfig;
        $this->configFactory = $configFactory;
        $this->mpagescacheFactory = $mpagescacheFactory;
        $this->filesystem = $filesystem;
        $this->tagalysProduct = $tagalysProduct;
        $this->tagalysMpages = $tagalysMpages;
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
                        foreach ($this->tagalysConfiguration->getStoresForTagalys() as $storeId) {
                            $this->tagalysMpages->updateSpecificMpageCache($storeId, $params['mpage']);
                        }
                        $response = array('updated' => true);
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