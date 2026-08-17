<?php

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Services\JsonService;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Support\Logger;

class ProductsService extends JsonService
{

    public function groupByCategory(array $products, array $categories) : array
    {
        $roots = array_values(array_filter(
            $categories,
            fn (array $category) => ($category['parent_id'] ?? null) === null
        ));

        return array_map(
            fn (array $root) => $this->buildCategoryNode($root, $products, $categories),
            $roots
        );
    }

    private function buildCategoryNode(array $category, array $products, array $categories) : array
    {
        $category['products'] = array_values(array_filter(
            $products,
            fn (array $product) => $product['category_id'] === $category['id']
        ));

        $category['sub-category'] = array_map(
            fn (array $child) => $this->buildCategoryNode($child, $products, $categories),
            array_values(array_filter(
                $categories,
                fn (array $c) => ($c['parent_id'] ?? null) === $category['id']
            ))
        );

        return $category;
    }

    public function findByProductId(int $id) : array
    {
        $products = $this->load("products");

        $returnProducts = [];

        foreach($products as $product){
            if( $product["category_id"] === $id){
                $returnProducts[] = $product;
            }
        }

        return $returnProducts;
    }
}

?>