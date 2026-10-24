<?php

declare(strict_types=1);

fwrite(STDOUT, 'partial');
fwrite(STDERR, 'secret');
exit(7);
