<?php

namespace Tagalys\Sync\Cron;
 
class Sync
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
        $cronHeartbeatSent = $this->tagalysConfiguration->getConfig("cron_heartbeat_sent");
        if ($cronHeartbeatSent == false) {
            $this->tagalysApi->log('info', 'Cron heartbeat');
            $cronHeartbeatSent = $this->tagalysConfiguration->setConfig("cron_heartbeat_sent", true);
        }
        $this->tagalysConfiguration->setConfig("heartbeat:cron", $timeNow);
        
        $this->tagalysSync->sync(100);
    }
}