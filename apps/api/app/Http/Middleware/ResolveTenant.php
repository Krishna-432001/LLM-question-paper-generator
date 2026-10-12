<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Layer 1 of tenant isolation.
 *
 * The organization is resolved from the authenticated user's memberships.
 * X-Organization-Id is honoured only after the membership is verified, so a
 * forged header selects nothing.
 */
final class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Publish identity before tenancy — the organizations RLS policy needs
        // current_user_id() to resolve membership.
        TenantContext::seedUser($user->getKey());

        $requestedOrgId = $request->header('X-Organization-Id');

        $organization = $requestedOrgId !== null
            ? $user->organizationIfMember($requestedOrgId)
            : $user->defaultOrganization();

        abort_if($organization === null, 403, 'No accessible organization for this account.');
        abort_if(! $organization->is_active, 403, 'This organization is suspended.');

        // Spatie runs in teams mode: without an active team, every role and
        // permission lookup resolves against no team and silently returns
        // false — authorization would fail closed everywhere, invisibly.
        // This must happen BEFORE building the context, which reads a
        // permission to decide whether the user may curate platform content.
        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->getKey());

        $context = TenantContext::forUser($user, $organization);

        app()->instance(TenantContext::class, $context);
        $context->applyToDatabase();

        Context::add('organization_id', $context->organizationId);
        Context::add('user_id', $context->userId);

        $response = $next($request);
        $response->headers->set('X-Organization-Id', $context->organizationId);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if (app()->bound(TenantContext::class)) {
            TenantContext::clearDatabase();
        }
    }
}
