$(document).ready(function(){

    $('#branch_sel').on('change', function(){
    var selected_branch = $(this).val();
    
    $.ajax({
      url: 'get_branch_users.php',
      type: 'POST',
      data: {
        'branch': selected_branch
      },
      success: function(response){
        $('#fing_sel').html(response);
      }
    });
  });
  
  // Get Report passenger
  $(document).on('click', '#user_log', function(){
        // Get all filter values
        var date_sel_start = $('#date_sel_start').val();
        var date_sel_end = $('#date_sel_end').val();
        var time_sel = $(".time_sel:checked").val();
        var time_sel_start = $('#time_sel_start').val();
        var time_sel_end = $('#time_sel_end').val();
        var fing_sel = $('#fing_sel option:selected').val();
        var branch_sel = $('#branch_sel option:selected').val();
        
        $.ajax({
            url: 'user_log_up.php',
            type: 'POST',
            data: {
                'date_sel_start': date_sel_start,
                'date_sel_end': date_sel_end,
                'time_sel': time_sel,
                'time_sel_start': time_sel_start,
                'time_sel_end': time_sel_end,
                'fing_sel': fing_sel,
                'branch_sel': branch_sel
            },
            success: function(response){
                $('.up_info2').fadeIn(500);
                $('.up_info2').text("The Filter has been selected!");
                $('#Filter-export').modal('hide');
                setTimeout(function() {
                    $('.up_info2').fadeOut(500);
                }, 35000);
                $('#userslog').html(response);
            }
        });
    });
  $('form').on('submit', function(e){
    $('#hidden_branch_sel').val($('#branch_sel').val());
    $('#hidden_date_start').val($('#date_sel_start').val());
    $('#hidden_date_end').val($('#date_sel_end').val());
    $('#hidden_time_sel').val($('input[name="time_sel"]:checked').val());
    $('#hidden_time_start').val($('#time_sel_start').val());
    $('#hidden_time_end').val($('#time_sel_end').val());
    $('#hidden_fing_sel').val($('#fing_sel').val());
    $('#hidden_dev_sel').val($('#dev_sel').val());
});
});
