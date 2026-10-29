<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

use RuntimeException;

/**
 * Every place where the config layer and arco.php set the same thing differently, collected before
 * one is reported. Each error is `config.layer_conflict` with params {kind, name}; values are never
 * part of an error, nor of the message.
 */
final class LayerConflicts extends RuntimeException
{
    /** @param list<array{key: string, params: array<string, string>}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", array_map(
            fn (array $error): string => $error['key'] . ' ' . json_encode($error['params']),
            $errors,
        )));
    }
}
