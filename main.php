<?php
/*
Plugin Name: MFS3 
Plugin URI: http://magicfields.org
Description: This plugin provide a integration between magic fields and Amazon S3 ( Using the tantan plugin) 
Version: 1
Author: Magic Fields Team
Author URI: http://magicfields.org
Licence: GPL2
*/

add_action('mf_after_upload_file','mfs3_after_upload');
function mfs3_after_upload($file) {

  require_once(WP_PLUGIN_DIR.'/tantan-s3/wordpress-s3/lib.s3.php');

  if (!$s3_options) $s3_options = get_option('tantan_wordpress_s3');

    $s3 = new TanTanS3($s3_options['key'], $s3_options['secret']);
    $s3->setOptions($s3_options);

    //getting the name of the file
    preg_match('/wp\-content\/.+/',$file['tmp_name'],$match);
    $file_name = $match[0];

    $s3->putObjectStream($s3_options['bucket'], $file_name, $file);
}



add_action('mf_before_delete_file','mfs3_before_delete');
function mfs3_before_delete($file_name) {
  global $wpdb;

  require_once(WP_PLUGIN_DIR.'/tantan-s3/wordpress-s3/lib.s3.php');

  if (!$s3_options) $s3_options = get_option('tantan_wordpress_s3');

  $s3 = new TanTanS3($s3_options['key'], $s3_options['secret']);
  $s3->setOptions($s3_options);

  $file = 'wp-content/files_mf/'.$file_name;

  $query = "DELETE FROM ".$wpdb->prefix."mfs3_images WHERE bucket = '".$s3_options['bucket']."' AND image_path = '".$file."'";
  $wpdb->query($query);
  $s3->deleteObject($s3_options['bucket'], $file);
}

/**
 * Saving the MF image
 */ 
add_action('mf_presave','mfs3_save_image',10,7);
function  mfs3_save_image($field_meta_id,$name,$group_index,$field_index,$post_id,$value_field,$writepanel_id){
  global $wpdb,$FIELD_TYPES;

  if($value_field == "") {
    return false;
  }

  //Loading the documentation of the tantan plugin
  require_once(WP_PLUGIN_DIR.'/tantan-s3/wordpress-s3/lib.s3.php');
  if (!$s3_options) $s3_options = get_option('tantan_wordpress_s3');

  //S3 Prefix
  $prefix = 'wp-content/files_mf/';

  //checking if the custom fields is a image
  $type = $wpdb->get_var("
    SELECT 
      sf.type  
    FROM 
      ".MF_TABLE_GROUP_FIELDS." as sf 
    LEFT JOIN 
      ".MF_TABLE_PANEL_GROUPS." as g 
    ON 
      (sf.group_id = g.id ) 
    WHERE 
      panel_id = ".$writepanel_id."
    AND 
      sf.name = '".$name."'"
  );

  if( $type == $FIELD_TYPES['image'])  {
    //Saving in the database a reference to this image
    $wpdb->query("INSERT INTO ".$wpdb->prefix."mfs3_images VALUES ('','".$s3_options['bucket']."','".$prefix.$value_field."')");
  }
}
