<?php
$p = dirname(__DIR__) . '/traf_docx/ntarf/2.1 [NON-TRAVEL] Activity Request Form.docx';
$z = new ZipArchive();
$z->open($p);
$x = $z->getFromName('word/document.xml');
$z->close();

$patterns = [
    'College/Office/Project',
    'combined',
];
$n = 0;
$offset = 0;
while (($pos = strpos($x, 'Name of Requester', $offset)) !== false) {
    $n++;
    echo "=== occurrence $n at $pos ===\n";
    echo substr($x, $pos - 30, 200), "\n\n";
    $offset = $pos + 1;
}
echo "Total occurrences: $n\n";

$combo = '&lt;&lt;Name of Requester&gt;&gt; (&lt;&lt;College/Office/Project&gt;&gt;)';
echo "Combo contiguous: " . (strpos($x, $combo) !== false ? 'yes' : 'no') . "\n";
