<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

enum WireProtocol: int
{
    case PamJson = 1;
    case OtlpHttpJson = 2;
}
