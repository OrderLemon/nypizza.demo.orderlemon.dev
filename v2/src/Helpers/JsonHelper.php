<?php

namespace Pmsrapi\V2\Helpers;

class JsonHelper
{

    public static function encode(array $payload, bool $valuesOnly = false): string|bool
    {
        if( $valuesOnly){
            return json_encode(
                array_values($payload),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }

        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}

?>