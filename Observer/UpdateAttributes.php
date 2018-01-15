<?php
namespace Tagalys\Sync\Observer;

class UpdateAttributes implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Tagalys\Sync\Helper\Queue $queueHelper
    )
    {
        $this->queueHelper = $queueHelper;
    }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $updatedProductIds = $observer->getEvent()->getProductIds();
        $this->queueHelper->insertUnique($updatedProductIds);
    }
}