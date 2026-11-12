<?php

use App\Kernel;

require_once __DIR__.'/restore_authorization.php';

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
