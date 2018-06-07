<?php
namespace Tagalys\Sync\Helper;

class Product extends \Magento\Framework\App\Helper\AbstractHelper
{
    public function __construct(
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\ConfigurableProduct\Api\LinkManagementInterface $linkManagement,
        \Magento\GroupedProduct\Model\Product\Type\Grouped $grouped,
        \Magento\Framework\Stdlib\DateTime\DateTime $datetime,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezoneInterface,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Catalog\Model\Product\Media\Config $productMediaConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Image\AdapterFactory $imageFactory,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Catalog\Api\CategoryRepositoryInterface $categoryRepository
    )
    {
        $this->productFactory = $productFactory;
        $this->linkManagement = $linkManagement;
        $this->grouped = $grouped;
        $this->datetime = $datetime;
        $this->timezoneInterface = $timezoneInterface;
        $this->directoryList = $directoryList;
        $this->scopeConfig = $scopeConfig;
        $this->productMediaConfig = $productMediaConfig;
        $this->storeManager = $storeManager;
        $this->imageFactory = $imageFactory;
        $this->filesystem = $filesystem;
        $this->categoryRepository = $categoryRepository;
    }

    public function getPlaceholderImageUrl() {
        try {
            return $this->storeManager->getStore()->getBaseUrl('media') . $this->productMediaConfig->getBaseMediaUrlAddition() . DIRECTORY_SEPARATOR . 'placeholder' . DIRECTORY_SEPARATOR . $this->scopeConfig->getValue('catalog/placeholder/small_image_placeholder');
        } catch(\Exception $e) {
            return null;
        }
    }

    public function getProductImageUrl($product, $forceRegenerateThumbnail) {
        try {
            $productImagePath = $product->getImage();
            if ($productImagePath != null) {
                $baseProductImagePath = $this->filesystem->getDirectoryRead('media')->getAbsolutePath($this->productMediaConfig->getBaseMediaUrlAddition()) . $productImagePath;
                // $baseProductImagePath = $this->directoryList->getPath('media') . DIRECTORY_SEPARATOR . "catalog" . DIRECTORY_SEPARATOR . "product" . $productImagePath;
                if(file_exists($baseProductImagePath)) {
                    $imageDetails = getimagesize($baseProductImagePath);
                    $width = $imageDetails[0];
                    $height = $imageDetails[1];
                    if ($width > 1 && $height > 1) {
                        $resizedProductImagePath = $this->filesystem->getDirectoryRead('media')->getAbsolutePath('tagalys/product_images') . $productImagePath;
                        // $resizedProductImagePath = $this->directoryList->getPath('media') . DIRECTORY_SEPARATOR . 'tagalys' . DIRECTORY_SEPARATOR . 'product_thumbnails' . $productImagePath;
                        if ($forceRegenerateThumbnail || !file_exists($resizedProductImagePath)) {
                            if (file_exists($resizedProductImagePath)) {
                                unlink($resizedProductImagePath);
                            }
                            $imageResize = $this->imageFactory->create();
                            $imageResize->open($baseProductImagePath);
                            $imageResize->constrainOnly(TRUE);
                            $imageResize->keepTransparency(TRUE);
                            $imageResize->keepFrame(FALSE);
                            $imageResize->keepAspectRatio(TRUE);
                            $imageResize->resize(300, 300);
                            $imageResize->save($resizedProductImagePath);
                        }
                        if (file_exists($resizedProductImagePath)) {
                            return str_replace('http:', '', $this->storeManager->getStore()->getBaseUrl('media') . 'tagalys/product_images' . $productImagePath);
                        } else {
                            return $this->getPlaceholderImageUrl();
                        }
                    }
                } else {
                    return $this->getPlaceholderImageUrl();
                }
            } else {
                return $this->getPlaceholderImageUrl();
            }
        } catch(\Exception $e) {
            // Mage::log("Exception in getProductImageUrl: {$e->getMessage()}", null, 'tagalys-image-generation.log');
            return $this->getPlaceholderImageUrl();
        }
    }

