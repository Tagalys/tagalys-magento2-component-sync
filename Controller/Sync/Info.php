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
        \Magento\Framework\View\Page\Config $pageConfig,
        \Tagalys\Sync\Model\ConfigFactory $configFactory,
        \Magento\Framework\Filesystem $filesystem,
        \Tagalys\Sync\Helper\Product $tagalysProduct
    )
    {
        $this->jsonResultFactory = $jsonResultFactory;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
        $this->tagalysSync = $tagalysSync;
        $this->pageConfig = $pageConfig;
        $this->configFactory = $configFactory;
        $this->filesystem = $filesystem;
        $this->tagalysProduct = $tagalysProduct;
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
                        $info = array('config' => array(), 'files_in_media_folder' => array(), 'sync_status' => $this->tagalysSync->status());
                        $queueCollection = $this->configFactory->create()->getCollection()->setOrder('id', 'ASC');
                        foreach($queueCollection as $i) {
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
                        $resultJson->setData($info);
                        break;
                    case 'product_details':
                        $productDetails = array();
                        foreach ($this->tagalysConfiguration->getStoresForTagalys() as $storeId) {
                            $productDetailsForStore = (array) $this->tagalysProduct->productDetails($params['product_id'], $storeId);
                            $productDetails['store-'.$storeId] = $productDetailsForStore;
                        }
                        $resultJson->setData($productDetails);
                        break;
                }
            } else {
                $this->tagalysApi->log('warn', 'Invalid identification in InfoAction', array('params' => $params));
                $resultJson->setData(array('result' => false));
            }
        } else {
            throw new \Magento\Framework\Exception\NotFoundException(__('Missing params'));
            $resultJson->setData(array('result' => false));
        }

        return $resultJson;
    }
}