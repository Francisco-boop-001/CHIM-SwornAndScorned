# Sworn & Scorned 0.1.7 — PRE-ALPHA

The llama has stopped handing MO2 users three boxes and a cryptic riddle. **[Use the MO2 ZIP alone](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v0.1.7/sworn_and_scorned-0.1.7-mo2.zip)**; it already contains the server package. Launch Skyrim/SKSE through MO2, load a save with CHIM connected, then check the installed version in the server's Plugin Manager.

The MO2 ZIP now includes a minimal `meta.ini` with its manifest-derived version, project URL and `validated=true` for the installed-mod missing-content flag. No fake Nexus ID, personal settings, credentials or dummy ESP. The initial installer dialog may still require continuing with the correct Data/CHIM layout. Metadata does not provide automatic GitHub update checks.

The standalone DWPkg and tar archive remain for supported server/admin tooling; the checksum list is optional. The inspected Plugin Manager does not offer manual upload, so the README no longer invents a button for it. CHIM status errors have a separate troubleshooting path.

This is a packaging/documentation correction. The runtime manifest moves to 0.1.7; evaluator, settings, ledger and logging behavior are unchanged. Published 0.1.6 assets remain untouched. Publication does not install the update on your server; follow the launch/load and installed-version checks in the README.

See [verification and limits](../docs/verification/mo2-0.1.7.md), including the unverified MO2/game synchronization gate.
