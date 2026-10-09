# WooCommerce installation package

Prepare the desired environment in `includes/config.php`, then run from the plugin directory:

```bash
composer package
```

The result is `dist/CC_Woocommerce_UNI_v.<VERSION>.zip`. The version comes from the literal `MTUC_VERSION` runtime declaration in `mtunicredit.php` and must agree with its WordPress `Version` header. The ZIP contains one plugin directory, `mtunicredit/`. Install it through **WordPress → Plugins → Add Plugin → Upload Plugin**, with WooCommerce installed and active.

This command replaces manual ZIP assembly. It builds from the **current working source**, including intentional uncommitted runtime changes. It does not stage, commit, reset, rewrite configuration, change source timestamps or modify source `vendor/`. `includes/config.php` is copied byte-for-byte; no environment template or second deployment config is created. Preserve the existing CP destination policy when preparing a release.

## Local deployment inputs

The existing runtime requires `secrets/smartucf-key.php` for a self-contained production installation. Supply the real deployment file before building. It must return the array understood by `includes/mtuc-secrets.php`, with a nonempty `smartucf_key_password` string. Missing, unreadable or invalid files stop the build. Host environment variables and WordPress constants are not portable deployment inputs and do not replace this file in the package. The builder validates it in an isolated PHP process and suppresses its output; it never displays the password.

`keys/` is a runtime data directory. The installer includes **exactly** `keys/.htaccess` and `keys/index.php`, preserving both current files byte-for-byte. Those two entries create the empty protected directory on installation. The existing certificate synchronizer obtains certificate/private-key material from CP when first required and creates lease storage at runtime.

Certificates (`.pem`, `.key`, `.crt`, `.cer`), private keys, `keys/leases/**`, synchronization locks, downloaded bundles, backups and temporary runtime files are always excluded, even when present in the development installation. The builder never enumerates or reads this runtime material and does not modify or delete it. The verifier rejects any `keys/` entry other than the two protection files, and the manifest cannot opt runtime material back in. Existing protection files under `secrets/` are required and preserved; unrelated secret files are excluded.

The ZIP contains the production deployment secret. Treat it as a **sensitive deployment artifact**. `dist/` and ZIPs remain ignored by Git and must never be staged or committed. Generated `dist/.htaccess` denies HTTP access on Apache when overrides are enabled; archive and protection files are created with mode `0600`, and new distribution directories with mode `0700`. Other HTTP servers require their own access restrictions. Do not publish the ZIP as public source.

## Manifest and verification

`tools/package-manifest.json` enumerates audited runtime PHP, JavaScript, CSS, templates, assets, font license, language catalog, installation metadata, the production secret and the two `keys/` protection files. Supported translation files immediately under `languages/` (`.mo`, `.po`, `.l10n.php`) are included in sorted order. Symlinks in deployment inputs are rejected. Update the manifest when adding runtime files; arbitrary unlisted files are excluded.

This plugin currently has **no runtime Composer dependencies**, so the package has no `vendor/` tree. Development dependencies, Composer/PHPCS tooling, tests and fixtures, repository metadata, documentation, recovery copies, caches, logs, building scripts and previous ZIPs are excluded. If production Composer requirements change, the builder fails until the dependency preparation and runtime manifest are reviewed.

The builder copies inputs into a private temporary staging tree, checks parity, uses sorted entries with fixed ZIP timestamps, compression and permissions, verifies the archive, rechecks the current source, then atomically publishes it. It removes its staging tree after success or failure. A failed build leaves any previous completed ZIP in place; that previous artifact must pass verification against current source before use. Existing custom `dist/.htaccess` files are not overwritten.

Verify an artifact against the current working source with:

```bash
composer package:verify -- dist/CC_Woocommerce_UNI_v.<VERSION>.zip
```

The verifier checks the exact filename, single plugin root, exact sorted manifest, regular files, no extra or missing entries, canonical version and byte parity for **every** runtime/deployment input, including config and secrets. It rejects malformed archives, unexpected development content, nested roots and stale artifacts. Its output contains only archive facts and a checksum of the entire ZIP; it does not list secret contents or secret checksums.

Repeated builds from the same inputs and PHP/libzip/zlib toolchain produce identical ZIP bytes. Source mtimes, local timezone and umask do not affect the archive. Different compression toolchains may produce different bytes. Building while source or the deployment secret is changing can fail the parity check; retry from stable inputs. Runtime certificate/lease changes do not affect distribution inputs.

## Validation

Packaging requires PHP 7.4+ with `ext-zip` (`ZipArchive::setMtimeName`) and working CLI subprocess support. Composer invokes PHP directly; no runtime Composer installation is necessary.

The CLI user must be able to read every listed runtime/deployment input, traverse their parent directories, and read, write and traverse `dist/`. No read access to certificates, private keys or lease contents is needed. Arrange access for the authorized deployment user before building. The command does not change ownership or access permissions of existing source files or directories; unreadable required inputs stop the build with a filename-only diagnostic.

```bash
composer validate --strict
composer test:package
composer package
composer package:verify -- dist/CC_Woocommerce_UNI_v.<VERSION>.zip
git diff --check
```

Run all `tests/run-*-tests.php` suites before release. Packaging tests use private temporary fixtures with test-only secret data, never the real deployment secret. They cover successful builds, source/config parity, filename/version agreement, exclusion rules, malformed archives, missing inputs, version ambiguity, translations, rejected symlinks, cleanup and repeatability under different fixture timestamps/timezones/umasks. They prove that unreadable runtime certificates/leases do not affect builds and that archives containing such material are rejected. Independently extract the final production ZIP and compare all distribution inputs with the source before distributing it.

The preserved `readme.txt` contains earlier manual assembly wording. This document defines the canonical automated release workflow without rewriting the existing CP remediation documentation.
