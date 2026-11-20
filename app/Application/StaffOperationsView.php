<?php
declare(strict_types=1);
namespace Kios\Application;
use Kios\Infrastructure\ShopOperations;
use PDO;
final class StaffOperationsView {
 public static function render(PDO $db,int $outlet,string $role,string $csrf,array $get):string {
  $ops=new ShopOperations($db);$ops->sweep($outlet);$state=$ops->state($outlet);$rows=$ops->rows($outlet);$escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  if($role==='cashier')return self::renderCashier($state,$rows,$get);
  $buckets=['awaiting'=>'Awaiting payment','preparing'=>'Paid / preparing','ready'=>'Ready for collection','exceptions'=>'Overdue / carry-over exceptions','history'=>'Completed history'];$selected=is_string($get['queue']??null)&&isset($buckets[$get['queue']])?$get['queue']:($role==='ground_floor'?'ready':'awaiting');$search=is_string($get['ops_q']??null)?substr(trim($get['ops_q']),0,100):'';
  $html='<link rel="stylesheet" href="/assets/shop-operations.css"><section class="ops-panel"><h2>Current service · '.$escape($state['businessDate']).'</h2><p>Shop '.($state['open']?'OPEN':'CLOSED').' · '.$escape($state['reason']).'. Existing paid work remains available.</p><nav class="ops-actions">';
  foreach($buckets as $key=>$name){$count=count(array_filter($rows,fn($r)=>$r['bucket']===$key));$html.='<a class="button button-outline" href="/cashier.php?queue='.$key.'">'.$escape($name).' ('.$count.')</a>';}
  $html.='</nav><form method="get" class="ops-form"><input type="hidden" name="queue" value="'.$escape($selected).'"><label>Search all history by token, permanent ID or date<input name="ops_q" value="'.$escape($search).'" maxlength="100"></label><button class="button button-outline">Search history</button></form>';
  $filtered=array_values(array_filter($rows,fn($r)=>$search!==''?stripos($r['pickup_token'].' '.$r['order_id'].' '.$r['public_id'].' '.$r['pickup_business_date'],$search)!==false:$r['bucket']===$selected));$page=max(1,min(max(1,(int)ceil(count($filtered)/20)),(int)($get['ops_page']??1)));
  $html.='<h3>'.($search!==''?'Search results':$escape($buckets[$selected])).' · '.count($filtered).'</h3><p>Newest first. All unresolved orders remain accessible through the queues and pages. Kitchen preparation priority is unchanged.</p>';
  foreach(array_slice($filtered,($page-1)*20,20) as $row){$id=(int)$row['order_id'];$html.='<article class="ops-order'.($row['aging']||$row['ready_warning']?' ops-aging':'').'" data-order-id="'.$id.'"><h3>'.$escape($row['pickup_token']).' · ID '.$id.'</h3><p>'.$escape($row['pickup_business_date']).' · '.($row['carry_over']?'CARRY-OVER · ':'').$escape(strtoupper($row['status'])).' · '.($row['paid']?'PAID':'UNPAID').' · INR '.number_format((int)$row['total_paise']/100,2).'</p><p>Order age '.(int)floor($row['age_seconds']/60).' minutes'.($row['ready_warning']?' · READY / UNCOLLECTED WARNING':'').'</p>';
   if($row['unpaid_expired'])$html.='<p>EXPIRED UNPAID — cannot accept payment. Historical evidence is preserved.</p>';
   if($row['bucket']==='exceptions'&&$row['paid'])$html.='<p>Paid order requires authorized collection or an owner reconciliation decision. No automatic refund, cancellation or disposal has occurred.</p>';
   if($role==='cashier'&&!$row['carry_over']&&!$row['unpaid_expired'])$html.='<a class="button button-outline" href="/cashier.php?token='.rawurlencode($row['pickup_token']).'">Open order and payment details</a>';
   if($role==='ground_floor'&&$row['paid']&&$row['status']==='ready')$html.='<form method="post" action="/cashier.php"><input type="hidden" name="_csrf" value="'.$escape($csrf).'"><input type="hidden" name="action" value="collect-order"><input type="hidden" name="order_id" value="'.$id.'"><input type="hidden" name="token" value="'.$escape($row['pickup_token']).'"><button class="button button-primary">Mark collected</button></form>';
   $html.='</article>';
  }
  if($filtered===[])$html.='<p>No orders in this view.</p>';
  if($page>1)$html.='<a class="button button-outline" href="?'.http_build_query(['queue'=>$selected,'ops_q'=>$search,'ops_page'=>$page-1]).'">Newer page</a>';
  if($page*20<count($filtered))$html.='<a class="button button-outline" href="?'.http_build_query(['queue'=>$selected,'ops_q'=>$search,'ops_page'=>$page+1]).'">Older page</a>';
  return $html.'</section>';
 }

