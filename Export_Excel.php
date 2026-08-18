<?php
require 'connectDB.php';

if (isset($_POST["To_Excel"])) {
    $export_format = isset($_POST['export_format']) ? $_POST['export_format'] : 'csv';

    $whereClause = '';
    $conditions = [];

    // Date filter
    if (!empty($_POST['date_sel_start']) && !empty($_POST['date_sel_end'])) {
        $date_start = mysqli_real_escape_string($conn, $_POST['date_sel_start']);
        $date_end = mysqli_real_escape_string($conn, $_POST['date_sel_end']);
        $conditions[] = "DATE(ul.checkindate) BETWEEN '$date_start' AND '$date_end'";
    }

    // Branch filter
    if (!empty($_POST['branch_sel']) && $_POST['branch_sel'] != '0') {
        $branch = mysqli_real_escape_string($conn, $_POST['branch_sel']);
        $conditions[] = "u.device_branch = '$branch'";
    }

    // Fingerprint filter via username
    if (!empty($_POST['fing_sel']) && $_POST['fing_sel'] != '0') {
        $username = mysqli_real_escape_string($conn, $_POST['fing_sel']);
        $finger_sql = "SELECT fingerprint_id FROM users WHERE username = '$username' LIMIT 1";
        $finger_result = mysqli_query($conn, $finger_sql);

        if ($finger_row = mysqli_fetch_assoc($finger_result)) {
            $finger_id = $finger_row['fingerprint_id'];
            $conditions[] = "ul.fingerprint_id = '$finger_id'";
        }
    }

    // Time filter
    if (!empty($_POST['time_sel']) && !empty($_POST['time_sel_start']) && !empty($_POST['time_sel_end'])) {
        $time_start = mysqli_real_escape_string($conn, $_POST['time_sel_start']);
        $time_end = mysqli_real_escape_string($conn, $_POST['time_sel_end']);
        $time_column = $_POST['time_sel'] == 'Time_in' ? 'timein' : 'timeout';
        $conditions[] = "TIME(ul.$time_column) BETWEEN TIME('$time_start') AND TIME('$time_end')";
    }

    // Combine conditions
    if (count($conditions) > 0) {
        $whereClause = ' WHERE ' . implode(' AND ', $conditions);
    }

    // SQL query
    $sql = "SELECT 
        ul.id,
        ul.username,
        ul.serialnumber,
        ul.fingerprint_id,
        
        ul.checkindate,
        ul.timein,
        ul.timeout,
        u.device_branch as branch_name,
        COALESCE(d.location, '') as location,
        u.start_time as user_start,
        u.end_time as user_end,
        u.hourly_pay as hourly_pay,
        CASE 
            WHEN ul.timeout = '00:00:00' THEN 0
            ELSE TIMESTAMPDIFF(HOUR, ul.timein, ul.timeout)
        END as hours_worked,
        CASE
            WHEN TIME_TO_SEC(TIMEDIFF(ul.timein, u.start_time)) > 0 
            THEN ROUND(TIME_TO_SEC(TIMEDIFF(ul.timein, u.start_time))/60, 2)
            ELSE 0
        END as late_minutes,
        CASE
            WHEN ul.timeout != '00:00:00' AND TIME_TO_SEC(TIMEDIFF(ul.timeout, u.end_time)) > 0 
            THEN ROUND(TIME_TO_SEC(TIMEDIFF(ul.timeout, u.end_time))/60, 2)
            ELSE 0
        END as overtime_minutes
    FROM users_logs ul 
    LEFT JOIN devices d ON ul.device_uid = d.device_uid 
    LEFT JOIN users u ON ul.fingerprint_id = u.fingerprint_id
    $whereClause
    ORDER BY ul.checkindate DESC, ul.timein DESC";

    // Execute the query
    $result = mysqli_query($conn, $sql);

    if ($export_format == 'excel') {
    // Determine if we're filtering by a specific user
    $show_pay_info = (!empty($_POST['fing_sel']) && $_POST['fing_sel'] != '0');
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="UserLog_'.date('Y-m-d').'.xls"');
    
    echo "<html>";
    echo "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />";
    echo "<table border='1'>";
    
    // Output headers
    echo "<tr>";
    echo "<th>Sl_No</th><th>Name</th><th>Employee_Id</th><th>Fingerprint ID</th>";
    echo "<th>Branch</th><th>Location</th><th>Checkin Date</th>";
    echo "<th>Hours Worked</th><th>Time In</th><th>Time Out</th>";
    echo "<th>Late By (minutes)</th><th>Overtime (minutes)</th>";
    if ($show_pay_info) {
        echo "<th>Total Pay</th>";
    }
    echo "</tr>";

    $total_late = 0;
    $total_overtime = 0;
    $total_hours = 0;
    $total_pay = 0;

    while ($row = mysqli_fetch_assoc($result)) {
        $late_minutes = max(0, round($row['late_minutes'], 2));
        $overtime_minutes = max(0, round($row['overtime_minutes'], 2));
        $hours_worked = $row['timeout'] != '00:00:00' ? $row['hours_worked'] : 0;
        $hourly_pay = isset($row['hourly_pay']) ? $row['hourly_pay'] : 0;
        $pay = $hours_worked * $hourly_pay;

        $total_late += $late_minutes;
        $total_overtime += $overtime_minutes;
        $total_hours += $hours_worked;
        $total_pay += $pay;

        echo "<tr>";
        echo "<td>".$row['id']."</td>";
        echo "<td>".$row['username']."</td>";
        echo "<td>".$row['serialnumber']."</td>";
        echo "<td>".$row['fingerprint_id']."</td>";
        echo "<td>".$row['branch_name']."</td>";
        echo "<td>".$row['location']."</td>";
        echo "<td>".$row['checkindate']."</td>";
        echo "<td>".$hours_worked."</td>";
        echo "<td>".$row['timein']."</td>";
        echo "<td>".$row['timeout']."</td>";
        echo "<td>".($late_minutes > 0 ? number_format($late_minutes, 2) : '')."</td>";
        echo "<td>".($overtime_minutes > 0 ? number_format($overtime_minutes, 2) : '')."</td>";
        if ($show_pay_info) {
            echo "<td>=\"".number_format($pay, 2)."\"</td>";
        }
        echo "</tr>";
    }

    // Totals row
    echo "<tr>";
    echo "<td colspan='6'></td>";
    echo "<td><b>TOTALS</b></td>";
    echo "<td>".$total_hours." hrs</td>";
    echo "<td></td>";
    echo "<td></td>";
    echo "<td>".number_format($total_late, 2)." min</td>";
    echo "<td>".number_format($total_overtime, 2)." min</td>";
    if ($show_pay_info) {
        echo "<td><b>".number_format($total_pay, 2)."</b></td>";
    }
    echo "</tr>";

    // Converted format row
    $late_hours = floor($total_late / 60);
    $late_mins = round(fmod($total_late, 60));
    $overtime_hours = floor($total_overtime / 60);
    $overtime_mins = round(fmod($total_overtime, 60));

    echo "<tr>";
    echo "<td colspan='6'></td>";
    echo "<td><b>Converted Format</b></td>";
    echo "<td>".$total_hours." hrs</td>";
    echo "<td></td>";
    echo "<td></td>";
    echo "<td>".($late_hours > 0 ? $late_hours."hrs " : '').$late_mins."min</td>";
    echo "<td>".($overtime_hours > 0 ? $overtime_hours."hrs " : '').$overtime_mins."min</td>";
    if ($show_pay_info) {
        echo "<td><b>".number_format($total_pay, 2)."</b></td>";
    }
    echo "</tr>";

    echo "</table>";
    echo "</html>";
} else {
    // CSV format export
    $show_pay_info = (!empty($_POST['fing_sel']) && $_POST['fing_sel'] != '0');
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="UserLog_'.date('Y-m-d').'.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

    // Create headers array conditionally
    $headers = array(
        'Sl_No', 'Name', 'Employee_Id', 'Fingerprint ID', 
        'Branch', 'Location', 'Checkin Date', 
        'Hours Worked', 'Time In', 'Time Out', 
        'Late By (minutes)', 'Overtime (minutes)'
    );
    
    if ($show_pay_info) {
        $headers[] = 'Total Pay';
    }
    
    fputcsv($output, $headers);

    $total_late = 0;
    $total_overtime = 0;
    $total_hours = 0;
    $total_pay = 0;

    while ($row = mysqli_fetch_assoc($result)) {
        $late_minutes = max(0, round($row['late_minutes'], 2));
        $overtime_minutes = max(0, round($row['overtime_minutes'], 2));
        $hours_worked = $row['timeout'] != '00:00:00' ? $row['hours_worked'] : 0;
        $hourly_pay = isset($row['hourly_pay']) ? $row['hourly_pay'] : 0;
        $pay = $hours_worked * $hourly_pay;

        $total_late += $late_minutes;
        $total_overtime += $overtime_minutes;
        $total_hours += $hours_worked;
        $total_pay += $pay;

        // Create data array
        $data = array(
            $row['id'],
            $row['username'],
            $row['serialnumber'],
            $row['fingerprint_id'],
            $row['branch_name'],
            $row['location'],
            $row['checkindate'],
            $hours_worked,
            $row['timein'],
            $row['timeout'],
            $late_minutes > 0 ? number_format($late_minutes, 2) : '',
            $overtime_minutes > 0 ? number_format($overtime_minutes, 2) : ''
        );
        
        if ($show_pay_info) {
            $data[] = number_format($pay, 2);
        }
        
        fputcsv($output, $data);
    }

    fputcsv($output, array('')); // Spacer row

    // Create totals array
    $totals = array(
        '', '', '', '', '', '', 'TOTALS',
        '', $total_hours . ' hrs',
        '',
        number_format($total_late, 2) . ' min',
        number_format($total_overtime, 2) . ' min'
    );
    
    if ($show_pay_info) {
        $totals[] = number_format($total_pay, 2);
    }
    
    fputcsv($output, $totals);

    $late_hours = floor($total_late / 60);
    $late_mins = round(fmod($total_late, 60));
    $overtime_hours = floor($total_overtime / 60);
    $overtime_mins = round(fmod($total_overtime, 60));

    // Create converted format array
    $converted = array(
        '', '', '', '', '', '', 'Converted Format',
        '', $total_hours . ' hrs',
        '',
        ($late_hours > 0 ? $late_hours . 'hrs ' : '') . $late_mins . 'min',
        ($overtime_hours > 0 ? $overtime_hours . 'hrs ' : '') . $overtime_mins . 'min'
    );
    
    if ($show_pay_info) {
        $converted[] = number_format($total_pay, 2);
    }
    
    fputcsv($output, $converted);

    fclose($output);
}
    exit();
}

mysqli_close($conn);
?>