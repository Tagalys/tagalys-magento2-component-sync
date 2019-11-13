<?php

namespace Tagalys\Sync\Api;


interface TagalysManagementInterface
{


    /**
     * POST for Post api
     * @param string $param
     * @return string
     */

    public function getPost($params);
    
    /**
     * POST for Post api
     * @param int $page
     * @param int $perPage
     * @return string
     */

    public function getProducts($page = 1, $perPage = 100);
}
