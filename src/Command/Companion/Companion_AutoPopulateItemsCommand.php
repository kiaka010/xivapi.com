<?php

namespace App\Command\Companion;

use App\Command\CommandConfigureTrait;
use App\Service\Companion\CompanionItemManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Companion_AutoPopulateItemsCommand extends Command
{
    use CommandConfigureTrait;

    const COMMAND = [
        'name' => 'Companion_AutoPopulateItemsCommand',
        'desc' => 'Automatically populate the companion market tracking database with item ids',
    ];

    /** @var CompanionItemManager */
    private $cim;

    public function __construct(CompanionItemManager $cim, $name = null)
    {
        $this->cim = $cim;
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->cim->populateMarketDatabaseWithItems();
    }
}
