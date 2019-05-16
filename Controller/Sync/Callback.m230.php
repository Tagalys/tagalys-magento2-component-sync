<?php
 
namespace Tagalys\Sync\Controller\Sync;
 
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
 
class Callback extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    protected $jsonResultFactory;

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function __construct(
        Context $context,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Tagalys\Sync\Helper\Sync $tagalysSync,
        \Magento\Framework\View\Page\Config $pageConfig
    )
    {
        $this->jsonResultFactory = $jsonResultFactory;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
        $this->tagalysSync = $tagalysSync;
        $this->pageConfig = $pageConfig;
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
                $split = explode('media/tagalys/', $params['completed']);
                $filename = $split[1];
                if (is_null($filename)) {
                    $split = explode('media\/tagalys\/', $params['completed']);
                    $filename = $split[1];
                }
                if (is_null($filename)) {
                    $this->tagalysApi->log('error', 'Error in callbackAction. Unable to read filename', array('params' => $params));
                    $resultJson->setData(array('result' => false));
                }
                $this->tagalysSync->receivedCallback($params['identification']['store_id'], $filename);
                $resultJson->setData(array('result' => true));
            } else {
                $this->tagalysApi->log('warn', 'Invalid identification in callbackAction', array('params' => $params));
                $resultJson->setData(array('result' => false));
            }
        } else {
            throw new \Magento\Framework\Exception\NotFoundException(__('Missing params'));
            $resultJson->setData(array('result' => false));
        }

        return $resultJson;
    }
}