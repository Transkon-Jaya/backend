<?php
header("Content-Type: application/json");
require 'db.php';     
require 'auth.php';     

//authorize(9, ["admin_asset"], [], null);
$user = verifyToken();

$method = $_SERVER['REQUEST_METHOD'];

try {
   if ($method === 'GET' && isset($_GET['ta_id'])) {
    $ta_id = $_GET['ta_id'];

    $sql = "SELECT id, ta_id, no_urut, doc_desc, remarks, created_at, created_by
            FROM transmittal_documents
            WHERE ta_id = ?
            ORDER BY no_urut ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            "status" => 500,
            "error" => "Prepare failed: " . $conn->error
        ]);
        exit;
    }

    // Bind parameter
    $stmt->bind_param("s", $ta_id);

    // Eksekusi query
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode([
            "status" => 500,
            "error" => "Execute failed: " . $stmt->error
        ]);
        exit;
    }

    // Ambil hasil (fallback kalau get_result() tidak tersedia)
    $docs = [];
    $res = $stmt->get_result();
    if ($res) {
        $docs = $res->fetch_all(MYSQLI_ASSOC);
    } else {
        // fallback bind_result
        $stmt->store_result();
        $stmt->bind_result($id, $ta_id_db, $no_urut, $doc_desc, $remarks, $created_at, $created_by);
        while ($stmt->fetch()) {
            $docs[] = [
                "id" => $id,
                "ta_id" => $ta_id_db,
                "no_urut" => $no_urut,
                "doc_desc" => $doc_desc,
                "remarks" => $remarks,
                "created_at" => $created_at,
                "created_by" => $created_by
            ];
        }
    }

    $stmt->close();

    echo json_encode([
        "status" => 200,
        "data" => $docs
    ]);
    exit;
}

}
