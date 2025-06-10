<?php
function mapRowWithCasts($row, $casts = []) {
    $mapped = [];
    foreach ($row as $key => $value) {
        if (isset($casts[$key])) {
            if ($casts[$key] === 'int') $value = (int)$value;
            elseif ($casts[$key] === 'bool') $value = (bool)$value;
            elseif ($casts[$key] === 'nullable_int') $value = $value !== null ? (int)$value : null;
        }
        $mapped[$key] = $value;
    }
    return $mapped;
}
