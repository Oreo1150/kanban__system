<?php
// pages/production/production-status.php

require_once '../../config/database.php';

// สร้างการเชื่อมต่อฐานข้อมูล
$db = new Database();
$conn = $db->getConnection();

// ดึงข้อมูลงานผลิตพร้อมชื่อสินค้า
try {
    $sql = "
        SELECT 
            pj.job_id,
            pj.job_number,
            p.product_name,
            pj.quantity_planned,
            pj.quantity_produced,
            pj.status,
            pj.start_date,
            pj.end_date,
            pj.notes
        FROM production_jobs pj
        INNER JOIN products p ON pj.product_id = p.product_id
        ORDER BY pj.job_id DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $jobs = $stmt->fetchAll();
} catch (PDOException $e) {
    echo "Query error: " . $e->getMessage();
    die();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Production Status</title>
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
        }
        tr:hover {background-color: #f9f9f9;}
    </style>
</head>
<body>
    <h2>Production Status</h2>
    <table>
        <thead>
            <tr>
                <th>Job ID</th>
                <th>Job Number</th>
                <th>Product Name</th>
                <th>Planned Quantity</th>
                <th>Produced Quantity</th>
                <th>Status</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($jobs)): ?>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?= htmlspecialchars($job['job_id']) ?></td>
                        <td><?= htmlspecialchars($job['job_number']) ?></td>
                        <td><?= htmlspecialchars($job['product_name']) ?></td>
                        <td><?= htmlspecialchars($job['quantity_planned']) ?></td>
                        <td><?= htmlspecialchars($job['quantity_produced']) ?></td>
                        <td><?= htmlspecialchars($job['status']) ?></td>
                        <td><?= htmlspecialchars($job['start_date']) ?></td>
                        <td><?= htmlspecialchars($job['end_date']) ?></td>
                        <td><?= htmlspecialchars($job['notes']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9">ไม่มีข้อมูลงานผลิต</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
