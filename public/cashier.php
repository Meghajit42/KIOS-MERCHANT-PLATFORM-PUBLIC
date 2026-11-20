<?php
declare(strict_types=1);

use Kios\Domain\PaymentMethod;
use Kios\Application\ManualKotView;
use Kios\Application\PreparationStationView;
use Kios\Application\PrintQueueView;
use Kios\Application\CollectionQueueView;
use Kios\Domain\PreparationStation;
use Kios\Domain\PreparationTaskStatus;
use Kios\Domain\StaffRole;
use Kios\Infrastructure\Database;
use Kios\Infrastructure\DatabaseConnectionException;
use Kios\Infrastructure\Environment;
use Kios\Infrastructure\OrderRepository;
use Kios\Infrastructure\PreparationQueueService;
use Kios\Infrastructure\PreparationWorkflowService;
use Kios\Infrastructure\PrintJobService;
use Kios\Infrastructure\PrintGateway;
use Kios\Infrastructure\StaffAccessPolicy;

require_once dirname(__DIR__) . '/app/bootstrap.php';

$remoteAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
$demoMode = Environment::get('APP_ENV', 'local') === 'demo';
$secureCookie = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
if (!StaffAccessPolicy::allows($demoMode, $remoteAddress, $secureCookie)) {
    if ($demoMode) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Secure HTTPS is required for staff access.\n");
    }
    http_response_code(404);
    exit;
}

if (\Kios\Infrastructure\StaffAccountService::enabled()) {
    session_name('KIOSSTAFFACCOUNTS');
} else {
    session_name('KIOSSTAFF');
}
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/cashier.php',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");

