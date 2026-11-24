<?php

namespace App\Admin\Controllers;

use App\Admin\DataTransfer\DataTransferCatalog;
use App\Admin\Navigation\AdminNavigation;
use App\Identity\Infrastructure\Persistence\UserRecord as User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class DataTransferController
{
    public function __invoke(Request $request, AdminNavigation $navigation, DataTransferCatalog $catalog): Response
    {
        Gate::authorize('data-transfer.view');
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Admin/DataTransfer/Index', [
            'navigation' => $navigation->forUser($user),
            'breadcrumbs' => [
                ['label' => __('navigation.dashboard'), 'href' => route('admin.dashboard', [], false)],
                ['label' => __('navigation.data_transfer'), 'href' => null],
            ],
            'transfers' => $catalog->forUser($user),
        ]);
    }
}
