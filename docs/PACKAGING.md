# WooCommerce installation package

Prepare the desired environment in `includes/config.php`, then run from the plugin directory:

```bash
composer package
```

The result is `dist/woo_uni_<VERSION>.zip`, using all three version components (for example, `dist/woo_uni_2.0.3.zip`). The version comes from the literal `MTUC_VERSION` runtime declaration in `mtunicredit.php` and must agree with its WordPress `Version` header. The ZIP contains one plugin directory, `mtunicredit/`. Install it through **WordPress → Plugins → Add Plugin → Upload Plugin**, with WooCommerce installed and active.

This command replaces manual ZIP assembly. It builds from the **current working source**, including intentional uncommitted runtime changes. It does not stage, commit, reset, rewrite configuration, change source timestamps or modify source `vendor/`. `includes/config.php` is copied byte-for-byte; no environment template or second deployment config is created. Preserve the existing CP destination policy when preparing a release.

## Local deployment inputs

The existing runtime requires `secrets/smartucf-key.php` for a self-contained production installation. Supply the real deployment file before building. It must return the array understood by `includes/mtuc-secrets.php`, with a nonempty `smartucf_key_password` string. Missing, unreadable or invalid files stop the build. Host environment variables and WordPress constants are not portable deployment inputs and do not replace this file in the package. The builder validates it in an isolated PHP process and suppresses its output; it never displays the password.

`keys/` is a runtime data directory. The installer includes **exactly** `keys/.htaccess` and `keys/index.php`, preserving both current files byte-for-byte. Those two entries create the empty protected directory on installation. The existing certificate synchronizer obtains certificate/private-key material from CP when first required and creates lease storage at runtime.

Certificates (`.pem`, `.key`, `.crt`, `.cer`), private keys, `keys/leases/**`, synchronization locks, downloaded bundles, backups and temporary runtime files are always excluded, even when present in the development installation. The builder never enumerates or reads this runtime material and does not modify or delete it. The verifier rejects any `keys/` entry other than the two protection files, and the manifest cannot opt runtime material back in. Existing protection files under `secrets/` are required and preserved; unrelated secret files are excluded.

The ZIP contains the production deployment secret. Treat it as a **sensitive deployment artifact**. `dist/` and ZIPs remain ignored by Git and must never be staged or committed. Generated `dist/.htaccess` denies HTTP access on Apache when overrides are enabled; archive and protection files are created with mode `0600`, and new distribution directories with mode `0700`. Other HTTP servers require their own access restrictions. Do not publish the ZIP as public source.

## Manifest and verification

`tools/package-manifest.json` enumerates audited runtime PHP, JavaScript, CSS, templates, assets, font license, language catalog, installation metadata, the production secret and the two `keys/` protection files. Supported translation files immediately under `languages/` (`.mo`, `.po`, `.l10n.php`) are included in sorted order. Symlinks in deployment inputs are rejected. Update the manifest when adding runtime files; arbitrary unlisted files are excluded.

This plugin currently has **no runtime Composer dependencies**, so the package has no `vendor/` tree. Development dependencies, Composer/PHPCS tooling, tests and fixtures, repository metadata, documentation, recovery copies, caches, logs, building scripts and previous ZIPs are excluded. If production Composer requirements change, the builder fails until the dependency preparation and runtime manifest are reviewed.

The builder copies inputs into a private temporary staging tree, checks parity, uses sorted entries with one current UTC build timestamp, fixed compression and permissions, verifies the archive, rechecks the current source, then atomically publishes it. It removes its staging tree after success or failure. A failed build leaves any previous completed ZIP in place; that previous artifact must pass verification against current source before use. Existing custom `dist/.htaccess` files are not overwritten.

After successful verification and publication, the builder removes other regular files directly in `dist/` whose names exactly match `woo_uni_<VERSION>.zip` (the same three-component version syntax, including supported prerelease/build suffixes). It always keeps the newly published filename. Symlinks, directories, nested files, checksums, backups and unrelated artifacts are left untouched. Repeated cleanup is idempotent; generation, verification or publication failures do not trigger cleanup. If removing a previous installer fails, the command reports the cleanup failure and leaves the new verified ZIP in place.

