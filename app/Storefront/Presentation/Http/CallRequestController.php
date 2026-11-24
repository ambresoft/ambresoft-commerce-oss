<?php

namespace App\Storefront\Presentation\Http;

use App\Customer\Infrastructure\CallRequests;
use App\Storefront\Infrastructure\StorefrontAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CallRequestController
{
    public function __invoke(Request $request, CallRequests $calls): JsonResponse
    {
        abort_unless(app(StorefrontAccess::class)->allowed(request()), 404);
        $data = $request->validate(['form' => ['required', 'uuid'], 'website' => ['nullable', 'string', 'max:200']]);
        // Recheck the saved switch, including references from per-page content overrides.
        $enabled = DB::table('storefront_blocks as b')->where('b.id', $data['form'])->where('b.type', 'identity')->where('b.settings->show_call_request', true)
            ->where(fn ($q) => $q->whereExists(fn ($p) => $p->selectRaw('1')->from('storefront_placements')->whereColumn('block_id', 'b.id')->where('enabled', true))
                ->orWhereExists(fn ($o) => $o->selectRaw('1')->from('storefront_overrides')->whereColumn('block_id', 'b.id')->where('enabled', true)))->exists();
        abort_unless($enabled, 404);
        if (($data['website'] ?? '') === '') {
            $calls->submit($request->only(['name', 'phone', 'call_from', 'call_to', 'order_number']));
        }

        return response()->json(['status' => 'ok']);
    }
}
