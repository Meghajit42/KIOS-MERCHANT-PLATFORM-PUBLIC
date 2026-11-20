<?php
declare(strict_types=1);
namespace Kios\Application;

/** Ground Floor presentation only. Task order and mutation forms remain unchanged. */
final class GroundFloorPreparationView
{
    public static function decorate(string $html, array $tasks, ?array $context): string
    {
        $counts = ['all'=>count($tasks),'queued'=>0,'preparing'=>0,'ready'=>0,'overdue'=>0];
        $overdue = $context['overdueOrderIds'] ?? [];
        foreach ($tasks as $task) {
            $counts[$task['status']->value]++;
            if (in_array($task['orderId'] ?? null, $overdue, true)) $counts['overdue']++;
        }
        $toolbar = '<div class="gf-toolbar"><p>Ground Floor preparation · Sweets, Snacks, Dahi, Raita and approved items</p>';
        if ($context !== null) $toolbar .= '<p class="gf-shop-state">Shop ' . (!empty($context['open']) ? 'OPEN' : 'CLOSED') . ' · Existing paid work remains available.</p>';
        $toolbar .= '<nav class="gf-filters" aria-label="Preparation queues">';
        foreach (['all'=>'All active','queued'=>'New','preparing'=>'Preparing','ready'=>'Ready','overdue'=>'Overdue'] as $key=>$label) {
            $toolbar .= '<button type="button" data-gf-filter="'.$key.'" aria-pressed="'.($key==='all'?'true':'false').'">'.$label.' <strong>'.$counts[$key].'</strong></button>';
        }
        $toolbar .= '<a href="/cashier.php?queue=history">Completed history ↗</a></nav><p class="gf-priority">Queue priority is preserved. Ready tasks remain here while another station is preparing the order.</p><p class="gf-filter-empty" hidden role="status">No orders in this view.</p></div>';
        $html = str_replace('class="station-view"', 'class="station-view gf-preparation"', $html);
        $html = preg_replace('/<h1 id="station-title">(.*?)<\/h1>/', '<h1 id="station-title" data-station-label="$1">Ground Floor</h1>', $html);
        $html = str_replace('<p class="station-code">SWEETS</p>', '<p class="station-code">Preparation queue</p>', $html);
        $html = str_replace('<p class="station-task-time">', '<p class="station-task-time">Arrived ', $html);
        $headerEnd = strpos($html, '</header>');
        if ($headerEnd !== false) $html = substr_replace($html, $toolbar, $headerEnd + strlen('</header>'), 0);
        $index = 0;
        $html = preg_replace_callback('/<li class="station-task panel">/', static function () use (&$index,$tasks,$overdue): string {
            $task=$tasks[$index++];$late=in_array($task['orderId']??null,$overdue,true);
            return '<li class="station-task panel'.($late?' gf-overdue':'').'" data-gf-status="'.$task['status']->value.'" data-gf-overdue="'.($late?'1':'0').'">'.($late?'<p class="gf-warning">Overdue · preparation needs attention</p>':'');
        }, $html);
        $html = str_replace('Open manual KOT','View Digital KOT',$html);
        $html = str_replace('Mark station ready','Mark Ready',$html);
        $html = str_replace('<p class="station-kot-status','<details class="gf-details"><summary>View Order Details</summary><p class="station-kot-status',$html);
        $html = str_replace('<p class="station-manual-kot">','</details><p class="station-manual-kot">',$html);
        return '<link rel="stylesheet" href="/assets/ground-floor.css?v=1"><script src="/assets/ground-floor.js?v=1" defer></script>'.$html;
    }
}
