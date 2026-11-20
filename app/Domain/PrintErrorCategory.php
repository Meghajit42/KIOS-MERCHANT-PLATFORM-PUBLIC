<?php
declare(strict_types=1);

namespace Kios\Domain;

enum PrintErrorCategory: string
{
    case PrinterOffline = 'printer_offline';
    case PrinterNotFound = 'printer_not_found';
    case NoPrinterConfigured = 'no_printer_configured';
    case SpoolerError = 'spooler_error';
    case WriteFailed = 'write_failed';
    case RenderFailed = 'render_failed';
    case Timeout = 'timeout';
    case Unknown = 'unknown';
}
