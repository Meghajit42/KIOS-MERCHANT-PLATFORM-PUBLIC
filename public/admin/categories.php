<?php
declare(strict_types=1);

use Kios\Domain\OperationalDepartment;

require_once __DIR__ . '/_bootstrap.php';
adminRequireLogin();
$repository = adminRepository();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        adminRequireCsrf();
        if (adminPost('action') !== 'save-category') {
            throw new DomainException('That category action is not available.');
        }
        $slug = adminPost('slug');
        $isNew = $slug === 'new';
        $versionText = adminPost('version');
        $version = $isNew ? 0 : (preg_match('/^[1-9][0-9]{0,8}$/D', $versionText) ? (int) $versionText : 0);
        $sortText = adminPost('sort_order');
        if (!$isNew && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw new DomainException('Choose a valid category.');
        }
        if (!preg_match('/^(?:0|[1-9][0-9]{0,3})$/D', $sortText)) {
            throw new DomainException('Category order must be a whole number from 0 to 9999.');
        }
        $departmentCode = adminPost('default_department_code');
        $repository->saveCategory(
            KIOS_ADMIN_OUTLET,
            $isNew ? null : $slug,
            $version,
            adminPost('name'),
            isset($_POST['active']),
            (int) $sortText,
            $departmentCode === '' ? null : $departmentCode,
        );
        adminFlash($isNew ? 'Category added.' : 'Category saved.');
    } catch (Throwable $exception) {
        adminFlash(adminFail($exception));
    }
    header('Location: /admin/categories.php', true, 303);
    exit;
}
$categories = $repository->categoriesForAdmin(KIOS_ADMIN_OUTLET);
adminStart('Categories');
echo '<p class="admin-intro">Categories with no available products disappear from the customer menu. Set a default floor for new or unassigned station routes; each product’s selected station remains its explicit route override. Unassigned routes are blocked from checkout.</p>';
echo '<section class="admin-category-list">';
foreach ($categories as $category) {
    echo '<form method="post" class="admin-panel admin-category-row">' . adminCsrfField() . '<input type="hidden" name="action" value="save-category">';
    echo '<input type="hidden" name="slug" value="' . adminH($category['slug']) . '"><input type="hidden" name="version" value="' . adminH($category['version']) . '">';
    echo '<label>Category name<input name="name" maxlength="120" required value="' . adminH($category['name']) . '"></label>';
    echo '<label>Default preparation department<select name="default_department_code"><option value="">Owner review / unassigned</option>';
    foreach (OperationalDepartment::cases() as $department) {
        echo '<option value="' . adminH($department->value) . '"' . (($category['default_department_code'] ?? null) === $department->value ? ' selected' : '') . '>' . adminH($department->label()) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Display order<input type="number" min="0" max="9999" name="sort_order" required value="' . adminH($category['sort_order']) . '"></label>';
    echo '<label class="admin-check"><input type="checkbox" name="active" value="1"' . ((int) $category['active'] === 1 ? ' checked' : '') . '> Visible in customer menu</label>';
    echo '<span class="admin-muted">' . adminH($category['product_count']) . ' active products</span><button class="admin-button admin-button-secondary" type="submit">Save category</button></form>';
}
echo '</section><section class="admin-panel admin-new-category"><h2>Add category</h2><form method="post" class="admin-category-row">' . adminCsrfField();
echo '<input type="hidden" name="action" value="save-category"><input type="hidden" name="slug" value="new"><input type="hidden" name="version" value="0">';
echo '<label>Category name<input name="name" maxlength="120" required></label><label>Default preparation department<select name="default_department_code"><option value="">Owner review / unassigned</option>';
foreach (OperationalDepartment::cases() as $department) {
    echo '<option value="' . adminH($department->value) . '">' . adminH($department->label()) . '</option>';
}
echo '</select></label><label>Display order<input type="number" min="0" max="9999" name="sort_order" value="0" required></label>';
echo '<label class="admin-check"><input type="checkbox" name="active" value="1" checked> Visible in customer menu</label><button class="admin-button admin-button-primary" type="submit">Add category</button></form></section>';
adminEnd();
