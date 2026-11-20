<?php
declare(strict_types=1);

namespace Kios\Application;

final class PrintQueueView
{
    /** @param list<array<string, mixed>> $jobs */
    public static function render(array $jobs, string $csrfToken, bool $owner = false): string
    {
        $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<section class="print-queue panel"><div class="print-queue-heading"><div class="print-queue-title"><img src="/assets/brand-product-placeholder.svg" alt="" width="54" height="48">'
            . '<div><p class="eyebrow">Synthetic Restaurant Demo · Staff operations</p><h2>Print queue</h2></div></div><span class="station-count">' . count($jobs) . ' recent jobs</span></div>';
        if ($jobs === []) {
            return $html . '<p class="print-queue-empty">No print jobs yet.</p></section>';
        }

        $html .= '<ol class="print-job-list">';
        foreach ($jobs as $job) {
            $status = (string) ($job['status'] ?? 'unknown');
            $gateway = ($job['adapter_type_snapshot'] ?? null) === 'gateway';
            $jobId = (int) ($job['print_job_id'] ?? 0);
            $title = ($job['job_type'] ?? '') === 'STATION_KOT' ? 'Station KOT' : 'Cashier receipt';
            if (is_string($job['copy_label'] ?? null) && $job['copy_label'] !== '') {
                $title .= ' · ' . $job['copy_label'];
            }
            $html .= '<li class="print-job-card"><div class="print-job-heading"><div><p class="eyebrow">' . $escape($title)
                . ($job['station_code_snapshot'] !== null ? ' · ' . $escape($job['station_code_snapshot']) : '') . '</p><h3>'
                . $escape($job['token_snapshot']) . '</h3></div><span class="print-job-status print-status-' . $escape($status) . '">'
                . $escape(self::statusLabel($status)) . '</span></div>';
            $html .= '<dl class="print-job-details"><div><dt>Printer</dt><dd>' . $escape($job['printer_name']) . '</dd></div>'
                . '<div><dt>Attempts</dt><dd>' . $escape($job['attempt_count']) . '</dd></div>'
                . '<div><dt>Created</dt><dd>' . $escape($job['created_at']) . '</dd></div>';
            if (is_string($job['last_error_category'] ?? null) && $job['last_error_category'] !== '') {
                $html .= '<div><dt>Error</dt><dd>' . $escape($job['last_error_category']) . '</dd></div>';
            }
            if (is_string($job['last_error_message'] ?? null) && $job['last_error_message'] !== '') {
                $html .= '<div class="print-job-error"><dt>Details</dt><dd>' . $escape($job['last_error_message']) . '</dd></div>';
            }
            if (!empty($job['reprint_of_job_id'])) {
                $html .= '<div><dt>Reprint of</dt><dd>#' . $escape($job['reprint_of_job_id']) . '</dd></div>';
                $html .= '<div class="print-job-error"><dt>Reason</dt><dd>' . $escape($job['reprint_reason'] ?? '') . '</dd></div>';
                $html .= '<div><dt>Requested by</dt><dd>' . $escape($job['requested_by'] ?? '') . '</dd></div>';
            }
            $html .= '</dl><div class="print-job-actions">';
            if (!$owner && !$gateway && in_array($status, ['failed', 'retry_pending', 'queued'], true)) {
                $html .= '<form method="post">' . self::csrf($csrfToken, $escape)
                    . '<input type="hidden" name="action" value="retry-print-job"><input type="hidden" name="print_job_id" value="' . $jobId . '">'
                    . '<button class="button button-outline" type="submit">Retry print</button></form>';
            }
            if ((!$gateway || $owner) && in_array($status, ['printed', 'failed', 'submitted_unconfirmed', 'needs_review'], true)) {
                $html .= '<form method="post">' . self::csrf($csrfToken, $escape)
                    . '<input type="hidden" name="action" value="reprint-print-job"><input type="hidden" name="print_job_id" value="' . $jobId . '">'
                    . '<input type="hidden" name="request_id" value="' . bin2hex(random_bytes(16)) . '">'
                    . ($owner ? '<label><input type="checkbox" name="inspected" value="1" required> I checked for an earlier paper copy</label>' : '')
                    . '<label for="print-reason-' . $jobId . '">Reprint reason</label><input id="print-reason-' . $jobId
                    . '" name="reason" minlength="3" maxlength="240" required><button class="button button-outline" type="submit">Create reprint</button></form>';
            }
            if ($owner && $gateway && in_array($status, ['queued', 'reprint_requested'], true)) {
                $html .= '<form method="post">' . self::csrf($csrfToken, $escape)
                    . '<input type="hidden" name="action" value="cancel-print-job"><input type="hidden" name="print_job_id" value="' . $jobId . '">'
                    . '<button class="button button-outline" type="submit">Cancel queued ticket</button></form>';
            }
            $html .= '</div></li>';
        }
        return $html . '</ol></section>';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'claimed' => 'Gateway claimed',
            'submitted_unconfirmed' => 'Submitted · paper not confirmed',
            'needs_review' => 'Needs attention · check for paper',
            'reprint_requested' => 'Reprint queued',
            default => strtoupper(str_replace('_', ' ', $status)),
        };
    }

    /** @param callable(mixed):string $escape */
    private static function csrf(string $token, callable $escape): string
    {
        return '<input type="hidden" name="_csrf" value="' . $escape($token) . '">';
    }
}
