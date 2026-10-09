<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/pdf.php';

$pdf = new SimplePDF('Escapes (and) \\ works', 'Test');
$pdf->text('value (with) \\ delimiters');
for ($i = 0; $i < 60; $i++) {
    $pdf->text('line ' . $i);
}
$data = $pdf->output();

assert(str_starts_with($data, "%PDF-1.4\n"), 'PDF header');
assert(str_ends_with($data, "%%EOF\n"), 'PDF EOF marker');
assert(str_contains($data, 'value \\(with\\) \\\\ delimiters'), 'PDF string escaping');

preg_match('/xref\n0 (\d+)\n(.*)trailer/s', $data, $match);
assert($match !== [], 'xref exists');
$size = (int) $match[1];
$lines = explode("\n", trim($match[2]));
assert(count($lines) === $size, 'xref entry count');
assert($lines[0] === '0000000000 65535 f ', 'xref free entry');
for ($id = 1; $id < $size; $id++) {
    $offset = (int) substr($lines[$id], 0, 10);
    assert(substr($data, $offset, strlen($id . ' 0 obj')) === $id . ' 0 obj', "xref offset {$id}");
}

fwrite(STDOUT, "pdf: OK (" . ($size - 1) . " objects)\n");
