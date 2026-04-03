<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header('Location: ../index.php');
    exit();
}
include('../connect.php');

// ── KPI Queries ──────────────────────────────────────────────
// Total incidents
$total_result = $conn->query("SELECT COUNT(*) AS total FROM incidents");
$total = $total_result ? $total_result->fetch_assoc()['total'] : 0;

// Resolved incidents
$resolved_result = $conn->query("SELECT COUNT(*) AS resolved FROM incidents WHERE status = 'resolved'");
$resolved = $resolved_result ? $resolved_result->fetch_assoc()['resolved'] : 0;

// Pending incidents
$pending_result = $conn->query("SELECT COUNT(*) AS pending FROM incidents WHERE status = 'pending'");
$pending = $pending_result ? $pending_result->fetch_assoc()['pending'] : 0;

// Resolution rate
$resolution_rate = $total > 0 ? round(($resolved / $total) * 100) : 0;

// Incidents by category
$category_result = $conn->query("SELECT category, COUNT(*) AS count FROM incidents GROUP BY category");
$categories = [];
$cat_counts  = [];
if ($category_result) {
    while ($row = $category_result->fetch_assoc()) {
        $categories[] = $row['category'];
        $cat_counts[]  = (int)$row['count'];
    }
}

// Monthly incidents (last 6 months)
$monthly_result = $conn->query("
    SELECT DATE_FORMAT(created_at, '%b %Y') AS month, COUNT(*) AS count
    FROM incidents
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%b %Y')
    ORDER BY MIN(created_at)
");
$months       = [];
$month_counts = [];
if ($monthly_result) {
    while ($row = $monthly_result->fetch_assoc()) {
        $months[]       = $row['month'];
        $month_counts[] = (int)$row['count'];
    }
}

// Status breakdown for doughnut
$status_result = $conn->query("SELECT status, COUNT(*) AS count FROM incidents GROUP BY status");
$statuses      = [];
$status_counts = [];
if ($status_result) {
    while ($row = $status_result->fetch_assoc()) {
        $statuses[]      = ucfirst($row['status']);
        $status_counts[] = (int)$row['count'];
    }
}

// Fallback demo data if DB tables not set up yet
if ($total == 0) {
    $total           = 42;
    $resolved        = 28;
    $pending         = 14;
    $resolution_rate = 67;
    $categories      = ['Accident', 'Criminal', 'Health', 'Fire', 'Other'];
    $cat_counts      = [12, 9, 8, 7, 6];
    $months          = ['Nov 2025', 'Dec 2025', 'Jan 2026', 'Feb 2026', 'Mar 2026', 'Apr 2026'];
    $month_counts    = [5, 8, 6, 10, 7, 6];
    $statuses        = ['Pending', 'Resolved', 'In Progress'];
    $status_counts   = [14, 28, 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KPI Dashboard – Admin</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            color: #333;
        }

        /* ── Top bar ── */
        .topbar {
            background: #c0392b;
            color: #fff;
            padding: 16px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .topbar h1 { font-size: 20px; font-weight: 600; letter-spacing: 0.5px; }
        .topbar a {
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            background: rgba(255,255,255,0.2);
            padding: 6px 14px;
            border-radius: 4px;
        }
        .topbar a:hover { background: rgba(255,255,255,0.35); }

        /* ── Page wrapper ── */
        .wrapper { max-width: 1100px; margin: 0 auto; padding: 28px 20px; }

        .page-title {
            font-size: 22px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 6px;
        }
        .page-sub {
            font-size: 13px;
            color: #777;
            margin-bottom: 28px;
        }

        /* ── KPI cards ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .kpi-card {
            background: #fff;
            border-radius: 8px;
            padding: 20px 22px;
            border-left: 4px solid #ccc;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07);
        }
        .kpi-card.red    { border-color: #c0392b; }
        .kpi-card.green  { border-color: #27ae60; }
        .kpi-card.orange { border-color: #e67e22; }
        .kpi-card.blue   { border-color: #2980b9; }

        .kpi-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #888;
            margin-bottom: 8px;
        }
        .kpi-value {
            font-size: 36px;
            font-weight: 700;
            color: #2c3e50;
            line-height: 1;
        }
        .kpi-value span { font-size: 18px; color: #aaa; }

        /* ── Charts grid ── */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .chart-card {
            background: #fff;
            border-radius: 8px;
            padding: 20px 22px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07);
        }
        .chart-card.full { grid-column: 1 / -1; }
        .chart-title {
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 16px;
        }
        canvas { max-height: 260px; }

        /* ── Footer ── */
        .footer {
            text-align: center;
            font-size: 12px;
            color: #aaa;
            margin-top: 32px;
            padding-bottom: 20px;
        }

        @media (max-width: 640px) {
            .charts-grid { grid-template-columns: 1fr; }
            .chart-card.full { grid-column: 1; }
        }
    </style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
    <h1>🚨 Emergency Management System</h1>
    <a href="index.php">← Back to Admin Panel</a>
</div>

<div class="wrapper">

    <p class="page-title">KPI Analytics Dashboard</p>
    <p class="page-sub">Real-time overview of incident reports and resolution performance</p>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card red">
            <div class="kpi-label">Total Incidents</div>
            <div class="kpi-value"><?= $total ?></div>
        </div>
        <div class="kpi-card green">
            <div class="kpi-label">Resolved</div>
            <div class="kpi-value"><?= $resolved ?></div>
        </div>
        <div class="kpi-card orange">
            <div class="kpi-label">Pending</div>
            <div class="kpi-value"><?= $pending ?></div>
        </div>
        <div class="kpi-card blue">
            <div class="kpi-label">Resolution Rate</div>
            <div class="kpi-value"><?= $resolution_rate ?><span>%</span></div>
        </div>
    </div>

    <!-- Charts -->
    <div class="charts-grid">

        <!-- Monthly trend -->
        <div class="chart-card full">
            <div class="chart-title">📈 Monthly Incident Trend (Last 6 Months)</div>
            <canvas id="monthlyChart"></canvas>
        </div>

        <!-- By category -->
        <div class="chart-card">
            <div class="chart-title">📊 Incidents by Category</div>
            <canvas id="categoryChart"></canvas>
        </div>

        <!-- Status doughnut -->
        <div class="chart-card">
            <div class="chart-title">🟢 Status Breakdown</div>
            <canvas id="statusChart"></canvas>
        </div>

    </div>

    <div class="footer">
        Dashboard developed by Diya Shree Sadineni &nbsp;|&nbsp; Emergency Management System
    </div>

</div>

<script>
// ── Monthly Trend Line Chart ──
new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($months) ?>,
        datasets: [{
            label: 'Incidents Reported',
            data: <?= json_encode($month_counts) ?>,
            borderColor: '#c0392b',
            backgroundColor: 'rgba(192,57,43,0.08)',
            borderWidth: 2,
            pointBackgroundColor: '#c0392b',
            pointRadius: 5,
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } }
        }
    }
});

// ── Category Bar Chart ──
new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($categories) ?>,
        datasets: [{
            label: 'Count',
            data: <?= json_encode($cat_counts) ?>,
            backgroundColor: [
                '#c0392b','#e67e22','#f39c12','#2980b9','#8e44ad'
            ],
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

// ── Status Doughnut Chart ──
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($statuses) ?>,
        datasets: [{
            data: <?= json_encode($status_counts) ?>,
            backgroundColor: ['#e67e22','#27ae60','#2980b9'],
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 16, font: { size: 12 } } }
        },
        cutout: '65%'
    }
});
</script>

</body>
</html>