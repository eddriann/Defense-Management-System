<?php

function bind_stmt_params(mysqli_stmt $stmt, string $types, array $values): void
{
    if (strlen($types) !== count($values)) {
        throw new InvalidArgumentException('Prepared statement parameter types do not match parameter values.');
    }

    if ($types === '') {
        return;
    }

    $params = [$types];
    foreach ($values as &$value) {
        $params[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $params);
}

function request_filter_string(array $source, string $key): string
{
    $value = $source[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function is_valid_filter_date(string $value): bool
{
    return preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1
        && checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4));
}
