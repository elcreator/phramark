<?php

namespace Phramark\Model;

use xPDO\Om\xPDOSimpleObject;

/**
 * One row of the shared phramark_article fixture table, mapped as an xPDO
 * object so the category snippet reads it through MODX's own ORM
 * (xPDOQuery, getCollection, toArray) rather than a raw PDO statement.
 *
 * @property int    $category
 * @property string $title
 * @property string $introtext
 * @property string $alias
 * @property string $hero_image
 * @property string $author
 * @property int    $reading_time
 * @property int    $published_at
 */
class Article extends xPDOSimpleObject
{
}
