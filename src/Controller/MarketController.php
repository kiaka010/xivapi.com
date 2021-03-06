<?php

namespace App\Controller;

use App\Entity\CompanionToken;
use App\Exception\InvalidCompanionMarketRequestException;
use App\Exception\InvalidCompanionMarketRequestServerSizeException;
use App\Service\Companion\Companion;
use App\Service\Companion\CompanionConfiguration;
use App\Service\Companion\CompanionErrorHandler;
use App\Service\Companion\CompanionItemManager;
use App\Service\Companion\CompanionMarket;
use App\Service\Companion\CompanionStatistics;
use App\Service\Companion\CompanionTokenManager;
use App\Service\Content\GameServers;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @package App\Controller
 */
class MarketController extends AbstractController
{
    const DEFAULT_MAX_HISTORY = 100;
    
    /** @var CompanionStatistics */
    private $companionStatistics;
    /** @var CompanionMarket */
    private $companionMarket;
    /** @var CompanionItemManager */
    private $companionItemManager;
    /** @var CompanionErrorHandler */
    private $companionErrorHandler;
    /** @var CompanionTokenManager */
    private $companionTokenManager;
    /** @var Companion */
    private $companion;
    
    public function __construct(
        Companion $companion,
        CompanionMarket $companionMarket,
        CompanionItemManager $companionItemManager,
        CompanionStatistics $companionStatistics,
        CompanionErrorHandler $companionErrorHandler,
        CompanionTokenManager $companionTokenManager
    ) {
        $this->companion                = $companion;
        $this->companionMarket          = $companionMarket;
        $this->companionItemManager     = $companionItemManager;
        $this->companionStatistics      = $companionStatistics;
        $this->companionErrorHandler    = $companionErrorHandler;
        $this->companionTokenManager    = $companionTokenManager;
    }
    
    /**
     * @Route("/market/categories")
     */
    public function categories()
    {
        return $this->json(
            $this->companion->getCategories()
        );
    }
    
    /**
     * @Route("/market/stats", name="market_stats")
     */
    public function statistics()
    {
        $criticalCount = $this->companionErrorHandler->getCriticalExceptionCount();

        return $this->json([
            'Stats'         => $this->companionStatistics->getStatistics(),
            'Errors'        => $this->companionErrorHandler->getExceptions(),
            'IsCritical'    => $criticalCount > CompanionConfiguration::ERROR_COUNT_THRESHOLD,
            'CriticalCount' => $criticalCount,
        ]);
    }
    
    /**
     * @Route("/market/online", name="market_online")
     */
    public function online()
    {
        $status  = [];
        
        /** @var CompanionToken $token */
        foreach ($this->companionTokenManager->getCompanionTokens() as $token) {
            $status[] = [
                'Server'     => $token->getServer(),
                'Message'    => $token->getMessage(),
                'LastOnline' => $token->getLastOnline(),
                'Online'     => $token->isOnline(),
            ];
        }
        
        return $this->json([
            'list'    => GameServers::LIST,
            'Offline' => CompanionTokenManager::SERVERS_OFFLINE,
            'Status'  => $status
        ]);
    }
    
    /**
     * @Route("/market/search")
     */
    public function search(Request $request)
    {
        return $this->json(
            false //$this->companionMarket->search()
        );
    }

    /**
     * @Route("/market/ids")
     */
    public function sellable()
    {
        return $this->json(
            $this->companionItemManager->getMarketItemIds()
        );
    }
    
    /**
     * Obtain price + history for multiple items
     *
     * @Route("/market/items")
     */
    public function itemMulti(Request $request)
    {
        $itemIds = array_filter(explode(',', $request->get('ids')));
        $results = [];
        
        if (count($itemIds) > 100) {
            throw new \Exception("No, too many ids!");
        }
        
        foreach ($itemIds as $id) {
            $results[] = $this->item($request, $id, true);
        }
        
        return $this->json($results);
    }
    
    /**
     * Obtain price + history for an item on multiple servers
     *
     * @Route("/market/item/{itemId}")
     */
    public function item(Request $request, int $itemId, bool $isInternal = false)
    {
        $servers = array_filter(explode(',', $request->get('servers')));
        $dc      = ucwords($request->get('dc'));
        
        if (count($servers) > 15) {
            throw new \Exception("No, too many servers!");
        }
        
        // overwrite servers if a DC is provided
        $servers = $dc ? GameServers::LIST_DC[$dc] : $servers;
        
        // server or dc is empty
        if (empty($servers) && empty($dc)) {
            throw new InvalidCompanionMarketRequestException();
        }
        
        // too many servers
        if (count($servers) > InvalidCompanionMarketRequestServerSizeException::MAX_SERVERS) {
            throw new InvalidCompanionMarketRequestServerSizeException();
        }
        
        // options
        $maxHistory = $request->get('max_history') ?: self::DEFAULT_MAX_HISTORY;
        
        // build response
        $response = [];
        foreach ($servers as $server) {
            $serverId = is_string($server) ? GameServers::getServerId($server) : $server;
            $response[$server] = $this->companionMarket->get($serverId, $itemId, $maxHistory);
        }
        
        if ($isInternal) {
            return $response;
        }
        
        return $this->json($response);
    }
    
    /**
     * Obtain price + history for an item on a specific server
     *
     * @Route("/market/{server}/items/{itemId}")
     * @Route("/market/{server}/item/{itemId}")
     */
    public function itemByServer(Request $request, string $server, int $itemId)
    {
        // options
        $maxHistory = $request->get('max_history') ?: self::DEFAULT_MAX_HISTORY;
        
        // build response
        $serverId = GameServers::getServerId($server);
        $response = $this->companionMarket->get($serverId, $itemId, $maxHistory);
        
        return $this->json($response);
    }
    
    /**
     * Obtain all the items a retainer is selling
     *
     * @Route("/market/retainer/{retainerId}")
     */
    public function retainerItems(string $retainerId)
    {
        return $this->json(
            $this->companionMarket->retainerItems($retainerId)
        );
    }
}
