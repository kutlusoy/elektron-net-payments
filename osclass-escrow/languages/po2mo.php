<?php
// Fallback .po -> .mo compiler for translators without gettext's own
// `msgfmt` installed. Prefer `msgfmt -o messages.mo messages.po` when it is
// available; this only handles simple msgid/msgstr pairs (no plural forms).
// Usage: php po2mo.php <locale>/messages.po <locale>/messages.mo

function parsePo(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $entries = [];
    $msgid = null;
    $msgstr = null;
    $mode = null;

    $flush = function () use (&$entries, &$msgid, &$msgstr) {
        if ($msgid !== null && $msgstr !== null) {
            $entries[$msgid] = $msgstr;
        }
        $msgid = null;
        $msgstr = null;
    };

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^msgid\s+"(.*)"$/', $line, $m)) {
            $flush();
            $msgid = stripcslashes($m[1]);
            $mode = 'id';
            continue;
        }
        if (preg_match('/^msgstr\s+"(.*)"$/', $line, $m)) {
            $msgstr = stripcslashes($m[1]);
            $mode = 'str';
            continue;
        }
        if (preg_match('/^"(.*)"$/', $line, $m)) {
            $chunk = stripcslashes($m[1]);
            if ($mode === 'id') {
                $msgid .= $chunk;
            } elseif ($mode === 'str') {
                $msgstr .= $chunk;
            }
        }
    }
    $flush();

    unset($entries['']); // header entry, gettext doesn't need it re-added for our simple reader
    return $entries;
}

function writeMo(array $entries, string $outPath): void
{
    ksort($entries);
    $keys = array_keys($entries);
    $values = array_values($entries);
    $count = count($keys);

    $koffsets = [];
    $voffsets = [];
    $kdata = '';
    $vdata = '';

    // Header (28 bytes) + 2 tables (4 * 4 bytes each per entry)
    $headerSize = 28;
    $tableSize = $count * 4 * 4;
    $offset = $headerSize + $tableSize;

    foreach ($keys as $k) {
        $koffsets[] = [strlen($k), $offset + strlen($kdata)];
        $kdata .= $k . "\0";
    }
    $keyBlockEnd = $offset + strlen($kdata);
    foreach ($values as $v) {
        $voffsets[] = [strlen($v), $keyBlockEnd + strlen($vdata)];
        $vdata .= $v . "\0";
    }

    $mo = pack('V', 0x950412de); // magic
    $mo .= pack('V', 0);         // revision
    $mo .= pack('V', $count);    // number of strings
    $mo .= pack('V', $headerSize);                 // offset of key table
    $mo .= pack('V', $headerSize + $count * 8);    // offset of value table
    $mo .= pack('V', 0); // hash table size
    $mo .= pack('V', 0); // hash table offset

    foreach ($koffsets as [$len, $off]) {
        $mo .= pack('V', $len) . pack('V', $off);
    }
    foreach ($voffsets as [$len, $off]) {
        $mo .= pack('V', $len) . pack('V', $off);
    }
    $mo .= $kdata . $vdata;

    file_put_contents($outPath, $mo);
}

$poPath = $argv[1];
$moPath = $argv[2];
writeMo(parsePo($poPath), $moPath);
echo "wrote {$moPath}\n";
