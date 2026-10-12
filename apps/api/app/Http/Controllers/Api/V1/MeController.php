<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $context = app(TenantContext::class);

        return response()->json([
            'data' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->timezone,
                'is_platform_admin' => $user->isPlatformAdmin(),
                'roles' => $user->getRoleNames(),
                'organization' => [
                    'id' => $context->organizationId,
                    'name' => $context->organization?->name,
                    'type' => $context->organization?->type,
                    'plan' => $context->organization?->activeSubscription?->plan?->code,
                ],
            ],
        ]);
    }

    public function organizations(Request $request): JsonResponse
    {
        $memberships = $request->user()->memberships()->with('organization')->get();

        return response()->json([
            'data' => $memberships->map(fn ($m) => [
                'id' => $m->organization_id,
                'name' => $m->organization?->name,
                'type' => $m->organization?->type,
                'status' => $m->status,
                'is_default' => $m->is_default,
            ])->all(),
        ]);
    }
}
