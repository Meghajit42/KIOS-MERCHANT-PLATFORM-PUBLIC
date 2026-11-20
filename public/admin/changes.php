<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
adminRequireLogin();
$changes = adminRepository()->recentChanges(KIOS_ADMIN_OUTLET);
$labels = [
    'product_created' => 'Product added', 'product_updated' => 'Product updated',
    'availability_on' => 'Availability turned on', 'availability_off' => 'Availability turned off',
    'product_archived' => 'Product archived', 'product_restored' => 'Product restored',
    'category_created' => 'Category added', 'category_updated' => 'Category updated',
    'image_replaced' => 'Product image replaced', 'preparation_notice_updated' => 'Preparation time updated',
];
adminStart('Recent changes');
echo '<p class="admin-intro">A short record of recent menu updates. Passwords, session details and database credentials are never recorded here.</p>';
if ($changes === []) {
    echo '<section class="admin-panel admin-empty"><h2>No changes recorded yet</h2><p>Owner menu updates will appear here.</p></section>';
} else {
    echo '<ol class="admin-change-list">';
    foreach ($changes as $change) {
        $before = $change['before_summary'] === null ? '' : (string) $change['before_summary'];
        $after = (string) $change['after_summary'];
        $time = is_string($change['created_at']) ? strtotime($change['created_at']) : false;
        $formattedTime = $time === false ? (string) $change['created_at'] : date('d M Y, H:i', $time);
        echo '<li class="admin-panel"><div class="admin-change-top"><strong>' . adminH($labels[$change['action']] ?? 'Menu updated') . '</strong><time>' . adminH($formattedTime) . '</time></div>';
        if ($before !== '') {
            echo '<p><span class="admin-before">' . adminH($before) . '</span><span class="admin-change-arrow" aria-hidden="true">→</span><span>' . adminH($after) . '</span></p>';
        } else {
            echo '<p>' . adminH($after) . '</p>';
        }
        echo '</li>';
    }
    echo '</ol>';
}
adminEnd();
