<?php
declare(strict_types=1);

namespace Plugins\Orders\Controllers;

use Plugins\Whatsapp\Gateway\WhatsappGateway;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Services\OrderQueryService;
use Pmsrapi\V2\Services\UsualOrderService;
use Pmsrapi\V2\Services\DraftOrderService;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Support\Logger;

final class OrderController
{
    private const RESERVED_QUERY = ['page', 'per_page', 'order', 'fields'];

    public function __construct(
        private readonly OrderQueryService $queryService,
        private readonly UsualOrderService $usualOrderService,
        private readonly WhatsappGateway $whatsappGateway,
        private readonly DraftOrderService $drafts,
        private readonly Logger $logger,
        private readonly Config $config,
    ) {
    }

    public function indexActive(Request $request) : Response
    {
        $orders = $this->queryService->indexActive();
        return Response::ok(["orders" => $orders]);
    }

    public function referenceOrder(Request $request): Response
    {
        $values = $request->body;

        if(!isset($values["reference"])){
            throw new ValidationException(['reference' => 'Reference is missing!']);
        }

        $draft = $this->drafts->byReference($values["reference"]);

        if($draft === null || empty($draft)){
            return Response::error(404, ["not found" => "Draft not found!"]);
        }

        return Response::ok($draft);
    }

    public function usualFor(Request $request, $phone) : Response
    {
        if(empty($phone) || !is_numeric($phone)){
            throw new ValidationException(["Invalid data" => "Provided phone is invalid!"]);
        }

        $orders = $this->usualOrderService->forPhone($phone);

        if(empty($orders)){
            return Response::ok(["status" => "success", "message" => "No usual orders found.", "orders" => []]);
        }

        return Response::ok(["status" => "success", "orders" => $orders]);
    }

    public function indexActiveForClient(Request $request, string $phone) : Response
    {
        if(empty($phone) || !is_numeric($phone)){
            throw new ValidationException(["Invalid data" => "Provided phone is invalid!"]);
        }

        $orders = $this->queryService->loadForPhone($phone);

        if(empty($orders)){
            return Response::ok(["status" => "success", "message" => "No active orders found.", "orders" => []]);
        }

        return Response::ok(["status" => "success", "orders" => $orders]);
    }

    public function show(Request $request, array $params): Response
    {
        $draft = $this->drafts->byReference(trim((string) $request->query('ref', '')));

        if ($draft === null) {
            throw new ApiException('No basket found for that reference', 404);
        }

        return Response::ok([
            'reference' => $draft['reference'],
            'items'     => $draft['items'],
            'total'     => $draft['total'],
        ]);
    }

    public function reorder(Request $request) : Response
    {
        $phone = trim((string) $request->body["phone"] ?? "");
 
        if ($phone === '') {
            throw new ValidationException(["phone" => 'phone is required']);
        }

        $hash = trim((string) $request->body['hash']);
 
        if ($hash === '') {
            throw new ValidationException(["hash" => 'hash is required']);
        }

        $items = $this->usualOrderService->basketFor($phone, $hash);

        if ($items === null) {
            return Response::error(404, ["basket" => "No basket found for that hash"]);
        }

        return Response::ok(["items" => $items]);
    }
}
?>