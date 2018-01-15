<?php

namespace Tagalys\Sync\Cron;
 
class Resync
{
    public function __construct(
        \Tagalys\Sync\Helper\Sync $tagalysSync,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Api $tagalysApi
    )
    {
        $this->tagalysSync = $tagalysSync;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
    }

    public function execute()
    {
        $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
        $timeNow = $utcNow->format(\DateTime::ATOM);

        $this->tagalysConfiguration->setConfig("heartbeat:resyncCron", $timeNow);
        $stores = $this->tagalysConfiguration->getStoresForTagalys();
        foreach ($stores as $i => $storeId) {
            $resync_required = $this->tagalysConfiguration->getConfig("store:$storeId:resync_required");
            if ($resync_required == '1') {
                $this->tagalysSync->triggerFeedForStore($storeId);
                $this->tagalysConfiguration->setConfig("store:$storeId:resync_required", '0');
            }
        }
    }
}