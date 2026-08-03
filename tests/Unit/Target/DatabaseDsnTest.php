<?php

use App\Services\Target\Support\DatabaseDsn;

it('parses a mysql url into a pdo dsn', function (): void {
    $dsn = DatabaseDsn::parse('mysql://root:secret@127.0.0.1:3306/app');

    expect($dsn->driver)->toBe('mysql')
        ->and($dsn->host)->toBe('127.0.0.1')
        ->and($dsn->port)->toBe(3306)
        ->and($dsn->database)->toBe('app')
        ->and($dsn->username)->toBe('root')
        ->and($dsn->password)->toBe('secret')
        ->and($dsn->pdoDsn())->toBe('mysql:host=127.0.0.1;port=3306;dbname=app');
});

it('falls back to the default port of the driver', function (): void {
    expect(DatabaseDsn::parse('postgres://app@db/shop')->port)->toBe(5432)
        ->and(DatabaseDsn::parse('postgres://app@db/shop')->driver)->toBe('pgsql')
        ->and(DatabaseDsn::parse('mariadb://app@db/shop')->driver)->toBe('mysql');
});

it('decodes credentials so passwords may contain url separators', function (): void {
    $dsn = DatabaseDsn::parse('mysql://root:p%40ss%3Aword@127.0.0.1:3306/app');

    expect($dsn->password)->toBe('p@ss:word')
        ->and($dsn->host)->toBe('127.0.0.1');
});

it('never leaks the password in its label', function (): void {
    $label = DatabaseDsn::parse('mysql://root:secret@127.0.0.1:3306/app')->label();

    expect($label)->toBe('mysql://root@127.0.0.1:3306/app')
        ->not->toContain('secret');
});

it('reads sqlite paths, including windows drive letters', function (): void {
    expect(DatabaseDsn::parse('sqlite:///var/www/database.sqlite')->database)->toBe('/var/www/database.sqlite')
        ->and(DatabaseDsn::parse('sqlite:///C:/app/database.sqlite')->database)->toBe('C:/app/database.sqlite')
        ->and(DatabaseDsn::parse('sqlite:///C:/app/database.sqlite')->pdoDsn())->toBe('sqlite:C:/app/database.sqlite')
        ->and(DatabaseDsn::parse('sqlite:database.sqlite')->isSqlite())->toBeTrue();
});

it('rejects what it cannot connect to', function (string $value): void {
    expect(fn () => DatabaseDsn::parse($value))->toThrow(InvalidArgumentException::class);
})->with([
    'senza schema' => ['127.0.0.1:3306/app'],
    'driver ignoto' => ['oracle://app@db:1521/shop'],
    'senza database' => ['mysql://root@127.0.0.1:3306'],
    'sqlite senza path' => ['sqlite://'],
]);
