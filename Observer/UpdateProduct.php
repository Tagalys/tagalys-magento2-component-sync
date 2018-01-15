<?php
namespace Tagalys\Sync\Observer;

class UpdateProduct implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Tagalys\Sync\Helper\Queue $queueHelper
    )
    {
        $this->queueHelper = $queueHelper;
    }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $productId = $observer->getProduct()->getId();
        $this->queueHelper->insertUnique($productId);
    }
}