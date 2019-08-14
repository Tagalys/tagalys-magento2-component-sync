<?php
namespace Tagalys\Sync\Observer;

class UpdateCategory implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Tagalys\Sync\Helper\Category $tagalysCategory
    )
    {
        $this->queueHelper = $queueHelper;
        $this->tagalysCategory = $tagalysCategory;
    }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $category = $observer->getEvent()->getCategory();
            $products = $category->getPostedProducts();
            if (!is_array($products)) {
                $products = array($products);
            }
            $oldProducts = $category->getProductsPosition();
            $insert = array_diff_key($products, $oldProducts);
            $delete = array_diff_key($oldProducts, $products);

            $insertedProductIds = array();
            $modifiedProductIds = array();
            foreach($insert as $productId => $pos) {
                array_push($insertedProductIds, $productId);
                array_push($modifiedProductIds, $productId);
            }
            foreach($delete as $productId => $pos) {
                array_push($modifiedProductIds, $productId);
            }
            $this->queueHelper->insertUnique($modifiedProductIds);
            if (count($insertedProductIds) > 0) {
                $this->tagalysCategory->updateProductCategoryPositionsIfRequired($insertedProductIds, array($category->getId()), 'category');
            }
        } catch (\Exception $e) { }
    }
}
