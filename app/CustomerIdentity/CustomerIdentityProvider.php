<?php
declare(strict_types=1);
namespace Kios\CustomerIdentity;
/** Trusted future server authentication port. Never implement from a submitted customer UUID or merchant session. */
interface CustomerIdentityProvider { public function currentCustomerId():?string; }
