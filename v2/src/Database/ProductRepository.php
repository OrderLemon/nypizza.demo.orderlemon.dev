<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Database;

use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Database\Connection;


class ProductRepository 
{
    public function __construct(
        private readonly Repository $repository,
        private readonly Schema $schema,
        private readonly Connection $connection
    ) {
    }

    public function searchByText(array $filters): array
    {
        $products = $this->searchProductsByColumn($filters);

        if(count($products) < 1){
            $products = $this->searchProductsBySpecs($filters);
        }

        return $products;
    }


    private function searchProductsBySpecs($filters): array
    {
        $sql = 'SELECT * FROM products where ';

        if( isset($filters["category"])){
            $sql .= "category_id = " . $filters["category"] . " AND ";
            }
            
        if( isset($filters["max_price"])){
            $sql .= "price <= " . $filters["max_price"] . " AND ";
        }

         foreach($filters["items"] as $idx => $term){
            $sql .= "( ";
            if( $term["properties"]["mode"] === "contains"){
                $sql .=  'json_search(specs, \'one\', ' . "'%" . $term["properties"]["term"] . "%', null, '$[*].value') is not null"; 
                }else{
                $sql .=  'json_search(specs, \'one\', ' . "'%" . $term["properties"]["term"] . "%', null, '$[*].value') is null"; 
            }
            $sql .= ")";

            if( $idx < count($filters["items"]) - 1){
                $sql .= " and ";
            }
        }

        var_dump($sql);
        return $this->connection->select($sql);
    }

    private function searchProductsByColumn(array $filters): array
    {
        $sql = 'SELECT * FROM products where ';

        if( isset($filters["category"])){
            $sql .= "category_id = " . $filters["category"] . " AND ";
            }
            
        if( isset($filters["max_price"])){
            $sql .= "price <= " . $filters["max_price"] . " AND ";
        }

        foreach($filters["items"] as $idx => $term){
            $sql .= "( ";
            if( $term["properties"]["mode"] === "contains"){
                $sql .=  'name like "%'  . $term["properties"]["term"] . '%" '; 
                $sql .=  'or description like "%'  . $term["properties"]["term"] . '%" '; 
                $sql .=  'or short_description like "%'  . $term["properties"]["term"] . '%" '; 
            }else{
                $sql .=  'name not like "%'  . $term["properties"]["term"] . '%" '; 
                $sql .=  'and description not like "%'  . $term["properties"]["term"] . '%" '; 
                $sql .=  'and short_description not like "%'  . $term["properties"]["term"] . '%" '; 
            }
            $sql .= ")";

            if( $idx < count($filters["items"]) - 1){
                $sql .= " and ";
            }
        }

        // var_dump($sql);
        // die();
        return $this->connection->select($sql);
    }
}

?>