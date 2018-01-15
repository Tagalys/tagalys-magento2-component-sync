<?php

namespace Tagalys\Sync\Cron;
 
class PopularSearches
{
    public function __construct(
        \Tagalys\Sync\Helper\Sync $tagalysSync,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration
    )
    {
        $this->tagalysSync = $tagalysSync;
        $this->tagalysConfiguration = $tagalysConfiguration;
    }

    public function execute()
    {
        try {
            $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
            $timeNow = $utcNow->format(\DateTime::ATOM);
            $this->tagalysConfiguration->setConfig("heartbeat:cachePopularSearchesCron", $timeNow);
            $this->tagalysSync->cachePopularSearches();
        } catch (\Exception $e) {
            $this->tagalysApi->log('local', "Error in cachePopularSearchesCron: ". $e->getMessage(), array());
        }
    }
}