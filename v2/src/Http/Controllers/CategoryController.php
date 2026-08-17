<?php

namespace Pmsrapi\V2\Http\Controllers;

use Pmsrapi\V2\Services\CategoryService;
use Pmsrapi\V2\Services\ProductsService;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Exception\ApiException;

class CategoryController
{

    private const array ALLOWED_INCLUDES = ["products"];

    function __construct(
        private readonly ProductsService $productsService,
        private readonly CategoryService $categoryService,
    ){}


    public function index(Request $request) : Response
    {
        $categories = $this->categoryService->load("categories");

        return Response::ok(["categories" => $categories]);
    }

    
    public function show(Request $request, string $id) : Response
    {
        if(!is_numeric($id)){
            throw new ApiException("Provided id is not a valid value!");
        }

        $categories = $this->categoryService->load("categories");

        if(empty($categories)){
            return Response::ok(["success" => false, "message" => "no categories found!"]);
        }

        $categoryKey = array_search($id, array_column($categories, "id"));

        if($categoryKey === false){
            return Response::ok(["success" => false, "message" => "Category {$id} not found"]);
        }

        return Response::ok(["success" => true, "category" => $categories[$categoryKey]]);
    }

    public function indexWithProducts(Request $request) : Response
    {
        $categories = $this->categoryService->load("categories");

        $products = $this->productsService->load("products");
        
        $prodsByCategory = $this->productsService->groupByCategory($products, $categories);
        
        return Response::ok(["categories" => $prodsByCategory]);

    }

    public function indexProductsForCategory(Request $request, string $id) : Response
    {
        $categories = $this->categoryService->load("categories");

        if(!is_numeric($id)){
            throw new ApiException("Provided id is not a valid value!");
        }

        $categories = $this->categoryService->load("categories");

        if(empty($categories)){
            return Response::ok(["success" => false, "message" => "no categories found!"]);
        }

        $categoryKey = array_search($id, array_column($categories, "id"));

        if($categoryKey === false){
            return Response::ok(["success" => false, "message" => "Category {$id} not found"]);
        }

        $category = $categories[$categoryKey];

        $products = $this->productsService->findByProductId((int)$category["id"]);
        
        return Response::ok(["category" => $category, "products" => $products]);
    }
}

?>