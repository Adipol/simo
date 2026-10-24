<?php

declare(strict_types=1);

for ($n = 0; $n < 1025; $n++) {
    fwrite(STDOUT, str_repeat('x', 8192));
}
