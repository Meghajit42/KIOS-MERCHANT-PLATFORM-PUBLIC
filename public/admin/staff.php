<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
use Kios\Infrastructure\StaffAccountService;
use Kios\Domain\StaffRole;

if (!StaffAccountService::enabled()) { http_response_code(404); exit; }
adminRequireLogin();
$service=adminStaffAccounts();$temporary=null;$temporaryLogin=null;$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        adminRequireCsrf();
        $request=adminPost('request_id');
        if (!isset($_SESSION['_staff_admin_forms'][$request])) throw new DomainException('This form was already used. Reload before another change.');
        unset($_SESSION['_staff_admin_forms'][$request]);
        $action=adminPost('action');$id=adminPost('account_id');$version=(int)adminPost('version');
        if($action==='create') {
            $result=$service->create($_SESSION,adminPost('username'),adminPost('display_name'),adminPost('role'),adminPost('enabled')==='1');
            $temporary=$result['password'];$temporaryLogin=strtolower(trim(adminPost('username')));
        } elseif($action==='reset') {
            $temporary=$service->reset($_SESSION,$id,$version);
            foreach($service->listAccounts($_SESSION) as $account)if($account['account_id']===$id)$temporaryLogin=$account['username'];
        } elseif($action==='manage') {
            $service->manage($_SESSION,$id,adminPost('role'),adminPost('enabled')==='1',$version);
            adminFlash('Staff access updated. Existing sessions have been revoked.');
            header('Location: /admin/staff.php',true,303);exit;
        } else { throw new DomainException('Choose an available staff action.'); }
    } catch(Throwable $e) { http_response_code($e instanceof DomainException?400:500);$error=adminFail($e); }
}
function staffFormNonce(): string {
    $id=bin2hex(random_bytes(16));
    if(count($_SESSION['_staff_admin_forms']??[])>=100)$_SESSION['_staff_admin_forms']=[];
    $_SESSION['_staff_admin_forms'][$id]=true;
    return adminCsrfField().'<input type="hidden" name="request_id" value="'.adminH($id).'">';
}
function staffRoleOptions(string $current='ground_floor'): string {
    $html='';foreach(StaffRole::cases() as $r){$html.='<option value="'.adminH($r->value).'"'.($r->value===$current?' selected':'').'>'.adminH($r->label()).'</option>';}return $html;
}
adminStart('Staff accounts');
echo '<p>Create individual logins. Roles set the permitted floor and actions; cashier access does not include kitchen preparation.</p>';
if($error!=='')echo '<p class="admin-flash" role="alert">'.adminH($error).'</p>';
if(is_string($temporary)) {
    echo '<section class="admin-card"><h2>Temporary password — shown once</h2><p>Give this password securely to the selected staff member. It will not be available after leaving this page. They must change it before starting work. An active sign-in cooldown must expire before retrying.</p><p>Staff login: <strong>'.adminH($temporaryLogin??'').'</strong></p><p><code>'.adminH($temporary).'</code></p></section>';
    unset($temporary);
}
echo '<section class="admin-card"><h2>Create staff account</h2><form method="post" class="admin-form">'.staffFormNonce().'<input type="hidden" name="action" value="create">';
echo '<label>Login name<input name="username" autocomplete="off" pattern="[a-z][a-z0-9._-]{2,63}" maxlength="64" required></label><label>Staff name<input name="display_name" maxlength="100" required></label>';
echo '<label>Role and floor<select name="role">'.staffRoleOptions().'</select></label><label><input type="checkbox" name="enabled" value="1" checked> Account enabled</label><button class="admin-button" type="submit">Create and generate password</button></form></section>';
foreach($service->listAccounts($_SESSION) as $a) {
    echo '<section class="admin-card"><h2>'.adminH($a['display_name']).'</h2><p>Login: '.adminH($a['username']).' · '.((int)$a['enabled']===1?'Enabled':'Disabled').' · '.((int)$a['must_change']===1?'Password change required':'Password current').'</p>';
    $hidden='<input type="hidden" name="account_id" value="'.adminH($a['account_id']).'"><input type="hidden" name="version" value="'.adminH($a['auth_version']).'">';
    echo '<form method="post" class="admin-form">'.staffFormNonce().$hidden.'<input type="hidden" name="action" value="manage"><label>Role and floor<select name="role">'.staffRoleOptions($a['role']).'</select></label><label><input type="checkbox" name="enabled" value="1"'.((int)$a['enabled']===1?' checked':'').'> Account enabled</label><button class="admin-button" type="submit">Save access and revoke sessions</button></form>';
    echo '<form method="post">'.staffFormNonce().$hidden.'<input type="hidden" name="action" value="reset"><button class="admin-button" type="submit">Generate reset password and revoke sessions</button></form></section>';
}
echo '<section class="admin-card"><h2>Security audit</h2><table><thead><tr><th>Time (UTC)</th><th>Administrator or actor</th><th>Account</th><th>Action</th></tr></thead><tbody>';
foreach($service->auditHistory($_SESSION) as $a)echo '<tr><td>'.adminH(gmdate('Y-m-d H:i:s',(int)$a['occurred_at'])).'</td><td>'.adminH($a['actor_login'] ?? ($a['actor']==='owner:menu-admin'?'Owner (menu-admin)':$a['actor'])).'</td><td>'.adminH($a['target_login']??'Unidentified sign-in').'</td><td>'.adminH($a['action']).'</td></tr>';
echo '</tbody></table></section>';adminEnd();
