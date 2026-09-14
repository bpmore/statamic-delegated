<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Administration;

use RuntimeException;

/**
 * A delegated-administration action that would have given someone more
 * than the actor is allowed to give. One named constructor per invariant
 * (spec §4), so a test can assert *which* invariant refused and a log can
 * say so.
 *
 * Thrown before anything is written. An action that is refused leaves no
 * trace except, in time, an audit row saying it was refused.
 */
final class EscalationDenied extends RuntimeException
{
    public const ROLE_NOT_HELD = 'role_not_held';

    public const TERRITORY_NOT_ADMINISTERED = 'territory_not_administered';

    public const SUPER_USER = 'super_user';

    public const ROLE_MANAGEMENT = 'role_management';

    public const OWN_TERRITORY = 'own_territory';

    public const USER_NOT_VISIBLE = 'user_not_visible';

    public const USER_SHARED = 'user_shared';

    public const RESERVED_ATTRIBUTE = 'reserved_attribute';

    private function __construct(public readonly string $invariant, string $message)
    {
        parent::__construct($message);
    }

    /** @param  list<string>  $handles */
    public static function roleNotHeld(array $handles): self
    {
        return new self(self::ROLE_NOT_HELD, 'Cannot grant a role the actor does not hold: '.implode(', ', $handles));
    }

    public static function territoryNotAdministered(int $territoryId): self
    {
        return new self(self::TERRITORY_NOT_ADMINISTERED, "Cannot change territory {$territoryId}: the actor does not administer it");
    }

    public static function superUser(string $userId): self
    {
        return new self(self::SUPER_USER, "Cannot edit, demote or delete super user {$userId}");
    }

    /** The same flag, the other direction. */
    public static function promotion(string $userId): self
    {
        return new self(self::SUPER_USER, "Cannot make user {$userId} a super user");
    }

    public static function roleManagement(): self
    {
        return new self(self::ROLE_MANAGEMENT, 'Cannot create or modify roles, or grant a role that could');
    }

    public static function ownTerritory(int $territoryId, string $change): self
    {
        return new self(self::OWN_TERRITORY, "Cannot change '{$change}' of territory {$territoryId}: a territory's scope is immutable to the people who administer it");
    }

    public static function userNotVisible(string $userId): self
    {
        return new self(self::USER_NOT_VISIBLE, "Cannot act on user {$userId}: no assignment overlaps the actor's territories");
    }

    /**
     * Credentials and identity are not profile fields. A territory admin who
     * could set a colleague's password would own the account.
     */
    public static function reservedAttribute(string $userId, string $attribute): self
    {
        return new self(self::RESERVED_ATTRIBUTE, "Cannot set '{$attribute}' on user {$userId} through delegated administration");
    }

    /**
     * A refinement of invariant 6: a user who also belongs to a territory the
     * actor does not administer can be seen, but not changed — roles and
     * emails are global, and editing them reaches into the other department.
     */
    public static function userShared(string $userId): self
    {
        return new self(self::USER_SHARED, "Cannot change user {$userId}: they also belong to a territory the actor does not administer");
    }
}
