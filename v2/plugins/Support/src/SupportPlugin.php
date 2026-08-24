<?php

declare(strict_types=1);

namespace Plugins\Support;

use Pmsrapi\V2\Plugin\AbstractPlugin;

/**
 * No-op entry class. This plugin contributes no services or routes of its
 * own — it exists so PluginManager discovers it and registers the
 * Plugins\Support\ namespace, making ShopContext available to any plugin
 * that needs shop-scoped routes.
 */
final class SupportPlugin extends AbstractPlugin
{
}
