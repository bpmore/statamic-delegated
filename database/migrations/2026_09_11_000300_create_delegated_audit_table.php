<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of who changed what in Delegated (spec §6).
 *
 * Append-only is a property of the code that writes here, not of the schema:
 * there is no updated_at, no foreign key that could cascade a delete, and the
 * model will expose no update or delete. A row must outlive the territory,
 * assignment or user it describes — that is the point of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegated_audit', function (Blueprint $table) {
            $table->id();

            // Nullable: a console command or a migration acts as nobody.
            $table->string('actor_id', 191)->nullable();
            // A short verb-noun handle: territory.created, assignment.revoked,
            // and so on. Free text so the set can grow without a migration.
            $table->string('action', 64);

            $table->string('target_type', 32);
            $table->string('target_id', 191)->nullable();

            // Which territory the change belongs to, so a territory admin can
            // be shown their own log and nobody else's (spec §11). Deliberately
            // not a foreign key: deleting a territory must leave the record of
            // its deletion behind, and nullOnDelete would rewrite a row that
            // is supposed to be immutable.
            $table->unsignedBigInteger('territory_id')->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->dateTime('created_at');

            // The per-territory log, newest first.
            $table->index(['territory_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegated_audit');
    }
};
