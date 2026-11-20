# Public source publication review

Gitleaks scanned the complete public export with redacted evidence retained privately. Four generic-key detections were individually reviewed, all unchanged synthetic fixture code: tests/tools/m02a-integration.php, m05d-analytics.php, m05e-billing.php and m06a-entitlements.php. They assign native session fields from generated synthetic identities; no literal live credential is present. Findings are acknowledged rather than hidden. Credential-redaction sentinel text in observability tests is synthetic and contains no private key material.

Supplementary checks found no legacy menu names/SKUs from the removed 105-entry dataset, private host identifiers, personal email addresses, installed dependency trees, binary assets, credential files, private logs, runtime configs, backups or database dumps. Migration SQL consists of schema and generic system-role defaults, not merchant data dumps. Generic code-native SVG artwork was reviewed. No new license is granted.

Only the new root commit and sanitized files are published; no old Git objects are imported. Review evidence is not a guarantee against every possible unknown secret. The separate hosted deployment and original source workspace were not modified. Prior public copies of the old repository may remain outside the owner's control; changing visibility does not recall them.
