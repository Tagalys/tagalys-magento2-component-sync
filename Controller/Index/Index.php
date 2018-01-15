<?php
 
namespace Tagalys\Sync\Controller\Index;
 
use Magento\Framework\App\Action\Context;
 
class Index extends \Magento\Framework\App\Action\Action
{
    protected $_resultPageFactory;

    public function __construct(
        Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Tagalys\Sync\Helper\Product $productHelper,
        \Magento\Framework\View\Page\Config $pageConfig
    )
    {
        $this->_resultPageFactory = $resultPageFactory;
        $this->productHelper = $productHelper;
        $this->pageConfig = $pageConfig;
        parent::__construct($context);
    }
 
    public function execute()
    {
        $resultPage = $this->_resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->set('Tagalys');

        $this->pageConfig->setRobots('NOINDEX,NOFOLLOW');

        return $resultPage;
    }
}