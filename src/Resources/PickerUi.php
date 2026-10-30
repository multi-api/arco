<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

/** The questions the `arco resources` picker asks (spec §7); PromptsPickerUi asks them in a terminal, tests answer them. */
interface PickerUi
{
    public function confirm(string $label, bool $default): bool;

    /**
     * @param array<string, string> $options id => label
     * @param list<string> $default the ids ticked to begin with
     * @return list<string> the ticked ids
     */
    public function multiselect(string $label, array $options, array $default): array;

    public function text(string $label, string $default): string;

    /** @param array<string, string> $options value => label */
    public function select(string $label, array $options, string $default): string;
}