    private static function renderCashier(array $state, array $rows, array $get): string
    {
        $escape = self::escape(...);
        $queues = [
            'awaiting' => 'New · awaiting payment',
            'paid' => 'Paid',
            'preparing' => 'Preparing',
            'ready' => 'Ready for collection',
            'exceptions' => 'Needs attention',
            'history' => 'Completed history',
            'closed' => 'Cancelled / expired',
        ];
        $selected = is_string($get['queue'] ?? null) && isset($queues[$get['queue']])
            ? $get['queue']
            : 'awaiting';
        $search = is_string($get['ops_q'] ?? null) ? substr(trim($get['ops_q']), 0, 100) : '';

        $counts = [];
        foreach (array_keys($queues) as $key) {
            $counts[$key] = count(array_filter($rows, static fn(array $row): bool => self::inCashierQueue($row, $key)));
        }

        $html = '<section class="ops-panel ops-cashier-panel" aria-labelledby="cashier-ops-title">'
            . '<div class="ops-panel-heading"><div><p class="ops-eyebrow">Cashier desk</p>'
            . '<h2 id="cashier-ops-title">Orders · ' . $escape($state['businessDate']) . '</h2></div>'
            . '<span class="ops-date-label">Newest first</span></div>'
            . '<div class="ops-shop-state ' . ($state['open'] ? 'is-open' : 'is-closed') . '" role="status">'
            . '<span class="ops-shop-state-mark" aria-hidden="true">' . ($state['open'] ? '✓' : '•') . '</span>'
            . '<div><strong>Shop ' . ($state['open'] ? 'OPEN' : 'CLOSED') . '</strong>'
            . '<span>' . ($state['open']
                ? 'Customer ordering is within the scheduled service window.'
                : 'New customer orders are off. Existing orders remain available for follow-up.')
            . '</span></div></div>'
            . '<nav class="ops-queues" aria-label="Cashier order queues">';

        foreach ($queues as $key => $label) {
            $current = $selected === $key && $search === '';
            $html .= '<a class="ops-queue-link' . ($current ? ' is-current' : '') . '" href="/cashier.php?queue=' . $key . '"'
                . ($current ? ' aria-current="page"' : '') . '><span>' . $escape($label) . '</span>'
                . '<strong class="ops-queue-count" aria-label="' . $counts[$key] . ' orders">' . $counts[$key] . '</strong></a>';
        }
        $html .= '</nav>'
            . '<form method="get" class="ops-form ops-cashier-search" role="search">'
            . '<label for="cashier-history-search">Find an order <span>Token, order ID or business date</span>'
            . '<input id="cashier-history-search" name="ops_q" value="' . $escape($search) . '" maxlength="100"'
            . ' placeholder="For example, G-101 or 2026-11-07" autocomplete="off"></label>'
            . '<button class="button button-primary" type="submit">Search orders</button>';
        if ($search !== '') {
            $html .= '<a class="ops-clear-search" href="/cashier.php?queue=' . rawurlencode($selected) . '">Clear search</a>';
        } else {
            $html .= '<input type="hidden" name="queue" value="' . $escape($selected) . '">';
        }
        $html .= '</form>';

        $filtered = array_values(array_filter($rows, static function (array $row) use ($selected, $search): bool {
            if ($search !== '') {
                return stripos(
                    (string) $row['pickup_token'] . ' ' . (string) $row['order_id'] . ' '
                    . (string) $row['public_id'] . ' ' . (string) $row['pickup_business_date'],
                    $search,
                ) !== false;
            }
            return self::inCashierQueue($row, $selected);
        }));
        $pageCount = max(1, (int) ceil(count($filtered) / 20));
        $page = max(1, min($pageCount, (int) ($get['ops_page'] ?? 1)));
        $title = $search !== '' ? 'Search results' : $queues[$selected];
        $html .= '<div class="ops-results-heading"><h3>' . $escape($title) . '</h3>'
            . '<span>' . count($filtered) . ' ' . (count($filtered) === 1 ? 'order' : 'orders') . '</span></div>';

        $visibleRows = array_slice($filtered, ($page - 1) * 20, 20);
        if ($visibleRows === []) {
            $html .= '<p class="ops-empty-state" role="status">No orders in this view. Other open orders remain available in their queues.</p>';
        } else {
            $html .= '<div class="ops-order-list">';
            foreach ($visibleRows as $row) {
                $html .= self::renderCashierOrder($row, $escape);
            }
            $html .= '</div>';
        }

        if ($page > 1) {
            $html .= '<a class="button button-outline" href="?' . http_build_query([
                'queue' => $selected,
                'ops_q' => $search,
                'ops_page' => $page - 1,
            ]) . '">Newer orders</a>';
        }
        if ($page * 20 < count($filtered)) {
            $html .= '<a class="button button-outline" href="?' . http_build_query([
                'queue' => $selected,
                'ops_q' => $search,
                'ops_page' => $page + 1,
            ]) . '">Older orders</a>';
        }

        return $html . '</section>';
    }

