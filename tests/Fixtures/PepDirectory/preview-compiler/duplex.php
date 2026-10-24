<?php

declare(strict_types=1);

for ($n = 0; $n < 256 && ! feof(STDIN); $n++) {
    $chunk = fread(STDIN, 8192);
    if ($chunk !== '') {
        fwrite(STDOUT, $chunk);
        fwrite(STDERR, 'e');
    }
}
