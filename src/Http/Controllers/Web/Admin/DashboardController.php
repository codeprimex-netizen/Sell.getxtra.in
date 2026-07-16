<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Application\Admin\AdminReportService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Admin operations dashboard (Req 12.5): GMV, order/user/product counts,
 * moderation backlog, open disputes, and top sellers.
 */
final class DashboardController extends Controller
{
    public function __construct(private AdminReportService $reports)
    {
    }

    public function index(Request $request): Response
    {
        return $this->view($request, 'admin.dashboard', array_merge(
            $this->reports->dashboard(),
            ['wide' => true],
        ));
    }
}
