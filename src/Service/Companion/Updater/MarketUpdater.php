<?php

namespace App\Service\Companion\Updater;

use App\Entity\CompanionCharacter;
use App\Entity\CompanionItem;
use App\Entity\CompanionRetainer;
use App\Entity\CompanionToken;
use App\Repository\CompanionCharacterRepository;
use App\Repository\CompanionItemRepository;
use App\Repository\CompanionRetainerRepository;
use App\Service\Companion\CompanionConfiguration;
use App\Service\Companion\CompanionErrorHandler;
use App\Service\Companion\CompanionMarket;
use App\Service\Companion\Models\MarketHistory;
use App\Service\Companion\Models\MarketItem;
use App\Service\Companion\Models\MarketListing;
use App\Service\Content\GameServers;
use App\Service\ThirdParty\GoogleAnalytics;
use Companion\CompanionApi;
use Companion\Config\CompanionSight;
use Companion\Config\SightToken;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Auto-Update item price + history
 */
class MarketUpdater
{
    const PRICES  = 10;
    const HISTORY = 50;
    
    /** @var EntityManagerInterface */
    private $em;
    /** @var CompanionCharacterRepository */
    private $repositoryCompanionCharacter;
    /** @var CompanionRetainerRepository */
    private $repositoryCompanionRetainer;

    /** @var ConsoleOutput */
    private $console;
    /** @var CompanionMarket */
    private $market;
    /** @var CompanionErrorHandler */
    private $errorHandler;
    /** @var array */
    private $tokens = [];
    /** @var array */
    private $items = [];
    /** @var array */
    private $marketItemEntryUpdated = [];
    /** @var int */
    private $priority = 0;
    /** @var int */
    private $queue = 0;
    /** @var int */
    private $deadline = 0;
    /** @var array */
    private $startTime;

    public function __construct(
        EntityManagerInterface $em,
        CompanionMarket $companionMarket,
        CompanionErrorHandler $companionErrorHandler
    ) {
        $this->em           = $em;
        $this->market       = $companionMarket;
        $this->errorHandler = $companionErrorHandler;
        $this->console      = new ConsoleOutput();

        // repositories for market data
        $this->repositoryCompanionCharacter = $this->em->getRepository(CompanionCharacter::class);
        $this->repositoryCompanionRetainer  = $this->em->getRepository(CompanionRetainer::class);
    }
    
    public function update(int $queue)
    {
        /**
         * It feels like SE restart their servers every hour????
         */
        $minute = (int)date('i');
        if (in_array($minute, [7,8])) {
            $this->console("Skipping as minute: {$minute}");
            exit();
        }
    
        // init
        $this->console("Queue: {$queue}");
        $this->startTime = microtime(true);
        $this->deadline = time() + CompanionConfiguration::CRONJOB_TIMEOUT_SECONDS;
        $this->queue = $queue;
        $this->console('Starting!');
    
        // fetch tokens and items
        $this->fetchCompanionTokens();
        $this->fetchItemIdsToUpdate($queue);
    
        // no items? da fookz
        if (empty($this->items)) {
            $this->console('No items to update');
            $this->closeDatabaseConnection();
            return;
        }
        
        // initialize companion api
        $api = new CompanionApi();
        
        // settings
        CompanionSight::set('CLIENT_TIMEOUT', 2.5);
        CompanionSight::set('QUERY_LOOP_COUNT', 6);
        CompanionSight::set('QUERY_DELAY_MS', 1000);
        
        // begin
        // $this->tokens[$serverId]
        foreach ($this->items as $item) {
            $a = microtime(true);
            
            // Break if any errors or we're at the cronjob deadline
            if ($this->checkErrorCount() || $this->checkScriptDeadline()) {
                break;
            }
    
            // deeds
            $itemId     = $item['item'];
            $serverId   = $item['server'];
            $serverName = GameServers::LIST[$serverId];
            $serverDc   = GameServers::getDataCenter($serverName);
    
            if (!isset($this->tokens[$serverId]) || empty($this->tokens[$serverId])) {
                $this->console("No tokens for: {$serverName} {$serverDc}");
                continue;
            }
    
            // pick a random token
            $token = $this->tokens[$serverId][array_rand($this->tokens[$serverId])];
            
            // set token
            $api->Token()->set($token);
    
            /**
             * GET PRICES
             */
            $prices = $api->Market()->getItemMarketListings($itemId);
            GoogleAnalytics::companionTrackItemAsUrl("/{$itemId}/Prices");
            if ($this->checkResponseForErrors($item, $prices)) {
                break;
            }
    
            /**
             * GET HISTORY
             */
            $history = $api->Market()->getTransactionHistory($itemId);
            GoogleAnalytics::companionTrackItemAsUrl("/{$itemId}/History");
            if ($this->checkResponseForErrors($item, $history)) {
                break;
            }
    
            /**
             * Store in market
             */
            $this->storeMarketData($item, $prices, $history);
    
            /**
             * Log
             */
            $duration = round(microtime(true) - $a, 1);
            $this->console("{$itemId} on {$serverName} - {$serverDc} - Duration: {$duration}");
        }
    
        // update the database market entries with the latest updated timestamps
        $this->updateDatabaseMarketItemEntries();
        $this->em->flush();
    
        // finish, output completed duration
        $duration = round(microtime(true) - $this->startTime, 1);
        $this->console("-> Completed. Duration: <comment>{$duration}</comment>");
        $this->closeDatabaseConnection();
    }
    
