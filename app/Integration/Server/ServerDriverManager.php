<?php

declare(strict_types=1);

namespace App\Integration\Server;

use App\Enums\ServerDriverType;
use App\Models\Server;

class ServerDriverManager
{
    public function for(Server $server): ServerDriver
    {
        return match ($server->driver) {
            ServerDriverType::Fake => new FakeDriver,
            ServerDriverType::ZinoPanel => $this->zinoPanel($server),
        };
    }

    private function zinoPanel(Server $server): ZinoPanelDriver
    {
        $entry = $server->credentials_ref === null ? null : config("servers.{$server->credentials_ref}");

        if (! is_array($entry)) {
            throw new ServerDriverException("zino_panel: no hay entrada de config/servers.php para el servidor {$server->slug}");
        }

        $url = $entry['url'] ?? null;
        $url = is_string($url) && $url !== '' ? $url : $server->base_url;

        if (! is_string($url) || $url === '') {
            throw new ServerDriverException("zino_panel: el servidor {$server->slug} no tiene url configurada");
        }

        return new ZinoPanelDriver(
            $server,
            $url,
            isset($entry['user']) ? (string) $entry['user'] : null,
            isset($entry['password']) ? (string) $entry['password'] : null,
            new ServerCircuitBreaker,
        );
    }
}
