<?php

namespace App\Controller;

use App\Exception\ContentGoneException;
use App\Service\Lodestone\FreeCompanyService;
use App\Service\Lodestone\ServiceQueues;
use App\Service\LodestoneQueue\FreeCompanyQueue;
use Lodestone\Api;
use App\Service\Redis\Redis;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @package App\Controller
 */
class LodestoneFreeCompanyController extends AbstractController
{
    /** @var FreeCompanyService */
    private $service;
    
    public function __construct(FreeCompanyService $service)
    {
        $this->service = $service;
    }
    
    /**
     * @Route("/FreeCompany/Search")
     * @Route("/freecompany/search")
     */
    public function search(Request $request)
    {
        return $this->json(
            (new Api())->searchFreeCompany(
                $request->get('name'),
                ucwords($request->get('server')),
                $request->get('page') ?: 1
            )
        );
    }
    
    /**
     * @Route("/FreeCompany/{lodestoneId}")
     * @Route("/freecompany/{lodestoneId}")
     */
    public function index(Request $request, $lodestoneId)
    {
        $lodestoneId = strtolower(trim($lodestoneId));
        
        // choose which content you want
        $data = $request->get('data') ? explode(',', strtoupper($request->get('data'))) : [];
        $content = (object)[
            'FCM' => in_array('FCM', $data),
        ];
    
        $response = (Object)[
            'FreeCompany'            => null,
            'FreeCompanyMembers'     => null,
            'Info' => (Object)[
                'FreeCompany'        => null,
                'FreeCompanyMembers' => null,
            ],
        ];

        $freecompany = $this->service->get($lodestoneId);
        $response->FreeCompany = $freecompany->data;
        $response->Info->FreeCompany = $freecompany->ent->getInfo();
    
        if ($content->FCM) {
            $members = $this->service->getMembers($lodestoneId);
            $response->FreeCompanyMembers = $members;
            $response->Info->FreeCompanyMembers = $members->ent->getInfo();
        }
    
        return $this->json($response);
    }

    /**
     * @Route("/FreeCompany/{lodestoneId}/Update")
     * @Route("/freecompany/{lodestoneId}/update")
     */
    public function update($lodestoneId)
    {
        $freecompany = $this->service->get($lodestoneId);
    
        if ($freecompany->ent->isBlackListed()) {
            throw new ContentGoneException(ContentGoneException::CODE, 'Blacklisted');
        }
    
        if ($freecompany->ent->isAdding()) {
            throw new ContentGoneException(ContentGoneException::CODE, 'Not Added');
        }
        
        if (Redis::Cache()->get(__METHOD__.$lodestoneId)) {
            return $this->json(0);
        }

        FreeCompanyQueue::request($lodestoneId, 'free_company_update');
        
        Redis::Cache()->set(__METHOD__.$lodestoneId, 1, ServiceQueues::UPDATE_TIMEOUT);
        return $this->json(1);
    }
}