    /**
     * Checks for any problems in the response
     */
    private function checkResponseForErrors($item, $response)
    {
        $itemId     = $item['item'];
        $serverId   = $item['server'];
        $serverName = GameServers::LIST[$serverId];
        $serverDc   = GameServers::getDataCenter($serverName);
        
        if (isset($response->state) && $response->state == "rejected") {
            $this->console("Response Rejected");
            $this->errorHandler->exception("Rejected", "RESPONSE REJECTED: {$itemId} : ({$serverId}) {$serverName} - {$serverDc}");
            GoogleAnalytics::companionTrackItemAsUrl('companion_rejected');
            return true;
        }
    
        if (isset($response->error) || isset($response->error)) {
            $this->console("Response Error");
            $this->errorHandler->exception($response->reason, "RESPONSE ERROR: {$itemId} : ({$serverId}) {$serverName} - {$serverDc}");
            GoogleAnalytics::companionTrackItemAsUrl('companion_error');
            return true;
        }
    
        // if responses are null
        if ($response == null) {
            $this->console("Response Empty");
            $this->errorHandler->exception('Empty Response', "RESPONSE EMPTY: {$itemId} : ({$serverId}) {$serverName} - {$serverDc}");
            GoogleAnalytics::companionTrackItemAsUrl('companion_empty');
            return true;
        }
        
        return false;
    }
    
    /**
     * Checks the current critical exception rate
     */
    private function checkErrorCount()
    {
        if ($this->errorHandler->getCriticalExceptionCount() >= CompanionConfiguration::ERROR_COUNT_THRESHOLD) {
            $this->console('Exceptions are above the ERROR_COUNT_THRESHOLD.');
            return true;
        }
        
        return false;
    }
    
    /**
     * Tests to see if the time deadline has hit
     */
    private function checkScriptDeadline()
    {
        // if we go over the deadline, we stop.
        if (time() > $this->deadline) {
            $this->console(date('H:i:s') ." | Ending auto-update as time limit seconds reached.");
            return true;
        }
        
        return false;
    }

    /**
     * Store the market data
    *
     * @param array $item
     * @param \stdClass $prices
     * @param \stdClass $history
     */
    private function storeMarketData($item, $prices, $history)
    {
        $itemId     = $item['item'];
        $server     = $item['server'];
    
        // update item entry
        $this->marketItemEntryUpdated[] = $item['id'];
    
        // grab market item document
        $marketItem = $this->getMarketItemDocument($server, $itemId);
    
        // record lodestone info
        $marketItem->LodestoneID = $prices->eorzeadbItemId;

        // CURRENT PRICES
        if ($prices && isset($prices->error) === false && $prices->entries) {
            // reset prices
            $marketItem->Prices = [];

            // append current prices
            foreach ($prices->entries as $row) {
                // try build a semi unique id
                $id = sha1(
                    implode("_", [
                        $itemId,
                        $row->isCrafted,
                        $row->hq,
                        $row->sellPrice,
                        $row->stack,
                        $row->registerTown,
                        $row->sellRetainerName,
                    ])
                );

                // grab internal records
                $row->_retainerId = $this->getInternalRetainerId($server, $row->sellRetainerName);
                $row->_creatorSignatureId = $this->getInternalCharacterId($server, $row->signatureName);

                // append prices
                $marketItem->Prices[] = MarketListing::build($id, $row);
            }

            // sort prices low -> high
            usort($marketItem->Prices, function($first,$second) {
                return $first->PricePerUnit > $second->PricePerUnit;
            });
        }

        // CURRENT HISTORY
        if ($history && isset($history->error) === false && $history->history) {
            foreach ($history->history as $row) {
                // build a custom ID based on a few factors (History can't change)
                // we don't include character name as I'm unsure if it changes if you rename yourself
                $id = sha1(
                    implode("_", [
                        $itemId,
                        $row->stack,
                        $row->hq,
                        $row->sellPrice,
                        $row->buyRealDate,
                    ])
                );

                // if this entry is in our history, then just finish
                $found = false;
                foreach ($marketItem->History as $existing) {
                    if ($existing->ID == $id) {
                        $found = true;
                        break;
                    }
                }

                // once we've found an existing entry we don't need to add anymore
                if ($found) {
                    break;
                }

                // grab internal record
                $row->_characterId = $this->getInternalCharacterId($server, $row->buyCharacterName);

                // add history to front
                array_unshift($marketItem->History, MarketHistory::build($id, $row));
            }

            // sort history new -> old
            usort($marketItem->History, function($first,$second) {
                return $first->PurchaseDate < $second->PurchaseDate;
            });
        }
        
        // save market item
        $this->market->set($marketItem);
    }
    
