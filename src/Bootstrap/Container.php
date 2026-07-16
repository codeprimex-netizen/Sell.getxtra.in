<?php

declare(strict_types=1);

namespace App\Bootstrap;

use Closure;
use RuntimeException;

/**
 * Lightweight PSR-11-style dependency injection container.
 *
 * Supports singleton bindings, factory bindings, instance registration,
 * and autowiring of concrete classes via constructor reflection.
 */
final class Container
{
    /** @var array<string, Closure> */
    private array $bindings = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    private static ?Container $instance = null;

    public static function getInstance(): Container
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(?Container $container): void
    {
        self::$instance = $container;
    }

    public function bind(string $id, Closure $factory, bool $shared = false): void
    {
        $this->bindings[$id] = $factory;
        $this->shared[$id] = $shared;
        unset($this->instances[$id]);
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->bind($id, $factory, true);
    }

    public function instance(string $id, mixed $object): void
    {
        $this->instances[$id] = $object;
        $this->shared[$id] = true;
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            $object = ($this->bindings[$id])($this);
            if ($this->shared[$id] ?? false) {
                $this->instances[$id] = $object;
            }
            return $object;
        }

        // Fall back to autowiring for concrete classes.
        if (class_exists($id)) {
            return $this->build($id);
        }

        throw new RuntimeException("Container has no binding for [{$id}].");
    }

    /**
     * Autowire a concrete class by resolving its constructor dependencies.
     *
     * @param class-string $class
     */
    public function build(string $class): object
    {
        $reflector = new \ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Class [{$class}] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $this->get($type->getName());
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                "Cannot resolve parameter [\${$param->getName()}] of [{$class}]."
            );
        }

        return $reflector->newInstanceArgs($dependencies);
    }
}
