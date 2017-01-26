<?php

return [
    'ERROR_REPORTING' => E_ALL,

    'APP_DIR' => dirname(__DIR__),

    //TODO настройка productionMode

    'DEV' => [
        \MicroMir\Error\Notifiers\HtmlNotifier::class => [
            'handleTrace'   => true,
            'minimizeTrace' => true,
            'stringLength'  => 80,
            'fontSize'      => 15,
        ],
    ],

    'PROD' => [

    ],

    'CLI'  => [
        \MicroMir\Error\Notifiers\CliNotifier::class => [],

    ],
];