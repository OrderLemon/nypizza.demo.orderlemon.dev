<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Database;

use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Database\Connection;


class OrderRepository 
{
    public function __construct(
        private readonly Repository $repository,
        private readonly Schema $schema,
        private readonly Connection $connection
    ) {
    }

    public function getFullOrders(
        string $table,
        array $filters = [],
        ?string $orderBy = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array
    {
        $rows =  $this->selectRows($table, $filters, $orderBy, $limit, $offset);
        $pk = $this->schema->primaryKey('orders');

        $ids = array_column($rows, $pk);

        foreach($ids as $key => $id){
            $items = $this->selectRows('order_items', ['order_id' => $id]);
            $rows[$key]["items"] = $items;
        }

        return $rows;
    }

    public function selectRows(
        string $table,
        array $filters = [],
        ?string $orderBy = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        $this->schema->assertTable($table);
        
        [$where, $params] = $this->buildWhere($table, $filters);

        $sql = 'SELECT * FROM ' . $this->schema->quote($table) . $where . $this->buildOrderBy($table, $orderBy);

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset ?? 0);
        }

        return $this->connection->select($sql, $params);
    }

    public function count(string $table, array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($table, $filters);

        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM ' . $this->schema->quote($table) . $where,
            $params,
        );
    }

    private function buildOrderBy(string $table, ?string $orderBy): string
    {
        if ($orderBy === null || $orderBy === '') {
            return '';
        }

        [$column, $direction] = array_pad(explode(':', $orderBy, 2), 2, 'asc');
        $this->schema->assertColumns($table, $column);
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

        return ' ORDER BY ' . $this->schema->quote($column) . ' ' . $direction;
    }


     /**
     * @param array<string, scalar|null> $filters
     * @return array{0: string, 1: list<scalar|null>} where-clause (with leading space) and bound params
     */
    private function buildWhere(string $table, array $filters): array
    {
        $dateFilters = ["from", "to"];

        if ($filters === []) {
            return ['', []];
        }

        $clauses = [];
        $params = [];

        foreach ($filters as $column => $value) {

            if(in_array($column, $dateFilters)){
                if($column === "from"){
                    $clauses[] = $this->schema->quote("created_at") . ' >= ?';
                }
                else{
                    $clauses[] = $this->schema->quote("created_at") . ' <= ?';
                }
                $params[] = $value;
                continue;
            }

            $this->schema->assertColumns($table, (string) $column);

            if ($value === null) {
                $clauses[] = $this->schema->quote((string) $column) . ' IS NULL';
            } else {
                $clauses[] = $this->schema->quote((string) $column) . ' = ?';
                $params[] = $value;
            }
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    public function aggregate( array $spec, string $table = "orders"): array
    {
        return $this->repository->aggregate($table, $spec);
    }

    public function getItemsForOrder(int $id): array
    {
        return $this->repository->selectRows("order_items", ["order_id" => $id]);
    }

    public function insertRow(string $table, array $data): int
    {
        return $this->repository->insertRow($table, $data);
    }
}

?>