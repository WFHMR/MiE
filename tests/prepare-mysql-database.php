<?php

/**
 * Creates the throwaway database the suite is run against on MySQL.
 *
 * Dropped and recreated on every run so a previous run cannot leave state behind, and
 * deliberately separate from the shop's own database: HardResetTest wipes whatever it is
 * pointed at.
 *
 * Reads its connection details from the environment, which tests/run-mysql.ps1 fills in from
 * .env, so no credential is written down anywhere.
 */
$target = getenv( 'DB_DATABASE' );

if ( empty( $target ) ) {
    fwrite( STDERR, 'DB_DATABASE is not set.' . PHP_EOL );

    exit( 1 );
}

$pdo = new PDO(
    'mysql:host=' . getenv( 'DB_HOST' ) . ';port=' . getenv( 'DB_PORT' ),
    getenv( 'DB_USERNAME' ),
    getenv( 'DB_PASSWORD' )
);

$quote = chr( 96 );

$pdo->exec( 'DROP DATABASE IF EXISTS ' . $quote . $target . $quote );
$pdo->exec( 'CREATE DATABASE ' . $quote . $target . $quote . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );

echo 'Prepared ' . $target . PHP_EOL;