    public function getProductFields($product) {
        $productFields = array();
        $attributes = $product->getTypeInstance()->getEditableAttributes($product);
        $attributesToIgnore = array();
        if ($product->getTypeId() === "configurable") {
            $attributesToIgnore = array_map(function ($el) {
                return $el['attribute_code'];
            }, $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product));
        }
        foreach ($attributes as $attribute) {
            if (!in_array($attribute->getAttributeCode(), $attributesToIgnore)) {
                $isForDisplay = ((bool)$attribute->getUsedInProductListing() && (bool)$attribute->getIsUserDefined());
                if ($attribute->getIsFilterable() || $attribute->getIsSearchable() || $isForDisplay) {

                    if (!in_array($attribute->getAttributeCode(), array('status', 'tax_class_id')) && $attribute->getFrontendInput() != 'multiselect') {
                        $attributeValue = $attribute->getFrontend()->getValue($product);
                        if (!is_null($attributeValue)) {
                            if ($attribute->getFrontendInput() == 'boolean') {
                                $productFields[$attribute->getAttributeCode()] = ($attributeValue == 'Yes');
                            } else {
                                $productFields[$attribute->getAttributeCode()] = $attributeValue;
                            }
                        }
                    }
                }
            }
        }
        return $productFields;
    }

    public function getProductTags($product, $storeId) {
        $productTags = array();

        // categories
        array_push($productTags, array("tag_set" => array("id" => "__categories", "label" => "Categories" ), "items" => $this->getProductCategories($product, $storeId)));

        // other attributes
        $attributes = $product->getTypeInstance()->getEditableAttributes($product);
        foreach ($attributes as $attribute) {
            $isForDisplay = ((bool)$attribute->getUsedInProductListing() && (bool)$attribute->getIsUserDefined());
            if (!in_array($attribute->getAttributeCode(), array('status', 'tax_class_id')) && !in_array($attribute->getFrontendInput(), array('boolean')) && ($attribute->getIsFilterable() || $attribute->getIsSearchable() || $isForDisplay)) {
                $productAttribute = $product->getResource()->getAttribute($attribute->getAttributeCode());
                if ($productAttribute->usesSource()) {
                    // select, multi-select
                    $fieldType = $productAttribute->getFrontendInput();
                    $items = array();
                    if ($fieldType == 'multiselect') {
                        $value = $product->getData($attribute->getAttributeCode());
                        $ids = explode(',', $value);
                        foreach ($ids as $id) {
                            $label = $attribute->setStoreId($storeId)->getSource()->getOptionText($id);
                            if ($id != null && $label != false) {
                                $items[] = array('id' => $id, 'label' => $label);
                            }
                        }
                    } else {
                        $value = $product->getData($attribute->getAttributeCode());
                        $label = $productAttribute->setStoreId($storeId)->getFrontend()->getOption($value);
                        if ($value != null && $label != false) {
                            $items[] = array('id' => $value, 'label' => $label);
                        }
                    }
                    if (count($items) > 0) {
                        array_push($productTags, array("tag_set" => array("id" => $attribute->getAttributeCode(), "label" => $productAttribute->getStoreLabel($storeId), 'type' => $fieldType ),"items" => $items));
                    }
                }
            }
        }

        if ($product->getTypeId() === "configurable") {
            $configurableAttributes = array_map(function ($el) {
                return $el['attribute_code'];
            }, $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product));

            $associatedProducts = $this->linkManagement->getChildren($product->getSku());

            foreach($configurableAttributes as $configurableAttribute) {
                $items = array();
                $ids = array();
                foreach($associatedProducts as $associatedProduct){
                    if ($associatedProduct->isSaleable()) {
                        if (!in_array($associatedProduct->getData($configurableAttribute), $ids)) {
                            $ids[] = $associatedProduct->getData($configurableAttribute);
                            $items[] = array('id' => $associatedProduct->getData($configurableAttribute), 'label' => $associatedProduct->setStoreId($storeId)->getAttributeText($configurableAttribute));
                        }
                    }
                }
                if (count($items) > 0) {
                    array_push($productTags, array( "tag_set" => array("id" => $configurableAttribute, "label" => $product->getResource()->getAttribute($configurableAttribute)->getStoreLabel($storeId)), "items" => $items));
                }
            }
        }

        return $productTags;
    }

    public function mergeIntoCategoriesTree($categoriesTree, $pathIds) {
        $pathIdsCount = count($pathIds);
        if (!array_key_exists($pathIds[0], $categoriesTree)) {
            $categoriesTree[$pathIds[0]] = array();
        }
        if ($pathIdsCount > 1) {
            $categoriesTree[$pathIds[0]] = $this->mergeIntoCategoriesTree($categoriesTree[$pathIds[0]], array_slice($pathIds, 1));
        }
        return $categoriesTree;
    }

    public function detailsFromCategoryTree($categoriesTree, $storeId) {
        $detailsTree = array();
        foreach($categoriesTree as $categoryId => $subCategoriesTree) {
            $category = $this->categoryRepository->get($categoryId, $storeId);
            if ($category->getIsActive()) {
                $thisCategoryDetails = array("id" => $category->getId() , "label" => $category->getName());
                $subCategoriesCount = count($subCategoriesTree);
                if ($subCategoriesCount > 0) {
                    $thisCategoryDetails['items'] = $this->detailsFromCategoryTree($subCategoriesTree, $storeId);
                }
                array_push($detailsTree, $thisCategoryDetails);
            }
        }
        return $detailsTree;
    }

    public function getProductCategories($product, $storeId) {
        $categoryIds =  $product->getCategoryIds();
        $activeCategoryPaths = array();
        foreach ($categoryIds as $key => $value) {
            $category = $this->categoryRepository->get($value, $this->storeManager->getStore()->getId());
            if ($category->getIsActive()) {
                $activeCategoryPaths[] = $category->getPath();
            }
        }
        $activeCategoriesTree = array();
        foreach($activeCategoryPaths as $activeCategoryPath) {
            $pathIds = explode('/', $activeCategoryPath);
            // skip the first two levels which are 'Root Catalog' and the Store's root
            $pathIds = array_splice($pathIds, 2);
            if (count($pathIds) > 0) {
                $activeCategoriesTree = $this->mergeIntoCategoriesTree($activeCategoriesTree, $pathIds);
            }
        }
        $activeCategoryDetailsTree = $this->detailsFromCategoryTree($activeCategoriesTree, $storeId);
        return $activeCategoryDetailsTree;
    }

    public function getProductForPrices($product, $storeId) {
        $productForPrices = $product;
        switch($product->getTypeId()) {
            case 'configurable':
                $minSalePrice = null;
                foreach($this->linkManagement->getChildren($product->getSku()) as $connectedProduct) {
                    $connectedProductId = $connectedProduct->getId();
                    if ($connectedProductId == NULL) {
                        $p = $this->productFactory->create()->setStoreId($storeId);
                        $connectedProduct = $p->load($p->getIdBySku($connectedProduct->getSku()));
                    } else {
                        $connectedProduct = $this->productFactory->create()->setStoreId($storeId)->load($connectedProductId);
                    }
                    $thisSalePrice = $connectedProduct->getFinalPrice();
                    if ($minSalePrice == null || $minSalePrice > $thisSalePrice) {
                        $minSalePrice = $thisSalePrice;
                        $productForPrices = $connectedProduct;
                    }
                }
                break;
            case 'grouped':
                $minSalePrice = null;
                foreach($product->getTypeInstance()->getAssociatedProductIds($product) as $connectedProductId) {
                    $connectedProduct = $this->productFactory->create()->setStoreId($storeId)->load($connectedProductId);
                    $thisSalePrice = $connectedProduct->getFinalPrice();
                    if ($minSalePrice == null || $minSalePrice > $thisSalePrice) {
                        $minSalePrice = $thisSalePrice;
                        $productForPrices = $connectedProduct;
                    }
                }
                break;
        }
        return $productForPrices;
    }

    public function productDetails($id, $storeId, $forceRegenerateThumbnail = false) {
        $product = $this->productFactory->create()->setStoreId($storeId)->load($id);

        $productDetails = array(
            '__id' => $product->getId(),
            'name' => $product->getName(),
            'link' => $this->productFactory->create()->load($id)->getProductUrl(),
            'sku' => $product->getSku(),
            'scheduled_updates' => array(),
            'introduced_at' => date(\DateTime::ATOM, strtotime($product->getCreatedAt())),
            'in_stock' => $product->isSaleable(),
            'image_url' => $this->getProductImageUrl($product, $forceRegenerateThumbnail),
            '__tags' => $this->getProductTags($product, $storeId)
        );

        $productDetails = array_merge($productDetails, $this->getProductFields($product));

        // synced_at
        $utc_now = new \DateTime("now", new \DateTimeZone('UTC'));
        $time_now =  $utc_now->format(\DateTime::ATOM);
        $productDetails['synced_at'] = $time_now;

        // prices and sale price from/to
        if ($product->getTypeId() == 'bundle') {
            $productDetails['price'] = $product->getPriceModel()->getTotalPrices($product, 'min', 1);
            $productDetails['sale_price'] = $product->getPriceModel()->getTotalPrices($product, 'min', 1);
        } else {
            $productForPrices = $this->getProductForPrices($product, $storeId);
            $productDetails['price'] = $productForPrices->getPrice();
            $productDetails['sale_price'] = $productForPrices->getFinalPrice();
            if ($productForPrices->getSpecialFromDate() != null) {
                $special_price_from_datetime = new \DateTime($productForPrices->getSpecialFromDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                $current_datetime = new \DateTime("now", new \DateTimeZone('UTC'));
                if ($current_datetime->getTimestamp() >= $special_price_from_datetime->getTimestamp()) {
                    if ($productForPrices->getSpecialToDate() != null) {
                        $special_price_to_datetime = new \DateTime($productForPrices->getSpecialToDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                        if ($current_datetime->getTimestamp() <= ($special_price_to_datetime->getTimestamp() + 24*60*60 - 1)) {
                            // sale price is currently valid. record to date
                            array_push($productDetails['scheduled_updates'], array('at' => str_replace('00:00:00', '23:59:59', $special_price_to_datetime->format('Y-m-d H:i:sP')), 'updates' => array('sale_price' => $productDetails['price'])));
                        } else {
                            // sale is past expiry; don't record from/to datetimes
                        }
                    } else {
                        // sale price is valid indefinitely; make no changes;
                    }
                } else {
                    // future sale - record other sale price and from/to datetimes
                    $specialPrice = $productForPrices->getSpecialPrice();
                    if ($specialPrice != null && $specialPrice > 0) {
                        $special_price_from_datetime = new \DateTime($productForPrices->getSpecialFromDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                        array_push($productDetails['scheduled_updates'], array('at' => $special_price_from_datetime->format('Y-m-d H:i:sP'), 'updates' => array('sale_price' => $productForPrices->getSpecialPrice())));
                        if ($productForPrices->getSpecialToDate() != null) {
                            $special_price_to_datetime = new \DateTime($productForPrices->getSpecialToDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                            array_push($productDetails['scheduled_updates'], array('at' => str_replace('00:00:00', '23:59:59', $special_price_to_datetime->format('Y-m-d H:i:sP')), 'updates' => array('sale_price' => $productDetails['price'])));
                        }
                    }
                }
            }
        }

        return $productDetails;
    }
}