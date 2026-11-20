<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
adminRequireLogin();
$repository = adminRepository();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        adminRequireCsrf();
        if (adminPost('action') !== 'save-preparation-notice') {
            throw new DomainException('That setting is not available.');
        }
        $version = adminPost('version');
        if (!preg_match('/^[1-9][0-9]{0,8}$/D', $version)) {
            throw new DomainException('Reload this page before saving the preparation time.');
        }
        $repository->savePreparationNotice(KIOS_ADMIN_OUTLET, (int) $version, adminPost('preparation_notice'));
        adminFlash('Preparation time saved.');
    } catch (Throwable $exception) {
        adminFlash(adminFail($exception));
    }
    header('Location: /admin/settings.php', true, 303);
    exit;
}
$setting = $repository->preparationSetting(KIOS_ADMIN_OUTLET);
adminStart('Preparation time');
echo '<section class="admin-panel admin-settings-panel"><p class="admin-eyebrow">CUSTOMER MENU NOTICE</p><h2>What should customers expect?</h2>';
echo '<p>This short plain-text message appears on the menu, cart and order confirmation. Keep it clear, for example “15–20 mins” or “25–30 mins”.</p>';
echo '<form method="post" class="admin-form">' . adminCsrfField() . '<input type="hidden" name="action" value="save-preparation-notice"><input type="hidden" name="version" value="' . adminH($setting['version']) . '">';
echo '<label for="preparation_notice">Preparation time<input id="preparation_notice" name="preparation_notice" maxlength="80" required value="' . adminH($setting['preparation_notice']) . '"></label>';
echo '<p class="admin-preview"><span>Customer preview</span><strong>Please allow ' . adminH($setting['preparation_notice']) . ' for preparation.</strong></p>';
echo '<button class="admin-button admin-button-primary" type="submit">Save preparation time</button></form></section>';
adminEnd();
