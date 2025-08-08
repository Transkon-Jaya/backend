<?php
header("Content-Type: application/json");
require '../db.php';
require '../auth.php';

$currentUser = authorize();
$currentName = $currentUser['name'] ?? 'system';
$method = $_SERVER['REQUEST_METHOD'];

$ta_id = $_GET['ta_id'] ?? ($_POST['ta_id'] ?? null);
$invoice_id = $_GET['invoice_id'] ?? ($_POST['invoice_id'] ?? null);

try {
    $conn->autocommit(false);

    // ========================
    // === POST (Create) ======
    // ========================
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) {
            throw new Exception("Input tidak valid", 400);
        }

        if (empty($ta_id)) {
            throw new Exception("TA ID harus disertakan", 400);
        }

        $sql = "INSERT INTO invoices_internal (
            ta_id, invoice_no, invoice_date, 
            acct, tax_dept, admin, expedisi, 
            remarks, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "sssiiiiss",
            $ta_id,
            $input['invoice_no'],
            $input['invoice_date'],
            $input['acct'] ? 1 : 0,
            $input['tax_dept'] ? 1 : 0,
            $input['admin'] ? 1 : 0,
            $input['expedisi'] ? 1 : 0,
            $input['remarks'] ?? null,
            $currentName
        );
        $stmt->execute();
        $invoice_id = $stmt->insert_id;

        $conn->commit();

        echo json_encode([
            "status" => 201,
            "message" => "Invoice berhasil ditambahkan",
            "invoice_id" => $invoice_id
        ]);
        exit;
    }

    // ====================
    // === PUT (Update) ===
    // ====================
    if ($method === 'PUT') {
        if (!$invoice_id) {
            throw new Exception("Invoice ID tidak valid", 400);
        }

        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Input tidak valid", 400);

        $sql = "UPDATE invoices_internal SET 
            invoice_no = ?,
            invoice_date = ?,
            acct = ?,
            tax_dept = ?,
            admin = ?,
            expedisi = ?,
            remarks = ?,
            updated_by = ?
            WHERE id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "ssiiiissi",
            $input['invoice_no'],
            $input['invoice_date'],
            $input['acct'] ? 1 : 0,
            $input['tax_dept'] ? 1 : 0,
            $input['admin'] ? 1 : 0,
            $input['expedisi'] ? 1 : 0,
            $input['remarks'] ?? null,
            $currentName,
            $invoice_id
        );
        $stmt->execute();

        $conn->commit();

        echo json_encode(["status" => 200, "message" => "Invoice berhasil diperbarui"]);
        exit;
    }

    // =====================
    // === DELETE ==========
    // =====================
    if ($method === 'DELETE') {
        if (!$invoice_id) {
            throw new Exception("Invoice ID tidak valid", 400);
        }

        $stmt = $conn->prepare("DELETE FROM invoices_internal WHERE id = ?");
        $stmt->bind_param("i", $invoice_id);
        $stmt->execute();

        $conn->commit();

        echo json_encode(["status" => 200, "message" => "Invoice berhasil dihapus"]);
        exit;
    }

    // ========================
    // === GET (by TA ID) ====
    // ========================
    if ($method === 'GET' && $ta_id) {
        $stmt = $conn->prepare("
            SELECT * FROM invoices_internal 
            WHERE ta_id = ?
            ORDER BY invoice_date DESC
        ");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $invoices = [];

        while ($row = $result->fetch_assoc()) {
            // Konversi boolean fields
            $row['acct'] = (bool)$row['acct'];
            $row['tax_dept'] = (bool)$row['tax_dept'];
            $row['admin'] = (bool)$row['admin'];
            $row['expedisi'] = (bool)$row['expedisi'];
            $invoices[] = $row;
        }

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "data" => $invoices
        ]);
        exit;
    }

    throw new Exception("Method tidak diizinkan", 405);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => $e->getCode() ?: 500,
        "error" => $e->getMessage()
    ]);
} finally {
    $conn->close();
}