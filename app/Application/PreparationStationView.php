<?php
declare(strict_types=1);

namespace Kios\Application;

use Kios\Domain\PreparationTaskStatus;
use Throwable;

final class PreparationStationView
{
    /**
     * @param list<array{taskId:int,token:string,status:PreparationTaskStatus,createdAt:string,kotPrintStatus?:?string,kotAdapterType?:?string,items:list<array{name:string,variant:string,unit:string,quantity:int}>}> $tasks
     */
    public static function render(string $stationCode, string $stationName, array $tasks, string $csrfToken, ?array $groundFloorContext = null, ?array $firstFloorContext = null): string
    {
        $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<section class="station-view" aria-labelledby="station-title">';
        $html .= '<header class="station-view-header"><div class="station-view-brand"><div><p class="eyebrow">GMB · Live preparation</p>';
        $html .= '<h1 id="station-title">' . $escape($stationName) . '</h1>';
        $html .= '<p class="station-code">' . $escape($stationCode) . '</p></div></div>';
        $html .= '<span class="station-count">' . count($tasks) . (count($tasks) === 1 ? ' active order' : ' active orders') . '</span></header>';

        if ($tasks === []) {
            $html .= '<div class="station-empty panel" role="status"><h2>Queue clear</h2><div class="station-empty-counts">'
                . '<span><strong>0</strong><small>waiting</small></span><span><strong>0</strong><small>preparing</small></span></div>'
                . '<p class="station-ready-note">Station ready</p></div></section>';
            return $stationCode === 'SWEETS' ? GroundFloorPreparationView::decorate($html, $tasks, $groundFloorContext) : ($firstFloorContext !== null ? FirstFloorPreparationView::decorate($html, $tasks, $firstFloorContext, $stationName) : $html);
        }

        $html .= '<ol class="station-task-list">';
        foreach ($tasks as $task) {
            $status = $task['status'];
            try {
                $created = (new \DateTimeImmutable($task['createdAt']))
                    ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                $elapsedMinutes = max(0, intdiv(time() - $created->getTimestamp(), 60));
                $timeLabel = $created->format('M j, H:i');
                $timeMarkup = '<time datetime="' . $escape($created->format(DATE_ATOM)) . '">' . $escape($timeLabel) . '</time>';
                $elapsedLabel = $elapsedMinutes . ' min';
            } catch (Throwable) {
                $timeMarkup = '<span>Time unavailable</span>';
                $elapsedLabel = '—';
            }

            $html .= '<li class="station-task panel"><header class="station-task-heading"><div>';
            $html .= '<p class="eyebrow">Pickup token</p><h2>' . $escape($task['token']) . '</h2></div>';
            if(\Kios\Infrastructure\ShopOperations::enabled()&&isset($task['businessDate'],$task['orderId']))$html.='<p class="station-code">'.$escape($task['businessDate']).' · Order ID '.$escape($task['orderId']).'</p>';
            $html .= '<span class="station-task-status status-' . $escape($status->value) . '">' . $escape(strtoupper($status->value)) . '</span></header>';
            $html .= '<p class="station-task-time">' . $timeMarkup . ' <span aria-label="elapsed">· ' . $escape($elapsedLabel) . '</span></p>';
            $html .= '<ul class="station-item-list">';
            foreach ($task['items'] as $item) {
                $html .= '<li><div><strong>' . $escape($item['name']) . '</strong>';
                $html .= '<small>' . $escape($item['variant']) . ' · ' . $escape($item['unit']) . '</small></div>';
                $html .= '<span class="station-item-quantity">× ' . $escape($item['quantity']) . '</span></li>';
            }
            $html .= '</ul>';

            $printStatus = $task['kotPrintStatus'] ?? null;
            $printStatusLabel = match ($printStatus) {
                'queued' => 'Queued · not printed',
                'printing' => 'Sending · completion not confirmed',
                'printed' => ($task['kotAdapterType'] ?? null) === 'virtual'
                    ? 'Virtual preview created · not physically printed'
                    : 'Job submitted · physical output not verified',
                'failed' => 'Print failed · not printed',
                'claimed' => 'Gateway claimed · paper not confirmed',
                'submitted_unconfirmed' => 'Submitted · paper not confirmed',
                'needs_review' => 'Needs attention · check for paper or use the digital KOT',
                'reprint_requested' => 'Reprint queued · paper not confirmed',
                'cancelled' => 'Ticket cancelled · use the digital KOT',
                default => 'No automatic KOT job · use manual paper fallback if needed',
            };
            $attention = in_array($printStatus, ['failed', 'needs_review', 'cancelled'], true) ? ' print-status-failed' : '';
            $html .= '<p class="station-kot-status' . $attention . '" role="status"><strong>KOT print status:</strong> ' . $escape($printStatusLabel) . '</p>';
            $html .= '<p class="station-manual-kot"><a class="button button-outline" href="/cashier.php?station=' . rawurlencode($stationCode)
                . '&amp;manual_kot=' . $escape($task['taskId']) . '">Open manual KOT</a>'
                . '<small>This view is for a staff-prepared paper fallback. Opening it does not send a printer job or mark the KOT printed.</small></p>';

            if ($status === PreparationTaskStatus::Queued || $status === PreparationTaskStatus::Preparing) {
                $label = $status === PreparationTaskStatus::Queued ? 'Start preparing' : 'Mark station ready';
                $html .= '<form method="post" action="/cashier.php" class="station-task-action">';
                $html .= '<input type="hidden" name="_csrf" value="' . $escape($csrfToken) . '">';
                $html .= '<input type="hidden" name="action" value="advance-station-task">';
                $html .= '<input type="hidden" name="task_id" value="' . $escape($task['taskId']) . '">';
                $html .= '<input type="hidden" name="station_code" value="' . $escape($stationCode) . '">';
                $html .= '<input type="hidden" name="expected_status" value="' . $escape($status->value) . '">';
                $html .= '<button class="button button-primary button-wide" type="submit">' . $escape($label) . '</button></form>';
            } else {
                $html .= '<p class="station-ready-note" role="status">This station is ready.</p>';
            }
            $html .= '</li>';
        }
        $html .= '</ol></section>';
        return $stationCode === 'SWEETS' ? GroundFloorPreparationView::decorate($html, $tasks, $groundFloorContext) : ($firstFloorContext !== null ? FirstFloorPreparationView::decorate($html, $tasks, $firstFloorContext, $stationName) : $html);
    }
}
