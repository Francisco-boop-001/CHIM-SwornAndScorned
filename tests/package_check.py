from __future__ import annotations

import hashlib
import json
import subprocess
import sys
import tempfile
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
    "surfacing.php",
    "tables.php",
    "transitions.php",
    "vocab.php",
    "witness.php",
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
    "dashboard-art.webp",
)


class PackageCheck(unittest.TestCase):
    def test_dwpkg_members_manifest_checksums_and_source_bytes(self) -> None:
        manifest = json.loads((ROOT / "server" / "manifest.json").read_text(encoding="utf-8"))
        self.assertEqual(manifest["name"], "sworn_and_scorned")
        self.assertEqual(manifest["config_url"], "../ext/sworn_and_scorned/index.php?local=1")
        self.assertEqual(
            manifest["server_compatibility_reference"],
            "Installed HerikaServer source identity: main.php sha256 ef2ff9a50203820ca5416f334504f352793ee5413053e3b7beee1a222c8e721b; deployed revision unverified",
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
