<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use RuntimeException;

/** Why a submitted tree could not be folded into the stored one. */
final class TreeMergeRefused extends RuntimeException
{
    public static function unknown(string $id): self
    {
        return new self("Page [{$id}] is not in this tree");
    }

    public static function outside(string $id): self
    {
        return new self("Page [{$id}] is outside your territory");
    }

    public static function missing(string $id): self
    {
        return new self("Page [{$id}] was left out; a page cannot be removed from the tree without being deleted");
    }

    public static function duplicate(string $id): self
    {
        return new self("Page [{$id}] appears twice");
    }

    public static function deletedAndKept(string $id): self
    {
        return new self("Page [{$id}] is both deleted and kept");
    }

    public static function moveOutside(string $id): self
    {
        return new self("Page [{$id}] can only be moved within your territory");
    }

    public static function placeholderMoved(string $id): self
    {
        return new self("Page [{$id}] is outside your territory and cannot be moved");
    }

    public static function deleteOutside(string $id): self
    {
        return new self("Page [{$id}] is outside your territory and cannot be deleted");
    }
}
