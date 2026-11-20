<?php
declare(strict_types=1);

namespace Kios\Application;

final class PublicTokenDisplayView
{
    /**
     * @param array{outlet:string,tokens:list<array{token:string,publicStatus:string,outlet:string,updatedAt:string}>} $snapshot
     */
    public static function render(array $snapshot, bool $connectionFailed = false): string
    {
        $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tokens = $snapshot['tokens'];
        $preparing = array_values(array_filter($tokens, static fn(array $token): bool => $token['publicStatus'] === 'PREPARING'));
        $ready = array_values(array_filter($tokens, static fn(array $token): bool => $token['publicStatus'] === 'READY'));
        $collected = array_values(array_filter($tokens,static fn(array $token):bool=>$token['publicStatus']==='COLLECTED'));
        $hasTokens = $preparing !== [] || $ready !== [];
        $shopOpen = $snapshot['shopOpen'] ?? null;
        // Version both assets so the hosted CDN cannot serve an older board contract.
        $assetRoot = dirname(__DIR__, 2) . '/public/assets/';
        $cssVersion = substr(hash_file('sha256', $assetRoot . 'display.css'), 0, 12);
        $jsVersion = substr(hash_file('sha256', $assetRoot . 'display.js'), 0, 12);

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<meta name="theme-color" content="#BA0107"><link rel="icon" href="/assets/brand/gmb-favicon-192.png" type="image/png">';
        $html .= '<title>Synthetic Restaurant Demo · Order status</title><link rel="stylesheet" href="/assets/display.css?v=' . $cssVersion . '">';
        $html .= '<script src="/assets/display.js?v=' . $jsVersion . '" defer></script></head><body><div class="display-page">';
        $html .= '<header class="display-header"><img src="/assets/brand-product-placeholder.svg" alt="" width="164" height="142">';
        $html .= '<div><p class="display-eyebrow">Synthetic Restaurant Demo · Synthetic fixtures</p>';
        $html .= '<h1>Order status</h1><p class="display-outlet-line">Outlet: <span id="display-outlet">'
            . $escape($snapshot['outlet']) . '</span></p>';
        $html .= '<p class="display-updated-line">Last checked: <time id="display-updated">'
            . ($connectionFailed ? 'Waiting for connection' : $escape(date('H:i:s'))) . '</time></p></div></header>';
        $html .= '<p id="display-connection" class="display-connection" role="status" aria-live="polite">'
            . ($connectionFailed ? 'Offline · reconnecting' : 'Connecting to live updates…') . '</p>';
        $html .= '<p id="shop-status" class="shop-status" role="status">'
            . ($connectionFailed || !is_bool($shopOpen) ? 'Shop status unavailable' : ($shopOpen ? 'SHOP OPEN' : 'SHOP CLOSED · Ordering is currently unavailable')) . '</p>';
        $html .= '<p id="display-warning" class="display-warning" role="status" aria-live="polite"'
            . ($connectionFailed ? '' : ' hidden') . '>Connection lost. Live token status is hidden until the board reconnects.</p>';
        $html .= '<main id="display-board" class="display-board"' . (!$hasTokens ? ' hidden' : '') . '><section class="display-section display-section-preparing" aria-labelledby="preparing-title">';
        $html .= '<h2 id="preparing-title">PREPARING</h2><ol id="preparing-tokens" class="display-token-list">';
        foreach ($preparing as $token) {
            $html .= '<li class="display-token">' . $escape($token['token']) . '</li>';
        }
        $html .= '</ol></section><section class="display-section display-section-ready" aria-labelledby="ready-title">';
        $html .= '<h2 id="ready-title">READY FOR COLLECTION</h2><ol id="ready-tokens" class="display-token-list">';
        foreach ($ready as $token) {
            $html .= '<li class="display-token">' . $escape($token['token']) . '</li>';
        }
        $html .= '</ol></section></main><section id="collected-section" class="collected-section" aria-labelledby="collected-title"' . ($collected === [] ? ' hidden' : '') . '><h2 id="collected-title">RECENTLY COLLECTED</h2><ol id="collected-tokens" class="collected-list">';
        foreach($collected as $token)$html.='<li class="display-token">'.$escape($token['token']).'</li>';
        $html .= '</ol></section><p id="display-empty" class="display-empty" role="status"'
            . ($hasTokens || $connectionFailed ? ' hidden' : '') . '>No orders are waiting right now.</p>';
        $html .= '</div></body></html>';
        return $html;
    }
}
