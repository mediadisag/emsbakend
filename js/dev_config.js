$(document).ready(function(){

	//Add Device 
	$(document).on('click', '#dev_add', function(e){
    e.preventDefault();
    
    // Log form data
    console.log("Submitting device data:", {
        dev_name: $('#dev_name').val(),
        dev_dep: $('#dev_dep').val(),
        branch_code: $('#branch_code').val(),
        location: $('#location').val(),
        state: $('#state').val()
    });

    $.ajax({
        url: 'dev_config.php',
        type: 'POST',
        data: {
            'dev_add': 1,
            'dev_name': $('#dev_name').val().trim(),
            'dev_dep': $('#dev_dep').val().trim(),
            'branch_code': $('#branch_code').val().trim(),
            'location': $('#location').val().trim(),
            'state': $('#state').val().trim()
        },
        success: function(response){
            console.log("Server response:", response);
            
            try {
                if (response == 1) {
                    console.log("Device added successfully");
                    $('#new-device').modal('hide');
                    $('.alert_dev').html('<p class="alert alert-success">Device added successfully!</p>');
                    refreshDeviceList();
                    $('#dev_name, #dev_dep, #branch_code, #location, #state').val('');
                } else {
                    console.error("Server error:", response);
                    $('.alert_dev').html(response);
                }
            } catch (e) {
                console.error("Error processing response:", e);
                $('.alert_dev').html('<p class="alert alert-danger">Error processing server response</p>');
            }
        },
        error: function(xhr, status, error) {
            console.error("AJAX Error:", {
                status: status,
                error: error,
                responseText: xhr.responseText
            });
        }
    });
});
	
	function refreshDeviceList() {
		$.ajax({
			url: "dev_up.php",
			type: 'POST',
			data: { 'dev_up': 1 }
		}).done(function(data) {
			$('#devices').html(data);
		});
	}

	$(document).on('click', '.dev_edit', function(){
		var device_id = $(this).data('id');
		var row = $(this).closest('tr');
		
		// Get current values
		var dev_name = row.find('td:eq(0)').text();
		var dev_dep = row.find('td:eq(1)').text();
		var branch_code = row.find('td:eq(3)').text();
		var location = row.find('td:eq(4)').text();
		var state = row.find('td:eq(5)').text();
		
		// Set values in modal
		$('#edit_device_id').val(device_id);
		$('#edit_dev_name').val(dev_name);
		$('#edit_dev_dep').val(dev_dep);
		$('#edit_branch_code').val(branch_code);
		$('#edit_location').val(location);
		$('#edit_state').val(state);
		
		// Show modal
		$('#edit-device').modal('show');
	});
	
	$(document).on('click', '#update_device', function(){
		var device_id = $('#edit_device_id').val();
		var dev_name = $('#edit_dev_name').val().trim();
		var dev_dep = $('#edit_dev_dep').val().trim();
		var branch_code = $('#edit_branch_code').val().trim();
		var location = $('#edit_location').val().trim();
		var state = $('#edit_state').val().trim();
		
		if(!dev_name || !dev_dep || !branch_code || !location || !state) {
			$('.alert_dev').fadeIn(500);
			$('.alert_dev').html('<p class="alert alert-danger">All fields are required!</p>');
			return false;
		}
		
		$.ajax({
			url: 'dev_config.php',
			type: 'POST',
			data: {
				'dev_edit': 1,
				'device_id': device_id,
				'dev_name': dev_name,
				'dev_dep': dev_dep,
				'branch_code': branch_code,
				'location': location,
				'state': state
			},
			success: function(response){
				if(response == 1) {
					$('#edit-device').modal('hide');
					$('.alert_dev').fadeIn(500);
					$('.alert_dev').html('<p class="alert alert-success">Device updated successfully!</p>');
					refreshDeviceList();
				} else {
					$('.alert_dev').fadeIn(500);
					$('.alert_dev').html(response);
				}
				setTimeout(function(){
					$('.alert_dev').fadeOut(500);
				}, 2000);
			}
		});
	});

	//Device Token update
	$(document).on('click', '.dev_uid_up', function(){

		var el = this;
		var dev_id = $(this).data('id');

		bootbox.confirm("Do you really want to Update this Device Token?", function(result) {
			if(result){
			     // AJAX Request
			     $.ajax({
			       url: 'dev_config.php',
			       type: 'POST',
			       data: { 
			          'dev_uid_up': 1,
			          'dev_id_up': dev_id,
			       },
			       success: function(response){

			        $(el).closest('tr').css('background','#5cb85c');
			        $(el).closest('tr').fadeOut(300,function(){
			              $(this).show();
			        });
			        if(response == 1){			    
				    	$('.alert_dev').fadeIn(500);
			        	$('.alert_dev').html('<p class="alert alert-success">The device Token has been updated successfully</p>');

				        setTimeout(function () {
					        $('.alert_dev').fadeOut(500);
					        
					    }, 2000);
			            $.ajax({
			              	url: "dev_up.php",
			              	type: 'POST',
			              	data: {
			                  'dev_up': 1,
			              	}
			              	}).done(function(data) {
			              	$('#devices').html(data);
			            });
			        }
				    else {
			    		$('.alert_dev').fadeIn(500);
				    	$('.alert_dev').html(response);

				    	setTimeout(function () {
					        $('.alert_dev').fadeOut(500);
					    }, 2000);
				    }
			       }
			     });
		   	}
		});
	});

	//Delete Device
	$(document).on('click', '.dev_del', function(){

		var el = this;
		var deleteid = $(this).data('id');

		bootbox.confirm("Do you really want to delete this Device?", function(result) {
		if(result){
		     // AJAX Request
		     $.ajax({
		       url: 'dev_config.php',
		       type: 'POST',
		       data: { 
		          'dev_del': 1,
		          'dev_sel': deleteid,
		       },
		       success: function(response){

		         // Removing row from HTML Table
		          if(response == 1){
		              $(el).closest('tr').css('background','#d9534f');
		              $(el).closest('tr').fadeOut(800,function(){
		                $(this).remove();
		              });

		              $.ajax({
	                    url: "dev_up.php",
	                    type: 'POST',
	                    data: {
	                        'dev_up': 1,
	                    }
	                    }).done(function(data) {
	                    $('#devices').html(data);
	                  });
		          }
		          else{
		            $('.alert_dev_del').fadeIn(500);
		            $('.alert_dev_del').html(response);

		            setTimeout(function () {
		                $('.alert_dev_del').fadeOut(500);
		            }, 2000);
		              bootbox.alert('Device not deleted.');
		          }
		       }
		     });
		   }
		});
	});

	//Device Mode
	$(document).on('click', '.mode_sel', function(){

		var el = this;
    	var dev_mode = $(this).attr("value");
		var dev_id = $(this).data('id');

		bootbox.confirm("Do you really want to change this Device Mode?", function(result) {
			if(result){
			     // AJAX Request
			     $.ajax({
			       url: 'dev_config.php',
			       type: 'POST',
			       data: { 
			          'dev_mode_set': 1,
			          'dev_mode': dev_mode,
			          'dev_id': dev_id,
			       },
			       success: function(response){
			       		
			          	if(response == 1){
			          		// bootbox.alert('<p class="alert alert-success">The device Mode has been updated successfully</p>');
			              	$(el).closest('tr').css('background','#5cb85c');
					        $(el).closest('tr').fadeOut(300,function(){
					              $(this).show();
					        });

			              	$.ajax({
								url: "dev_up.php",
		                    	type: 'POST',
		                    	data: {
		                        'dev_up': 1,
		                    	}
		                    }).done(function(data) {
		                    	$('#devices').html(data);
		                  	});
			          	}
			          	else{
				            $('.alert_dev_del').fadeIn(500);
				            $('.alert_dev_del').html(response);

				            setTimeout(function () {
				                $('.alert_dev_del').fadeOut(500);
				            }, 2000);
				              bootbox.alert('Device not changed.');
		         	 	}
			       	}
			     });
		   	}
		   	else{
			   	$.ajax({
		            url: "dev_up.php",
		            type: 'POST',
		            data: {
		                'dev_up': 1,
		            }
		            }).done(function(data) {
		            $('#devices').html(data);
	          	});
		   	}
		});
	});
});