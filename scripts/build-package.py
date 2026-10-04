"""Build and verify the explicit CHIM Sworn & Scorned package payload."""

from __future__ import annotations

import argparse
import gzip
import hashlib
import io
import json
import os
import re
import stat
import tarfile
import tempfile
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZIP_STORED, BadZipFile, ZipFile, ZipInfo


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
PACKAGE_SCHEMA = 4
_CHECKSUM = re.compile(r"^([a-f0-9]{64})  (.+)$")
_NAME = re.compile(r"^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$")
_VERSION = re.compile(r"^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$")
REPOSITORY_TAR_MTIME = 315532800  # 1980-01-01 UTC; stable on Windows/DrvFs.


class PackageError(ValueError):
    """The package cannot be verified against its source allowlist."""


def _source_entries(project_root: Path) -> tuple[dict, dict[str, bytes]]:
    root = Path(project_root).resolve()
    server_root = root / "server"
    if server_root.is_symlink() or not server_root.is_dir():
        raise PackageError("Missing server payload directory")

    missing = [
        name for name in SERVER_FILES
        if not (server_root / name).is_file() or (server_root / name).is_symlink()
    ]
    if missing:
        raise PackageError(f"Missing allowlisted server payload: {', '.join(missing)}")

    entries: dict[str, bytes] = {}
    for name in SERVER_FILES:
        path = server_root / name
        if not path.resolve().is_relative_to(server_root.resolve()):
            raise PackageError(f"Server payload escapes its root: {name}")
        entries[f"server/{name}"] = path.read_bytes()

    try:
        plugin_manifest = json.loads(entries["server/manifest.json"])
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise PackageError("server/manifest.json must be valid UTF-8 JSON") from error
    if not isinstance(plugin_manifest, dict):
        raise PackageError("server/manifest.json must contain an object")
    name, version = plugin_manifest.get("name"), plugin_manifest.get("version")
    if not isinstance(name, str) or not _NAME.fullmatch(name) or name.endswith((".", " ")):
        raise PackageError("server/manifest.json has an invalid package name")
    if not isinstance(version, str) or not _VERSION.fullmatch(version):
        raise PackageError("server/manifest.json has an invalid package version")

    package_manifest = {
        "schema_version": PACKAGE_SCHEMA,
        "name": name,
        "version": version,
        "server": {"mutable_paths": []},
    }
    entries["manifest.json"] = (
        json.dumps(package_manifest, indent=2, sort_keys=True) + "\n"
    ).encode("utf-8")
    entries["checksums.sha256"] = "".join(
        f"{hashlib.sha256(contents).hexdigest()}  {path}\n"
        for path, contents in sorted(entries.items())
    ).encode("ascii")
    return plugin_manifest, entries


def verify_package(archive_path: Path, project_root: Path) -> dict:
    """Check archive membership, CRC, checksums, manifests, and source equality."""
    _, expected = _source_entries(project_root)
    try:
        with ZipFile(archive_path) as archive:
            names = archive.namelist()
            if names != sorted(expected) or len(names) != len(set(names)):
                raise PackageError("Archive member list differs from the explicit allowlist")
            if archive.testzip() is not None:
                raise PackageError("Archive contains a CRC failure")
            actual = {name: archive.read(name) for name in names}
    except (OSError, BadZipFile, KeyError, ValueError) as error:
        if isinstance(error, PackageError):
            raise
        raise PackageError(f"Could not verify package archive: {error}") from error

    try:
        package_manifest = json.loads(actual["manifest.json"])
        checksum_text = actual["checksums.sha256"].decode("ascii")
    except (UnicodeDecodeError, json.JSONDecodeError, KeyError) as error:
        raise PackageError("Archive manifests or checksums are invalid") from error
    if package_manifest.get("schema_version") != PACKAGE_SCHEMA:
        raise PackageError("Package manifest schema must be 4")
    if package_manifest.get("server", {}).get("mutable_paths") != []:
        raise PackageError("Package must not declare mutable paths")

    hashes: dict[str, str] = {}
    for line in checksum_text.splitlines():
        match = _CHECKSUM.fullmatch(line)
        if not match or match[2] in hashes:
            raise PackageError("Archive checksum list is malformed")
        hashes[match[2]] = match[1]
    if set(hashes) != set(actual) - {"checksums.sha256"}:
        raise PackageError("Checksum list does not cover the exact package members")
    for name, contents in actual.items():
        if name == "checksums.sha256":
            continue
        if hashes[name] != hashlib.sha256(contents).hexdigest():
            raise PackageError(f"Checksum mismatch: {name}")
        if contents != expected[name]:
            raise PackageError(f"Packaged source differs from allowlisted source: {name}")
    return package_manifest


