<?php
declare(strict_types=1);namespace Pam\Native\Observability;enum SignalKind:int{case Span=1;case Log=2;case Counter=3;case Gauge=4;case Crash=5;}
