<?php

declare(strict_types=1);

namespace Plugins\Cart\Controllers;

use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Services\CartService;

/**
 * A plain controller. It returns a {@see Response} exactly like a core
 * controller does — the envelope, status codes, and streaming helpers are all
 * available to plugins.
 */
final class CartController
{
    function __construct(
        private readonly CartService $cartService
    ){}

    public function update(Request $request): Response
    {
        $body = $request->body;

        if(!isset($body["phonenumber"])){
            throw new ValidationException(["phone" => "Missing phone parameter!"]);
        }

        if(!isset($body["items"])){
            throw new ValidationException(["phone" => "Missing items!"]);
        }

        $result = $this->cartService->updateCart($body["items"], $body["phonenumber"]);

        return Response::ok($result);
    }

    public function checkout(Request $request): Response
    {
        $body = $request->body;

        if(!isset($body["phonenumber"])){
            throw new ValidationException(["phone" => "Missing phone parameter!"]);
        }

        if(!isset($body["checkout_data"])){
            throw new ValidationException(["checkout data" => "Missing checkout data!"]);
        }

        $order = $this->cartService->checkoutOrder($body["checkout_data"], $body["phonenumber"]);

        return Response::ok($order);

    }

    public function getCart(Request $request, string $phone): Response
    {
        $body = $request->body;

        if(trim($phone) === ""){
            throw new ValidationException(["phone" => "Invalid phone parameter!"]);
        }

        $order = $this->cartService->activeOrderFor($phone);

        if($order === null){
            return Response::error(404, ["not found" => "No active cart for this phone number!"]);
        }

        $fullOrder = $this->cartService->withItemsAndTotal($order["id"], [], false);

        return Response::ok($fullOrder);

    }

}
