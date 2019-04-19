<?php

namespace App\Command\Misc;

use App\Command\CommandHelperTrait;
use App\Command\GameData\SaintCoinachRedisCommand;
use App\Service\Redis\Redis;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Lodestone\Api;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use XIVAPI\XIVAPI;

class DownloadLodestoneIconsCommand extends Command
{
    use CommandHelperTrait;

    // store our data so we don't have to re-download everything.
    const SAVED_LIST_FILENAME = __DIR__ . '/db.json';
    // url to grab lodestone info from, we can get this from the market
    const XIVAPI_MARKET_URL = '/market/phoenix/items/%s';
    // url to companion icon, which is a bit smaller than the lodestone one
    const COMPANION_ICON_URL = 'https://img.finalfantasyxiv.com/lds/pc/global/images/itemicon/%s.png';
    // path to icon directory
    const ICON_DIRECTORY = __DIR__.'/../../../public/i2/ls/';
    
    /** @var Client */
    private $guzzle;
    /** @var Api */
    private $lodestone;
    /** @var XIVAPI */
    private $xivapi;
    /** @var array */
    private $exceptions = [];
    /** @var int */
    private $saved = 0;
    /** @var array */
    private $completed = [];
    
    public function __construct(?string $name = null)
    {
        parent::__construct($name);
        
        $this->guzzle    = new Client([ 'base_uri' => 'https://xivapi.com' ]);
        $this->lodestone = new Api();
        $this->xivapi    = new XIVAPI();
    }
    
    protected function configure()
    {
        $this
            ->setName('DownloadLodestoneIconsCommand')
            ->setDescription('Downloads a bunch of info from Lodestone, including icons.')
            ->addArgument('item_id', InputArgument::OPTIONAL, 'Test Item');
        ;
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setSymfonyStyle($input, $output);
        $this->io->title('Lodestone Icon Downloader');
    
        $ids   = Redis::Cache()->get('ids_Item');
        $test  = $input->getArgument('item_id');
        
        // load out completed list
        $this->loadCompleted();
        
        $this->io->section('Downloading icons');
        $this->io->progressStart(count($ids));
        foreach ($ids as $i => $itemId) {
            $this->io->progressAdvance();
            
            // skip non test ones
            if ($test && $test != $itemId) {
                continue;
            }
            
            // if we've completed, skip it
            if ($test == null && in_array($itemId, $this->completed)) {
                continue;
            }

            // grab lodestone market data
            $lodestoneId = $this->getLodestoneId($itemId);

            // skip if no lodestone id
            if (!isset($lodestoneId)) {
                $this->markComplete(false, 'No lodestone ID', $itemId);
                continue;
            }
            
            
            // parse db page for the "big" icon
            try {
                $lodestoneItem = $this->lodestone->getDatabaseItem($lodestoneId);
            } catch (\Exception $ex) {
                $this->exceptions[$itemId] = $ex->getMessage();
                $this->markComplete(false, 'No lodestone database item icon', $itemId, $lodestoneMarket);
                continue;
            }
            
            // skip if it fields
            if ($lodestoneItem == null || empty($lodestoneItem->Icon)) {
                $this->markComplete(false, 'No lodestone database item icon', $itemId);
                continue;
            }
            
            // download both icons
            $this->downloadIcon($lodestoneItem->Icon, __DIR__.'/Icons/Lodestone/', $itemId);
            
            // save
            $this->markComplete(true, 'Download OK', $itemId, $lodestoneMarket, $lodestoneItem);
        }
        $this->io->progressFinish();

        // print exceptions
        $this->io->text(count($this->exceptions) . ' exceptions were recorded.');
        foreach ($this->exceptions as $itemId => $error) {
            $this->io->text("Exception: {$itemId} = {$error}");
        }
        
        // print saved total
        $this->io->text([ ' ', "Saved: {$this->saved} icons" ]);
        
        // copy icons
        $this->io->section('Copying files');
        $this->io->progressStart(count($ids));
        foreach ($ids as $i => $itemId) {
            $file = __DIR__."/Icons/Lodestone/{$itemId}.png";
            $this->io->progressAdvance();
            
            if (file_exists($file)) {
                copy($file, self::ICON_DIRECTORY . $itemId . ".png");
            }
        }
        $this->io->progressFinish();
    }
    
    /**
     * Load the item ids that have been completed.
     */
    private function loadCompleted()
    {
        $this->io->text('Loading the complete list');
        $saved = file_get_contents(self::SAVED_LIST_FILENAME);
        $saved = json_decode($saved);
        
        foreach ($saved as $itemId => $info) {
            $hasPassed = $info->Status;
            
            // ignore ones that are OK
            if ($hasPassed) {
                continue;
            }

            if (!empty($info->LodestoneMarket->Icon)) {
                continue;
            }
    
            $this->completed[] = $itemId;
        }
        
        $this->complete();
        unset($saved);
    }
    
    /**
     * marks an item as complete
     */
    private function markComplete($status, $message, $itemId, $lodestoneMarket = null, $lodestoneItem = null)
    {
        // load current saved list
        $saved = file_get_contents(self::SAVED_LIST_FILENAME);
        $saved = json_decode($saved);
        
        // append item
        $saved->{$itemId} = [
            'ItemID'          => $itemId,
            'Status'          => $status,
            'Message'         => $message,
            'LodestoneMarket' => $lodestoneMarket,
            'LodestoneItem'   => $lodestoneItem,
        ];
        
        // re save
        file_put_contents(self::SAVED_LIST_FILENAME, json_encode($saved, JSON_PRETTY_PRINT));
    }
    
    /**
     * Download the icons
     */
    private function downloadIcon($url, $path, $filename)
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        
        if (empty($url)) {
            return;
        }
        
        copy($url . "?t=" . time(), $path . $filename . ".png");
        $this->saved++;
    }
    
    /**
     * Grab lodestone data from Market API
     */
    private function getLodestoneId(int $itemId): ?\stdClass
    {
        $market = $this->xivapi->_private->itemPrices(
            getenv('SITE_CONFIG_COMPANION_TOKEN_PASS'),
            $itemId,
            'Phoenix'
        );
        
        return $market->eorzeadbItemId;
    }
}
