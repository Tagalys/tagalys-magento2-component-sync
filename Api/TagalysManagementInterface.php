<?php

namespace Tagalys\Sync\Api;


interface TagalysManagementInterface
{


    /**
     * POST for Info api
     * @param mixed $params
     * @return string
     */
    // ALERT: Test this in 2.0 - 2.1
    public function info($params);

    /**
     * POST for Categories api
     * @param mixed $category
     * @return string
     */
    public function categorySave($category);

    /**
     * POST for Categories api
     * @param mixed $storeIds
     * @param int $categoryId
     * @param boolean|null $forceDelete
     * @return string
     */
    public function categoryTryDelete($storeIds, $categoryId, $forceDelete = false);
}
