<?php

namespace Starter\Ai\Agents;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;

/**
 * Tools that agents may be allowed to use. The application registers its
 * tools; an agent version can only allow registered names, and a tool is
 * offered to the model only when the actor holds its permission (the
 * underlying service authorizes again when it runs).
 */
class ToolRegistry
{
    /**
     * @var array<string, ToolDefinition>
     */
    protected array $tools = [];

    public function __construct(protected Container $container) {}

    public function register(ToolDefinition $definition): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $definition->name) !== 1) {
            throw new InvalidArgumentException("Invalid tool name [{$definition->name}].");
        }

        $this->tools[$definition->name] = $definition;
    }

    public function get(string $name): ?ToolDefinition
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<string, ToolDefinition>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Instantiate the allowed tools the actor may use.
     *
     * @param  list<string>  $names
     * @return list<Tool>
     */
    public function resolve(array $names, ToolContext $context): array
    {
        $tools = [];

        foreach ($names as $name) {
            $definition = $this->tools[$name] ?? null;

            if ($definition === null) {
                continue;
            }

            if ($definition->permission !== null && ! (method_exists($context->actor, 'can') && $context->actor->can($definition->permission))) {
                continue;
            }

            /** @var Tool $tool */
            $tool = $this->container->make($definition->class, ['context' => $context, 'definition' => $definition]);

            $tools[] = $tool;
        }

        return $tools;
    }
}
