<?php
declare(strict_types=1);

use Kios\Infrastructure\Database;
use Kios\Infrastructure\ProductVerificationRepository;
use Kios\Domain\Money;

require_once __DIR__ . '/_bootstrap.php';
adminRequireLogin();
$reviews = new ProductVerificationRepository(Database::connect());
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        adminRequireCsrf();
        $id=adminPost('product_id'); $version=adminPost('version');
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$id) || !preg_match('/^[1-9][0-9]{0,8}$/D',$version)) {
            throw new DomainException('Choose a valid item to review.');
        }
        $reviews->confirm(KIOS_ADMIN_OUTLET,(int)$id,(int)$version,adminPost('action'));
        adminFlash('Current details confirmed.');
    } catch (Throwable $e) { adminFlash(adminFail($e)); }
    header('Location: /admin/review.php',true,303);
    exit;
}
adminStart('Needs Review');
echo '<p>Review the current details below. Edit an item to correct its name, price or serving before confirming.</p>';
foreach ($reviews->needsReview(KIOS_ADMIN_OUTLET) as $row) {
    echo '<section class="admin-panel"><h2>'.adminH($row['name']).'</h2><p>'.adminH($row['category_name']).'</p>';
    echo '<h3>Current operational details</h3>';
    foreach ($row['variants'] as $variant) {
        echo '<p>'.adminH(Money::fromPaise((int)$variant['price_paise'])->formatRupees()).' · '.adminH($variant['label']).'</p>';
    }
    echo '<p>'.adminH(ucwords(str_replace('_',' ',$row['verification_status']))).'</p><p>'.adminH($row['verification_note']??'').'</p>';
    if ($row['verification_evidence']) {
        $evidence=json_decode($row['verification_evidence'],true);
        if (is_array($evidence) && isset($evidence['source_price_paise'],$evidence['source_unit'])) {
            echo '<p>Voice source proposal: '.adminH(Money::fromPaise((int)$evidence['source_price_paise'])->formatRupees()).' / '.adminH($evidence['source_unit']).'</p>';
        }
    }
    $duplicate=$row['verification_status']==='possible_duplicate_needs_owner_confirmation';
    echo '<a class="admin-button" href="/admin/product.php?id='.adminH($row['product_id']).'">Edit item</a>';
    echo '<form method="post">'.adminCsrfField().'<input type="hidden" name="product_id" value="'.adminH($row['product_id']).'"><input type="hidden" name="version" value="'.adminH($row['version']).'"><input type="hidden" name="action" value="'.($duplicate?'keep_separate':'confirm_current').'"><button class="admin-button" type="submit">'.($duplicate?'Keep as Separate Product':'Confirm Current Details').'</button></form></section>';
}
adminEnd();
