<?php

namespace Phramark\Model\mysql;

/**
 * Platform map of phramark_article; the columns mirror
 * benchmark/fixtures/cms/typo3-seed.sql so benchmark/fixtures/cms/seed-articles.php
 * fills the table on every stack alike.
 */
class Article extends \Phramark\Model\Article
{
    public static $metaMap = [
        // Keyed like the addPackage() call (no trailing backslash) so xPDO
        // applies the package's empty table prefix instead of modx_.
        'package' => 'Phramark\\Model',
        'version' => '3.0',
        'table' => 'phramark_article',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => ['engine' => 'InnoDB'],
        'fields' => [
            'category' => 0,
            'title' => '',
            'introtext' => '',
            'alias' => '',
            'hero_image' => '',
            'author' => null,
            'reading_time' => null,
            'published_at' => 0,
        ],
        'fieldMeta' => [
            'category' => ['dbtype' => 'int', 'precision' => '10', 'attributes' => 'unsigned', 'phptype' => 'integer', 'null' => false, 'default' => 0],
            'title' => ['dbtype' => 'varchar', 'precision' => '255', 'phptype' => 'string', 'null' => false, 'default' => ''],
            'introtext' => ['dbtype' => 'text', 'phptype' => 'string', 'null' => false],
            'alias' => ['dbtype' => 'varchar', 'precision' => '64', 'phptype' => 'string', 'null' => false, 'default' => ''],
            'hero_image' => ['dbtype' => 'varchar', 'precision' => '255', 'phptype' => 'string', 'null' => false, 'default' => ''],
            'author' => ['dbtype' => 'varchar', 'precision' => '64', 'phptype' => 'string', 'null' => true],
            'reading_time' => ['dbtype' => 'int', 'precision' => '10', 'attributes' => 'unsigned', 'phptype' => 'integer', 'null' => true],
            'published_at' => ['dbtype' => 'int', 'precision' => '10', 'attributes' => 'unsigned', 'phptype' => 'integer', 'null' => false, 'default' => 0],
        ],
        'indexes' => [
            'category_published' => [
                'alias' => 'category_published',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => [
                    'category' => ['length' => '', 'collation' => 'A', 'null' => false],
                    'published_at' => ['length' => '', 'collation' => 'A', 'null' => false],
                ],
            ],
        ],
    ];
}
