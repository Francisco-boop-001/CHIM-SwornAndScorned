# Key encryption acceptance — 0.1.2 candidate

Verified on 2026-10-04 using Linux PHP 8.2.29 with sodium and POSIX in the registered disposable `DwemerAI4Skyrim3-test` clone. This is implementation and test-server evidence, not installation on the gaming server. The clone had no installed Sworn & Scorned directory or plugin settings table before testing; all secret fixtures used generated dummy credentials. No live provider request was needed.

## Results

| Gate | Result |
| --- | --- |
| PHP syntax, packaged runtime files | 33/33 passed |
| Regular unit/regression scripts | 30/30 passed |
| Isolated synthetic database suites | 6/6 passed |
| Package unit checks | 2/2 passed |
| New DWPkg, MO2 ZIP, repository tar and checksums | Built and verified against the explicit source allowlist |
| Independent integrated security review | Accepted after blocking findings were fixed |
| Publication-file/archive privacy scan | 89 source files; 206 inspected items; no recognizable credential or master-key-file findings |

Commands: `php -l` for each runtime PHP file, `php tests/*_test.php` individually, `SAS_IT=1 php tests/integration/*_it.php` individually, `python tests/package_check.py`, and `python scripts/build-package.py --format release --release-dir dist/release-v0.1.2`. PHP execution took place only inside the test clone. Database fixtures were serialised and confined to their owned `sas_it` schema.

## Behaviours exercised

- Real libsodium encryption/decryption with a fresh nonce per save; no plaintext in the database's new saved-key value.
- Exact versioned envelopes, malformed data, authentication failure, altered ciphertext, wrong or missing master keys, size/control-byte bounds.
- Private service-owned master file/directory, unsuitable modes/owners, hard links, symlinks, untrusted/writable ancestors and served/plugin-tree path refusals.
- Legacy plaintext migration before use, idempotent encrypted reads, bounded migration revision retries, rejected migration writes including an actual SQL exception, and recovery without returning the plaintext to a backend.
- No provider dispatch when stored-key recovery fails; safe dashboard status without plaintext/ciphertext, and removal without a master key.
- Root-only provisioning, service-account readability, refusal to overwrite an existing master or provision in an existing service-owned directory, and an actual empty HTTP 403 for the provisioning endpoint.
- Direct localhost key entry, authenticated HTTPS, remote HTTP refusal and refusal to trust client forwarding headers; the HTTP page has no key-entry form.
- Exception argument visibility explicitly enabled: decrypted invalid test credentials remained absent from exception traces. Independent read-only probes reproduced the original leak and then verified redaction and four noncanonical-path refusals.

Test-first failures demonstrated original plaintext saves, absent transport/recovery/provisioning controls and package omissions. Independent review additionally exposed an exception-trace leak, root provisioning ownership/path races and migration SQL-error recovery; fixes have regression coverage. Root provisioning holds a private root-owned directory until metadata operations finish, refuses writable ancestors and rejects noncanonical paths before mutation.

## Limits

Encryption covers this plugin's saved OpenRouter setting. It does not encrypt CHIM's fallback connector credentials, erase historical plaintext backups/WAL, protect against root or compromised PHP code, or revoke a provider key. A database and its matching master file together are sufficient to decrypt the credential. Back up the master separately; replacing a lost master cannot recover existing ciphertext.

The tested default master path is outside the web root. Administrators choosing an alternative path must keep it outside **all** served directories, use the same private layout for the actual PHP/worker account, and configure any path override consistently for web and CLI processes. The provisioning helper targets `www-data` and the default CHIM/Linux layout.

No new Skyrim gameplay, real-provider interpretation, automatic MO2 transfer or production concurrency claim is made. Publication does not deploy this security update to an existing gaming server; the older 0.1.1 release retains its documented plaintext storage.

The privacy scan covers recognizable OpenRouter/GitHub/AWS credentials, private-key markers, credentialed URLs and master-key filenames. It is a bounded scan, not proof that every possible secret format is absent. No master file is shipped; the package builder includes only its reviewed runtime allowlist.
