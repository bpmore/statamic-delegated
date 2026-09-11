<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who belongs to a territory, and at what level (spec §4, §6).
 *
 * A subject is a user group or, though v1 recommends against it (spec §11), an
 * individual user. A member can work on the territory's content; an admin can
 * also add and remove members — and nothing else, which is what the six
 * escalation invariants in Phase 2 are about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegated_assignments', function (Blueprint $table) {
            $table->id();

            // Deleting a territory deletes its assignments. The audit log,
            // which has no such cascade, is where the history stays.
            $table->foreignId('territory_id')
                ->constrained('delegated_territories')->cascadeOnDelete();

            // user | group. Statamic ids are strings on the file driver
            // (UUIDs) and whatever the site chose on the Eloquent driver, so
            // no foreign key is possible and none is attempted.
            $table->string('subject_type', 8);
            $table->string('subject_id', 191);

            // member | admin
            $table->string('level', 8);

            // The user who made the assignment. Nullable because a seeder or
            // a console command has no user, and a territory admin who has
            // since been deleted must not take the assignment down with them.
            $table->string('granted_by', 191)->nullable();
            $table->dateTime('granted_at');

            // Spec §6. One row per subject per territory; changing a level is
            // an update, not a second row.
            $table->unique(['territory_id', 'subject_type', 'subject_id']);
            // The hot path (spec §6): "which territories is this user in" runs
            // on every listing render, and joins from here.
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegated_assignments');
    }
};
