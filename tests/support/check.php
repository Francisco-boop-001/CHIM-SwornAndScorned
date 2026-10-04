<?php
declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejected(callable $call, string $message): void
{
    try {
        $call();
    } catch (UnexpectedValueException) {
        return;
    }
    throw new RuntimeException($message);
}
