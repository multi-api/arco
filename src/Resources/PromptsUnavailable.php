<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use RuntimeException;

/** laravel/prompts could not ask in this terminal, its fallback neither: `arco resources` prints the listing instead. */
final class PromptsUnavailable extends RuntimeException
{
}
