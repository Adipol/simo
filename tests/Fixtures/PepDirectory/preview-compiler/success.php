<?php

declare(strict_types=1);

fwrite(STDOUT, stream_get_contents(STDIN));
fwrite(STDERR, 'diagnostic');
