<?php

namespace Espo\Modules\StudioManagement;

$mqAutoload = '/opt/studio-mq-vendor/autoload.php';

if (is_file($mqAutoload)) {
    require_once $mqAutoload;
}

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Core\Utils\Config\ConfigWriterFileManager;
use Espo\Modules\StudioManagement\Tools\AtomicStateWriter;
use Espo\Core\Utils\DataCache;
use Espo\Modules\StudioManagement\Tools\StableAclCache;

class Binding implements BindingProcessor
{
    public function process(Binder $binder): void
    {
        $binder->bindImplementation(ConfigWriterFileManager::class, AtomicStateWriter::class);
        $binder->bindImplementation(DataCache::class, StableAclCache::class);
    }
}
