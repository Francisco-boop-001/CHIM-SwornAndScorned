from __future__ import annotations

import configparser
import hashlib
import io
import json
import subprocess
import sys
import tempfile
import tarfile
import unittest
import uuid
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]
EVIDENCE = ROOT / "tasks" / "execution-2026-10-03" / "worker-support"
SERVER_FILES = (
    "apply.php",
    "backend.php",
    "backend_error.php",
    "batches.php",
    "context_pre.php",
    "decisions_backend.php",
    "evaluator.php",
    "jobs.php",
    "key_crypto.php",
    "launcher.php",
    "ledger.php",
    "llm_backend.php",
    "llm_connector.php",
    "llm_request.php",
    "logging.php",
    "manifest.json",
    "pg.php",
    "provision_key.php",
    "queue.php",
    "questions.php",
    "rows.php",
    "store.php",
    "substance.php",
    "surfacing.php",
    "tables.php",
    "transitions.php",
    "vocab.php",
    "witness.php",
    "written_evidence.php",
    "written_source.php",
    "written_worker.php",
    "worker.php",
    "hook.php",
    "lore.php",
    "lore_grudges.json",
    "page_security.php",
    "page_actions.php",
    "page_model.php",
    "page_view.php",
    "index.php",
    "assets/style.css",
    "assets/dashboard.js",
    "dashboard-art.webp",
)


class PackageCheck(unittest.TestCase):
    def test_mo2_metadata_and_reproducible_release_assets(self) -> None:
        manifest = json.loads((ROOT / "server" / "manifest.json").read_text(encoding="utf-8"))
        name, version = manifest["name"], manifest["version"]
        EVIDENCE.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix=".sas-mo2-check-", dir=EVIDENCE) as directory:
            releases = [Path(directory) / suffix for suffix in ("first", "second")]
            for release in releases:
                result = subprocess.run(
                    [sys.executable, str(ROOT / "scripts/build-package.py"), "--format", "release", "--release-dir", str(release)],
                    cwd=ROOT, capture_output=True, text=True,
                )
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            for asset in releases[0].iterdir():
                self.assertEqual(asset.read_bytes(), (releases[1] / asset.name).read_bytes(), asset.name)
            with ZipFile(releases[0] / f"{name}-{version}-mo2.zip") as archive:
                member = f"CHIM/server-plugins/{name}/{version}.dwpkg"
                self.assertEqual(set(archive.namelist()), {"meta.ini", member})
                self.assertIsNone(archive.testzip())
                metadata = configparser.ConfigParser(interpolation=None)
                metadata.read_string(archive.read("meta.ini").decode("utf-8"))
                self.assertEqual(dict(metadata["General"]), {
                    "version": version, "repository": "GitHub", "hascustomurl": "true",
                    "url": f"https://github.com/{manifest['git_repo']}", "validated": "true",
                })
                package = archive.read(member)
                self.assertEqual(package, (releases[0] / f"{name}-{version}.dwpkg").read_bytes())
                with ZipFile(io.BytesIO(package)) as inner:
                    self.assertNotIn("meta.ini", inner.namelist())
            with tarfile.open(releases[0] / f"{name}.tar.gz", "r:gz") as archive:
                self.assertFalse(any(Path(path).name == "meta.ini" for path in archive.getnames()))

    def test_dwpkg_members_manifest_checksums_and_source_bytes(self) -> None:
        manifest = json.loads((ROOT / "server" / "manifest.json").read_text(encoding="utf-8"))
        self.assertEqual(manifest["name"], "sworn_and_scorned")
        self.assertEqual(manifest["config_url"], "../ext/sworn_and_scorned/index.php?local=1")
        self.assertEqual(
            manifest["server_compatibility_reference"],
            "Playthrough-switch verification used HerikaServer 0ec590b42c8087860138bb4e9aab470049ff9a2b; broader installed revisions remain unverified",
        )
        EVIDENCE.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix=".sas-package-check-", dir=EVIDENCE) as directory:
            output = Path(directory) / "out.dwpkg"
            result = subprocess.run(
                [sys.executable, str(ROOT / "scripts" / "build-package.py"), "--output", str(output)],
                cwd=ROOT,
                check=False,
                capture_output=True,
                text=True,
            )
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            with ZipFile(output) as archive:
                names = archive.namelist()
                expected_names = sorted([f"server/{name}" for name in SERVER_FILES] + ["manifest.json", "checksums.sha256"])
                self.assertEqual(names, expected_names)
                self.assertIsNone(archive.testzip())
                self.assertNotIn("server/AGENTS.md", names)
                self.assertTrue(all(name.startswith("server/") or name in {"manifest.json", "checksums.sha256"} for name in names))
                outer = json.loads(archive.read("manifest.json"))
                self.assertEqual(outer, {
                    "schema_version": 4,
                    "name": "sworn_and_scorned",
                    "version": manifest["version"],
                    "server": {"mutable_paths": []},
                })
                lines = archive.read("checksums.sha256").decode("ascii").splitlines()
                checksums = dict(line.split("  ", 1)[::-1] for line in lines)
                self.assertEqual(set(checksums), set(names) - {"checksums.sha256"})
                for path, digest in checksums.items():
                    contents = archive.read(path)
                    self.assertEqual(hashlib.sha256(contents).hexdigest(), digest)
                    if path.startswith("server/"):
                        relative = path.removeprefix("server/")
                        self.assertEqual(contents, (ROOT / "server" / relative).read_bytes())

    def test_builder_rejects_output_outside_project(self) -> None:
        output = ROOT.parent / f".sas-package-escape-{uuid.uuid4().hex}.dwpkg"
        result = subprocess.run(
            [sys.executable, str(ROOT / "scripts" / "build-package.py"), "--output", str(output)],
            cwd=ROOT,
            check=False,
            capture_output=True,
            text=True,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Package output must stay inside the project", result.stderr)
        self.assertFalse(output.exists())


if __name__ == "__main__":
    unittest.main()