function cashierH(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cashierPost(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function cashierCsrf(): string
{
    if (!isset($_SESSION['_cashier_csrf']) || !is_string($_SESSION['_cashier_csrf'])) {
        $_SESSION['_cashier_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_cashier_csrf'];
}

function cashierRequireCsrf(): void
{
    $expected = $_SESSION['_cashier_csrf'] ?? '';
    if (!is_string($expected) || $expected === '' || !hash_equals($expected, cashierPost('_csrf'))) {
        http_response_code(400);
        throw new RuntimeException('Reload the cashier page and try again.');
    }
}

function cashierRedirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function cashierFormToken(): string
{
    return '<input type="hidden" name="_csrf" value="' . cashierH(cashierCsrf()) . '">';
}

function cashierDemoOutletId(\PDO $pdo): int
{
    $query = $pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = :code AND active = 1');
    $query->execute(['code' => 'demo-outlet']);
    $outletId = $query->fetchColumn();
    if ($outletId === false) {
        throw new RuntimeException('The demo outlet is unavailable.');
    }
    return (int) $outletId;
}

$flash = '';
$loginRole = StaffRole::Cashier;
$loginFailureMessage = null;
$accountMode = \Kios\Infrastructure\StaffAccountService::enabled();
$accountService = null;
$accountIdentity = null;
if ($accountMode) {
    try {
        if (strlen(Environment::get('KIOS_AUTH_RATE_KEY', '') ?? '') < 32) throw new RuntimeException('Account authentication configuration is incomplete.');
        $accountService = new \Kios\Infrastructure\StaffAccountService(Database::connect(), 'demo-outlet', Environment::get('KIOS_AUTH_RATE_KEY', '') ?? '');
        $accountIdentity = $accountService->identity($_SESSION);
        unset($_SESSION['staff_role']);
        $_SESSION['cashier_authenticated'] = false;
        if ($accountIdentity !== null) {
            $_SESSION['staff_role'] = $accountIdentity['role'];
            $_SESSION['cashier_authenticated'] = $accountIdentity['role'] === 'cashier';
        }
    } catch (Throwable $error) {
        \Kios\Infrastructure\RequestObservability::recordCaughtException($error, 'staff_account_lookup');
        http_response_code(503);
        exit('Staff sign-in is temporarily unavailable.');
    }
} elseif (isset($_SESSION['_staff_account_id'])) {
    unset($_SESSION['_staff_account_id'], $_SESSION['_staff_account_version'], $_SESSION['staff_role']);
    $_SESSION['cashier_authenticated'] = false;
}
$staffRole = StaffRole::tryFrom(is_string($_SESSION['staff_role'] ?? null) ? $_SESSION['staff_role'] : '');
if ($staffRole === null && ($_SESSION['cashier_authenticated'] ?? false) === true) {
    $staffRole = StaffRole::Cashier;
}
$authenticated = $staffRole !== null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Preserve only a known role; never retain the submitted password.
        $loginRole = StaffRole::tryFrom(cashierPost('staff_role')) ?? StaffRole::Cashier;
        if (cashierPost('action') === 'login') {
            $loginFailureMessage = 'Reload the staff sign-in page and try again.';
        }
        cashierRequireCsrf();
        $loginFailureMessage = null;
        $action = cashierPost('action');
        if ($action === 'login') {
            if ($accountMode) {
                $identity = $accountService->authenticate(cashierPost('username'), $_POST['password'] ?? null, $remoteAddress);
                if ($identity === null) {
                    http_response_code(401);
                    throw new DomainException('Staff sign-in could not be verified. Check your login name and password, or wait before retrying.');
                }
                session_regenerate_id(true);
                \Kios\Infrastructure\StaffAccountService::bind($_SESSION, $identity);
                $_SESSION['_cashier_csrf'] = bin2hex(random_bytes(32));
                cashierRedirect((int)$identity['must_change'] === 1 ? '/cashier.php?password=change' : '/cashier.php');
            }
            $requestedRoleText = cashierPost('staff_role');
            $requestedRole = $requestedRoleText === '' ? StaffRole::Cashier : StaffRole::tryFrom($requestedRoleText);
            if ($requestedRole === null) {
                throw new InvalidArgumentException('Choose an available staff role.');
            }
            $now = time();
            $lockedUntil = (int) ($_SESSION['_cashier_locked_until'] ?? 0);
            if ($lockedUntil > $now) {
                $loginFailureMessage = 'Too many sign-in attempts. Wait 15 minutes after the last failed attempt before trying again.';
                throw new RuntimeException('Too many attempts. Wait a little before trying again.');
            }
            $configuredPassword = Environment::get($requestedRole->passwordEnvironmentKey());
            $submittedPassword = cashierPost('password');
            if ($configuredPassword === null || $configuredPassword === '' || $submittedPassword === ''
                || !hash_equals($configuredPassword, $submittedPassword)) {
                $attempts = (int) ($_SESSION['_cashier_attempts'] ?? 0) + 1;
                $_SESSION['_cashier_attempts'] = $attempts;
                if ($attempts >= 5) {
                    $_SESSION['_cashier_locked_until'] = $now + 900;
                    $_SESSION['_cashier_attempts'] = 0;
                }
                $loginFailureMessage = 'Staff sign-in could not be verified. Check the selected role and its role password.';
                throw new RuntimeException('Cashier sign-in could not be verified.');
            }
            session_regenerate_id(true);
            $_SESSION['staff_role'] = $requestedRole->value;
            $_SESSION['cashier_authenticated'] = $requestedRole === StaffRole::Cashier;
            $_SESSION['_cashier_attempts'] = 0;
            unset($_SESSION['_cashier_locked_until']);
            $_SESSION['_cashier_csrf'] = bin2hex(random_bytes(32));
            cashierRedirect('/cashier.php');
        }

        if (!$authenticated) {
            http_response_code(401);
            throw new RuntimeException('Sign in to continue.');
        }
        if ($action === 'logout') {
            if ($accountMode) $accountService->recordLogout($_SESSION);
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_cashier_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['cashier_authenticated'] = false;
            cashierRedirect('/cashier.php');
        }
        if ($accountMode && $action === 'change-password') {
            $identity = $accountService->changePassword($_SESSION, $_POST['current_password'] ?? null, $_POST['new_password'] ?? null, $_POST['confirm_password'] ?? null);
            session_regenerate_id(true);
            \Kios\Infrastructure\StaffAccountService::bind($_SESSION, $identity);
            $_SESSION['_cashier_csrf'] = bin2hex(random_bytes(32));
            cashierRedirect('/cashier.php?result=password-changed');
        }
        if ($accountMode && (int)($accountIdentity['must_change'] ?? 1) === 1) {
            http_response_code(403);
            throw new DomainException('Change your temporary password before starting work.');
        }
        if ($staffRole === null || !$staffRole->allowsAction($action)) {
            http_response_code(403);
            throw new DomainException('This staff role cannot perform that action.');
        }
        if ($action === 'advance-station-task') {
            $taskIdInput = cashierPost('task_id');
            if (!preg_match('/^[1-9][0-9]{0,17}$/D', $taskIdInput)) {
                throw new InvalidArgumentException('Choose a valid station task.');
            }
            $stationCode = strtoupper(cashierPost('station_code'));
            if (PreparationStation::tryFrom($stationCode) === null) {
                throw new InvalidArgumentException('Choose a configured preparation station.');
            }
            $expectedStatus = PreparationTaskStatus::tryFrom(cashierPost('expected_status'));
            if (!in_array($expectedStatus, [PreparationTaskStatus::Queued, PreparationTaskStatus::Preparing], true)) {
                throw new InvalidArgumentException('Reload the station queue and choose the current task action.');
            }
            $pdo = Database::connect();
            $outletId = cashierDemoOutletId($pdo);
            $departmentCode = $staffRole->department()?->value;
            if ($departmentCode === null) {
                http_response_code(403);
                throw new DomainException('Only an assigned floor operator can advance preparation work.');
            }
            (new PreparationWorkflowService($pdo))->advanceTask(
                (int) $taskIdInput,
                $outletId,
                $stationCode,
                $expectedStatus,
                $departmentCode,
                $staffRole->value,
            );
            cashierRedirect('/cashier.php?station=' . rawurlencode($stationCode) . '&result=task-updated');
        }
        if ($action === 'collect-order') {
            $token = strtoupper(cashierPost('token'));
            $pdo = Database::connect();
            $outletId = cashierDemoOutletId($pdo);
            if(\Kios\Infrastructure\ShopOperations::enabled()&&cashierPost('order_id')!==''){if(!ctype_digit(cashierPost('order_id')))throw new InvalidArgumentException('Invalid order ID.');(new OrderRepository($pdo))->markCollectedById((int)cashierPost('order_id'),$outletId,$staffRole->value);}else{(new OrderRepository($pdo))->markCollectedByPickupToken($token,$outletId,$staffRole->value);}
            cashierRedirect('/cashier.php?result=collected');
        }
        if ($action === 'retry-print-job' || $action === 'reprint-print-job') {
            $jobIdInput = cashierPost('print_job_id');
            if (!preg_match('/^[1-9][0-9]{0,17}$/D', $jobIdInput)) {
                throw new InvalidArgumentException('Choose a valid print job.');
            }
            $pdo = Database::connect();
            $outletId = cashierDemoOutletId($pdo);
            $printing = new PrintJobService($pdo);
            if ($action === 'retry-print-job') {
                $printing->retry((int) $jobIdInput, $outletId, 'local-cashier');
            } else {
                $printing->reprint((int) $jobIdInput, $outletId, 'local-cashier', cashierPost('reason'), cashierPost('request_id') ?: null);
            }
            cashierRedirect('/cashier.php?prints=1&result=print-updated');
        }
        if ($action === 'confirm-payment' || $action === 'cancel-order') {
            $token = strtoupper(cashierPost('token'));
            $pdo = Database::connect();
            $outletId = cashierDemoOutletId($pdo);
            $repository = new OrderRepository($pdo);
            if ($action === 'confirm-payment') {
                $method = PaymentMethod::tryFrom(cashierPost('payment_method'));
                if ($method === null) {
                    throw new InvalidArgumentException('Choose CASH, UPI, CARD, or OTHER.');
                }
                $repository->confirmPaymentByPickupToken($token, $method, 'local-cashier', $outletId);
                cashierRedirect('/cashier.php?token=' . rawurlencode($token) . '&result=paid');
            }
            $repository->cancelPendingByPickupToken($token, 'local-cashier', $outletId);
            cashierRedirect('/cashier.php?token=' . rawurlencode($token) . '&result=cancelled');
        }
        throw new InvalidArgumentException('That cashier action is not available.');
    } catch (Throwable $error) {
        if (http_response_code() < 400) {
            http_response_code(400);
        }
        $isExpectedInputError = $error instanceof InvalidArgumentException || $error instanceof DomainException;
        if (!$isExpectedInputError) {
            \Kios\Infrastructure\RequestObservability::recordCaughtException($error, 'cashier_action');
        }
        $flash = $loginFailureMessage ?? ($isExpectedInputError
            ? $error->getMessage()
            : 'The cashier request failed. Reload and try again.');
        if ($error instanceof DatabaseConnectionException || $error instanceof PDOException) {
            $flash = 'The order service is temporarily unavailable. Try again shortly.';
            http_response_code(503);
        }
    }
}

$staffRole = StaffRole::tryFrom(is_string($_SESSION['staff_role'] ?? null) ? $_SESSION['staff_role'] : '');
if ($staffRole === null && ($_SESSION['cashier_authenticated'] ?? false) === true) {
    $staffRole = StaffRole::Cashier;
}
$authenticated = $staffRole !== null;
$departmentCode = $staffRole?->department()?->value;
$token = is_string($_GET['token'] ?? null) ? strtoupper(trim($_GET['token'])) : '';
$requestedStationCode = $_GET['station'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['station_code'] ?? '') : '');
$stationCode = is_string($requestedStationCode) ? strtoupper(trim($requestedStationCode)) : '';
$stationName = '';
$stationTasks = [];
$availableStations = [];
$pendingOrders = [];
$collectionOrders = [];
$orderRoutes = [];
$paymentBlockers = [];
$printJobs = [];
$showPrintQueue = ($_GET['prints'] ?? '') === '1';
$order = null;
$paymentInfo = null;
$manualKotHtml = null;
$orderPrintJobs = [];
$showAccountPassword = $accountMode && $authenticated && ((int)($accountIdentity['must_change'] ?? 1) === 1 || ($_GET['password'] ?? '') === 'change');
if ($authenticated && !$showAccountPassword) {
    try {
        $pdo = Database::connect();
        $outletId = cashierDemoOutletId($pdo);
        if ($showPrintQueue) {
            if ($staffRole !== StaffRole::Cashier) {
                http_response_code(403);
                $flash = 'The print queue is available to cashier staff only.';
            } else {
                $printJobs = (new PrintJobService($pdo))->listForOutlet($outletId);
                if (($_GET['result'] ?? '') === 'print-updated') {
                    $flash = 'Print queue action recorded.';
                }
            }
        }
        if ($staffRole === StaffRole::Cashier && !$showPrintQueue && $token === '') {
            $orderRepository = new OrderRepository($pdo);
            $pendingOrders = \Kios\Infrastructure\ShopOperations::enabled()?[]:$orderRepository->awaitingPaymentForOutlet($outletId);
            foreach ($pendingOrders as $pendingOrder) {
                $orderRoutes[$pendingOrder->id] = $orderRepository->departmentRoutesForOrder($pendingOrder->id, $outletId);
                $paymentBlockers[$pendingOrder->id] = $orderRepository->paymentBlockingReasonsForPublicOrder($pendingOrder->id, $outletId);
            }
        }
        if ($staffRole === StaffRole::GroundFloor && !$showPrintQueue && $stationCode === '') {
            $collectionOrders = \Kios\Infrastructure\ShopOperations::enabled()?[]:(new OrderRepository($pdo))->readyForCollectionForOutlet($outletId);
        }
        if ($departmentCode !== null) {
            $stationList = $pdo->prepare(
                'SELECT DISTINCT ps.code, ps.name FROM preparation_stations ps '
                . 'WHERE ps.outlet_id = :outlet_id AND ps.active = 1 AND ('
                . 'ps.department_code = :department_code OR EXISTS ('
                . 'SELECT 1 FROM preparation_tasks pt WHERE pt.outlet_id = ps.outlet_id AND pt.station_id = ps.station_id '
                . 'AND pt.department_code_snapshot = :task_department)) ORDER BY ps.name, ps.code'
            );
            $stationList->execute(['outlet_id' => $outletId, 'department_code' => $departmentCode, 'task_department' => $departmentCode]);
            $availableStations = $stationList->fetchAll(\PDO::FETCH_ASSOC);
        }
        if ($stationCode !== '') {
            if ($departmentCode === null || PreparationStation::tryFrom($stationCode) === null) {
                http_response_code(403);
            } else {
                $stationQuery = $pdo->prepare(
                    'SELECT ps.name FROM preparation_stations ps WHERE ps.outlet_id = :outlet_id AND ps.code = :code AND ps.active = 1 '
                    . 'AND (ps.department_code = :department_code OR EXISTS ('
                    . 'SELECT 1 FROM preparation_tasks pt WHERE pt.outlet_id = ps.outlet_id AND pt.station_id = ps.station_id '
                    . 'AND pt.department_code_snapshot = :task_department))'
                );
                $stationQuery->execute([
                    'outlet_id' => $outletId,
                    'code' => $stationCode,
                    'department_code' => $departmentCode,
                    'task_department' => $departmentCode,
                ]);
                $stationLabel = $stationQuery->fetchColumn();
                if (is_string($stationLabel)) {
                    $stationName = $stationLabel;
                    if (PrintGateway::enabled()) {
                        (new PrintGateway($pdo))->flagExpiredClaims($outletId);
                    }
                    $stationTasks = (new PreparationQueueService($pdo))->forStation($outletId, $stationCode, $departmentCode);
                    $manualKotInput = $_GET['manual_kot'] ?? '';
                    if ($manualKotInput !== '') {
                        if (!is_string($manualKotInput) || !preg_match('/^[1-9][0-9]{0,17}$/D', $manualKotInput)) {
                            http_response_code(400);
                            $manualKotHtml = '<section class="cashier-order panel"><h2>Manual KOT unavailable</h2><p>Choose a valid task from this authorized station queue.</p></section>';
                        } else {
                            foreach ($stationTasks as $stationTask) {
                                if ($stationTask['taskId'] === (int) $manualKotInput) {
                                    $manualKotHtml = ManualKotView::render($stationCode, $stationName, $stationTask);
                                    break;
                                }
                            }
                            if ($manualKotHtml === null) {
                                http_response_code(404);
                                $manualKotHtml = '<section class="cashier-order panel"><h2>Manual KOT unavailable</h2><p>This task is not available in your assigned station queue.</p></section>';
                            }
                        }
                    }
                } else {
                    http_response_code(404);
                }
            }
            if (($_GET['result'] ?? '') === 'task-updated') {
                $flash = 'Station task status saved.';
            }
        } elseif (($_GET['result'] ?? '') === 'collected') {
            $flash = 'Order handoff recorded.';
        } elseif ($token !== '' && $staffRole === StaffRole::Cashier) {
            $repository = new OrderRepository($pdo);
            $order = $repository->findByPickupTokenForOutlet($token, $outletId);
            if ($order !== null) {
                $orderRoutes[$order->id] = $repository->departmentRoutesForOrder($order->id, $outletId);
                $paymentBlockers[$order->id] = $repository->paymentBlockingReasonsForPublicOrder($order->id, $outletId);
                $query = $pdo->prepare(
                    'SELECT pc.payment_method, pc.amount_paise, pc.confirmed_at FROM payment_confirmations pc '
                    . 'JOIN orders o ON o.order_id = pc.order_id WHERE o.public_id = :public_id AND o.outlet_id = :outlet_id'
                );
                $query->execute(['public_id' => $order->id, 'outlet_id' => $outletId]);
                $paymentInfo = $query->fetch() ?: null;
                if ($paymentInfo !== null) {
                    $orderPrintJobs = (new PrintJobService($pdo))->listForOutlet($outletId, 20, $order->id);
                }
            }
        }
    } catch (Throwable $error) {
        \Kios\Infrastructure\RequestObservability::recordCaughtException($error, 'staff_data_lookup');
        http_response_code(503);
        $flash = 'The order service is temporarily unavailable. Try again shortly.';
    }
}

