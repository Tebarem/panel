<?php

declare(strict_types=1);

namespace Ramjet\ProxyProvisioning\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;
use Ramjet\ProxyProvisioning\Http\Requests\CreateProxyRequest;
use Ramjet\ProxyProvisioning\ProxyProvisioner;

final class ProxyController
{
    public function __construct(private readonly ProxyProvisioner $provisioner) {}

    public function status(Request $request): JsonResponse
    {
        return new JsonResponse($this->provisioner->status($this->caller($request)));
    }

    public function create(CreateProxyRequest $request): JsonResponse
    {
        $server = $this->provisioner->create($this->caller($request));

        Activity::event(ProxyProvisioner::ACTIVITY_EVENT)
            ->subject($server)
            ->property('identifier', $server->uuidShort)
            ->log();

        return new JsonResponse(['server' => ProxyProvisioner::present($server)], Response::HTTP_CREATED);
    }

    /** The route is authenticated, so the caller is always a signed-in user; the proxy is only ever theirs. */
    private function caller(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new LogicException('Ramjet proxy routes require an authenticated user.');
    }
}
