# Sworn & Scorned 0.1.2 — PRE-ALPHA security candidate

The llama's filing cabinet now has a lock. The lock's key lives somewhere else. A radical departure from leaving both in the same drawer.

The OpenRouter key saved on this plugin's page is encrypted with libsodium authenticated secretbox and a fresh random nonce. The database stores ciphertext; a separate private 256-bit master key stays outside CHIM's served files and packages. Existing plaintext settings migrate before use. Failed migration, unavailable master keys and altered ciphertext block evaluation while preserving dashboard recovery.

After installing **0.1.2**, run the administrator-only setup command once on the Linux CHIM server:

```sh
sudo php /var/www/html/HerikaServer/ext/sworn_and_scorned/provision_key.php
```

Adjust the server location if needed. The tested service account is `www-data`; default storage is `/var/lib/chim-secrets/sworn_and_scorned.key`, mode `0600`, in a `0700` directory. Setup refuses existing keys, unsafe paths and service-owned existing directories. See [setup and recovery](../README.md#jev-or-your-chim-relationship-model) for other service accounts and environment configuration.

Remote key entry now requires authenticated HTTPS recognised by the server. Direct localhost setup remains available. Saved keys and ciphertext are omitted from the page; key-bearing arguments are redacted in supported PHP exception traces.

Back up the master key separately from the database. Losing it requires replacing the saved API key. Root, the PHP account or compromised server code can still access a key in use. CHIM's fallback connector credentials and historical plaintext backups are outside this change; clearing a setting does not revoke its OpenRouter key.

This is a PRE-ALPHA release. The older 0.1.1 downloads do not include these protections. [Security verification](../docs/verification/key-encryption-0.1.2.md) records the tests and their limits. All 33 runtime PHP syntax checks, 30 regression scripts, six isolated database suites and both package checks passed; independent security review accepted the final code. Automatic MO2 transfer and gaming-server deployment were not exercised.