$stylePath = dirname(__DIR__) . '/public/assets/app.css';
$styleVersion = is_file($stylePath) ? (string) filemtime($stylePath) : '1';
$cashierDeskPage = $staffRole === StaffRole::Cashier && $stationCode === '' && !$showPrintQueue;
$cashierDeskCss = dirname(__DIR__) . '/public/assets/cashier-dashboard.css';
$cashierDeskJs = dirname(__DIR__) . '/public/assets/cashier-dashboard.js';
$cashierDeskVersion = (string) max(is_file($cashierDeskCss) ? filemtime($cashierDeskCss) : 1, is_file($cashierDeskJs) ? filemtime($cashierDeskJs) : 1);
$staffHeading = $staffRole?->label() ?? 'Staff access';
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<meta name="theme-color" content="#BA0107"><link rel="icon" href="/assets/brand/gmb-favicon-192.png" type="image/png"><link rel="stylesheet" href="/assets/app.css?v=' . cashierH($styleVersion) . '">';
if ($cashierDeskPage) {
    echo '<link rel="stylesheet" href="/assets/cashier-dashboard.css?v=' . cashierH($cashierDeskVersion) . '"><script src="/assets/cashier-dashboard.js?v=' . cashierH($cashierDeskVersion) . '" defer></script>';
}
echo '<title>' . ($stationCode !== '' ? cashierH($stationName !== '' ? $stationName : 'Station queue') : 'Cashier') . ' · Kios demo</title></head><body class="cashier-body' . ($cashierDeskPage ? ' cashier-desk-page' : '') . '"><main class="cashier-shell">';
echo '<header class="cashier-header"><div class="cashier-brand"><img src="/assets/brand-product-placeholder.svg?v=' . cashierH($styleVersion) . '" alt="" width="76" height="66"><div><p class="eyebrow">Synthetic Restaurant Demo · Synthetic fixtures</p><h1>' . cashierH($staffHeading) . '</h1>';
if ($authenticated) {
    echo '<span class="cashier-session">Authorized demo staff session</span>';
    if ($accountMode && $accountIdentity !== null) {
        echo '<p>Signed in as ' . cashierH($accountIdentity['display_name']);
        if ($staffRole !== StaffRole::Cashier) echo ' (' . cashierH($accountIdentity['username']) . ')';
        echo '</p>';
    }
}
echo '</div></div>';
if ($authenticated) {
    echo '<form method="post">' . cashierFormToken() . '<input type="hidden" name="action" value="logout"><button class="button button-outline" type="submit">Sign out</button></form>';
}
echo '</header>';
if ($flash !== '') {
    echo '<p class="cashier-flash" role="status">' . cashierH($flash) . '</p>';
}

