<?php

declare(strict_types=1);

namespace App\Libraries\Gateway;

/**
 * A refused gateway assertion. `reason` is one of the contract's rejection codes (missing, malformed, alg, typ,
 * unknown_kid, signature, iss, aud, lifetime, expired, iat_future, htm, htp, replay, untrusted_peer, disabled).
 */
final class GatewayAssertionError extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
