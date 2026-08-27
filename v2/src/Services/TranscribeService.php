<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Helpers\JsonHelper;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Core\Config;

/*
* Service class used to transcribe audio files to text.
* Uses curl library to send requests to -- API NAME --. 
* Api configuration is set in config file. Path to files is provided throught method parameters.
* Returns the text extracted by the Api.
*/
class TranscribeService
{
    function __construct(
        private readonly Logger $logger,
        private readonly Config $config
    ){}

    private string $pathToFile = "";
    private string $api = "";
    private string $token = "";
    private string $model = "";

    public function transcribe(string $pathToFile) : ?string
    {
        $this->api = $this->config->secret("transcribe.api", "");
        $this->token = $this->config->secret("transcribe.token", "");
        $this->model = $this->config->secret("transcribe.model", "");

        $this->pathToFile = $pathToFile;

        if( trim($this->pathToFile) === ""){
            throw new ValidationException(["file path" => "Invalid file path provided for transcribe service!"]);
        }
        
        if( trim($this->api) === "" || trim($this->token) === "" || trim($this->model) === ""){
            throw new ServiceException("Invalid configuration for transcrbibe service!");
        }
        
        $result = $this->callApi();

        if(!is_array($result)){
            return null;
        }

        return $result["text"];
    }

    private function callApi(): mixed
    {
        $ch = curl_init($this->api);

        $payload = [
            "file" => $this->pathToFile,
            'model' => $this->model,
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false || $curlError !== '') {
            $this->logger->error("Transcribe service call failed: {$curlError}");

            return false;
        }

        if ($statusCode !== 200) {
            $this->logger->error("Transcribe service returned HTTP {$statusCode}: {$response}");

            return false;
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || ($decoded['success'] ?? false) !== true) {
            $this->logger->error("Transcribe service returned an unexpected response: {$response}");

            return false;
        }

        return $decoded;
    }
}

?>