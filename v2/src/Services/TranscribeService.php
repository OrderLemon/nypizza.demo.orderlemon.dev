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
* Uses curl library to send requests to OpenAI's /v1/audio/transcriptions endpoint.
* Api configuration is set in config file. Path (or URL) to the file is provided
* through method parameters. If a remote URL is given, the file is downloaded to a
* local temp file first, since OpenAI requires a real multipart file upload.
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
            $this->logger->error("transcribe service", ["file path" => "Invalid file path: $this->pathToFile"]);
            throw new ValidationException(["file path" => "Invalid file path provided for transcribe service!"]);
        }
        
        if( trim($this->api) === "" || trim($this->token) === "" || trim($this->model) === ""){
            $this->logger->error("transcribe service", ["config" => "Invalid configuration!"]);
            throw new ServiceException("Invalid configuration for transcrbibe service!");
        }
        
        $result = $this->callApi();

        if(!is_array($result)){
            return null;
        }

        $this->logger->info("OpenAI Transcribe response", $result);
        return $result["text"] ?? null;
    }

    /**
     * Ensures we have a local filesystem path to hand to CURLFile.
     * Downloads remote http(s) URLs into a temp file; leaves local paths as-is.
     * Returns [localPath, isTemp] so the caller knows whether to clean it up.
     */
    private function resolveLocalFile(string $source): array
    {
        $isRemote = (bool) preg_match('#^https?://#i', $source);

        if (!$isRemote) {
            return [$source, false];
        }

        $ch = curl_init($source);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $data = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data === false || $curlError !== '' || $statusCode !== 200) {
            $this->logger->error("Transcribe service: failed to download source file", [
                "url" => $source,
                "http_status" => $statusCode,
                "curl_error" => $curlError,
            ]);
            throw new ServiceException("Unable to download audio file for transcription.");
        }

        $ext = pathinfo(parse_url($source, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
        $ext = $ext !== '' ? $ext : 'ogg';

        $tmpPath = tempnam(sys_get_temp_dir(), 'transcribe_') . '.' . $ext;
        file_put_contents($tmpPath, $data);

        return [$tmpPath, true];
    }

    private function callApi(): mixed
    {
        [$localPath, $isTemp] = $this->resolveLocalFile($this->pathToFile);

        if (!is_file($localPath)) {
            $this->logger->error("Transcribe service: local file not found", ["path" => $localPath]);
            return false;
        }

        $mimeType = mime_content_type($localPath) ?: 'application/octet-stream';
        $fileName = basename($localPath);

        $ch = curl_init($this->api);

        $payload = [
            'file'  => new \CURLFile($localPath, $mimeType, $fileName),
            'model' => $this->model,
        ];

        $this->logger->info("OpenAI Transcribe payload", [
            "source" => $this->pathToFile,
            "local_file" => $localPath,
            "model" => $this->model,
        ]);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                // Do NOT set Content-Type manually here — curl sets the correct
                // multipart/form-data boundary automatically when POSTFIELDS is an array.
                'Authorization: Bearer ' . $this->token,
            ],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($isTemp && is_file($localPath)) {
            @unlink($localPath);
        }

        if ($response === false || $curlError !== '') {
            $this->logger->error("Transcribe service call failed: {$curlError}");

            return false;
        }

        if ($statusCode !== 200) {
            $this->logger->error("Transcribe service returned HTTP {$statusCode}: {$response}");

            return false;
        }

        $decoded = json_decode($response, true);

        // OpenAI's success response is simply { "text": "..." } — no "success" key.
        if (!is_array($decoded) || !isset($decoded['text'])) {
            $this->logger->error("Transcribe service returned an unexpected response: {$response}");

            return false;
        }

        return $decoded;
    }
}