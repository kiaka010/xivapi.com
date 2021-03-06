<?php

namespace App\Controller;

use App\Exception\ContentGoneException;
use App\Service\Lodestone\LinkshellService;
use App\Service\Lodestone\ServiceQueues;
use App\Service\LodestoneQueue\LinkshellQueue;
use Lodestone\Api;
use App\Service\Redis\Redis;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @package App\Controller
 */
class LodestoneLinkshellController extends AbstractController
{
    /** @var LinkshellService */
    private $service;
    
    public function __construct(LinkshellService $service)
    {
        $this->service = $service;
    }
    
    /**
     * @Route("/Linkshell/Search")
     * @Route("/linkshell/search")
     */
    public function search(Request $request)
    {
        return $this->json(
            (new Api())->searchLinkshell(
                $request->get('name'),
                ucwords($request->get('server')),
                $request->get('page') ?: 1
            )
        );
    }
    
    /**
     * @Route("/Linkshell/{lodestoneId}")
     * @Route("/linkshell/{lodestoneId}")
     */
    public function index($lodestoneId)
    {
        $lodestoneId = strtolower(trim($lodestoneId));
        
        $response = (Object)[
            'Linkshell'     => null,
            'Info' => (Object)[
                'Linkshell' => null,
            ],
        ];

        $linkshell = $this->service->get($lodestoneId);
        $response->Linkshell = $linkshell->data;
        $response->Info->Linkshell = $linkshell->ent->getInfo();
    
        return $this->json($response);
    }
    
    /**
     * @Route("/Linkshell/{lodestoneId}/Update")
     * @Route("/linkshell/{lodestoneId}/update")
     */
    public function update($lodestoneId)
    {
        $linkshell = $this->service->get($lodestoneId);
    
        if ($linkshell->ent->isBlackListed()) {
            throw new ContentGoneException(ContentGoneException::CODE, 'Blacklisted');
        }
    
        if ($linkshell->ent->isAdding()) {
            throw new ContentGoneException(ContentGoneException::CODE, 'Not Added');
        }
    
        if (Redis::Cache()->get(__METHOD__.$lodestoneId)) {
            return $this->json(0);
        }
        
        LinkshellQueue::request($lodestoneId, 'linkshell_update');

        Redis::Cache()->set(__METHOD__.$lodestoneId, ServiceQueues::UPDATE_TIMEOUT);
        return $this->json(1);
    }
}
