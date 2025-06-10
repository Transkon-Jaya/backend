<?php
require 'db.php';

function dynamicUpdate(string $table, array $data, $id, string $idColumn = 'id') {
    global $conn;

    $fields = [];
    $values = [];
    $types = '';

    foreach ($data as $key => $value) {
        $fields[] = "`$key` = ?";
        $values[] = $value;
        $types .= is_int($value) ? 'i' : 's'; // adjust as needed
    }

    $values[] = $id;
    $types .= is_int($id) ? 'i' : 's';

    $sql = "UPDATE `$table` SET " . implode(', ', $fields) . " WHERE `$idColumn` = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    return $stmt->affected_rows;
}

function dynamicInsert(string $table, array $data) {
    global $conn;

    $columns = array_keys($data);
    $placeholders = array_fill(0, count($data), '?');
    $values = array_values($data);
    $types = '';

    foreach ($values as $value) {
        $types .= is_int($value) ? 'i' : 's'; // basic type check
    }

    $sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    return $conn->insert_id;
}

function dynamicSelect(string $table, array $conditions = []) {
    global $conn;

    $whereClauses = [];
    $values = [];
    $types = '';

    foreach ($conditions as $key => $value) {
        $whereClauses[] = "`$key` = ?";
        $values[] = $value;
        $types .= is_int($value) ? 'i' : 's';
    }

    $sql = "SELECT * FROM `$table`";
    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(' AND ', $whereClauses);
    }
    echo json_encode($sql);
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    if (!empty($values)) {
        $stmt->bind_param($types, ...$values);
    }

    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}
