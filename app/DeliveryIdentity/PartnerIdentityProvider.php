<?php
declare(strict_types=1);
namespace Kios\DeliveryIdentity;
/** Trusted future server authentication port; never implement from submitted UUIDs or merchant/customer sessions. */
interface PartnerIdentityProvider { public function currentPartnerId(): ?string; }
