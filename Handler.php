<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2023-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13\Interfaces;

use \ArrayIterator;
use \Traversable;

interface HandlerInterface
{
    public function get(): array;
    public function set(array $handlers): static;
    public function pull(int|string $index, ?callable $default = null): array;
    public function fill(array $commands, array $handlers): static;
    public function pushHandler(callable $callback, int|string|null $command = null): static;
    public function count(): int;
    public function first(): array;
    public function last(): array;
    public function isset(int|string $offset): bool;
    public function has(array ...$indexes): bool;
    public function filter(callable $callback): static;
    public function find(callable $callback): array;
    public function clear(): static;
    public function map(callable $callback): static;
    public function merge(object $handler): static;
    public function toArray(): array;
    public function offsetExists(int|string $offset): bool;
    public function offsetGet(int|string $offset): array;
    public function offsetSet(int|string $offset, callable $callback): static;
    public function getIterator(): Traversable;
    public function __debugInfo(): array;
}

namespace Civ13;

use Civ13\Interfaces\HandlerInterface;
use \ArrayIterator;
use \Traversable;

class Handler implements HandlerInterface
{
    protected array $handlers = [];
    
    public function __construct(array $handlers = [])
    {
        $this->handlers = $handlers;
    }
    
    public function get(): array
    {
        return [$this->handlers];
    }
    
    public function set(array $handlers): static
    {
        $this->handlers = $handlers;
        return $this;
    }

    public function pull(int|string $index, ?callable $default = null): array
    {
        if (isset($this->handlers[$index])) {
            $default = $this->handlers[$index];
            unset($this->handlers[$index]);
        }

        return [$default];
    }

    public function fill(array $commands, array $handlers): static
    {
        if (count($commands) !== count($handlers)) {
            throw new \Exception('Commands and Handlers must be the same length.');
            return $this;
        }
        foreach ($handlers as $handler) $this->pushHandler($handler);
        return $this;
    }

    public function pushHandler(callable $callback, int|string|null $command = null): static
    {
        if ($command) $this->handlers[$command] = $callback;
        else $this->handlers[] = $callback;
        return $this;
    }

    public function count(): int
    {
        return count($this->handlers);
    }

    public function first(): array
    {
        return [array_shift(array_shift($this->toArray()) ?? [])];
    }
    
    public function last(): array
    {
        return [array_pop(array_shift($this->toArray()) ?? [])];
    }

    public function isset(int|string $offset): bool
    {
        return $this->offsetExists($offset);
    }
    
    public function has(array ...$indexes): bool
    {
        foreach ($indexes as $index)
            if (! isset($this->handlers[$index]))
                return false;
        return true;
    }
    
    public function filter(callable $callback): static
    {
        $static = new static([]);
        foreach ($this->handlers as $command => $handler)
            if ($callback($handler))
                $static->pushHandler($handler, $command);
        return $static;
    }
    
    public function find(callable $callback): array
    {
        foreach ($this->handlers as $handler)
            if ($callback($handler))
                return [$handler];
        return [];
    }

    public function clear(): static
    {
        $this->handlers = [];
        return $this;
    }

    public function map(callable $callback): static
    {
        return new static(array_combine(array_keys($this->handlers), array_map($callback, array_values($this->handlers))));
    }
    
    /**
     * @throws Exception if toArray property does not exist
     */
    public function merge(object $handler): static
    {
        if (! property_exists($handler, 'toArray')) {
            throw new \Exception('Handler::merge() expects parameter 1 to be an object with a method named "toArray", ' . gettype($handler) . ' given');
            return $this;
        }
        $toArray = $handler->toArray();
        $this->handlers = array_merge($this->handlers, array_shift($toArray));
        return $this;
    }
    
    public function toArray(): array
    {
        return [$this->handlers];
    }
    
    public function offsetExists(int|string $offset): bool
    {
        return isset($this->handlers[$offset]);
    }

    public function offsetGet(int|string $offset): array
    {
        return [$this->handlers[$offset] ?? null];
    }
    
    public function offsetSet(int|string $offset, callable $callback): static
    {
        $this->handlers[$offset] = $callback;
        return $this;
    }

    public function setOffset(int|string $newOffset, callable $callback): static
    {
        if ($offset = $this->getOffset($callback) === false) $offset = $newOffset;
        unset($this->handlers[$offset]);
        $this->handlers[$newOffset] = $callback;
        return $this;
    }
    
    public function getOffset(callable $callback): int|string|false
    {
        return array_search($callback, $this->handlers);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->handlers);
    }

    public function __debugInfo(): array
    {
        return ['handlers' => array_keys($this->handlers)];
    }
}