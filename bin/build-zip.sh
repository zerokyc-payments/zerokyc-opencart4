#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
OUT_ZIP="${DIST_DIR}/zerokyc.ocmod.zip"
EXT_DIR="${ROOT_DIR}/extension/zerokyc"

mkdir -p "${DIST_DIR}"
rm -f "${OUT_ZIP}"

php -r '
$extDir = $argv[1];
$outZip = $argv[2];

$zip = new ZipArchive();
if ($zip->open($outZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Failed to open zip archive: {$outZip}\n");
    exit(1);
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($extDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($it as $file) {
    $fullPath = $file->getRealPath();
    $relPath  = str_replace("\\", "/", substr($fullPath, strlen($extDir) + 1));
    if ($file->isDir()) {
        $zip->addEmptyDir($relPath);
    } else {
        $zip->addFile($fullPath, $relPath);
    }
}

$zip->close();
echo "Built {$outZip} (" . filesize($outZip) . " bytes)\n";
' "${EXT_DIR}" "${OUT_ZIP}"
