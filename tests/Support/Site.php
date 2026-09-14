<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests\Support;

use Bpmore\StatamicDelegated\Administration\Actor;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Territories\Assignment;
use Illuminate\Support\Facades\DB;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

/**
 * Builds the people and territories a Phase 2 test needs, in one place, so
 * every invariant test reads as a scenario rather than a page of setup.
 *
 * Everything here is written to the sandboxed Stache (users, roles, groups)
 * or the in-memory database (territories, assignments) and is gone after the
 * test.
 */
final class Site
{
    /** @param  list<string>  $permissions */
    public static function role(string $handle, array $permissions = []): void
    {
        Role::make($handle)->title(ucfirst($handle))->permissions($permissions)->save();
    }

    /** @param  list<string>  $roles */
    public static function group(string $handle, array $roles = []): void
    {
        UserGroup::make()->handle($handle)->title(ucfirst($handle))->roles($roles)->save();
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $groups
     */
    public static function user(string $id, array $roles = [], array $groups = [], bool $super = false): UserContract
    {
        $user = User::make()->id($id)->email("{$id}@example.com");

        foreach ($roles as $role) {
            $user->assignRole($role);
        }
        foreach ($groups as $group) {
            $user->addToGroup($group);
        }
        if ($super) {
            $user->makeSuper();
        }

        $user->save();

        return $user;
    }

    public static function territory(string $slug, string $scopeRef, string $scopeType = 'structure_branch', ?int $parentId = null, string $site = 'default'): int
    {
        $id = (int) DB::table('delegated_territories')->insertGetId([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'site' => $site,
            'scope_type' => $scopeType,
            'scope_ref' => $scopeRef,
            'parent_id' => $parentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TerritoryResolver::class)->invalidate();

        return $id;
    }

    public static function assign(int $territoryId, string $subject, string $level = 'member', ?string $grantedBy = null): void
    {
        [$type, $id] = explode(':', $subject, 2);

        DB::table('delegated_assignments')->insert([
            'territory_id' => $territoryId,
            'subject_type' => $type,
            'subject_id' => $id,
            'level' => $level,
            'granted_by' => $grantedBy,
            'granted_at' => now(),
        ]);

        app(TerritoryResolver::class)->invalidate();
    }

    /**
     * The Actor a Statamic user is, computed the way the feature will have
     * to compute it: roles held directly or through a group, and the
     * subjects membership resolves from.
     */
    public static function actor(string $userId): Actor
    {
        $user = User::find($userId);

        $subjects = [Assignment::subject('user', $user->id())];
        foreach ($user->groups() as $group) {
            $subjects[] = Assignment::subject('group', $group->handle());
        }

        return new Actor(
            userId: $user->id(),
            isSuper: $user->isSuper(),
            roles: $user->roles()->map->handle()->values()->all(),
            subjects: $subjects,
        );
    }

    /** @return list<string> role handles, sorted */
    public static function rolesOf(string $userId): array
    {
        $roles = User::find($userId)->explicitRoles()->map->handle()->values()->all();
        sort($roles);

        return $roles;
    }

    /** @return list<array{territory_id:int, subject:string, level:string}> */
    public static function assignments(): array
    {
        return DB::table('delegated_assignments')->orderBy('id')->get()
            ->map(fn (object $row): array => [
                'territory_id' => (int) $row->territory_id,
                'subject' => $row->subject_type.':'.$row->subject_id,
                'level' => (string) $row->level,
            ])->all();
    }
}
