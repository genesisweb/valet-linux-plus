<?php

namespace Valet;

use DomainException;
use Exception;
use Httpful\Request;

class Ngrok
{
    /**
     * @var string
     */
    private const TUNNEL_ENDPOINT = 'http://127.0.0.1:4040/api/tunnels';
    /**
     * @var CommandLine
     */
    public $cli;

    /**
     * Create a new Ngrok instance.
     *
     * @param CommandLine $cli
     */
    public function __construct(CommandLine $cli)
    {
        $this->cli = $cli;
    }

    /**
     * Get the current tunnel URL from the Ngrok API.
     * @throws Exception
     */
    public function currentTunnelUrl(): ?string
    {
        return retry(20, function () {
            $body = Request::get(self::TUNNEL_ENDPOINT)->send()->body;

            // If there are active tunnels on the Ngrok instance we will spin through them and
            // find the one responding on HTTP. Each tunnel has an HTTP and a HTTPS address
            // but for local testing purposes we just desire the plain HTTP URL endpoint.
            if (isset($body->tunnels) && count($body->tunnels) > 0) {
                return $this->findHttpTunnelUrl($body->tunnels);
            } else {
                throw new DomainException('Tunnel not established.');
            }
        }, 250);
    }

    /**
     * Find the HTTP tunnel URL from the list of tunnels.
     */
    public function setAuthToken(string $authToken): void
    {
        $this->cli->run(__DIR__.'/../../bin/ngrok config add-authtoken '.$authToken);
        info('Ngrok authentication token set.');
    }

    /**
     * Find the HTTP tunnel URL from the list of tunnels.
     */
    private function findHttpTunnelUrl(array $tunnels): ?string
    {
        foreach ($tunnels as $tunnel) {
            if ($tunnel->proto === 'http') {
                return $tunnel->public_url;
            }
        }

        return null;
    }
}
