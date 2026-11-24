<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Notifuse\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Notifuse nie przyjął żądania albo nie odpowiedział. */
final class NotifuseException extends \RuntimeException
{
}