if (!$authenticated) {
    echo '<section class="cashier-login panel"><p class="eyebrow">Staff access</p><h2>Sign in to staff operations</h2>';
    echo $accountMode ? '<p>Use the staff login name given by the owner. Your account determines your floor and permitted actions.</p>' : '<p>Choose your assigned role. Access is checked again on every protected action.</p>';
    echo '<form method="post" class="cashier-form">' . cashierFormToken() . '<input type="hidden" name="action" value="login">';
    if ($accountMode) {
        echo '<label for="username">Staff login name</label><input id="username" name="username" autocomplete="username" maxlength="64" required>';
        echo '<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" maxlength="64" required>';
    } else {
    echo '<label for="staff_role">Staff role</label><select id="staff_role" name="staff_role" required>';
    foreach (StaffRole::cases() as $roleOption) {
        echo '<option value="' . cashierH($roleOption->value) . '"' . ($roleOption === $loginRole ? ' selected' : '') . '>' . cashierH($roleOption->label()) . '</option>';
    }
    echo '</select><label for="password">Role password</label><input id="password" name="password" type="password" autocomplete="current-password" required>';
    }
    echo '<button class="button button-primary button-wide" type="submit">Sign in</button></form></section>';
} elseif ($showAccountPassword) {
    echo '<section class="cashier-login panel"><h2>Change password</h2><p>Use a phrase of at least 14 characters. Changing your password signs out your other sessions.</p>';
    echo '<form method="post" class="cashier-form">' . cashierFormToken() . '<input type="hidden" name="action" value="change-password">';
    echo '<label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" maxlength="64" required>';
    echo '<label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="14" maxlength="64" required>';
    echo '<label for="confirm_password">Repeat new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="14" maxlength="64" required>';
    echo '<button class="button button-primary" type="submit">Save new password</button></form></section>';
} else {
    if ($accountMode) echo '<p class="cashier-password-link"><a href="/cashier.php?password=change">Change password</a></p>';
    echo '<div class="staff-nav-frame"><nav class="station-nav" aria-label="Staff views" tabindex="0" data-scrollable-nav>';
    if ($staffRole === StaffRole::Cashier) {
        $cashierCurrent = $stationCode === '' && !$showPrintQueue;
        echo '<a class="button ' . ($cashierCurrent ? 'button-primary' : 'button-outline') . '" href="/cashier.php"' . ($cashierCurrent ? ' aria-current="page"' : '') . '>Cashier desk</a>';
        echo '<a class="button ' . ($showPrintQueue ? 'button-primary' : 'button-outline') . '" href="/cashier.php?prints=1"' . ($showPrintQueue ? ' aria-current="page"' : '') . '>Print queue</a>';
    } else {
        foreach ($availableStations as $availableStation) {
            $code = (string) $availableStation['code'];
            $name = (string) $availableStation['name'];
            $currentStation = $stationCode === $code;
            echo '<a class="button ' . ($currentStation ? 'button-primary' : 'button-outline') . '" href="/cashier.php?station=' . rawurlencode($code) . '"' . ($currentStation ? ' aria-current="page"' : '') . '>' . cashierH($name) . '</a>';
        }
    }
    echo '</nav></div>';
    if(\Kios\Infrastructure\ShopOperations::enabled()&&!$showPrintQueue&&$stationCode===''&&in_array($staffRole,[StaffRole::Cashier,StaffRole::GroundFloor],true)) echo \Kios\Application\StaffOperationsView::render($pdo,$outletId,$staffRole->value,cashierCsrf(),$_GET);
    if ($showPrintQueue) {
        echo PrintQueueView::render($printJobs, cashierCsrf());
    } elseif ($stationCode !== '') {
        if ($stationName === '') {
            echo '<section class="cashier-order panel"><h2>Station not found</h2><p>Choose an active station for this demo outlet.</p></section>';
        } elseif (is_string($manualKotHtml)) {
            echo $manualKotHtml;
        } else {
            $groundFloorContext = null;
            if ($stationCode === 'SWEETS' && \Kios\Infrastructure\ShopOperations::enabled()) {
                $groundFloorOps = new \Kios\Infrastructure\ShopOperations($pdo);
                $groundFloorContext = ['open' => $groundFloorOps->state($outletId)['open'], 'overdueOrderIds' => array_map(
                    static fn(array $row): int => (int)$row['order_id'],
                    array_values(array_filter($groundFloorOps->rows($outletId), static fn(array $row): bool => $row['aging']))
                )];
            }
            $firstFloorContext = null;
            if ($departmentCode === 'FIRST_FLOOR_KITCHEN') {
                $firstFloorContext = [];
                if (\Kios\Infrastructure\ShopOperations::enabled()) {
                    $firstFloorOps = new \Kios\Infrastructure\ShopOperations($pdo);
                    $firstFloorContext = ['open' => $firstFloorOps->state($outletId)['open'], 'overdueOrderIds' => array_map(
                        static fn(array $row): int => (int)$row['order_id'],
                        array_values(array_filter($firstFloorOps->rows($outletId), static fn(array $row): bool => $row['aging']))
                    )];
                }
            }
            echo PreparationStationView::render($stationCode, $stationName, $stationTasks, cashierCsrf(), $groundFloorContext, $firstFloorContext);
        }
    } else {
    if ($staffRole !== StaffRole::Cashier) {
        echo '<section class="cashier-order panel"><p class="eyebrow">Assigned department</p><h2>' . cashierH($staffRole?->department()?->label() ?? '') . '</h2>';
        echo '<p>Choose one of the assigned stations above to view paid work. This session cannot access cashier payment controls or another floor.</p></section>';
        if ($staffRole === StaffRole::GroundFloor) {
            if(!\Kios\Infrastructure\ShopOperations::enabled()) echo CollectionQueueView::render($collectionOrders, cashierCsrf());
        }
    } else {
    echo '<section class="cashier-search panel"><form method="get" class="cashier-search-form">';
    echo '<label for="token">Pickup token</label><div class="cashier-search-row"><input id="token" name="token" inputmode="text" autocomplete="off" placeholder="G-101" value="' . cashierH($token) . '">';
    echo '<button class="button button-primary" type="submit">Find order</button></div></form></section>';
    if ($token !== '' && $order === null) {
        echo '<section class="cashier-order panel"><p class="eyebrow">Order lookup</p><h2>Order not found</h2><p>Check the token and search again.</p></section>';
    } elseif ($order !== null) {
        $statusLabel = match ($order->status->value) {
            'awaiting_payment' => 'Awaiting payment',
            'paid' => 'Paid',
            'preparing' => 'Preparing',
            'ready' => 'Ready for collection',
            'collected' => 'Collected',
            'cancelled' => 'Cancelled',
            'expired' => 'Expired',
            default => strtoupper(str_replace('_', ' ', $order->status->value)),
        };
        echo '<section class="cashier-order panel cashier-order-detail"><div class="cashier-order-heading"><div><p class="eyebrow">'
            . cashierH(strtoupper(str_replace('_', ' ', $order->type->value))) . ' · ORDER SUMMARY</p>';
        echo '<h2 aria-label="Pickup token ' . cashierH($order->publicToken()) . '">' . cashierH($order->publicToken()) . '</h2>'
            . '<p class="cashier-detail-id">Token · Order ' . (int) $order->id . '</p></div><span class="cashier-status status-' . cashierH($order->status->value) . '">' . cashierH($statusLabel) . '</span></div>';
        echo '<h3 class="cashier-items-heading">Items and quantities</h3><ul class="cashier-items" aria-label="Ordered products and quantities">';
        foreach ($order->items as $item) {
            echo '<li><span><strong>' . cashierH($item->quantity) . ' × ' . cashierH($item->name) . '</strong><small>' . cashierH($item->variantLabel) . '</small></span>';
            echo '<strong>' . cashierH($item->lineTotal()->formatRupees()) . '</strong></li>';
        }
        echo '</ul><div class="cashier-total"><span>' . ($order->status->value === 'awaiting_payment' ? 'Amount due' : 'Order total') . '</span><strong>' . cashierH($order->total->formatRupees()) . '</strong></div>';
        if ($orderPrintJobs !== []) {
            echo '<section aria-label="Print status"><h3>Print status</h3><ul>';
            foreach ($orderPrintJobs as $printJob) {
                $label = $printJob['copy_label'] ?? $printJob['station_code_snapshot'] ?? 'Ticket';
                echo '<li>' . cashierH($label) . ' · ' . cashierH(PrintQueueView::statusLabel($printJob['status'])) . '</li>';
            }
            echo '</ul><a href="/cashier.php?prints=1">Open print queue</a></section>';
        }
        if (($orderRoutes[$order->id] ?? []) !== []) {
            echo '<p class="cashier-note">Preparation routes: ';
            echo cashierH(implode(' · ', array_map(static fn(array $route): string => $route['department'] . ' — ' . implode(', ', $route['stations']), $orderRoutes[$order->id])));
            echo '</p>';
        }
        if ($order->status->value === 'awaiting_payment') {
            $orderPaymentBlockers = $paymentBlockers[$order->id] ?? [];
            if ($orderPaymentBlockers !== []) {
                echo '<p class="cashier-flash" role="alert"><strong>Payment blocked before confirmation.</strong> ' . cashierH(implode(' ', $orderPaymentBlockers)) . ' Ask Owner Admin to correct the route before accepting payment.</p>';
            } else {
                echo '<form method="post" class="cashier-payment-form" data-confirm-once aria-label="Confirm payment for ' . cashierH($order->publicToken()) . '">' . cashierFormToken() . '<input type="hidden" name="action" value="confirm-payment"><input type="hidden" name="token" value="' . cashierH($order->publicToken()) . '">';
                echo '<fieldset class="payment-method-group"><legend>Payment method</legend><div class="payment-method-options">';
                $paymentIcons = [PaymentMethod::Cash->value => '₹', PaymentMethod::Upi->value => '↗', PaymentMethod::Card->value => '▤', PaymentMethod::Other->value => '•••'];
                foreach (PaymentMethod::cases() as $method) {
                    $methodId = 'payment-method-' . cashierH($method->value);
                    echo '<label class="payment-method-card" for="' . $methodId . '"><input id="' . $methodId . '" type="radio" name="payment_method" value="' . cashierH($method->value) . '" required>';
                    echo '<span class="payment-method-icon" aria-hidden="true">' . cashierH($paymentIcons[$method->value] ?? '•') . '</span><span>' . cashierH(strtoupper($method->value)) . '</span></label>';
                }
                echo '</div></fieldset><button class="button button-paid button-wide" type="submit">Confirm payment</button>';
                echo '<p class="cashier-note">This records a cashier confirmation only. No money is processed.</p></form>';
            }
            echo '<form method="post" class="cashier-cancel-form">' . cashierFormToken() . '<input type="hidden" name="action" value="cancel-order"><input type="hidden" name="token" value="' . cashierH($order->publicToken()) . '">';
            echo '<button class="button button-outline button-wide" type="submit">Cancel pending demo order</button></form>';
        } elseif ($paymentInfo !== null) {
            echo '<p class="cashier-payment-record">Confirmed ' . cashierH(strtoupper((string) $paymentInfo['payment_method']))
                . ' · ' . cashierH('₹' . intdiv((int) $paymentInfo['amount_paise'], 100) . '.' . str_pad((string) ((int) $paymentInfo['amount_paise'] % 100), 2, '0', STR_PAD_LEFT)) . '</p>';
        }
        echo '</section>';
    }
    if ($token === '' && !\Kios\Infrastructure\ShopOperations::enabled()) {
        echo '<section class="cashier-pending-queue" aria-labelledby="pending-orders-title"><div class="cashier-section-heading"><div><p class="eyebrow">Cashier queue</p><h2 id="pending-orders-title">Payment pending</h2></div><span class="cashier-status status-awaiting_payment">' . count($pendingOrders) . '</span></div>';
        if ($pendingOrders === []) {
            echo '<section class="cashier-order panel" role="status"><p>No orders are waiting for payment confirmation.</p></section>';
        }
        foreach ($pendingOrders as $pendingOrder) {
            echo '<article class="cashier-order panel"><div class="cashier-order-heading"><div><p class="eyebrow">' . cashierH(strtoupper(str_replace('_', ' ', $pendingOrder->type->value))) . '</p><h3>' . cashierH($pendingOrder->publicToken()) . '</h3></div><span class="cashier-status status-awaiting_payment">PAYMENT PENDING</span></div><ul class="cashier-items">';
            foreach ($pendingOrder->items as $item) {
                echo '<li><span><strong>' . cashierH($item->quantity) . ' × ' . cashierH($item->name) . '</strong><small>' . cashierH($item->variantLabel) . '</small></span><strong>' . cashierH($item->lineTotal()->formatRupees()) . '</strong></li>';
            }
            echo '</ul><div class="cashier-total"><span>Trusted order total</span><strong>' . cashierH($pendingOrder->total->formatRupees()) . '</strong></div>';
            if (($orderRoutes[$pendingOrder->id] ?? []) !== []) {
                echo '<p class="cashier-note">Preparation routes: ';
                echo cashierH(implode(' · ', array_map(static fn(array $route): string => $route['department'] . ' — ' . implode(', ', $route['stations']), $orderRoutes[$pendingOrder->id])));
                echo '</p>';
            }
            echo '<p class="cashier-note">Preparation tasks and KOTs remain locked until payment is confirmed. Receipt and print status will be available in the print queue after confirmation.</p>';
            $pendingBlockers = $paymentBlockers[$pendingOrder->id] ?? [];
            if ($pendingBlockers !== []) {
                echo '<p class="cashier-flash" role="alert"><strong>Payment blocked before confirmation.</strong> ' . cashierH(implode(' ', $pendingBlockers)) . ' Ask Owner Admin to correct the route before accepting payment.</p>';
            } else {
                echo '<form method="post" class="cashier-payment-form" data-confirm-once aria-label="Confirm payment for ' . cashierH($pendingOrder->publicToken()) . '">' . cashierFormToken() . '<input type="hidden" name="action" value="confirm-payment"><input type="hidden" name="token" value="' . cashierH($pendingOrder->publicToken()) . '"><label for="pending-payment-' . cashierH($pendingOrder->id) . '">Payment received by</label><select id="pending-payment-' . cashierH($pendingOrder->id) . '" name="payment_method" required><option value="">Choose payment method</option><option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option><option value="other">Other</option></select><button class="button button-paid button-wide" type="submit">Confirm payment</button></form>';
            }
            echo '<form method="post" class="cashier-cancel-form">' . cashierFormToken() . '<input type="hidden" name="action" value="cancel-order"><input type="hidden" name="token" value="' . cashierH($pendingOrder->publicToken()) . '"><button class="button button-outline button-wide" type="submit">Cancel pending demo order</button></form></article>';
        }
        echo '</section>';
    }
    }
    }
}
echo '<footer class="cashier-footer">Kios demo / staging · no payment gateway · no production connection</footer>';
echo '</main></body></html>';
