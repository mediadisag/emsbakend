<?php
session_start();
require 'connectDB.php';
$total_late_minutes = 0;
$total_overtime_minutes = 0;
$total_hours = 0;

// Build the WHERE clause based on filters (same as Export_Excel.php)
$conditions = ["1=1"];

// Date filter
if (!empty($_POST['date_sel_start']) && !empty($_POST['date_sel_end'])) {
    $date_start = mysqli_real_escape_string($conn, $_POST['date_sel_start']);
    $date_end = mysqli_real_escape_string($conn, $_POST['date_sel_end']);
    $conditions[] = "DATE(ul.checkindate) BETWEEN '$date_start' AND '$date_end'";
}

// Branch filter (changed to match Export_Excel.php)
if (!empty($_POST['branch_sel']) && $_POST['branch_sel'] != '0') {
    $branch = mysqli_real_escape_string($conn, $_POST['branch_sel']);
    $conditions[] = "u.device_branch = '$branch'"; // Changed from d.device_name to u.device_branch
}

// Fingerprint filter via username (same as Export_Excel.php)
if (!empty($_POST['fing_sel']) && $_POST['fing_sel'] != '0') {
    $username = mysqli_real_escape_string($conn, $_POST['fing_sel']);
    $finger_sql = "SELECT fingerprint_id FROM users WHERE username = '$username' LIMIT 1";
    $finger_result = mysqli_query($conn, $finger_sql);
    
    if ($finger_row = mysqli_fetch_assoc($finger_result)) {
        $finger_id = $finger_row['fingerprint_id'];
        $conditions[] = "ul.fingerprint_id = '$finger_id'";
    }
}

// Time filter (same as Export_Excel.php)
if (!empty($_POST['time_sel']) && !empty($_POST['time_sel_start']) && !empty($_POST['time_sel_end'])) {
    $time_start = mysqli_real_escape_string($conn, $_POST['time_sel_start']);
    $time_end = mysqli_real_escape_string($conn, $_POST['time_sel_end']);
    $time_column = ($_POST['time_sel'] == 'Time_in') ? 'timein' : 'timeout';
    $conditions[] = "TIME(ul.$time_column) BETWEEN '$time_start' AND '$time_end'";
}

$whereClause = implode(' AND ', $conditions);

// Main query (same as Export_Excel.php)
$sql = "SELECT 
    ul.*,
    d.device_name,
    u.start_time as user_start,
    IFNULL(u.end_time, '18:00:00') as user_end,
    CASE 
        WHEN ul.timeout = '00:00:00' THEN 0
        ELSE TIMESTAMPDIFF(HOUR, ul.timein, ul.timeout)
    END as hours_worked,
    CASE
        WHEN TIME_TO_SEC(TIMEDIFF(ul.timein, u.start_time)) > 0 
        THEN TIME_TO_SEC(TIMEDIFF(ul.timein, u.start_time))/60
        ELSE 0
    END as late_minutes,
    CASE
        WHEN ul.timeout != '00:00:00' AND TIME_TO_SEC(TIMEDIFF(ul.timeout, IFNULL(u.end_time, '18:00:00'))) > 0 
        THEN TIME_TO_SEC(TIMEDIFF(ul.timeout, IFNULL(u.end_time, '18:00:00')))/60
        ELSE 0
    END as overtime_minutes
FROM users_logs ul 
LEFT JOIN devices d ON ul.device_uid = d.device_uid 
LEFT JOIN users u ON ul.fingerprint_id = u.fingerprint_id
WHERE $whereClause
ORDER BY ul.checkindate DESC, ul.timein DESC";
// Execute main query
$result = mysqli_query($conn, $sql);

// Display results table
echo '<div class="table-responsive">
<table class="table table-bordered">
    <thead class="thead-dark">
        <tr>
            <th>Sl_No</th>
            <th>Name</th>
            <th>Employee_Id</th>
            <th>Fingerprint ID</th>
            <th>Branch</th>
            <!--<th>Department</th>-->
            <th>Date</th>
            <th>Time In</th>
            <th>Time Out</th>
            <th>Hours Worked</th>
            <th>Late By (min)</th>
            <th>Overtime (min)</th>
        </tr>
    </thead>
    <tbody>';

// Display rows
while ($row = mysqli_fetch_assoc($result)) {
    $late_minutes = max(0, round($row['late_minutes']));
    $overtime_minutes = max(0, round($row['overtime_minutes']));
    $hours_worked = $row['timeout'] != '00:00:00' ? $row['hours_worked'] : 0;
    
    // Accumulate totals
    $total_late_minutes += $late_minutes;
    $total_overtime_minutes += ($row['timeout'] != '00:00:00' ? $overtime_minutes : 0);
    $total_hours += $hours_worked;
    
    echo "<tr>
        <td>{$row['id']}</td>
        <td>{$row['username']}</td>
        <td>{$row['serialnumber']}</td>
        <td>{$row['fingerprint_id']}</td>
        <td>{$row['device_name']}</td>
        <!--<td>{$row['device_dep']}</td>-->
        <td>{$row['checkindate']}</td>
        <td>{$row['timein']}" . ($late_minutes > 0 ? " <span class='text-danger'>(" . date('H:i', strtotime($row['user_start'])) . ")</span>" : "") . "</td>
        <td>" . ($row['timeout'] != '00:00:00' ? $row['timeout'] : 'Not logged out') . 
            ($overtime_minutes > 0 ? " <span class='text-success'>(" . date('H:i', strtotime($row['user_end'])) . ")</span>" : "") . "</td>
        <td>" . ($hours_worked > 0 ? $hours_worked . ' hrs' : '-') . "</td>
        <td>" . ($late_minutes > 0 ? "$late_minutes min" : '-') . "</td>
        <td>" . ($overtime_minutes > 0 && $row['timeout'] != '00:00:00' ? "$overtime_minutes min" : '-') . "</td>
    </tr>";
}

// Display totals if filtering by fingerprint
if (!empty($_POST['fing_sel']) && $_POST['fing_sel'] != '0') {
    // Convert minutes to hours and minutes
    $late_hours = floor($total_late_minutes / 60);
    $late_remaining_minutes = $total_late_minutes % 60;
    $overtime_hours = floor($total_overtime_minutes / 60);
    $overtime_remaining_minutes = $total_overtime_minutes % 60;
    
    echo "<tr class='table-info font-weight-bold'>
        <td colspan='9' class='text-right'><strong>Totals:</strong></td>
        <td>{$total_hours} hrs</td>
        <td>{$total_late_minutes} min</td>
        <td>{$total_overtime_minutes} min</td>
    </tr>
    <tr class='table-secondary'>
        <td colspan='9' class='text-right'><strong>Converted Format:</strong></td>
        <td>{$total_hours} hrs</td>
        <td>" . ($late_hours > 0 ? $late_hours . "hrs " : "") . "{$late_remaining_minutes}min</td>
        <td>" . ($overtime_hours > 0 ? $overtime_hours . "hrs " : "") . "{$overtime_remaining_minutes}min</td>
    </tr>";
}

echo '</tbody></table></div>';
?>