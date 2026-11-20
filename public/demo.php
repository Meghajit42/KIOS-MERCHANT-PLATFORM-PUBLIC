<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#BA0107">
    <meta name="description" content="Synthetic Restaurant Demo digital ordering and operations demonstration. Owner-supplied menu and prices are not independently verified.">
    <link rel="icon" href="/assets/brand/gmb-favicon-192.png" type="image/png">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Kios — Gopal Demo</title>
</head>
<body class="demo-home-body">
<main class="demo-home">
    <header class="demo-home-hero">
        <img src="/assets/brand-product-placeholder.svg" alt="Synthetic Restaurant Demo" width="680" height="307">
        <p class="demo-home-since">SINCE 1950</p>
        <p class="eyebrow">Kios · Demo / Staging</p>
        <h1>Digital Ordering &amp; Queue Demonstration</h1>
    </header>
    <p class="demo-home-notice"><strong>Demo / Staging — Not Merchant Production.</strong> Menu content and prices are owner-supplied for this demonstration and have not been independently verified. No payment is collected and no order is sent to merchant production.</p>
    <section class="demo-home-section" aria-labelledby="demo-navigation-title">
        <h2 id="demo-navigation-title">Explore the demo</h2>
        <nav class="demo-home-grid" aria-label="Kios demo pages">
            <a class="demo-home-card" href="/index.php">
                <span class="demo-card-icon" aria-hidden="true">✦</span>
                <span class="demo-card-kicker">PUBLIC</span>
                <strong>Customer Ordering</strong>
                <span>Browse the owner-supplied Gopal menu and create a synthetic demo order.</span>
                <span class="demo-launch">Open ordering <span aria-hidden="true">→</span></span>
            </a>
            <a class="demo-home-card" href="/cashier.php">
                <span class="demo-card-icon" aria-hidden="true">₹</span>
                <span class="demo-card-kicker">STAFF SIGN-IN</span>
                <strong>Cashier</strong>
                <span>Look up a token and record a demo payment confirmation.</span>
                <span class="demo-launch">Open cashier <span aria-hidden="true">→</span></span>
            </a>
            <a class="demo-home-card" href="/cashier.php?station=SWEETS">
                <span class="demo-card-icon" aria-hidden="true">◇</span>
                <span class="demo-card-kicker">STAFF SIGN-IN</span>
                <strong>Sweets Counter</strong>
                <span>View the SWEETS preparation queue.</span>
                <span class="demo-launch">Open sweets queue <span aria-hidden="true">→</span></span>
            </a>
            <a class="demo-home-card" href="/cashier.php?station=SOUTH_INDIAN">
                <span class="demo-card-icon" aria-hidden="true">◉</span>
                <span class="demo-card-kicker">STAFF SIGN-IN</span>
                <strong>Kitchen / Station</strong>
                <span>View a representative SOUTH_INDIAN station queue.</span>
                <span class="demo-launch">Open kitchen <span aria-hidden="true">→</span></span>
            </a>
            <a class="demo-home-card" href="/display.php">
                <span class="demo-card-icon" aria-hidden="true">▤</span>
                <span class="demo-card-kicker">PUBLIC · READ ONLY</span>
                <strong>Token Display</strong>
                <span>See synthetic orders in PREPARING and READY.</span>
                <span class="demo-launch">Open display <span aria-hidden="true">→</span></span>
            </a>
            <a class="demo-home-card" href="/cashier.php?prints=1">
                <span class="demo-card-icon" aria-hidden="true">▣</span>
                <span class="demo-card-kicker">STAFF SIGN-IN</span>
                <strong>Print Queue / Preview</strong>
                <span>Inspect virtual receipt and station KOT job status.</span>
                <span class="demo-launch">Open print queue <span aria-hidden="true">→</span></span>
            </a>
        </nav>
    </section>
    <footer class="demo-home-footer">Virtual printing only · No payment gateway · Synthetic demonstration data</footer>
</main>
</body>
</html>
