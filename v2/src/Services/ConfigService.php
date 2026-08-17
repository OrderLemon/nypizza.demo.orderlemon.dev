<?php

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Core\Config;
class ConfigService
{
    function __construct(
        private readonly Config $config
    ){
    }

    public function gatewayConfig(): Config
    {
        $config = Config::load(V2_BASE . '/config.php');
        $gatewayConfig = $config->secret("wa_gateway");

        if( empty($gatewayConfig)){
            throw new ServiceException("WhatsApp Gateway configuration not found!");
        }
        
        return $config;
    }
}
?>