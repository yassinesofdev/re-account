<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;

class OrgAdminController extends Controller
{
    public function index(Request $request)
    {
        $organizations = Organization::query()
            ->withCount('users as member_count')
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($organizations);
    }
}
