<?php

declare(strict_types=1);

for ($n = 0; $n < 1024; $n++) {
    fwrite(STDOUT, str_repeat('x', 8192));
}
fwrite(STDERR, str_repeat('e', 8192));
