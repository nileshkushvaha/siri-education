<?php

declare(strict_types=1);

namespace App\Reporting\Exceptions;

/** A rejected export (e.g. over the synchronous row limit). The message is safe to show to the administrator. */
final class ReportExportException extends \RuntimeException {}