def build_package(project_root: Path, output_path: Path) -> dict:
    root = Path(project_root).resolve()
    output = Path(output_path).resolve()
    try:
        output.relative_to(root)
    except ValueError as error:
        raise PackageError("Package output must stay inside the project") from error
    if output in {(root / "server" / name).resolve() for name in SERVER_FILES}:
        raise PackageError("Package output cannot replace a server payload file")

    plugin_manifest, entries = _source_entries(root)
    output.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{output.name}.", suffix=".tmp", dir=output.parent
    )
    os.close(descriptor)
    temporary = Path(temporary_name)
    try:
        with ZipFile(temporary, "w", compression=ZIP_STORED) as archive:
            archive.comment = b""
            for name, contents in sorted(entries.items()):
                info = ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
                info.compress_type = ZIP_STORED
                info.create_system = 3
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                archive.writestr(info, contents)
        verify_package(temporary, root)
        os.replace(temporary, output)
    finally:
        temporary.unlink(missing_ok=True)
    return plugin_manifest


def verify_repository_archive(archive_path: Path, project_root: Path) -> dict:
    """Check the strip-one tar wrapper and every flattened source payload byte."""
    plugin_manifest, entries = _source_entries(Path(project_root))
    package_name = plugin_manifest["name"]
    expected = [(f"{package_name}/{name}", entries[f"server/{name}"]) for name in SERVER_FILES]
    expected_names = [package_name, *(name for name, _ in expected)]
    try:
        with Path(archive_path).open("rb") as raw:
            header = raw.read(8)
        if len(header) != 8 or header[:2] != b"\x1f\x8b" or header[4:8] != b"\0\0\0\0":
            raise PackageError("Repository gzip header is not deterministic")
        with gzip.open(archive_path, "rb") as compressed:
            while compressed.read(1024 * 1024):
                pass
        with tarfile.open(archive_path, "r:gz") as archive:
            members = archive.getmembers()
            if [member.name for member in members] != expected_names:
                raise PackageError("Repository archive member list differs from the explicit allowlist")
            root = members[0]
            if root.type != tarfile.DIRTYPE or root.size != 0 or root.mode != 0o755:
                raise PackageError("Repository archive root metadata is invalid")
            if (root.mtime, root.uid, root.gid) != (REPOSITORY_TAR_MTIME, 0, 0):
                raise PackageError("Repository archive root metadata is not deterministic")
            for member, (name, contents) in zip(members[1:], expected, strict=True):
                if member.name != name or member.type != tarfile.REGTYPE or member.size != len(contents):
                    raise PackageError(f"Repository payload does not match the allowlist: {member.name}")
                if (member.mode, member.mtime, member.uid, member.gid) != (0o644, REPOSITORY_TAR_MTIME, 0, 0):
                    raise PackageError(f"Repository payload metadata is not deterministic: {member.name}")
                source = archive.extractfile(member)
                if source is None:
                    raise PackageError(f"Could not read repository payload: {member.name}")
                with source:
                    if source.read() != contents:
                        raise PackageError(f"Repository source mismatch: {member.name}")
    except (OSError, EOFError, tarfile.TarError) as error:
        raise PackageError(f"Could not verify repository archive: {error}") from error
    return plugin_manifest


def build_repository_archive(project_root: Path, archive_path: Path) -> dict:
    """Build a deterministic one-wrapper tarball for the CHIM repository installer."""
    root = Path(project_root).resolve()
    output = Path(archive_path).resolve()
    try:
        output.relative_to(root)
    except ValueError as error:
        raise PackageError("Package output must stay inside the project") from error
    if output in {(root / "server" / name).resolve() for name in SERVER_FILES}:
        raise PackageError("Package output cannot replace a server payload file")

    plugin_manifest, entries = _source_entries(root)
    package_name = plugin_manifest["name"]
    output.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{output.name}.", suffix=".tmp", dir=output.parent
    )
    os.close(descriptor)
    temporary = Path(temporary_name)
    try:
        with temporary.open("wb") as raw:
            with gzip.GzipFile(filename="", fileobj=raw, mode="wb", compresslevel=9, mtime=0) as compressed:
                with tarfile.open(fileobj=compressed, mode="w", format=tarfile.USTAR_FORMAT) as archive:
                    directory = tarfile.TarInfo(package_name)
                    directory.type = tarfile.DIRTYPE
                    directory.mode = 0o755
                    directory.mtime = REPOSITORY_TAR_MTIME
                    directory.uid = directory.gid = 0
                    directory.uname = directory.gname = ""
                    archive.addfile(directory)
                    for name in SERVER_FILES:
                        contents = entries[f"server/{name}"]
                        member = tarfile.TarInfo(f"{package_name}/{name}")
                        member.size = len(contents)
                        member.mode = 0o644
                        member.mtime = REPOSITORY_TAR_MTIME
                        member.uid = member.gid = 0
                        member.uname = member.gname = ""
                        archive.addfile(member, io.BytesIO(contents))
        verify_repository_archive(temporary, root)
        os.replace(temporary, output)
    finally:
        temporary.unlink(missing_ok=True)
    return plugin_manifest


