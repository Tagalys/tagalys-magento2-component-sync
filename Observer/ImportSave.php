<?php
namespace Tagalys\Sync\Observer;

class ImportSave implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Magento\Catalog\Model\Product $product,
        \Tagalys\Sync\Helper\Queue $queueHelper
    )
    {
        $this->product = $product;
        $this->queueHelper = $queueHelper;
    }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $bunch = $observer->getEvent()->getData('bunch');

        $productIds = array();
        foreach($bunch as $bunchItem) {
            $sku = strtolower($bunchItem['sku']);
            $productId = $this->product->getIdBySku($sku);
            array_push($productIds, $productId);
        }

        $this->queueHelper->insertUnique($productIds);
    }
}