# Developer setup and testing

Use an isolated disposable checkout with no production credentials or live data. Follow AGENTS.md: offline deterministic application execution occurs in a hardened Windows Sandbox, with networking/clipboard/vGPU disabled, Protected Client enabled, source/runtime mapped read-only and only evidence output writable. The Sandbox launcher and trusted runtimes are local engineering infrastructure, not bundled secrets or dependencies.

Obtain trusted PHP 8.3, MariaDB, Node.js and browser distributions under their respective terms. No Composer/vendor/node_modules dependencies are included. Generate local-only credentials and configure UTC. `.env.example` is a placeholder template; it does not activate features. Use only a fresh disposable database for `bin/migrate.php` or synthetic initialization tools; review their exact arguments and safeguards first. Do not run cloud-seed against an existing database. Tests/tools integrations require dedicated synthetic identity/seed fixtures and a provisioned guest runner. Some historical scripts assume the original private fixture topology and are not turnkey public setup commands.

Targeted public checks in the hardened guest:

```text
php tests/public-export.php
php tests/tools/m07d-doc-contract.php .
php -l path/to/file.php
node --check path/to/file.js
```

`php tests/run.php` remains the inherited test aggregator, but the full old suite is not a supported passing command for this export: absent historical fixtures and changed synthetic IDs require a separately scoped adaptation. Do not suppress failures or claim the original private results prove public-export behavior. Current validation scope and known limitations are in PUBLIC_EXPORT_VALIDATION.md and SYNTHETIC_DATA_LIMITATIONS.md.
# M07E additions

Apply schema migrations through 038 only to a disposable synthetic database. Delivery identity, registration, dispatch and GPS flags stay at 0 by default. Run `php tests/tools/m07e-doc-contract.php .` inside the approved hardened guest for the 15 source/documentation assertions. The full partner integration tool requires generated engineering-runner fixtures; no public partner authentication or dispatch API exists. See the five M07E delivery contracts linked from the README.