def build_mo2_sync_archive(project_root: Path, archive_path: Path) -> dict:
    """Build a plain MO2 ZIP with exactly one CHIM server-plugin destination."""
    root = Path(project_root).resolve()
    output = Path(archive_path).resolve()
    try:
        output.relative_to(root)
    except ValueError as error:
        raise PackageError("Package output must stay inside the project") from error
    if output in {(root / "server" / name).resolve() for name in SERVER_FILES}:
        raise PackageError("Package output cannot replace a server payload file")

    plugin_manifest, _ = _source_entries(root)
    name, version = plugin_manifest["name"], plugin_manifest["version"]
    member_name = f"CHIM/server-plugins/{name}/{version}.dwpkg"
    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix=f".{output.stem}-", dir=output.parent) as temp_name:
        temporary_root = Path(temp_name)
        package_path = temporary_root / f"{name}-{version}.dwpkg"
        wrapper_path = temporary_root / output.name
        build_package(root, package_path)
        package_bytes = package_path.read_bytes()
        with ZipFile(wrapper_path, "w", compression=ZIP_DEFLATED, compresslevel=9) as archive:
            info = ZipInfo(member_name, date_time=(1980, 1, 1, 0, 0, 0))
            info.compress_type = ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            archive.writestr(info, package_bytes)
        with ZipFile(wrapper_path) as archive:
            if archive.namelist() != [member_name] or archive.testzip() is not None:
                raise PackageError("MO2 ZIP does not contain exactly one valid CHIM sync package")
            if archive.read(member_name) != package_bytes:
                raise PackageError("MO2 ZIP changed the CHIM sync package bytes")
        os.replace(wrapper_path, output)
    return plugin_manifest


def build_release(project_root: Path, release_dir: Path) -> dict:
    """Build every supported release asset and its hash list in one directory."""
    root = Path(project_root).resolve()
    output = Path(release_dir).resolve()
    try:
        output.relative_to(root)
    except ValueError as error:
        raise PackageError("Release output must stay inside the project") from error
    if output.is_symlink() or (output.exists() and (not output.is_dir() or any(output.iterdir()))):
        raise PackageError("Release output directory must be new or empty")

    plugin_manifest, _ = _source_entries(root)
    name, version = plugin_manifest["name"], plugin_manifest["version"]
    output.mkdir(parents=True, exist_ok=True)
    assets = [
        output / f"{name}-{version}.dwpkg",
        output / f"{name}.tar.gz",
        output / f"{name}-{version}-mo2.zip",
    ]
    build_package(root, assets[0])
    build_repository_archive(root, assets[1])
    build_mo2_sync_archive(root, assets[2])
    sums = "".join(
        f"{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.name}\n"
        for path in sorted(assets, key=lambda item: item.name)
    )
    (output / "SHA256SUMS.txt").write_bytes(sums.encode("ascii"))
    return plugin_manifest


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--format",
        choices=("dwpkg", "repository-tar-gz", "mo2-sync-zip", "release"),
        default="dwpkg",
        help="package format (default: dwpkg)",
    )
    parser.add_argument("--output", type=Path, help="output file for one archive format")
    parser.add_argument("--release-dir", type=Path, help="release folder; defaults to dist/release-v<version>")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    requested_output = args.release_dir if args.format == "release" else args.output
    if requested_output is not None:
        requested_output = requested_output if requested_output.is_absolute() else root / requested_output
        try:
            requested_output.resolve().relative_to(root)
        except ValueError as error:
            raise PackageError("Package output must stay inside the project") from error
    plugin_manifest, _ = _source_entries(root)
    if args.format == "release":
        if args.output is not None:
            parser.error("--output cannot be used with --format release; use --release-dir")
        output = args.release_dir or root / "dist" / f"release-v{plugin_manifest['version']}"
        if not output.is_absolute():
            output = root / output
        output = output.resolve()
        build_release(root, output)
        for name in sorted(path.name for path in output.iterdir()):
            path = output / name
            print(
                f"Built and verified {path.relative_to(root)} "
                f"({path.stat().st_size} bytes, sha256 {hashlib.sha256(path.read_bytes()).hexdigest()})"
            )
    else:
        if args.release_dir is not None:
            parser.error("--release-dir requires --format release")
        if args.format == "repository-tar-gz":
            output = args.output or root / "dist" / f"{plugin_manifest['name']}.tar.gz"
        elif args.format == "mo2-sync-zip":
            output = args.output or root / "dist" / f"{plugin_manifest['name']}-{plugin_manifest['version']}-mo2.zip"
        else:
            output = args.output or root / "dist" / f"{plugin_manifest['name']}-{plugin_manifest['version']}.dwpkg"
        if not output.is_absolute():
            output = root / output
        output = output.resolve()
        if args.format == "repository-tar-gz":
            manifest = build_repository_archive(root, output)
        elif args.format == "mo2-sync-zip":
            manifest = build_mo2_sync_archive(root, output)
        else:
            manifest = build_package(root, output)
        digest = hashlib.sha256(output.read_bytes()).hexdigest()
        print(f"Built and verified {output.relative_to(root)} ({manifest['name']} {manifest['version']}, sha256 {digest})")


if __name__ == "__main__":
    try:
        main()
    except (OSError, KeyError, json.JSONDecodeError, PackageError) as error:
        raise SystemExit(f"package failed: {error}") from error