Verify an artifact against the current working source with:

```bash
composer package:verify -- dist/woo_uni_<VERSION>.zip
```

The verifier checks the exact filename, single plugin root, exact sorted manifest, regular files, no extra or missing entries, canonical version and byte parity for **every** runtime/deployment input, including config and secrets. It rejects malformed archives, unexpected development content, nested roots and stale artifacts. Its output contains only archive facts and a checksum of the entire ZIP; it does not list secret contents or secret checksums.

Source mtimes, caller timezone and umask do not determine archive entry timestamps or permissions. Building while source or the deployment secret is changing can fail the parity check; retry from stable inputs. Runtime certificate/lease changes do not affect distribution inputs.

## Build timestamps and installation

Each build captures the current Unix time once and rounds it down to DOS two-second precision. Every ZIP entry receives that same timestamp, replacing the previous fixed historical epoch. Source and staging mtimes are not inherited. The ZIP-writing operation, including opening and closing the archive, temporarily uses process `TZ=UTC` and PHP timezone `UTC`; both caller states are restored in `finally` after success or failure. An unset process `TZ` remains distinct from an explicitly empty value.

Builds within the same DOS interval can have identical timestamps. Independently generated installers in different intervals can have different SHA-256 values even with identical source contents and toolchains; compression toolchain differences can also change ZIP bytes. Retain the exact installer that was tested, together with its whole-archive SHA-256, and distribute those verified bytes rather than rebuilding an assumed identical replacement.

Raw DOS date/time fields contain no timezone marker. This builder writes UTC fields, but an extraction tool may interpret them in the destination's local timezone. Timestamp-preserving extraction and placement can retain the resulting mtime; ordinary copying or WordPress replacement may assign another filesystem mtime. Check the actual destination behavior. The regression tests demonstrate controlled UTC extraction, rename placement and ordinary copying only in isolated fixtures; installed WordPress replacement behavior has not been verified.

After installation, compare the installed PHP bytes against the exact tested installer and inspect the installed filesystem mtimes, accounting for the extraction timezone and placement method. Correct bytes and current mtimes do not guarantee that web PHP immediately executes the new code. OPcache behavior depends on the web PHP configuration, including timestamp validation and revalidation intervals; use the hosting environment's established revalidation or worker-restart procedure when required. Packaging does not change web PHP OPcache settings.

## Validation

Packaging requires PHP 7.4+ with `ext-zip` (`ZipArchive::setMtimeName`), permission to set process `TZ` with `putenv`, and working CLI subprocess support. The packaging regression suite additionally uses `unzip` for independent integrity checks and extraction. Composer invokes PHP directly; no runtime Composer installation is necessary.

The CLI user must be able to read every listed runtime/deployment input, traverse their parent directories, and read, write and traverse `dist/`. No read access to certificates, private keys or lease contents is needed. Arrange access for the authorized deployment user before building. The command does not change ownership or access permissions of existing source files or directories; unreadable required inputs stop the build with a filename-only diagnostic.

```bash
composer validate --strict
composer test:package
composer package
composer package:verify -- dist/woo_uni_<VERSION>.zip
git diff --check
```

Run all `tests/run-*-tests.php` suites before release. Packaging tests use private temporary fixtures with test-only secret data, never the real deployment secret. They cover successful builds, source/config parity, filename/version agreement, exclusion rules, malformed archives, missing inputs, version ambiguity, translations, rejected symlinks and cleanup. Timestamp checks independently parse local and central headers, check CRCs, vary source mtimes and caller timezones, exercise timezone restoration after failures, and verify changed PHP bytes and extracted mtimes across distinct DOS intervals. Cross-build comparisons ignore only DOS timestamp fields; failed-build checks still require exact preservation of the previous installer. Tests also prove that unreadable runtime certificates/leases do not affect builds and that archives containing such material are rejected. Independently extract the final production ZIP and compare all distribution inputs with the source before distributing it.

The preserved `readme.txt` contains earlier manual assembly wording. This document defines the canonical automated release workflow without rewriting the existing CP remediation documentation.
