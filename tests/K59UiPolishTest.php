<?php
declare(strict_types=1);

return [
    'customer layout stays within narrow viewports and fits grid and desktop cart' => static function (string $root): void {
        $css = file_get_contents($root . '/public/assets/app.css');
        $index = file_get_contents($root . '/public/index.php');
        if (!is_string($css) || !is_string($index)
            || !str_contains($css, '.customer-menu-main .product-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }')
            || !str_contains($css, '.customer-menu-main .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr));')
            || !str_contains($css, '.welcome { width: 100%; min-width: 0; }')
            || !str_contains($css, '.welcome-brand { width: 100%; min-width: 0; }')
            || !str_contains($css, '.welcome-brand img { max-width: 100%; }')
            || !str_contains($css, '@media (min-width: 900px)')
            || !str_contains($css, '.customer-menu-layout { grid-template-columns: minmax(0, 1fr) minmax(220px, 250px);')
            || !str_contains($css, '.desktop-cart-summary { position: sticky; top: 94px; display: block;')
            || !str_contains($css, '.mobile-cart-bar { display: none; }')
            || !str_contains($index, 'aria-label="Cart summary"')
            || !str_contains($index, 'Add to cart')
            || !str_contains($index, 'Place demo order')
            || !str_contains($index, "h(\$preparationNotice)")
            || !str_contains($index, "\$preparationNotice = \$menuRepository->preparationNotice('demo-outlet')")) {
            throw new RuntimeException('customer grid, cart summary, demo labeling, or preparation notice is missing');
        }
    },
    'category rail exposes selected state, scroll controls, and keyboard support' => static function (string $root): void {
        $index = file_get_contents($root . '/public/index.php');
        $js = file_get_contents($root . '/public/assets/app.js');
        $css = file_get_contents($root . '/public/assets/app.css');
        if (!is_string($index) || !is_string($js) || !is_string($css)
            || !str_contains($index, 'aria-label="Scroll categories left"')
            || !str_contains($index, 'aria-label="Scroll categories right"')
            || !str_contains($index, 'aria-current="page"')
            || !str_contains($js, "event.key !== 'ArrowLeft' && event.key !== 'ArrowRight'")
            || !str_contains($css, '.category-nav::-webkit-scrollbar { display: none;')
            || !str_contains($css, '.category-nav-frame[data-can-scroll-right="true"]::after')) {
            throw new RuntimeException('category navigation is missing its accessible scroll affordance');
        }
    },
    'staff navigation is a selected horizontal rail rather than a wrapping row' => static function (string $root): void {
        $cashier = file_get_contents($root . '/public/cashier.php');
        $css = file_get_contents($root . '/public/assets/app.css');
        if (!is_string($cashier) || !is_string($css)
            || !str_contains($cashier, 'class="staff-nav-frame"')
            || !str_contains($cashier, 'aria-current="page"')
            || !str_contains($css, '.station-nav { flex-wrap: nowrap;')
            || !str_contains($css, '.station-nav::-webkit-scrollbar { display: none;')) {
            throw new RuntimeException('staff view navigation does not expose a compact selected rail');
        }
    },
    'station header and clear queue state prioritize operational counts' => static function (string $root): void {
        $view = file_get_contents($root . '/app/Application/PreparationStationView.php');
        $css = file_get_contents($root . '/public/assets/app.css');
        if (!is_string($view) || !is_string($css)
            || !str_contains($view, 'GMB · Live preparation')
            || !str_contains($view, 'active orders')
            || !str_contains($view, 'station-empty-counts')
            || !str_contains($view, '>waiting</small>')
            || !str_contains($view, '>preparing</small>')
            || !str_contains($view, 'Station ready')
            || !str_contains($css, '.station-view-header { min-height: 0;')) {
            throw new RuntimeException('station operational header or concise queue-clear state is missing');
        }
    },
    'token board fills widescreen space and keeps responsive large tokens' => static function (string $root): void {
        $css = file_get_contents($root . '/public/assets/display.css');
        if (!is_string($css)
            || !str_contains($css, 'min-height:100dvh')
            || !str_contains($css, 'grid-template-columns: 1fr 1.25fr')
            || !str_contains($css, '.display-section-ready .display-token')
            || !str_contains($css, '@media(max-width:900px)')
            || !str_contains($css, '[hidden]{display:none!important}')) {
            throw new RuntimeException('token board does not use the widescreen area or responsive token sizing');
        }
    },
    'demo home scales the wordmark and brings launch cards forward' => static function (string $root): void {
        $home = file_get_contents($root . '/public/demo.php');
        $css = file_get_contents($root . '/public/assets/app.css');
        if (!is_string($home) || !is_string($css)
            || !str_contains($home, 'SINCE 1950')
            || !str_contains($home, 'Digital Ordering &amp; Queue Demonstration')
            || !str_contains($home, 'aria-label="Kios demo pages"')
            || !str_contains($css, '.demo-home-hero > img { width: min(100%, 420px); }')
            || !str_contains($css, '.demo-home-section { margin-top: .9rem;')) {
            throw new RuntimeException('demo home hero balance or launch navigation is missing');
        }
    },
    'print queue retains audit details while using compact cards' => static function (string $root): void {
        $view = file_get_contents($root . '/app/Application/PrintQueueView.php');
        $css = file_get_contents($root . '/public/assets/app.css');
        if (!is_string($view) || !is_string($css)
            || !str_contains($view, '<dt>Printer</dt>')
            || !str_contains($view, '<dt>Attempts</dt>')
            || !str_contains($view, 'Reprint reason')
            || !str_contains($view, 'Retry print')
            || !str_contains($css, '.print-job-card { padding: .78rem;')) {
            throw new RuntimeException('print queue audit details or compact job card styling is missing');
        }
    },
    'token architecture remains unchanged during visual polish' => static function (string $root): void {
        $generator = file_get_contents($root . '/app/Domain/TokenGenerator.php');
        $report = file_get_contents($root . '/docs/K5.9_UX_REPORT.md');
        if (!is_string($generator) || !is_string($report)
            || !str_contains($generator, 'G-')
            || !str_contains($report, 'SHORT HUMAN-FACING PICKUP TOKEN')
            || !str_contains($report, 'G-104')
            || !str_contains($report, 'preserving the full internal order identifier')) {
            throw new RuntimeException('future short pickup token is undocumented or token architecture changed');
        }
    },
];
