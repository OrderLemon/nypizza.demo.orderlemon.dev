<?php

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Services\JsonService;
use DateTimeImmutable;

class CampaignService extends JsonService
{

    public function activeOnly(array $campaigns) : array
    {
        return array_values(array_filter(
            $campaigns,
            fn (array $campaign) => ($campaign["active"] ?? false) === true
        ));
    }

    public function activeNow(array $campaigns) : array
    {
        $now = new DateTimeImmutable();
        $today = strtolower($now->format("D"));

        return array_values(array_filter(
            $this->activeOnly($campaigns),
            function (array $campaign) use ($now, $today) {
                if(!empty($campaign["starts_at"]) && $now < new DateTimeImmutable($campaign["starts_at"])){
                    return false;
                }

                if(!empty($campaign["ends_at"]) && $now > new DateTimeImmutable($campaign["ends_at"])){
                    return false;
                }

                $days = $campaign["days_of_week"] ?? null;

                if(empty($days)){
                    return true;
                }

                return in_array($today, $days);
            }
        ));
    }
}

?>
