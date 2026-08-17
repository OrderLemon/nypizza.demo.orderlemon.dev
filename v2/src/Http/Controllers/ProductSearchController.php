<?php
namespace Pmsrapi\V2\Http\Controllers;

use Pmsrapi\V2\Database\OrderRepository;
use Pmsrapi\V2\Database\ProductRepository;
use Pmsrapi\V2\Database\Schema;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Exception\BadRequestException;
use Pmsrapi\V2\Cache\QueryCache;
use Pmsrapi\V2\Http\ResourceDefinition;
use Pmsrapi\V2\Queue\WebhookDispatcher;
use Pmsrapi\V2\Support\Paginator;
use Pmsrapi\V2\Services\OrderQueryService;
use Pmsrapi\V2\Cluster\ServiceClient;


class ProductSearchController{

    function __construct(
        private readonly ProductRepository $repository,
        private readonly Schema $schema,
        private readonly QueryCache $cache,
        private readonly WebhookDispatcher $webhooks,
    ){

    }

    public function search(Request $request): Response
    {
        if(!isset($request->body["filters"]) ){
            throw new ValidationException(["Fitlers" => "Folters are required for searching products!"]);
        }

        $filters = $request->body["filters"];
        
        $products = $this->repository->searchByText($filters);

        // var_dump($products);


        // die();
        return Response::ok($products);
    }
}

?>