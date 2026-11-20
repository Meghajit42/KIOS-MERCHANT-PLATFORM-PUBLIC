<?php
declare(strict_types=1);
namespace Kios\Application;

/** First Floor presentation only. Task order and mutation forms remain unchanged. */
final class FirstFloorPreparationView
{
    public static function decorate(string $html, array $tasks, ?array $context, string $stationName): string
    {
        $counts = ['all'=>count($tasks),'queued'=>0,'preparing'=>0,'ready'=>0,'overdue'=>0];
        $overdue = $context['overdueOrderIds'] ?? [];
        foreach ($tasks as $task) {
            $counts[$task['status']->value]++;
            if (in_array($task['orderId'] ?? null, $overdue, true)) $counts['overdue']++;
        }
        $toolbar = '<div class="ff-toolbar"><p>First Floor kitchen · Current station queue. Choose other assigned stations using the navigation above.</p>';
        if (isset($context['open'])) $toolbar .= '<p class="ff-shop-state">Shop ' . (!empty($context['open']) ? 'OPEN' : 'CLOSED') . ' · Existing paid work remains available.</p>';
        $toolbar .= '<nav class="ff-filters" aria-label="Preparation queues">';
        foreach (['all'=>'All active','queued'=>'New','preparing'=>'Preparing','ready'=>'Ready','overdue'=>'Overdue'] as $key=>$label) {
            $toolbar .= '<button type="button" data-ff-filter="'.$key.'" aria-pressed="'.($key==='all'?'true':'false').'">'.$label.' <strong>'.$counts[$key].'</strong></button>';
        }
        $toolbar .= '<details class="ff-history"><summary>Completed history</summary><p>Completed orders leave this active queue. Ask the cashier or owner to review completed order history.</p></details></nav><p class="ff-priority">Queue priority is preserved. Ready tasks remain here while another station is preparing the order.</p><p class="ff-filter-empty" hidden role="status">No orders in this view.</p></div>';
        $html = str_replace('class="station-view"', 'class="station-view ff-preparation"', $html);
        $html = preg_replace('/<h1 id="station-title">(.*?)<\/h1>/', '<h1 id="station-title">First Floor · $1</h1>', $html);
        $html = str_replace('<p class="station-task-time">', '<p class="station-task-time">Arrived ', $html);
        $headerEnd = strpos($html, '</header>');
        if ($headerEnd !== false) $html = substr_replace($html, $toolbar, $headerEnd + strlen('</header>'), 0);
        $stationLabel = htmlspecialchars($stationName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $index = 0;
        $html = preg_replace_callback('/<li class="station-task panel">/', static function () use (&$index,$tasks,$overdue,$stationLabel): string {
            $task=$tasks[$index++];$late=in_array($task['orderId']??null,$overdue,true);
            return '<li class="station-task panel'.($late?' ff-overdue':'').'" data-ff-status="'.$task['status']->value.'" data-ff-overdue="'.($late?'1':'0').'">'.($late?'<p class="ff-warning">Overdue · preparation needs attention</p>':'').'<p class="ff-station-label">Assigned station: <strong>'.$stationLabel.'</strong></p>';
        }, $html);
        $html = str_replace('Open manual KOT','View Digital KOT',$html);
        $html = str_replace('Mark station ready','Mark Ready',$html);
        $html = str_replace('<p class="station-kot-status','<details class="ff-details"><summary>View Order Details</summary><p class="station-kot-status',$html);
        $html = str_replace('<p class="station-manual-kot">','</details><p class="station-manual-kot">',$html);
        return '<link rel="stylesheet" href="/assets/first-floor.css?v=1"><script src="/assets/first-floor.js?v=1" defer></script>'.$html;
    }
}
