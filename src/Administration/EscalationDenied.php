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

    public static function roleManagement(): self
    {
        return new self(self::ROLE_MANAGEMENT, 'Cannot create or modify roles, or grant a role that could');
    }

    public static function ownTerritory(int $territoryId): self
    {
        return new self(self::OWN_TERRITORY, "Cannot widen territory {$territoryId}: the actor belongs to it");
    }

    public static function userNotVisible(string $userId): self
    {
        return new self(self::USER_NOT_VISIBLE, "Cannot act on user {$userId}: no assignment overlaps the actor's territories");
    }
}
