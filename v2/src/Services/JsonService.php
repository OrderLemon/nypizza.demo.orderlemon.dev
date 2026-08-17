<?php

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Helpers\JsonHelper;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Core\Config;

class JsonService
{
    protected string $jsonPath = "";

    private const ALLOWED_MOCKUPS = ["products", "categories", "orders", "campaigns", "order_tracking", "order_drafts"];

    function __construct(
        protected Logger $logger,
        protected Config $config,
    ){
}
    public function load(string $mockup) : array 
    {
        if(!in_array($mockup, self::ALLOWED_MOCKUPS)){
            throw new ApiException("Mockup {$mockup} not found!");
        }

        $mockupsDir = $this->config->secret("local_resources.mockups.path");

        if( !is_dir($mockupsDir)){
            $this->logger->error("Invalid resources directory: {$mockupsDir}");
            throw new ApiException("No resources found!");
        }

        $this->jsonPath = $mockupsDir . DIRECTORY_SEPARATOR . $mockup . ".json";


        if(!file_exists($this->jsonPath)){
            $this->logger->error("Invalid file path: {$this->jsonPath}");
            throw new ApiException("{$mockup} json path is not a valid path or the file does not exist!");
        }

        $contents = file_get_contents($this->jsonPath);

        if($contents === false || $contents === null){
            throw new ApiException("{$mockup} json could not be read!");
        }

        if(trim($contents) === ""){
            $contents = "[]";
        }
        
        $decoded = json_decode($contents, true);

        if($decoded === null || $decoded === false ){
            throw new ApiException("Error decoding the {$mockup} json content!");
        }

        return $decoded; 
    }


    public function addItems(array $items, string $mockup) : array
    {
        $records = $this->load($mockup);

        $ids = [];

        foreach($items as $key => $item){
            $id = count($records) + ((int)$key + 1);
            
            $ids[] = $id;
            $item["id"] = $id;
            
            $records[] = $item;
        }

        $encoded = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if(!$encoded){
            throw new ApiException("Error encoding data for insertion! Mockup: " . $mockup);
        }
            
        if(!file_put_contents($this->jsonPath, $encoded)){
            throw new ApiException("Error inserting data into json! Mockup: " . $mockup);
        }

        return $ids;
    }

    public function jsonPath(string $mockup) : string
    {
        if(!in_array($mockup, self::ALLOWED_MOCKUPS)){
            throw new ApiException("Mockup {$mockup} not found!");
        }

        $mockupsDir = $this->config->secret("local_resources.mockups.path");

        if( !is_dir($mockupsDir)){
            $this->logger->error("Invalid resources directory: {$mockupsDir}");
            throw new ApiException("No resources found!");
        }

        return $mockupsDir . DIRECTORY_SEPARATOR . $mockup . ".json";
    }

    /** Replace the record with this id. Returns false when no such id exists. */
    public function replaceItem(int $id, array $item, string $mockup): bool
    {
        $records = $this->load($mockup);

        foreach ($records as $key => $record) {
            if (!is_array($record) || (int) ($record['id'] ?? 0) !== $id) {
                continue;
            }

            $item['id']     = $id;
            $records[$key]  = $item;

            $encoded = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encoded === false || file_put_contents($this->jsonPath, $encoded) === false) {
                throw new ApiException("Error updating json! Mockup: " . $mockup);
            }

            return true;
        }

        return false;
    }

    public function save(array $items, string $mockup) : bool
    {
        if(!in_array($mockup, self::ALLOWED_MOCKUPS)){
            $this->logger->warning("mockup.save", ["mockup" => "Mockup $mockup does not exist!"]);
            return false;
        }

        $encoded = JsonHelper::encode($items, valuesOnly: true);

        if($encoded === false){
            $this->logger->warning("mockup.save", ["encoding" => "Could not encode items!"]);
            return false; 
        }

        if(!file_put_contents($this->jsonPath($mockup), $encoded)){
            $this->logger->warning("mockup.save", ["writing" => "Could not write to $mockup file!"]);
            return false; 
        }

        return true;
    }
}

?>