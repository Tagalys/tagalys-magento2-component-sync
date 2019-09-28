<?php
namespace Tagalys\Sync\Helper;

class Queue extends \Magento\Framework\App\Helper\AbstractHelper
{
    public function __construct(
        \Tagalys\Sync\Model\QueueFactory $queueFactory,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\ConfigurableProduct\Model\Product\Type\Configurable $configurableProduct,
        \Magento\Catalog\Model\ProductFactory $productFactory
    )
    {
        $this->queueFactory = $queueFactory;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->storeManager = $storeManager;
        $this->configurableProduct = $configurableProduct;
        $this->productFactory = $productFactory;

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/tagalys_core.log');
        $this->tagalysLogger = new \Zend\Log\Logger();
        $this->tagalysLogger->addWriter($writer);
    }

    public function insertUnique($productIds)
    {
        if (!is_array($productIds)) {
            $productIds = array($productIds);
        }
        foreach ($productIds as $i => $productId) {
            $queue = $this->queueFactory->create();
            if (!$queue->doesProductIdExist($productId)) {
                $queue->setProductId($productId);
                $queue->save();
                $queue = $this->queueFactory->create();
            }
        }
    }

    public function truncateIfEmpty() {
        $queue = $this->queueFactory->create();
        $count = $queue->getCollection()->getSize();
        if ($count == 0) {
            $this->truncate();
        }
    }

    public function truncate() {
        $queue = $this->queueFactory->create();
        $connection = $queue->getResource()->getConnection();
        $tableName = $queue->getResource()->getMainTable();
        $connection->truncateTable($tableName);
    }

    public function queuePrimaryProductIdFor($productId) {
        $primaryProductId = $this->getPrimaryProductId($productId);
        if ($primaryProductId === false) {
            // no related product id
        } elseif ($productId == $primaryProductId) {
            // same product. so no related product id.
        } else {
            // add primaryProductId and remove productId
            $this->insertUnique($primaryProductId);
        }
        return $primaryProductId;
    }

    public function _visibleInAnyStore($product) {
        $visible = false;
        $storesForTagalys = $this->tagalysConfiguration->getStoresForTagalys();
        foreach ($storesForTagalys as $storeId) {
            $this->storeManager->setCurrentStore($storeId);
            $productVisibility = $product->getVisibility();
            if ($productVisibility != 1) {
                $visible = true;
                break;
            }
        }
        return $visible;
    }

    public function getPrimaryProductId($productId) {
        $product = $this->productFactory->create()->load($productId);
        if ($product) {
            $productType = $product->getTypeId();
            $visibleInAnyStore = $this->_visibleInAnyStore($product);
            if (!$visibleInAnyStore) {
                // not visible individually
                if ($productType == 'simple' || $productType == 'virtual') {
                    // coulbe be attached to configurable product
                    $parentIds = $this->configurableProduct->getParentIdsByChild($productId);
                    if (count($parentIds) > 0) {
                        // check and return configurable product id
                        return $this->getPrimaryProductId($parentIds[0]);
                    }
                } else {
                    // configurable / grouped / bundled product that is not visible individually
                    return false;
                }
            } else {
                // any type of product that is visible individually. add to queue.
                return $productId;
            }
        } else {
            // product not found. might have to delete
            return $productId;
        }
    }
}