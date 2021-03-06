<?php

class AdLibException extends \CultureObject\Exception\ProviderException { }

class AdLib extends \CultureObject\Provider {
  
  private $provider = array(
    'name' => 'AdLib',
    'version' => '1.0',
    'developer' => 'Thirty8 Digital',
    'cron' => false
  );
  
  function get_provider_information() {
    return $this->provider;
  }
  
  function execute_load_action() {
    if (isset($_FILES['cos_adlib_import_file']) && isset($_POST['cos_adlib_nonce'])) {
      if (wp_verify_nonce($_POST['cos_adlib_nonce'], 'cos_adlib_import')) {
        $this->perform_sync();
      } else {
        die("Security Violation.");
      }
    }
  }
  
  function register_settings() {
    return;
  }
  
  function generate_settings_outside_form_html() {
  
    echo "<h3>Provider Settings</h3>";
    
    echo "<p>You're currently using version ".$this->provider['version']." of the ".$this->provider['name']." sync provider by ".$this->provider['developer'].".</p>";
    
    $show_message = get_transient('cos_adlib_show_message');
    if ($show_message) {
      echo "<p><strong>Your AdLib import was successful.</strong></p>";
      
      echo '<a href="#" id="show_adlib_import_log">Show Log</a>';
      
      ?>
      
      <script>
      jQuery('#show_adlib_import_log').click(function(e) {
        e.preventDefault();
        jQuery('#show_adlib_import_log').remove();
        jQuery('#adlib_import_log').css('display','block');
      });
      </script>
      
      <?php 
      
      echo '<div id="adlib_import_log" style="display: none">';
      if (get_transient('cos_adlib_status')) echo implode('<br />',get_transient('cos_adlib_status'));
      if (get_transient('cos_adlib_deleted')) echo implode('<br />',get_transient('cos_adlib_deleted'));
      echo '</div>';
      
      delete_transient('cos_adlib_show_message');
      delete_transient('cos_adlib_status');
      delete_transient('cos_adlib_deleted');
      
      
    } else {    
      echo "<p>You need to upload an xml export file from AdLib in order to import.</p>";
      
      echo '<form id="adlib_import_form" method="post" action="" enctype="multipart/form-data">';
        echo '<input type="file" name="cos_adlib_import_file" />';
        echo '<input type="hidden" name="cos_adlib_nonce" value="'.wp_create_nonce('cos_adlib_import').'" /><br /><br />';
        echo '<input id="adlib_import_submit" type="button" class="button button-primary" value="Import AdLib Dump" />';
      echo '</form>';
      echo '<script>
      jQuery("#adlib_import_submit").click(function(e) {
			  jQuery("#adlib_import_submit").val("Importing... This may take some time...");
			  jQuery("#adlib_import_submit").addClass("button-disabled");
			  window.setTimeout(\'jQuery("#adlib_import_form").submit();\',100);
			});
      </script>';
    }
  }
  
  function generate_settings_field_input_text($args) {
    $field = $args['field'];
    $value = get_option($field);
    echo sprintf('<input type="text" name="%s" id="%s" value="%s" />', $field, $field, $value);
  }
  
  function perform_sync() {
    set_time_limit(0);
    ini_set('memory_limit','2048M');
    
    $start = microtime(true);
        
    $previous_posts = $this->get_current_object_ids();
    
    $file = $_FILES['cos_adlib_import_file'];
    if ($file['error'] !== 0) {
	    throw new Exception("Unable to import. PHP reported an error code ".$file['error']);
	    return;
	  }
    $data = file_get_contents($file['tmp_name']);
    if (!$data) {
	    throw new Exception("Unable to import: File upload corrupt");
	    return;
	  }
    $result = json_decode(json_encode((array)simplexml_load_string($data)),1);
    
    unlink($file['tmp_name']);
    
    $created = 0;
    $updated = 0;
    
    if (isset($result['recordList']['record'])) {
	    $import = $result['recordList']['record'];
    } else if (isset($result['record'])) {
	    $import = $result['record'];
    } else {
	    throw new Exception("Unable to import. This file appears to be incompatible.");
	    return;
    }
    
    $number_of_objects = count($import);
    if ($number_of_objects > 0) {
      foreach($import as $doc) {
	      
        if (is_array($doc['title'])) $doc['title'] = array_pop($doc['title']); //This is weird. Why would you have more than one title per record?
        $object_exists = $this->object_exists($doc['object_number']);
        
        if (!$object_exists) {
          $current_objects[] = $this->create_object($doc);
          $import_status[] = "Created object: ".$doc['title'];
          $created++;
        } else {
          $current_objects[] = $this->update_object($doc);
          $import_status[] = "Updated object: ".$doc['title'];
          $updated++;
        }
        
      }
      $deleted = $this->clean_objects($current_objects,$previous_posts);
    }
          
    $end = microtime(true);
    
    $import_duration = $end-$start;
    
    set_transient('cos_message', "AdLib import completed with ".$created." objects created, ".$updated." updated and ".$deleted." deleted in ".round($import_duration, 2)." seconds.", 0);
    
    set_transient('cos_adlib_show_message', true, 0);
    set_transient('cos_adlib_status', $import_status, 0);
  }
  
  function get_current_object_ids() {
    $args = array('post_type'=>'object','posts_per_page'=>-1);
    $posts = get_posts($args);
    $current_posts = array();
    foreach($posts as $post) {
      $current_posts[] = $post->ID;
    }
    return $current_posts;
  }
  
  function clean_objects($current_objects,$previous_objects) {
    $to_remove = array_diff($previous_objects, $current_objects);
    
    $import_delete = array();
    
    $deleted = 0;
    
    foreach($to_remove as $remove_id) {
      wp_delete_post($remove_id,true);
      $import_delete[] = "Removed Post ID $remove_id as it is no longer in the exported list of objects from AdLib";
      $deleted++;
    }
    
    set_transient('cos_adlib_deleted', $import_delete, 0);
    
    return $deleted;
    
  }
  
  function create_object($doc) {
    $post = array(
      'post_title'        => $doc['title'],
      'post_type'         => 'object',
      'post_status'       => 'publish',
    );
    $post_id = wp_insert_post($post);
    $this->update_object_meta($post_id,$doc);
    return $post_id;
  }
  
  
  function update_object($doc) {
    $existing_id = $this->existing_object_id($doc['object_number']);
    $post = array(
      'ID'                => $existing_id,
      'post_title'        => $doc['title'],
      'post_type'         => 'object',
      'post_status'       => 'publish',
    );
    $post_id = wp_update_post($post);
    $this->update_object_meta($post_id,$doc);
    return $post_id;
  }
  
  function update_object_meta($post_id,$doc) {
    foreach($doc as $key => $value) {
	    if (is_array($value)) {
		    $value = array_filter($value);
		    $value = array_unique($value, SORT_REGULAR);
		    if (count($value) == 1) $value = array_pop($value);
	    }
	    if ($key == "reproduction.reference") {
		    if (is_array($value)) {
			    $newvalue = array();
			    foreach($value as $img) {
				    $newvalue[] = str_replace('\\','/',$img);
			    }
			    $value = $newvalue;
		    } else {
			    $value = str_replace('\\','/',$value);
		    }
	    }
      update_post_meta($post_id,$key,$value);
    }
  }
  
  function object_exists($id) {
    $args = array(
      'post_type' => 'object',
      'meta_key' => 'object_number',
      'meta_value' => $id,
    );
    return (count(get_posts($args)) > 0) ? true : false;
  }
  
  function existing_object_id($id) {
    $args = array(
      'post_type' => 'object',
      'meta_key' => 'object_number',
      'meta_value' => $id
    );
    $posts = get_posts($args);
    if (count($posts) == 0) throw new Exception("BUG: called existing_object_id for an object that doesn't exist.");
    return $posts[0]->ID;
  }
  
  
}

?>