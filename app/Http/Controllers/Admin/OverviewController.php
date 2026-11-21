<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\PlatformStats;
use Inertia\Inertia;
use Inertia\Response;

/** The admin's home: how the platform is being used (people only counted, never listed). */
class OverviewController extends Controller
{
    public function index(PlatformStats $stats): Response
    {
        return Inertia::render('admin/overview', [
            ...$stats->overview(),
            'days' => PlatformStats::DAYS,
        ]);
    }
}