    /**
     * Get a single market item entry.
     */
    public function getMarketItemEntry(int $serverId, int $itemId)
    {
        return $this->em->getRepository(CompanionItem::class)->findOneBy([
            'server' => $serverId,
            'item'   => $itemId,
        ]);
    }
    
    /**
     * Get the elastic search document
     */
    private function getMarketItemDocument($server, $itemId): MarketItem
    {
        // return an existing one, otherwise return a new one
        return $this->market->get($server, $itemId, null, true);
    }
    
    /**
     * Returns the ID for internally stored retainers
     */
    private function getInternalRetainerId(int $server, string $name): ?string
    {
        return $this->handleMarketTrackingNames(
            $server,
            $name,
            $this->repositoryCompanionRetainer,
            CompanionRetainer::class
        );
    }
    
    /**
     * Returns the ID for internally stored character ids
     */
    private function getInternalCharacterId(int $server, string $name): ?string
    {
        return $this->handleMarketTrackingNames(
            $server,
            $name,
            $this->repositoryCompanionCharacter,
            CompanionCharacter::class
        );
    }
    
    /**
     * Handles the tracking logic for all name fields
     */
    private function handleMarketTrackingNames(int $server, string $name, ObjectRepository $repository, $class)
    {
        if (empty($name)) {
            return null;
        }
        
        $obj = $repository->findOneBy([
            'name'   => $name,
            'server' => $server,
        ]);
        
        if ($obj === null) {
            $obj = new $class($name, $server);
            $this->em->persist($obj);
            $this->em->flush();
        }
        
        return $obj->getId();
    }

    /**
     * Fetches items to auto-update, this is performed here as the entity
     * manager is quite slow for thousands of throughput every second.
     */
    private function fetchItemIdsToUpdate($queue)
    {
        // get items to update
        $this->console('Finding Item IDs to Auto-Update');
        $s = microtime(true);

        $sql = "
            SELECT id, item, server
            FROM companion_market_item_queue
            WHERE queue = {$queue}
            LIMIT ". CompanionConfiguration::MAX_ITEMS_PER_CRONJOB;
        
        $stmt = $this->em->getConnection()->prepare($sql);
        $stmt->execute();

        $this->items = $stmt->fetchAll();
        
        $sqlDuration = round(microtime(true) - $s, 2);
        $this->console("Obtained items in: {$sqlDuration} seconds");
    }

    /**
     * Fetch the companion tokens, this will randomly pick one for each server
     */
    private function fetchCompanionTokens()
    {
        $conn = $this->em->getConnection();
        $sql  = "SELECT server, online, token FROM companion_tokens";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        foreach ($stmt->fetchAll() as $arr) {
            $serverId = GameServers::getServerId($arr['server']);
    
            // skip offline tokens
            if ($arr['online'] == 0) {
                continue;
            }
            
            if (!isset($this->tokens[$serverId])) {
                $this->tokens[$serverId] = [];
            }
    
            $this->tokens[$serverId][] = json_decode($arr['token']);
        }
    }

    /**
     * Update item entry
     */
    private function updateDatabaseMarketItemEntries()
    {
        $this->console('Updating database item entries');
        $conn = $this->em->getConnection();

        foreach ($this->marketItemEntryUpdated as $id) {
            $sql = "UPDATE companion_market_items SET updated = ". time() .", patreon_queue = NULL WHERE id = '{$id}'";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
        }
    }

    /**
     * Write to log
     */
    private function console($text)
    {
        $this->console->writeln(date('Y-m-d H:i:s') . " | {$this->priority} | {$this->queue} | {$text}");
    }
    
    /**
     * Close the db connections
     */
    private function closeDatabaseConnection()
    {
        $this->em->flush();
        $this->em->clear();
        $this->em->close();
        $this->em->getConnection()->close();
    }
    
    /**
     * Mark an item to be manually updated on an DC
     */
    public function updateManual(int $itemId, int $server, int $queueNumber)
    {
        /** @var CompanionItemRepository $repo */
        $repo    = $this->em->getRepository(CompanionItem::class);
        $servers = GameServers::getDataCenterServersIds(GameServers::LIST[$server]);
        $items   = $repo->findItemsInServers($itemId, $servers);
        
        /** @var CompanionItem $item */
        foreach ($items as $item) {
            $item->setPatreonQueue($queueNumber);
            $this->em->persist($item);
        }
        
        $this->em->flush();
    }
}
