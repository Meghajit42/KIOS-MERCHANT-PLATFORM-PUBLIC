<?php
declare(strict_types=1);
/** Exact immutable sequence, not a version-count shortcut. Qualification only. */
function qualifiedMigrationSequence(PDO $db):bool{
 $files=glob(dirname(__DIR__,2).'/database/migrations/*.sql')?:[];
 $expected=array_map('basename',$files);sort($expected,SORT_STRING);
 $actual=$db->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
 return $expected===$actual&&end($expected)==='037_customer_order_intents.sql';
}
