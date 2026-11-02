<?php

// The classes lived under JGamboa\LaravelAuroraDsql up to v0.2.2 (jgamboa/laravel-aurora-dsql).
// The old names stay as aliases so existing code keeps working; they are removed in 1.0.
// Aliased eagerly, not from an autoloader: type checks and instanceof never autoload.
foreach ([
    'DsqlServiceProvider',
    'Database\DsqlConnection',
    'Database\DsqlQueryGrammar',
    'Database\DsqlSchemaGrammar',
    'Migrations\DsqlMigration',
] as $class) {
    if (! class_exists('JGamboa\LaravelAuroraDsql\\'.$class, false)) {
        class_alias('Multiapi\LaravelAuroraDsql\\'.$class, 'JGamboa\LaravelAuroraDsql\\'.$class);
    }
}
