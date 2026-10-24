<?php

declare(strict_types=1);

// Finite active work, not a sleeping daemon or descendant process.
fwrite(STDOUT, 'started');
$until = hrtime(true) + 1_000_000_000;
for ($n = 0; $n < 1_000_000 && hrtime(true) < $until; $n++) {
    hash('sha256', str_repeat('x', 4096));
}
