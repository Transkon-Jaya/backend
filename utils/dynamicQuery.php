<?php
require 'db.php';

function dynamicUpdate(string $table, array $data, $id, string $idColumn = 'id') {
    global $conn;

    $fields = [];
    $values = [];

    foreach ($data as $key => $value) {
        $fields[] = "`$key` = ?";
        $values[] = $value;
    }

    $values[] = $id; // for WHERE clause
    $sql = "UPDATE `$table` SET " . implode(', ', $fields) . " WHERE `$idColumn` = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt->execute($values)) {
        throw new Exception("Update failed: " . implode(', ', $stmt->errorInfo()));
    }

    return $stmt->rowCount();
}

function dynamicInsert(string $table, array $data) {
    global $conn;

    $columns = array_keys($data);
    $placeholders = array_fill(0, count($data), '?');
    $values = array_values($data);

    $sql = "INSERT INTO `$table` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $conn->prepare($sql);
    if (!$stmt->execute($values)) {
        throw new Exception("Insert failed: " . implode(', ', $stmt->errorInfo()));
    }

    return $conn->lastInsertId();
}

function dynamicSelect(string $table, array $conditions = [], string $idColumn = 'id') {
    global $conn;

    $whereClauses = [];
    $values = [];

    foreach ($conditions as $key => $value) {
        $whereClauses[] = "`$key` = ?";
        $values[] = $value;
    }

    $sql = "SELECT * FROM `$table`";
    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(' AND ', $whereClauses);
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt->execute($values)) {
        throw new Exception("Select failed: " . implode(', ', $stmt->errorInfo()));
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}