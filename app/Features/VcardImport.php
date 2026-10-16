<?php

namespace App\Features;

/**
 * vCard (.vcf) import with a review/merge screen (#14). Only useful
 * alongside BirthdayEngine — the import routes require both.
 */
class VcardImport extends StagedFeature
{
    public string $name = 'vcard-import';

    public string $description = 'Import people and birthdays from a .vcf contacts export (Phase 1a).';

    public Rollout $stage = Rollout::Admins;
}
