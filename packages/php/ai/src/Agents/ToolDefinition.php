<?php

namespace Starter\Ai\Agents;

use Laravel\Ai\Contracts\Tool;

/**
 * A tool that agents may be allowed to use.
 */
final class ToolDefinition
{
    /**
     * @param  class-string<Tool>  $class
     */
    public function __construct(
        public readonly string $name,
        public readonly string $class,
        public readonly string $group,
        public readonly string $label,
        public readonly ToolKind $kind,
        public readonly ?string $permission = null,
    ) {}

    /**
     * @return array{name: string, group: string, label: string, kind: string, permission: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'group' => $this->group,
            'label' => $this->label,
            'kind' => $this->kind->value,
            'permission' => $this->permission,
        ];
    }
}
