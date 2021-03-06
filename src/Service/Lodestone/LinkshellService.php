<?php

namespace App\Service\Lodestone;

use App\Entity\Linkshell;
use App\Service\Content\LodestoneData;
use App\Service\LodestoneQueue\LinkshellQueue;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;

class LinkshellService extends AbstractService
{
    /**
     * Get a Linkshell
     */
    public function get($lodestoneId): \stdClass
    {
        if ($lodestoneId < 0 || preg_match("/[a-z]/i", $lodestoneId) || strlen($lodestoneId) < 15 || strlen($lodestoneId) > 20) {
            throw new NotAcceptableHttpException('Invalid lodestone ID: '. $lodestoneId);
        }
    
        /** @var Linkshell $ent */
        if ($ent = $this->getRepository(Linkshell::class)->find($lodestoneId)) {
            // if entity is cached, grab the data
            if ($ent->isCached()) {
                $data = LodestoneData::load('linkshell', 'data', $lodestoneId);
            }
        
            return (Object)[
                'ent'  => $ent,
                'data' => $data ?? null,
            ];
        }

        LinkshellQueue::request($lodestoneId, 'linkshell_add', true);
    
        return (Object)[
            'ent'  => new Linkshell($lodestoneId),
            'data' => null,
        ];
    }
}
