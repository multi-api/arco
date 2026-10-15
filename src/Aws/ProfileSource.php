<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

enum ProfileSource: string
{
    case Flag = 'flag';
    case Environment = 'environment';
    case Config = 'config';
    case Remembered = 'remembered';
    case Prompt = 'prompt';
}
