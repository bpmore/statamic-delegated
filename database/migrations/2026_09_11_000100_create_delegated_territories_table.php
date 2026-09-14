<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A territory: a named scope of content that a department owns (spec §4).
 *
 * A structure branch ("everything under /cardiology"), a whole collection,
 * or a taxonomy term. Territories nest: /cardiology/clinical-trials inherits
 * from /cardiology unless it has a row of its own, and resolution walks up the
 * parent chain until it finds one (spec §4). Who is *in* a territory is not
 * here — that is delegated_assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegated_territories', function (Blueprint $table) {
            $table->id();

            $table->string('name', 128);
            // Stable handle for URLs, config and the audit log. Unique per
            // site, not globally: two sites can each have a "cardiology".
            $table->string('slug', 128);
            $table->string('site', 64);

            // structure_branch | collection | taxonomy_term. A string, not a
            // database enum, so a fourth kind is an application change and not
            // a migration on every site. Validated at the model.
            $table->string('scope_type', 32);
            // What the scope points at: a branch URI, a collection handle, or
            // a term id (taxonomy::slug). URIs are lower-cased before they get
            // here — the Phase 0.3 spike found that `=` and LIKE disagree
            // about case between drivers, and this column is compared with
            // both.
            $table->string('scope_ref', 512);

            // A territory whose parent is deleted becomes a root territory
            // rather than disappearing: its members keep what they had. What
            // happens to the *parent's* content is the doctor command's job
            // to report, not the database's to decide.
            $table->foreignId('parent_id')->nullable()
                ->constrained('delegated_territories')->nullOnDelete();

            $table->timestamps();

            $table->unique(['site', 'slug']);
            // One territory per scope. Resolution finds "the owning territory"
            // for a piece of content; two rows for the same branch would make
            // that ambiguous. Several groups sharing a branch is expressed as
            // several assignments to one territory, not two territories.
            $table->unique(['site', 'scope_type', 'scope_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegated_territories');
    }
};
