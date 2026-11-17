<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UsageReportRequest;
use App\Services\Reports\ProviderReconciliationService;
use App\Services\Reports\UsageReportService;
use Inertia\Inertia;
use Inertia\Response;

class UsageReportController extends Controller
{
    /**
     * Show customer charges, provider costs and profit for a UTC date range, reconciled with Pixelforge.
     */
    public function __invoke(UsageReportRequest $request, UsageReportService $reports, ProviderReconciliationService $reconciliation): Response
    {
        $start = $request->startDate();
        $end = $request->endDate();

        return Inertia::render('admin/UsageReport', [
            'filters' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'max_days' => UsageReportRequest::MAX_DAYS,
            ],
            'report' => $reports->report($start, $end),
            // Calls Pixelforge, so it loads after the page instead of delaying it.
            'reconciliation' => Inertia::defer(fn () => $reconciliation->reconcile($start, $end)),
        ]);
    }
}
