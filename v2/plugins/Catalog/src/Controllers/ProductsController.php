<?php

namespace Plugins\Catalog\Controllers;

use Pmsrapi\V2\Services\ProductsService;
use Pmsrapi\V2\Services\CategoryService;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Exception\ApiException;


class ProductsController
{

    function __construct(
        private readonly ProductsService $productsService
    ){}

    public function index(Request $request) : Response
    {
        $products = $this->productsService->load("products");

        return Response::ok(["products" => $products]);
    }

    public function show(Request $request, string $id) : Response
    {
        if(!is_numeric($id)){
            throw new ApiException("Provided id is not a valid value!");
        }

        $products = $this->productsService->load("products");

        if(empty($products)){
            return Response::ok(["success" => false, "message" => "no prodcuts found!"]);
        }

        $productKey = array_search($id, array_column($products, "id"));

        if($productKey === false){
            return Response::ok(["success" => false, "message" => "Product {$id} not found"]);
        }

        return Response::ok(["success" => true, "product" => $products[$productKey]]);
    }
}

?>