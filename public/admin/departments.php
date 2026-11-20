<?php
declare(strict_types=1);

use Kios\Domain\OperationalDepartment;

require_once __DIR__ . '/_bootstrap.php';
adminRequireLogin();
$repository = adminRepository();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        adminRequireCsrf();
        if (adminPost('action') !== 'save-station-department') {
            throw new DomainException('That routing action is not available.');
        }
        $department = adminPost('department_code');
        $versionText = adminPost('department_version');
        if (!preg_match('/^[1-9][0-9]{0,8}$/D', $versionText)) {
            throw new DomainException('Reload the station route before saving.');
        }
        $repository->saveStationDepartment(
            KIOS_ADMIN_OUTLET,
            strtoupper(adminPost('station_code')),
            (int) $versionText,
            $department === '' ? null : $department,
        );
        adminFlash('Station department assignment saved. New orders will use this assignment.');
    } catch (Throwable $exception) {
        adminFlash(adminFail($exception));
    }
    header('Location: /admin/departments.php', true, 303);
    exit;
}

$stations = $repository->stations(KIOS_ADMIN_OUTLET);
adminStart('Floor routing');
echo '<p class="admin-intro">Assign each preparation station to one floor. Product station selections are the product-level override; category defaults apply only when a station has no floor assignment. Existing orders keep their saved department. Changes are audited and affect new checkouts.</p>';
echo '<section class="admin-category-list">';
foreach ($stations as $station) {
    echo '<form method="post" class="admin-panel admin-category-row">' . adminCsrfField();
    echo '<input type="hidden" name="action" value="save-station-department"><input type="hidden" name="station_code" value="' . adminH($station['code']) . '">';
    echo '<input type="hidden" name="department_version" value="' . adminH($station['department_version']) . '">';
    echo '<div><strong>' . adminH($station['name']) . '</strong><small class="admin-muted">' . adminH($station['code']) . '</small></div>';
    echo '<label>Assigned floor<select name="department_code"><option value="">Unassigned / review</option>';
    foreach (OperationalDepartment::cases() as $department) {
        echo '<option value="' . adminH($department->value) . '"' . (($station['department_code'] ?? null) === $department->value ? ' selected' : '') . '>' . adminH($department->label()) . '</option>';
    }
    echo '</select></label><button class="admin-button admin-button-secondary" type="submit">Save station route</button></form>';
}
echo '</section>';
adminEnd();
