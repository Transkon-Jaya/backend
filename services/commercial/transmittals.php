<?php
// Aktifkan error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

// Mulai output buffering untuk mencegah output sebelum JSON
ob_start();

// Header JSON
header("Content-Type: application/json");

// Sertakan koneksi dan otorisasi
require_once 'db.php';
require_once 'auth.php';

try {
    // Pastikan koneksi database tidak error
    if ($conn->connect_error) {
        throw new Exception("Koneksi database gagal: " . $conn->connect_error, 500);
    }

    // Otorisasi pengguna
    $currentUser = authorize();
    $currentName = $currentUser['name'] ?? 'system';
    if (empty($currentName)) {
        $currentName = 'system';
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $ta_id = $_GET['ta_id'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 12)));
    $search = $_GET['search'] ?? null;
    $status = $_GET['status'] ?? null;

    // Mulai transaksi
    $conn->begin_transaction();

    // === CREATE (POST) ===
    if ($method === 'POST' && !$ta_id) {
        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) {
            throw new Exception("Input tidak valid: bukan JSON atau format salah", 400);
        }

        // Validasi field wajib
        $required = ['date', 'from_origin'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || trim($input[$field]) === '') {
                throw new Exception("Field '$field' wajib diisi", 400);
            }
        }

        // Validasi ras_status
        $validStatus = ['', 'Pending', 'Received', 'In Transit', 'Delivered'];
        if (isset($input['ras_status']) && $input['ras_status'] !== '' && !in_array($input['ras_status'], $validStatus)) {
            throw new Exception("ras_status harus salah satu dari: " . implode(', ', $validStatus), 400);
        }

        // Auto-generate TA ID: TRJA002000, TRJA002001, ...
        if (!isset($input['ta_id']) || $input['ta_id'] === '' || $input['ta_id'] === null) {
            $prefix = "TRJA";
            $nextNum = 2000;

            $stmt = $conn->prepare("SELECT MAX(ta_id) FROM transmittals WHERE ta_id LIKE ?");
            if ($stmt) {
                $pattern = $prefix . '%';
                $stmt->bind_param("s", $pattern);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $row = $result->fetch_row();
                    $lastId = $row[0] ?? null;
                    if ($lastId && preg_match('/^TRJA(\d+)$/', $lastId, $matches)) {
                        $nextNum = (int)$matches[1] + 1;
                    }
                }
                $stmt->close();
            }

            $input['ta_id'] = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        }

        // Validasi akhir ta_id
        if (empty($input['ta_id']) || !preg_match('/^TRJA\d{6}$/', $input['ta_id'])) {
            throw new Exception("Gagal generate TA ID valid", 500);
        }

        // Cek duplikat ta_id
        $stmt = $conn->prepare("SELECT 1 FROM transmittals WHERE ta_id = ?");
        $stmt->bind_param("s", $input['ta_id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("TA ID sudah ada: " . $input['ta_id'], 400);
        }
        $stmt->close();

        // Siapkan data
        $createdBy = $currentName ?: 'system';

        // Insert ke transmittals
        $sql = "INSERT INTO transmittals (
            ta_id, date, from_origin, document_type, attention, 
            company, address, state, awb_reg, expeditur, receiver_name, receive_date, ras_status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Gagal prepare query transmittal: " . $conn->error, 500);
        }

        $stmt->bind_param(
            "ssssssssssssss",
            $input['ta_id'],
            $input['date'],
            $input['from_origin'],
            $input['document_type'] ?? null,
            $input['attention'] ?? '',
            $input['company'] ?? '',
            $input['address'] ?? '',
            $input['state'] ?? '',
            $input['awb_reg'] ?? '',
            $input['expeditur'] ?? '',
            $input['receiver_name'] ?? null,
            $input['receive_date'] ?? null,
            $input['ras_status'] ?? null,
            $createdBy
        );

        if (!$stmt->execute()) {
            throw new Exception("Gagal simpan transmittal: " . $stmt->error, 500);
        }
        $stmt->close();

        // Insert dokumen jika ada
        if (!empty($input['doc_details']) && is_array($input['doc_details'])) {
            $docStmt = $conn->prepare("INSERT INTO transmittal_documents (ta_id, no_urut, doc_desc, remarks, created_by) VALUES (?, ?, ?, ?, ?)");
            if (!$docStmt) {
                throw new Exception("Gagal prepare query dokumen: " . $conn->error, 500);
            }

            foreach ($input['doc_details'] as $doc) {
                if (!isset($doc['no_urut']) || !isset($doc['doc_desc'])) {
                    continue;
                }

                $remarks = $doc['remarks'] ?? '';
                $docStmt->bind_param(
                    "sisss",
                    $input['ta_id'],
                    (int)$doc['no_urut'],
                    $doc['doc_desc'],
                    $remarks,
                    $createdBy
                );

                if (!$docStmt->execute()) {
                    throw new Exception("Gagal simpan dokumen: " . $docStmt->error, 500);
                }
            }
            $docStmt->close();
        }

        $conn->commit();

        http_response_code(201);
        echo json_encode([
            "status" => 201,
            "message" => "Transmittal berhasil dibuat",
            "ta_id" => $input['ta_id']
        ]);
        exit;
    }

    // === UPDATE (PUT) ===
    if ($method === 'PUT') {
        if (!$ta_id) {
            throw new Exception("TA ID tidak diberikan", 400);
        }

        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) {
            throw new Exception("Input tidak valid", 400);
        }

        // Validasi ras_status
        if (isset($input['ras_status']) && $input['ras_status'] !== '') {
            $validStatus = ['Pending', 'Received', 'In Transit', 'Delivered'];
            if (!in_array($input['ras_status'], $validStatus)) {
                throw new Exception("ras_status tidak valid", 400);
            }
        }

        // Cek eksistensi
        $stmt = $conn->prepare("SELECT 1 FROM transmittals WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            throw new Exception("Transmittal tidak ditemukan", 404);
        }
        $stmt->close();

        // Siapkan field untuk update
        $fields = ['date', 'from_origin', 'document_type', 'attention', 'company', 'address', 'state', 'awb_reg', 'receiver_name', 'expeditur', 'receive_date', 'ras_status'];
        $setParts = [];
        $params = [];
        $types = '';

        foreach ($fields as $f) {
            if (isset($input[$f])) {
                $setParts[] = "$f = ?";
                $params[] = $input[$f];
                $types .= 's';
            }
        }

        if (empty($setParts)) {
            throw new Exception("Tidak ada data untuk diperbarui", 400);
        }

        $setParts[] = "updated_by = ?";
        $params[] = $currentName;
        $types .= 's';

        $params[] = $ta_id;
        $types .= 's';

        $sql = "UPDATE transmittals SET " . implode(', ', $setParts) . " WHERE ta_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Gagal prepare update: " . $conn->error, 500);
        }

        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new Exception("Update gagal: " . $stmt->error, 500);
        }
        $stmt->close();

        // Update dokumen: hapus dan insert baru
        if (isset($input['doc_details'])) {
            $delStmt = $conn->prepare("DELETE FROM transmittal_documents WHERE ta_id = ?");
            $delStmt->bind_param("s", $ta_id);
            if (!$delStmt->execute()) {
                throw new Exception("Gagal hapus dokumen lama", 500);
            }
            $delStmt->close();

            if (!empty($input['doc_details'])) {
                $insStmt = $conn->prepare("INSERT INTO transmittal_documents (ta_id, no_urut, doc_desc, remarks, created_by) VALUES (?, ?, ?, ?, ?)");
                if (!$insStmt) {
                    throw new Exception("Gagal prepare insert dokumen", 500);
                }

                foreach ($input['doc_details'] as $doc) {
                    if (!isset($doc['no_urut']) || !isset($doc['doc_desc'])) continue;

                    $insStmt->bind_param(
                        "sisss",
                        $ta_id,
                        (int)$doc['no_urut'],
                        $doc['doc_desc'],
                        $doc['remarks'] ?? '',
                        $currentName
                    );
                    if (!$insStmt->execute()) {
                        throw new Exception("Gagal simpan dokumen: " . $insStmt->error, 500);
                    }
                }
                $insStmt->close();
            }
        }

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => 200,
            "message" => "Transmittal berhasil diperbarui"
        ]);
        exit;
    }

    // === DELETE ===
    if ($method === 'DELETE') {
        if (!$ta_id) {
            throw new Exception("TA ID tidak diberikan", 400);
        }

        $stmt = $conn->prepare("DELETE FROM transmittal_documents WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM transmittals WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception("Transmittal tidak ditemukan", 404);
        }
        $stmt->close();

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => 200,
            "message" => "Transmittal berhasil dihapus"
        ]);
        exit;
    }

    // === GET SINGLE ===
    if ($method === 'GET' && $ta_id) {
        $stmt = $conn->prepare("SELECT * FROM transmittals WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        $trans = $stmt->get_result()->fetch_assoc();
        if (!$trans) {
            throw new Exception("Transmittal tidak ditemukan", 404);
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT * FROM transmittal_documents WHERE ta_id = ? ORDER BY no_urut");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        $docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $trans['doc_details'] = $docs;

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => 200,
            "data" => $trans
        ]);
        exit;
    }

    // === GET LIST ===
    if ($method === 'GET') {
        $sql = "
            SELECT 
                t.ta_id, t.date, t.from_origin, t.document_type, t.attention, t.company, t.ras_status,
                COUNT(d.id) as document_count, t.created_by, t.created_at
            FROM transmittals t
            LEFT JOIN transmittal_documents d ON t.ta_id = d.ta_id
            WHERE 1=1
        ";

        $params = [];
        $types = '';

        if ($search) {
            $term = "%$search%";
            $sql .= " AND (t.ta_id LIKE ? OR t.from_origin LIKE ? OR t.document_type LIKE ? OR t.company LIKE ?)";
            array_push($params, $term, $term, $term, $term);
            $types .= 'ssss';
        }
        if ($status) {
            $sql .= " AND t.ras_status = ?";
            $params[] = $status;
            $types .= 's';
        }

        $sql .= " GROUP BY t.ta_id ORDER BY t.date DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = ($page - 1) * $limit;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Gagal prepare query list: " . $conn->error, 500);
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Hitung total
        $countSql = "SELECT COUNT(*) as total FROM transmittals t WHERE 1=1";
        $countParams = [];
        $countTypes = '';

        if ($search) {
            $term = "%$search%";
            $countSql .= " AND (t.ta_id LIKE ? OR t.from_origin LIKE ? OR t.document_type LIKE ? OR t.company LIKE ?)";
            array_push($countParams, $term, $term, $term, $term);
            $countTypes .= 'ssss';
        }
        if ($status) {
            $countSql .= " AND t.ras_status = ?";
            $countParams[] = $status;
            $countTypes .= 's';
        }

        $countStmt = $conn->prepare($countSql);
        if (!$countStmt) {
            throw new Exception("Gagal prepare count: " . $conn->error, 500);
        }
        $countStmt->bind_param($countTypes, ...$countParams);
        $countStmt->execute();
        $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => 200,
            "data" => [
                "items" => $items,
                "totalCount" => $total,
                "page" => $page,
                "limit" => $limit,
                "totalPages" => ceil($total / $limit)
            ]
        ]);
        exit;
    }

    // Method tidak diizinkan
    throw new Exception("Method tidak diizinkan", 405);

} catch (Throwable $t) {
    // Tangkap semua error (termasuk fatal)
    ob_end_clean();
    if (isset($conn)) {
        $conn->rollback();
    }
    http_response_code(500);
    echo json_encode([
        "error" => "Fatal: " . $t->getMessage(),
        "file" => $t->getFile(),
        "line" => $t->getLine()
    ]);
    exit;
} catch (Exception $e) {
    // Tangkap exception
    if (isset($conn) && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "error" => $e->getMessage()
    ]);
    exit;
}