<?php

namespace App\Support\Contacts;

use RuntimeException;

/**
 * A user-facing problem with an uploaded .vcf — the message is shown as-is.
 */
class VcardImportException extends RuntimeException {}
