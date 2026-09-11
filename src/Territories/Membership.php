<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Territories;

/**
 * Which territories a set of subjects is in, and at what level — after
 * inheritance down the hierarchy has been applied. The answer to "what can
 * this user reach", handed to the policy layer and the query compiler alike.
 */
final class Membership
{
    /**
     * @param  array<int, Level>  $levels  territory id => level
     */
    public function __construct(private readonly array $levels = []) {}

    public static function none(): self
    {
        return new self;
    }

    public function has(int $territoryId): bool
    {
        return isset($this->levels[$territoryId]);
    }

    public function isAdminOf(int $territoryId): bool
    {
        return ($this->levels[$territoryId] ?? null) === Level::Admin;
    }

    public function levelIn(int $territoryId): ?Level
    {
        return $this->levels[$territoryId] ?? null;
    }

    /** @return list<int> */
    public function territoryIds(): array
    {
        return array_keys($this->levels);
    }

    /** @return list<int> */
    public function administeredIds(): array
    {
        return array_keys(array_filter($this->levels, fn (Level $level): bool => $level === Level::Admin));
    }

    public function isEmpty(): bool
    {
        return $this->levels === [];
    }

    /**
     * Whether any of the given territories is one the subjects belong to.
     *
     * @param  list<Territory>  $territories
     */
    public function coversAny(array $territories): bool
    {
        foreach ($territories as $territory) {
            if ($this->has($territory->id)) {
                return true;
            }
        }

        return false;
    }
}
