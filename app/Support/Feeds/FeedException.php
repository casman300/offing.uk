<?php

namespace App\Support\Feeds;

use RuntimeException;

/**
 * Why a feed couldn't be fetched or read, worded for the subscriber: it's
 * shown in Settings. Never put the URL in the message — it's often a secret.
 */
final class FeedException extends RuntimeException {}
