<div class="table-responsive-sm" style="max-height: 870px;"> 
  <table class="table">
    <thead class="table-primary">
      <tr>
        <th>Finger .ID</th>
        <th>Name</th>
        <th>Gender</th>
        <th>Emp_Id</th>
        <th>Start Time</th>
        <th>End Time</th>
        <th>Date</th>
        <th>Branch</th>
        <th>Dev.Status</th>
      </tr>
    </thead>
    <tbody class="table-secondary">
    <?php
      //Connect to database
      require'connectDB.php';

        $sql = "SELECT * FROM users WHERE del_fingerid=0 ORDER BY id DESC";
        $result = mysqli_stmt_init($conn);
        if (!mysqli_stmt_prepare($result, $sql)) {
            echo '<p class="error">SQL Error</p>';
        }
        else{
            mysqli_stmt_execute($result);
            $resultl = mysqli_stmt_get_result($result);
          if (mysqli_num_rows($resultl) > 0){
              while ($row = mysqli_fetch_assoc($resultl)){
                $fingerid = $row['fingerprint_id'];
                $device_uid = $row['device_uid'];
      ?>
                <TR class="user-row" data-fingerid="<?php echo $fingerid; ?>" data-device="<?php echo $device_uid; ?>">
                  <TD>
                    <span class="selection-indicator" style="display: none;"><i class='glyphicon glyphicon-ok' title='The selected UID'></i></span>
                    <form>
                      <button type="button" class="select_btn" data-id="<?php echo $fingerid;?>" name="<?php echo $device_uid;?>" title="select this UID"><?php echo $fingerid;?></button>
                    </form>
                  </TD>
                  <TD><?php echo $row['username'];?></TD>
                  <TD><?php echo $row['gender'];?></TD>
                  <TD><?php echo $row['serialnumber'];?></TD>
                  <TD><?php echo $row['start_time'];?></TD>
                  <TD><?php echo $row['end_time'];?></TD>
                  <TD><?php echo $row['user_date'];?></TD>
                  <TD><?php echo $row['device_branch'] ? $row['device_branch'] : 'All'; ?></TD>
                  <TD><?php echo ($row['add_fingerid'] == "0") ? "Added" : "Free"?></TD>
                </TR>
    <?php
            }   
        }
      }
    ?>
    </tbody>
  </table>
</div>

<script>
$(document).ready(function() {
  // Handle click on select button
  $('.select_btn').on('click', function() {
    var fingerid = $(this).data('id');
    var device_uid = $(this).attr('name');
    
    // Hide all indicators first
    $('.selection-indicator').hide();
    
    // Show indicator for the selected row
    $(this).closest('TD').find('.selection-indicator').show();
    
    // Make AJAX call to select the user in the backend
    $.ajax({
      url: 'manage_users_conf.php',
      type: 'GET',
      data: {
        select: 1,
        finger_id: fingerid,
        dev_uid: device_uid
      },
      success: function(response) {
        // Fill the form fields with user data
        if(response && response[0]) {
          $('#fingerid').val(response[0].fingerprint_id);
          $('#finger_id').val(response[0].fingerprint_id);
          $('#dev_id').val(response[0].device_uid);
          $('#name').val(response[0].username);
          $('#number').val(response[0].serialnumber);
          $('#hourly_pay').val(hourly_pay.Hourly_pay);
          $('#start_time').val(response[0].start_time);
          $('#end_time').val(response[0].end_time);
          if(response[0].gender == "Female"){
            $('.gender').val(["Female"]);
          }
          else{
            $('.gender').val(["Male"]);
          }
          'branch': branch
        }
      }
    });
  });
});
</script>