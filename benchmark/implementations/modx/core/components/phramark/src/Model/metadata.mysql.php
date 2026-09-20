<?php

// xPDO 3 package map, registered by the category snippet through
// $modx->addPackage('Phramark\Model', MODX_CORE_PATH . 'components/phramark/src/', '', 'Phramark\\').
// The empty table prefix keeps the table name phramark_article, the one the
// shared seeder fills on every stack.
$xpdo_meta_map = [
    'version' => '3.0',
    'namespace' => 'Phramark\\Model',
    'namespacePrefix' => 'Phramark',
    'class_map' => [
        'xPDO\\Om\\xPDOSimpleObject' => [
            'Phramark\\Model\\Article',
        ],
    ],
];
