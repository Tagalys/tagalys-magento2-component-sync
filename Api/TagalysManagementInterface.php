<?php

namespace Tagalys\Sync\Api;


interface TagalysManagementInterface
{


    /**
     * POST for Post api
     * @param mixed $params
     * @return string
     */
    // ALERT: Test this in 2.0 - 2.1
    public function info($params);

    /**
     * POST for Post api
     * @param mixed $category
     * @return string
     */
    public function categorySave($category);
}
