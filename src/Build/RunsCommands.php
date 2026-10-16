<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

/** A preset whose console commands go through one script (Laravel: artisan). */
interface RunsCommands
{
    /** Script relative to the app dir that `arco command "<args>"` runs as `php <script> <args>`. */
    public function commandEntry(): string;
}
