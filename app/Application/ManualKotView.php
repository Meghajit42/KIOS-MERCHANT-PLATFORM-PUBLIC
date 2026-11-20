<?php
declare(strict_types=1);

namespace Kios\Application;

use Kios\Domain\PreparationTaskStatus;

final class ManualKotView
{
    /** @param array{taskId:int,token:string,status:PreparationTaskStatus,createdAt:string,items:list<array{name:string,variant:string,unit:string,quantity:int}>} $task */
    public static function render(string $stationCode, string $stationName, array $task): string
    {
        $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<section class="manual-kot panel" aria-labelledby="manual-kot-title">';
        $html .= '<p class="eyebrow">STAFF FALLBACK · NOT SENT TO PRINTER</p><h1 id="manual-kot-title">Manual Kitchen KOT</h1>';
        $html .= '<p><strong>Station:</strong> ' . $escape($stationName) . ' · ' . $escape($stationCode) . '</p>';
        $html .= '<p class="manual-kot-token-label">Customer token</p><p class="manual-kot-token">' . $escape($task['token']) . '</p>';
        $html .= '<p><strong>Task status:</strong> ' . $escape(strtoupper($task['status']->value)) . '</p><ul class="station-item-list">';
        foreach ($task['items'] as $item) {
            $html .= '<li><div><strong>' . $escape($item['name']) . '</strong><small>' . $escape($item['variant']) . ' · ' . $escape($item['unit']) . '</small></div>';
            $html .= '<span class="station-item-quantity">× ' . $escape($item['quantity']) . '</span></li>';
        }
        $html .= '</ul><p class="cashier-flash" role="note">This page is a manual paper reference only. Printing it does not create or update a print job and does not record the KOT as printed.</p>';
        $html .= '<div class="manual-kot-actions"><button class="button button-primary" type="button" data-print-manual-kot>Print this manual KOT</button>';
        $html .= '<a class="button button-outline" href="/cashier.php?station=' . rawurlencode($stationCode) . '">Return to station queue</a></div></section>';
        $html .= '<script src="/assets/manual-kot.js" defer></script>';
        return $html;
    }
}
