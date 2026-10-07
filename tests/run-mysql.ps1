<#
.SYNOPSIS
    Runs the test suite against MySQL instead of SQLite.

.DESCRIPTION
    The suite normally runs on SQLite because it is fast and needs no server. That hides a
    whole class of problem, because the shop runs on MySQL: identifier length limits, strict
    modes, collation behaviour and how a float comes back from the driver all differ. An
    index name 66 characters long, which MySQL rejects outright, once shipped because SQLite
    accepted it.

    An isolated database is used and recreated from scratch on every run. That is not
    optional: the suite's reset test wipes whatever database it is pointed at, so running it
    against the shop's own database would destroy the catalogue and the sales history. This
    script refuses to touch the database named in .env.

    The generated PHPUnit configuration carries the database password, so it is written under
    storage/app (which is not tracked) and deleted afterwards.

.PARAMETER Database
    Name of the throwaway database. Created if missing, dropped and recreated each run.

.EXAMPLE
    ./tests/run-mysql.ps1
    ./tests/run-mysql.ps1 -Database nexopos_ci
#>
param(
    [string] $Database = 'nexopos_mysql_test'
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
Push-Location $root

try {
    $envFile = Get-Content .env -Raw

    function Read-EnvValue( [string] $name, [string] $fallback ) {
        $match = [regex]::Match( $envFile, "(?m)^$name=(.*)$" )

        if ( -not $match.Success ) {
            return $fallback
        }

        return $match.Groups[ 1 ].Value.Trim().Trim( '"' )
    }

    $host_ = Read-EnvValue 'DB_HOST' '127.0.0.1'
    $port = Read-EnvValue 'DB_PORT' '3306'
    $username = Read-EnvValue 'DB_USERNAME' 'root'
    $password = Read-EnvValue 'DB_PASSWORD' ''
    $working = Read-EnvValue 'DB_DATABASE' ''

    if ( $Database -eq $working ) {
        throw "Refusing to run: '$Database' is the database this installation works with. The suite would wipe it."
    }

    $env:DB_HOST = $host_
    $env:DB_PORT = $port
    $env:DB_USERNAME = $username
    $env:DB_PASSWORD = $password
    $env:DB_DATABASE = $Database

    php tests/prepare-mysql-database.php

    $configuration = 'storage/app/phpunit.mysql.xml'

    $xml = Get-Content phpunit.xml -Raw

    <#
        PHPUnit resolves relative paths against the directory holding the configuration, and
        this copy lives under storage/app. The paths are made absolute rather than moving the
        file to the project root, because it carries the database password and the root is
        tracked.
    #>
    $xml = $xml -replace 'bootstrap="vendor/autoload.php"', ( 'bootstrap="' + ( Join-Path $root 'vendor/autoload.php' ).Replace( '\', '/' ) + '"' )
    $xml = $xml -replace '\./tests/Feature', ( Join-Path $root 'tests/Feature' ).Replace( '\', '/' )
    $xml = $xml -replace '<directory>app</directory>', ( '<directory>' + ( Join-Path $root 'app' ).Replace( '\', '/' ) + '</directory>' )
    $xml = $xml -replace 'outputDirectory="tests/\.coverage"', ( 'outputDirectory="' + ( Join-Path $root 'tests/.coverage' ).Replace( '\', '/' ) + '"' )

    $xml = $xml -replace '<server name="DB_CONNECTION" value="sqlite"/>', '<server name="DB_CONNECTION" value="mysql"/>'
    $xml = $xml -replace '<server name="DB_DATABASE" value="tests/database.sqlite"/>', ( '<server name="DB_DATABASE" value="' + $Database + '"/>' )
    $xml = $xml -replace '<server name="DB_HOST" value=""/>', ( '<server name="DB_HOST" value="' + $host_ + '"/>' )
    $xml = $xml -replace '<server name="DB_USERNAME" value=""/>', ( '<server name="DB_USERNAME" value="' + $username + '"/>' )
    $xml = $xml -replace '<server name="DB_PASSWORD" value=""/>', ( '<server name="DB_PASSWORD" value="' + $password + '"/>' )

    Set-Content -Path $configuration -Value $xml -NoNewline

    try {
        <#
            Laravel's test command adds a --configuration of its own when it finds
            phpunit.xml, so PHPUnit warns that the option appears twice. The one passed here
            is the one that takes effect; the throwaway database holds the schema afterwards,
            which is how to confirm the run really went to MySQL.
        #>
        php artisan test --compact --configuration=$configuration @args
    }
    finally {
        Remove-Item $configuration -ErrorAction SilentlyContinue
    }
}
finally {
    Pop-Location
}
