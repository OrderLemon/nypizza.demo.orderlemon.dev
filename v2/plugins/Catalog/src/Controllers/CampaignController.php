<?php

namespace Plugins\Catalog\Controllers;

use Pmsrapi\V2\Services\CampaignService;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Exception\ApiException;


class CampaignController
{

    function __construct(
        private readonly CampaignService $campaignService
    ){}

    public function index(Request $request) : Response
    {
        $campaigns = $this->campaignService->load("campaigns");

        if($request->query("active") !== "all"){
            $campaigns = $this->campaignService->activeOnly($campaigns);
        }

        return Response::ok(["campaigns" => $campaigns]);
    }

    public function show(Request $request, string $id) : Response
    {
        if(!is_numeric($id)){
            throw new ApiException("Provided id is not a valid value!");
        }

        $campaigns = $this->campaignService->load("campaigns");

        if(empty($campaigns)){
            return Response::ok(["success" => false, "message" => "no campaigns found!"]);
        }

        $campaignKey = array_search($id, array_column($campaigns, "id"));

        if($campaignKey === false){
            return Response::ok(["success" => false, "message" => "Campaign {$id} not found"]);
        }

        return Response::ok(["success" => true, "campaign" => $campaigns[$campaignKey]]);
    }

    public function active(Request $request) : Response
    {
        $campaigns = $this->campaignService->load("campaigns");

        $campaigns = $this->campaignService->activeNow($campaigns);

        return Response::ok(["campaigns" => $campaigns]);
    }
}

?>
