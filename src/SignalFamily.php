<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

enum SignalFamily: int
{
    case Trace = 1;
    case Log = 2;
    case Metric = 3;
}
