<?php

namespace App\Controller;

use App\Entity\CompanionItem;
use App\Service\Companion\CompanionConfiguration;
use App\Service\Companion\CompanionTokenManager;
use App\Service\Companion\Updater\MarketUpdater;
use App\Service\Redis\Redis;
use Companion\CompanionApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Private access to Companion's API "Sight" directly, requires special access.
 *
 * @package App\Controller
 */
class MarketPrivateController extends AbstractController
{
    /** @var CompanionTokenManager */
    private $companionTokenManager;
    /** @var MarketUpdater */
    private $companionMarketUpdater;

    public function __construct(
        CompanionTokenManager $companionTokenManager,
        MarketUpdater $companionMarketUpdater
    ) {
        $this->companionTokenManager  = $companionTokenManager;
        $this->companionMarketUpdater = $companionMarketUpdater;
    }

    /**
     * @Route("/private/market/item")
     */
    public function itemPrices(Request $request)
    {
        if ($request->get('access') !== getenv('MB_ACCESS')) {
            throw new UnauthorizedHttpException('Denied');
        }

        $itemId = (int)$request->get('item_id');
        $server = ucwords($request->get('server'));
        $token  = $this->companionTokenManager->getCompanionTokenForServer($server);

        $api = new CompanionApi();
        $api->Token()->set($token->getToken());

        return $this->json(
            $api->Market()->getItemMarketListings($itemId)
        );
    }

    /**
     * @Route("/private/market/item/history")
     */
    public function itemHistory(Request $request)
    {
        if ($request->get('access') !== getenv('MB_ACCESS')) {
            throw new UnauthorizedHttpException('Denied');
        }

        $itemId = (int)$request->get('item_id');
        $server = ucwords($request->get('server'));
        $token  = $this->companionTokenManager->getCompanionTokenForServer($server);

        $api = new CompanionApi();
        $api->Token()->set($token->getToken());

        return $this->json(
            $api->Market()->getTransactionHistory($itemId)
        );
    }

    /**
     * @Route("/private/market/item/update")
     */
    public function manualUpdateItem(Request $request)
    {
        // 10 minute timeout per item.
        $timeout = 10;
        
        if ($request->get('access') !== getenv('MB_ACCESS')) {
            throw new UnauthorizedHttpException('Denied');
        }

        $itemId = (int)$request->get('item_id');
        $server = (int)$request->get('server');

        /** @var CompanionItem $marketEntry */
        $marketEntry = $this->companionMarketUpdater->getMarketItemEntry($server, $itemId);

        /**
         * First, check if the item was passed here already
         */
        $requestLastSent = Redis::Cache()->get("companion_market_manual_queue_check_{$itemId}_{$server}");
        if ($requestLastSent) {
            return $this->json([ false, $requestLastSent, 'Item already requested to be updated' ]);
        }

        // Place the item on this server in a 8 minute cooldown
        Redis::Cache()->set("companion_market_manual_queue_check_{$itemId}_{$server}", time(), (60 * $timeout));
        
        // if the item is already in the patreon queue, skip it
        if ($marketEntry->getPatreonQueue() > 0) {
            return $this->json([ false, $requestLastSent, 'Item already in the queue' ]);
        }

        /**
         * Check when the item was last updated, maybe it updated within the last 5 minutes,
         * if so then we don't need to update it again.
         */
        if ($marketEntry->getUpdated() > (time() - (60 * $timeout))) {
            return $this->json([ false, $marketEntry->getUpdated(), 'Item already updated within the past 5 minutes' ]);
        }

        /**
         * Pick a random queue, it should distribute mostly... evenly.
         */
        $queue  = null;
        $queues = range(
            min(CompanionConfiguration::QUEUE_CONSUMERS_PATREON),
            max(CompanionConfiguration::QUEUE_CONSUMERS_PATREON)
        );
        
        foreach ($queues as $i => $num) {
            $size = Redis::Cache()->get("companion_market_manual_queue_{$num}");

            if ($size === null) {
                Redis::Cache()->set("companion_market_manual_queue_{$num}", 1, 60);
                $queue = $num;
                break;
            }
        }

        /**
         * if we have a queue, use it, otherwise pick oen at random
         */
        $queue = $queue ? $queue : $queues[array_rand($queues)];
        $this->companionMarketUpdater->updateManual($itemId, $server, $queue);
        return $this->json([
            true,
            time(),
            "Item will be updated by patreon queue: {$queue}"
        ]);
    }
}
