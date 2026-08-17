<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\Gateway;

/**
 * The WhatsApp provider gateway's endpoint groups. The case value is the URL
 * path segment appended to the configured base (`.../account/`, `.../send/`…);
 * the specific operation is carried in the request body's `a` action key, not
 * the URL — see {@see WhatsappGateway}.
 */
enum WhatsappGatewayEndpoint: string
{
    case Account      = 'account';
    case Conversation = 'conversation';
    case Send         = 'send';
    case Server       = 'server';
}