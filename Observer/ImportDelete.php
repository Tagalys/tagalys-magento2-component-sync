<?php
namespace Tagalys\Sync\Observer;

class ImportDelete implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Tagalys\Sync\Helper\Queue $queueHelper
    )
    {
        $this->queueHelper = $queueHelper;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $idsToDelete = $observer->getEvent()->getData('ids_to_delete');
        $this->queueHelper->insertUnique($idsToDelete);
    }
}