    private static function renderCashierOrder(array $row, callable $escape): string
    {
        $id = (int) $row['order_id'];
        $token = (string) $row['pickup_token'];
        $status = (string) $row['status'];
        $statusLabel = match ($status) {
            'awaiting_payment' => 'Awaiting payment',
            'paid' => 'Paid',
            'preparing' => 'Preparing',
            'ready' => 'Ready for collection',
            'collected' => 'Collected',
            'cancelled' => 'Cancelled',
            'expired' => 'Expired',
            'draft' => 'New draft',
            default => strtoupper(str_replace('_', ' ', $status)),
        };
        $amount = '₹' . number_format((int) $row['total_paise'] / 100, 2, '.', ',');
        $paymentLabel = $row['paid'] ? 'Payment confirmed' : ($row['unpaid_expired'] ? 'Payment expired' : ($status === 'awaiting_payment' ? 'Payment due' : 'No payment recorded'));
        $class = 'ops-order ops-cashier-order';
        if ($status !== 'collected' && ($row['bucket'] === 'exceptions' || $row['ready_warning'] || $row['aging'] || $row['carry_over'])) {
            $class .= ' is-exception';
        } elseif ($row['bucket'] === 'ready') {
            $class .= ' is-ready';
        } elseif ($row['bucket'] === 'awaiting') {
            $class .= ' is-awaiting';
        }

        $html = '<article class="' . $class . '" data-order-id="' . $id . '">'
            . '<div class="ops-order-top"><div><span class="ops-order-kicker">Pickup token</span>'
            . '<h4 class="ops-order-token">' . $escape($token) . '</h4></div>'
            . '<div class="ops-order-amount"><span>Total</span><strong>' . $escape($amount) . '</strong></div></div>'
            . '<div class="ops-order-badges"><span class="ops-status-badge status-' . $escape($status) . '">' . $escape($statusLabel) . '</span>'
            . '<span class="ops-payment-badge ' . ($row['paid'] ? 'is-paid' : 'is-unpaid') . '">' . $escape($paymentLabel) . '</span></div>'
            . '<p class="ops-order-meta"><span>Order ' . $id . '</span><span>' . $escape($row['pickup_business_date']) . '</span>'
            . ($row['carry_over'] ? '<span class="ops-carry-over">Carry-over</span>' : '')
            . '<span>Elapsed ' . (int) floor((int) $row['age_seconds'] / 60) . ' min</span></p>';

        if ($row['unpaid_expired']) {
            $html .= '<p class="ops-order-alert" role="alert"><strong>Payment window expired.</strong> Do not confirm this payment; keep the record for follow-up.</p>';
        } elseif ($status === 'draft') {
            $html .= '<p class="ops-order-alert" role="status"><strong>Incomplete draft.</strong> No payment can be confirmed from this view; review the record.</p>';
        } elseif ($row['ready_warning']) {
            $html .= '<p class="ops-order-alert" role="status"><strong>Ready for too long.</strong> Collection follow-up is needed.</p>';
        } elseif ($status !== 'collected' && $row['carry_over']) {
            $html .= '<p class="ops-order-alert" role="status"><strong>Carry-over order.</strong> Review before taking action.</p>';
        } elseif ($status !== 'collected' && $row['aging']) {
            $html .= '<p class="ops-order-alert" role="status"><strong>Overdue order.</strong> This order has passed its service-time warning.</p>';
        }

        if ($row['bucket'] === 'exceptions' && $row['paid'] && !$row['ready_warning'] && !$row['carry_over']) {
            $html .= '<p class="ops-order-note">Paid order needs authorized collection or owner reconciliation. No refund or cancellation was applied.</p>';
        }

        if (!$row['carry_over'] && !$row['unpaid_expired'] && !in_array($status, ['cancelled', 'expired', 'draft'], true)) {
            $action = $status === 'awaiting_payment' ? 'Review payment' : 'View order';
            $html .= '<a class="button button-primary ops-order-action" href="/cashier.php?token=' . rawurlencode($token) . '">' . $action . '</a>';
        }

        return $html . '</article>';
    }

    private static function inCashierQueue(array $row, string $queue): bool
    {
        $status = (string) $row['status'];
        return match ($queue) {
            'awaiting' => $row['bucket'] === 'awaiting',
            'paid' => $status === 'paid' && $row['bucket'] !== 'exceptions',
            'preparing' => $status === 'preparing' && $row['bucket'] !== 'exceptions',
            'ready' => $status === 'ready' && $row['bucket'] === 'ready' && !$row['ready_warning'],
            'exceptions' => $status !== 'collected' && ($row['bucket'] === 'exceptions' || $row['ready_warning'] || $row['aging'] || $status === 'draft'),
            'history' => $status === 'collected',
            'closed' => in_array($status, ['cancelled', 'expired'], true) && $row['bucket'] !== 'exceptions',
            default => false,
        };
    }

    private static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
