<?php

declare(strict_types=1);

/*
 * Downloads the official e-Doręczenia API specifications into build/specs/ and verifies
 * their SHA-256 hashes against the values pinned below.
 *
 * The specifications are not committed to this repository because their redistribution
 * terms are not stated explicitly.
 *
 * A hash mismatch means the publisher changed a document. That is the signal to review the
 * change, update the SDK if needed, and then update the pinned hashes below.
 *
 * Usage: composer specs:fetch
 */

const TARGET_DIR = __DIR__ . '/../build/specs';

/**
 * @var list<array{name: string, url: string, sha256: string, extract: string, yaml_sha256: string}>
 */
const SPECS = [
    [
        'name' => 'se-api_v.4.0.5.yaml',
        'url' => 'https://www.gov.pl/attachment/89fa728d-0342-41e2-85d5-de4556ff80fa',
        'sha256' => 'c4e68778428a380f36c1a261e28c9b746edca36fccbc96780a49a17a6e0b8595',
        'extract' => 'docx-ole-zip',
        'yaml_sha256' => 'ae44baff4a43eae5316ab42b81394e07af9f431cb0267ffdc9c23dcc635f5927',
    ],
    [
        'name' => 'uaapi_3.0.8.2.yaml',
        'url' => 'https://edoreczenia.poczta-polska.pl/wp-content/uploads/2026/05/uaapi_3.0.8.2.zip',
        'sha256' => 'a33560ee56b10c91e46cfcc30b4567589614a0a3ee4a81679e0a799403056bd0',
        'extract' => 'zip',
        'yaml_sha256' => 'd6eb38dd5ecf1859346568bcedafdee5efdb48a584ef7f0db905975fe8b9aa83',
    ],
];

function fail(string $message): never
{
    fwrite(\STDERR, '[specs] ERROR: ' . $message . \PHP_EOL);

    exit(1);
}

function download(string $url): string
{
    if (!function_exists('curl_init')) {
        fail('ext-curl is required to download specifications.');
    }

    for ($attempt = 1; $attempt <= 4; ++$attempt) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 3,
            \CURLOPT_TIMEOUT => 60,
            \CURLOPT_USERAGENT => 'b44x-edoreczenia-php spec fetcher',
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);

        if (is_string($body) && $status === 200) {
            return $body;
        }

        fwrite(\STDERR, sprintf('[specs] attempt %d for %s failed (HTTP %s)%s', $attempt, $url, (string) $status, \PHP_EOL));
        sleep(2 ** $attempt);
    }

    fail('Unable to download ' . $url);
}

/**
 * Reads the single YAML file from a ZIP archive held in memory.
 */
function yamlFromZip(string $zipBytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'edor-spec');
    if ($path === false) {
        fail('Unable to create a temporary file.');
    }
    file_put_contents($path, $zipBytes);

    try {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            fail('Downloaded archive is not a valid ZIP file.');
        }
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('/\.ya?ml$/i', $name) === 1) {
                $content = $zip->getFromIndex($i);

                return is_string($content) ? $content : fail('Unable to read ' . $name);
            }
        }

        fail('No YAML file found in the archive.');
    } finally {
        unlink($path);
    }
}

/**
 * The SE API technical design (DOCX) embeds the contract as a ZIP inside an OLE "Package" object.
 * The ZIP bytes are located between the first local file header and the end-of-central-directory record.
 */
function yamlFromDocx(string $docxBytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'edor-docx');
    if ($path === false) {
        fail('Unable to create a temporary file.');
    }
    file_put_contents($path, $docxBytes);

    try {
        $docx = new ZipArchive();
        if ($docx->open($path) !== true) {
            fail('Downloaded document is not a valid DOCX file.');
        }
        $ole = $docx->getFromName('word/embeddings/oleObject1.bin');
        if (!is_string($ole)) {
            fail('DOCX has no embedded contract object (word/embeddings/oleObject1.bin).');
        }
    } finally {
        unlink($path);
    }

    $start = strpos($ole, "PK\x03\x04");
    $eocd = strrpos($ole, "PK\x05\x06");
    if ($start === false || $eocd === false || $eocd < $start) {
        fail('Embedded object does not contain a ZIP archive.');
    }
    $commentLength = unpack('v', substr($ole, $eocd + 20, 2));
    $length = $eocd - $start + 22 + (is_array($commentLength) && is_int($commentLength[1] ?? null) ? $commentLength[1] : 0);

    return yamlFromZip(substr($ole, $start, $length));
}

if (!class_exists(ZipArchive::class)) {
    fail('ext-zip is required.');
}
if (!is_dir(TARGET_DIR) && !mkdir(TARGET_DIR, 0o775, true)) {
    fail('Unable to create ' . TARGET_DIR);
}

$failures = 0;
foreach (SPECS as $spec) {
    $raw = download($spec['url']);
    $rawHash = hash('sha256', $raw);
    if ($rawHash !== $spec['sha256']) {
        fwrite(\STDERR, sprintf("[specs] CHANGED: %s\n  expected %s\n  actual   %s\n  The publisher updated this document. Review it before updating the pinned hash.\n", $spec['url'], $spec['sha256'], $rawHash));
        ++$failures;

        continue;
    }

    $yaml = $spec['extract'] === 'zip' ? yamlFromZip($raw) : yamlFromDocx($raw);
    if (hash('sha256', $yaml) !== $spec['yaml_sha256']) {
        fwrite(\STDERR, '[specs] Extracted YAML hash mismatch for ' . $spec['name'] . \PHP_EOL);
        ++$failures;

        continue;
    }

    file_put_contents(TARGET_DIR . '/' . $spec['name'], $yaml);
    fwrite(\STDOUT, '[specs] OK ' . $spec['name'] . \PHP_EOL);
}

exit($failures === 0 ? 0 : 1);
