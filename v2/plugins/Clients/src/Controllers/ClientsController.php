<?php

declare(strict_types=1);

namespace Plugins\Clients\Controllers;

use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Services\ClientService;

final class ClientsController
{
    function __construct(
        private readonly ClientService $clients
    ){}

    public function info(string $phoneNumber): Response
    {
        if(trim($phoneNumber) === ""){
            throw new ValidationException(["phone number" => "Phone number is required!"]);
        }

        $info = $this->clients->getByPhone($phoneNumber);

        if($info === []){
            return Response::error(404, ["not found" => "No client for this phone number!"]);
        }

        return Response::ok(
            [
                "phonenumber" => $info["phonenumber"],
                "email" => $info["email"],
                "full_name" => $info["full_name"],
                "country" => $info["country"],
                "state" => $info["state"],
                "city" => $info["city"],
                "zip" => $info["zip"],
                "street" => $info["street"],
                "box" => $info["box"],
            ]
        );
    }
}
