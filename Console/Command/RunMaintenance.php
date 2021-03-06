<?php
namespace Tagalys\Sync\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class RunMaintenance extends Command
{
    public function __construct(
        \Magento\Framework\App\State $appState,
        \Tagalys\Sync\Helper\Sync $syncHelper,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration
    ) {
        $this->appState = $appState;
        $this->syncHelper = $syncHelper;
        $this->tagalysConfiguration = $tagalysConfiguration;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('tagalys:run_maintenance');
        $this->setDescription('Run Tagalys maintenance tasks');

        parent::configure();
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Magento\Framework\Exception\LocalizedException $exception) {
            // do nothing
        }
        $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
        $timeNow = $utcNow->format(\DateTime::ATOM);
        $this->tagalysConfiguration->setConfig('heartbeat:command:run_maintenance', $timeNow);

        $this->syncHelper->runMaintenance();

        $output->writeln("Done");
    }
}
