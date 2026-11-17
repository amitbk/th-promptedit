<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Date range for the admin usage report. Dates are whole UTC days, inclusive, like Pixelforge's usage report.
 */
class UsageReportRequest extends FormRequest
{
    /** Pixelforge's GET /v1/usage accepts at most this many days. */
    public const MAX_DAYS = 92;

    private const DEFAULT_DAYS = 30;

    /** Invalid ranges return to the report's default range, with the errors shown beside the date fields. */
    protected $redirectRoute = 'admin.usage-report';

    public function authorize(): bool
    {
        return $this->user()?->can('viewAdminReports') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // Covers a start date given alone that is later than today's default end date.
                if ($this->startDate()->greaterThan($this->endDate())) {
                    $validator->errors()->add('start_date', __('The start date must be on or before the end date.'));

                    return;
                }

                if ((int) $this->startDate()->diffInDays($this->endDate()) + 1 > self::MAX_DAYS) {
                    $validator->errors()->add('end_date', __('The date range may cover at most :days days.', ['days' => self::MAX_DAYS]));
                }
            },
        ];
    }

    /** First day of the range, at 00:00:00 UTC. Defaults to 30 days ending today. */
    public function startDate(): CarbonImmutable
    {
        $start = $this->input('start_date');

        return $start
            ? CarbonImmutable::createFromFormat('!Y-m-d', $start, 'UTC')
            : $this->endDate()->subDays(self::DEFAULT_DAYS - 1);
    }

    /** Last day of the range, at 00:00:00 UTC. Defaults to today. */
    public function endDate(): CarbonImmutable
    {
        $end = $this->input('end_date');

        return $end
            ? CarbonImmutable::createFromFormat('!Y-m-d', $end, 'UTC')
            : CarbonImmutable::now('UTC')->startOfDay();
    }
}
