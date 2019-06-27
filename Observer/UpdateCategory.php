<?php
namespace Tagalys\Sync\Observer;

class UpdateCategory implements \Magento\Framework\Event\ObserverInterface
{
  public function __construct(
      \Tagalys\Sync\Helper\Queue $queueHelper
  )
  {
      $this->queueHelper = $queueHelper;
  }
  public function execute(\Magento\Framework\Event\Observer $observer)
  {
    try {
      $category = $observer->getEvent()->getCategory();
      $products = $category->getPostedProducts();
      $oldProducts = $category->getProductsPosition();
      $insert = array_diff_key($products, $oldProducts);
      $delete = array_diff_key($oldProducts, $products);

      $productIds = array();
      foreach($insert as $productId => $pos) {
        array_push($productIds, $productId);
      }
      foreach($delete as $productId => $pos) {
        array_push($productIds, $productId);
      }
      
      $this->queueHelper->insertUnique($productIds);
    } catch (\Exception $e) {

    }
  }
}
